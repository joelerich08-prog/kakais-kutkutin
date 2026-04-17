<?php
require_once __DIR__ . '/../middleware/cors.php';

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['productId']) || !isset($data['wholesaleQuantity'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: productId and wholesaleQuantity required']);
    exit;
}

$productId = trim($data['productId']);
$wholesaleQuantity = (int)$data['wholesaleQuantity'];
$variantId = normalizeInventoryVariantId($data['variantId'] ?? null);

if (empty($productId) || $wholesaleQuantity <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid productId or wholesaleQuantity']);
    exit;
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);
    $inventoryId = $inventory['id'];

    if ($inventory['wholesaleQty'] < $wholesaleQuantity) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient wholesale quantity']);
        exit;
    }

    $packsPerBox = $inventory['packsPerBox'];
    if ($packsPerBox <= 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Product does not have a valid packs-per-box conversion']);
        exit;
    }
    $retailQtyToAdd = $wholesaleQuantity * $packsPerBox;

    $stmt = $pdo->prepare("UPDATE inventory_levels SET wholesaleQty = wholesaleQty - :wholesaleQty, retailQty = retailQty + :retailQty WHERE id = :id");
    $updateParams = [
        ':wholesaleQty' => $wholesaleQuantity,
        ':retailQty' => $retailQtyToAdd,
        ':id' => $inventoryId,
    ];
    $stmt->execute($updateParams);

    $movementId = bin2hex(random_bytes(16));
    insertStockMovement($pdo, [
        'id' => $movementId,
        'productId' => $productId,
        'variantId' => $variantId,
        'movementType' => 'breakdown',
        'fromTier' => 'wholesale',
        'toTier' => 'retail',
        'quantity' => $wholesaleQuantity,
        'performedBy' => $userId,
    ]);

    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userRow['name'] ?? 'Unknown';

    $batchSql = "SELECT id, wholesaleQty
        FROM product_batches
        WHERE productId = :productId
          AND status != 'disposed'
          AND wholesaleQty > 0";
    $batchParams = [':productId' => $productId];

    if ($variantId !== null && $variantId !== '') {
        $batchSql .= " AND variantId = :variantId";
        $batchParams[':variantId'] = $variantId;
    } else {
        $batchSql .= " AND variantId IS NULL";
    }

    $batchSql .= " ORDER BY expirationDate ASC, receivedDate ASC FOR UPDATE";

    $stmt = $pdo->prepare($batchSql);
    $stmt->execute($batchParams);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remainingWholesaleToBreakdown = $wholesaleQuantity;
    $affectedBatchIds = [];

    foreach ($batches as $batch) {
        if ($remainingWholesaleToBreakdown <= 0) {
            break;
        }

        $availableWholesale = (int) $batch['wholesaleQty'];
        if ($availableWholesale <= 0) {
            continue;
        }

        $wholesaleToBreakdown = min($remainingWholesaleToBreakdown, $availableWholesale);
        $retailToAddForBatch = $wholesaleToBreakdown * $packsPerBox;

        $updateBatchStmt = $pdo->prepare(
            "UPDATE product_batches
             SET wholesaleQty = wholesaleQty - :wholesaleQty,
                 retailQty = retailQty + :retailQty
             WHERE id = :id"
        );
        $updateBatchStmt->execute([
            ':wholesaleQty' => $wholesaleToBreakdown,
            ':retailQty' => $retailToAddForBatch,
            ':id' => $batch['id'],
        ]);

        $remainingWholesaleToBreakdown -= $wholesaleToBreakdown;
        $affectedBatchIds[] = $batch['id'];
    }

    if ($remainingWholesaleToBreakdown > 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient batch stock to complete breakdown']);
        exit;
    }

    $activityId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO activity_logs (id, userId, userName, action, details) VALUES (?, ?, ?, 'breakdown', ?)");
    $stmt->execute([
        $activityId,
        $userId,
        $userName,
        "Broke down {$wholesaleQuantity} wholesale unit(s) into {$retailQtyToAdd} retail unit(s) for product {$productId}",
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock breakdown completed',
        'movementId' => $movementId,
        'retailQtyAdded' => $retailQtyToAdd,
        'affectedBatchIds' => $affectedBatchIds,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
}
?>
