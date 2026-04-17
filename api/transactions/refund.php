<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/transaction-validation.php';
require_once __DIR__ . '/../auth/check_permissions.php';

session_start();

requirePermission('pos', 'edit');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['transactionId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'transactionId is required']);
    exit;
}

// Validate refund request
$validationErrors = validateRefundRequest($input);
if (!empty($validationErrors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'details' => $validationErrors]);
    exit;
}

$transactionId = trim((string) $input['transactionId']);
$reason = isset($input['reason']) ? trim((string) $input['reason']) : '';

if ($transactionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'transactionId is required']);
    exit;
}

function detectInventoryTier(array $item, array $inventory, float $baseUnitPrice): ?string
{
    $itemUnitPrice = (float) $item['unitPrice'];
    $pcsPerPack = max(1, (int) ($inventory['pcsPerPack'] ?? 1));
    $packsPerBox = max(1, (int) ($inventory['packsPerBox'] ?? 1));

    $matchesPrice = static function (float $left, float $right): bool {
        return abs($left - $right) < 0.01;
    };

    if ($matchesPrice($itemUnitPrice, $baseUnitPrice)) {
        return 'shelf';
    }

    if ($pcsPerPack > 0 && $matchesPrice($itemUnitPrice, $baseUnitPrice * $pcsPerPack)) {
        return 'retail';
    }

    if ($pcsPerPack > 0 && $packsPerBox > 0 && $matchesPrice($itemUnitPrice, $baseUnitPrice * $pcsPerPack * $packsPerBox)) {
        return 'wholesale';
    }

    $productName = strtolower((string) ($item['productName'] ?? ''));
    $wholesaleUnit = strtolower((string) ($inventory['wholesaleUnit'] ?? ''));
    $retailUnit = strtolower((string) ($inventory['retailUnit'] ?? ''));
    $shelfUnit = strtolower((string) ($inventory['shelfUnit'] ?? ''));

    if ($wholesaleUnit !== '' && str_contains($productName, '(' . $wholesaleUnit . ')')) {
        return 'wholesale';
    }

    if ($retailUnit !== '' && str_contains($productName, '(' . $retailUnit . ')')) {
        return 'retail';
    }

    if ($shelfUnit !== '' && str_contains($productName, '(' . $shelfUnit . ')')) {
        return 'shelf';
    }

    return null;
}

$userId = $_SESSION['user_id'];
$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, invoiceNo, status FROM transactions WHERE id = ? FOR UPDATE');
    $stmt->execute([$transactionId]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
        exit;
    }

    if (($transaction['status'] ?? 'completed') !== 'completed') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Only completed transactions can be refunded']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT ti.id, ti.productId, ti.variantId, ti.productName, ti.variantName, ti.quantity, ti.unitPrice,
               p.retailPrice,
               pv.priceAdjustment
        FROM transaction_items ti
        LEFT JOIN products p ON p.id = ti.productId
        LEFT JOIN product_variants pv ON pv.id = ti.variantId
        WHERE ti.transactionId = ?
        FOR UPDATE
    ');
    $stmt->execute([$transactionId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Transaction has no refundable items']);
        exit;
    }

    foreach ($items as $item) {
        $productId = (string) $item['productId'];
        $variantId = normalizeVariantId($item['variantId'] ?? null);
        $quantity = (int) $item['quantity'];

        if ($quantity <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid refunded quantity detected']);
            exit;
        }

        $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);
        $baseUnitPrice = (float) ($item['retailPrice'] ?? 0) + (float) ($item['priceAdjustment'] ?? 0);

        if ($baseUnitPrice <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Unable to determine the original selling unit for one of the refunded items']);
            exit;
        }

        $tier = detectInventoryTier($item, $inventory, $baseUnitPrice);
        if ($tier === null) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Unable to restore stock because one refunded item has an unknown inventory tier']);
            exit;
        }

        $quantityColumn = $tier . 'Qty';
        $stmt = $pdo->prepare("UPDATE inventory_levels SET {$quantityColumn} = {$quantityColumn} + :quantity WHERE id = :id");
        $stmt->execute([
            ':quantity' => $quantity,
            ':id' => $inventory['id'],
        ]);

        $recordedAllocations = fetchRecordedBatchAllocations(
            $pdo,
            'sale',
            'transaction_item',
            (string) $item['id'],
            $productId,
            $variantId
        );

        $restoredAllocations = restoreBatchStock(
            $pdo,
            $productId,
            $variantId,
            $tier,
            $quantity,
            $recordedAllocations
        );

        $movementId = bin2hex(random_bytes(16));
        insertStockMovement($pdo, [
            'id' => $movementId,
            'productId' => $productId,
            'variantId' => $variantId,
            'movementType' => 'return',
            'fromTier' => null,
            'toTier' => $tier,
            'quantity' => $quantity,
            'reason' => $reason !== '' ? $reason : 'Admin refund',
            'notes' => buildBatchAllocationMovementNotes('transaction_item', (string) $item['id'], $restoredAllocations),
            'performedBy' => $userId,
        ]);
    }

    $stmt = $pdo->prepare("UPDATE transactions SET status = 'refunded' WHERE id = ?");
    $stmt->execute([$transactionId]);

    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $user['name'] ?? 'Unknown';

    $activityId = bin2hex(random_bytes(16));
    $details = 'Refunded transaction ' . $transaction['invoiceNo'];
    if ($reason !== '') {
        $details .= ' - ' . $reason;
    }

    $stmt = $pdo->prepare('INSERT INTO activity_logs (id, userId, userName, action, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $activityId,
        $userId,
        $userName,
        'transaction_refund',
        $details,
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'transactionId' => $transactionId,
        'status' => 'refunded',
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['error' => 'Refund failed: ' . $e->getMessage()]);
}
?>
