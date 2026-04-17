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
if (!$data || !isset($data['productId']) || !isset($data['sourceTier']) || !isset($data['destTier']) || !isset($data['quantity'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: productId, sourceTier, destTier, and quantity are required']);
    exit;
}

$productId = trim($data['productId']);
$sourceTier = trim($data['sourceTier']);
$destTier = trim($data['destTier']);
$quantity = (int)$data['quantity'];
$variantId = normalizeInventoryVariantId($data['variantId'] ?? null);

$validTiers = ['wholesale', 'retail', 'shelf'];
if (empty($productId) || $quantity <= 0 || !in_array($sourceTier, $validTiers, true) || !in_array($destTier, $validTiers, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid productId, tier, or quantity']);
    exit;
}

if ($sourceTier === $destTier) {
    http_response_code(400);
    echo json_encode(['error' => 'Source and destination tiers must be different']);
    exit;
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);
    $inventoryId = $inventory['id'];

    $sourceQty = $inventory["{$sourceTier}Qty"];
    if ($sourceQty < $quantity) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient stock in source tier']);
        exit;
    }

    $updated = [
        'wholesaleQty' => $inventory['wholesaleQty'],
        'retailQty' => $inventory['retailQty'],
        'shelfQty' => $inventory['shelfQty'],
    ];

    $updated["{$sourceTier}Qty"] -= $quantity;
    $updated["{$destTier}Qty"] += $quantity;

    $stmt = $pdo->prepare("UPDATE inventory_levels SET wholesaleQty = :wholesaleQty, retailQty = :retailQty, shelfQty = :shelfQty WHERE id = :id");
    $updateParams = [
        ':wholesaleQty' => $updated['wholesaleQty'],
        ':retailQty' => $updated['retailQty'],
        ':shelfQty' => $updated['shelfQty'],
        ':id' => $inventoryId,
    ];
    $stmt->execute($updateParams);

    // Check for low stock alerts after transfer
    $stmt = $pdo->prepare("SELECT wholesaleQty, retailQty, shelfQty, wholesaleReorderLevel, retailRestockLevel, shelfRestockLevel FROM inventory_levels WHERE id = ?");
    $stmt->execute([$inventoryId]);
    $updatedInventory = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($updatedInventory) {
        // Check shelf stock alert
        if ($updatedInventory['shelfQty'] <= $updatedInventory['shelfRestockLevel']) {
            $alertId = bin2hex(random_bytes(16));
            $message = "Shelf stock level fell below threshold after transfer for product {$productId}: {$updatedInventory['shelfQty']} remaining (threshold: {$updatedInventory['shelfRestockLevel']}).";
            $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_shelf', 'high', 'Low Shelf Stock Alert', ?, ?)");
            $stmt->execute([$alertId, $message, $productId]);
        }

        // Check retail stock alert
        if ($updatedInventory['retailQty'] <= $updatedInventory['retailRestockLevel']) {
            $alertId = bin2hex(random_bytes(16));
            $message = "Retail stock level fell below threshold after transfer for product {$productId}: {$updatedInventory['retailQty']} remaining (threshold: {$updatedInventory['retailRestockLevel']}).";
            $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_retail', 'medium', 'Low Retail Stock Alert', ?, ?)");
            $stmt->execute([$alertId, $message, $productId]);
        }

        // Check wholesale stock alert - only if reorder level is set (> 0)
        if ($updatedInventory['wholesaleReorderLevel'] > 0 && $updatedInventory['wholesaleQty'] <= $updatedInventory['wholesaleReorderLevel']) {
            $alertId = bin2hex(random_bytes(16));
            $message = "Wholesale stock level fell below threshold after transfer for product {$productId}: {$updatedInventory['wholesaleQty']} remaining (threshold: {$updatedInventory['wholesaleReorderLevel']}).";
            $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_wholesale', 'medium', 'Low Wholesale Stock Alert', ?, ?)");
            $stmt->execute([$alertId, $message, $productId]);
        }
    }

    $batchSql = "SELECT id, {$sourceTier}Qty
        FROM product_batches
        WHERE productId = :productId
          AND status != 'disposed'
          AND {$sourceTier}Qty > 0";
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

    $remainingToTransfer = $quantity;
    $affectedBatchIds = [];

    foreach ($batches as $batch) {
        if ($remainingToTransfer <= 0) {
            break;
        }

        $availableSourceQty = (int) $batch["{$sourceTier}Qty"];
        if ($availableSourceQty <= 0) {
            continue;
        }

        $quantityToTransfer = min($remainingToTransfer, $availableSourceQty);

        $updateBatchStmt = $pdo->prepare(
            "UPDATE product_batches
             SET {$sourceTier}Qty = {$sourceTier}Qty - :quantity,
                 {$destTier}Qty = {$destTier}Qty + :quantity
             WHERE id = :id"
        );
        $updateBatchStmt->execute([
            ':quantity' => $quantityToTransfer,
            ':id' => $batch['id'],
        ]);

        $remainingToTransfer -= $quantityToTransfer;
        $affectedBatchIds[] = $batch['id'];
    }

    if ($remainingToTransfer > 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient batch stock to complete transfer']);
        exit;
    }

    $movementId = bin2hex(random_bytes(16));
    insertStockMovement($pdo, [
        'id' => $movementId,
        'productId' => $productId,
        'variantId' => $variantId,
        'movementType' => 'transfer',
        'fromTier' => $sourceTier,
        'toTier' => $destTier,
        'quantity' => $quantity,
        'reason' => 'Stock transferred between inventory tiers',
        'notes' => "Transferred {$quantity} unit(s) from {$sourceTier} to {$destTier}",
        'performedBy' => $userId,
    ]);

    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userRow['name'] ?? 'Unknown';

    $activityId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO activity_logs (id, userId, userName, action, details) VALUES (?, ?, ?, 'transfer', ?)");
    $stmt->execute([
        $activityId,
        $userId,
        $userName,
        "Transferred {$quantity} units from {$sourceTier} to {$destTier} for product {$productId}" . ($variantId ? " (variant {$variantId})" : ''),
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock transfer completed',
        'movementId' => $movementId,
        'updatedInventory' => $updated,
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
