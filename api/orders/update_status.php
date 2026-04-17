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
if (!$data || !isset($data['orderId']) || !isset($data['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: orderId and status are required']);
    exit;
}

$orderId = trim($data['orderId']);
$status = trim($data['status']);
$validStatuses = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];

if ($orderId === '' || !in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid orderId or status']);
    exit;
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, source, status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    $previousStatus = $order['status'];
    if ($previousStatus === $status) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Order is already in the requested status']);
        exit;
    }

    $allowedTransitions = [
        'pending' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    if (!in_array($status, $allowedTransitions[$previousStatus] ?? [], true)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => "Cannot change order status from {$previousStatus} to {$status}"]);
        exit;
    }

    $sourceTierMap = [
        'facebook' => 'shelf',
        'sms' => 'shelf',
        'website' => 'shelf',
    ];

    $orderSource = $order['source'];
    $inventoryTier = $sourceTierMap[$orderSource] ?? 'shelf';
    $shouldAdjustStock = in_array($status, ['ready', 'completed'], true);
    $alreadyDeducted = in_array($previousStatus, ['ready', 'completed'], true);
    $shouldRestoreStock = $status === 'cancelled' && $alreadyDeducted;

    if ($shouldAdjustStock && !$alreadyDeducted) {
        $stmt = $pdo->prepare('SELECT id, productId, variantId, quantity FROM order_items WHERE orderId = ?');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Order contains no items to fulfill']);
            exit;
        }

        foreach ($items as $item) {
            $variantId = normalizeInventoryVariantId($item['variantId'] ?? null);
            $inventory = getOrCreateInventoryLevel($pdo, $item['productId'], $variantId, true);

            if ($inventory["{$inventoryTier}Qty"] < $item['quantity']) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Insufficient stock in ' . $inventoryTier . ' tier for product ' . $item['productId']]);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE inventory_levels SET {$inventoryTier}Qty = {$inventoryTier}Qty - :quantity WHERE id = :id");
            $stmt->execute([
                ':quantity' => $item['quantity'],
                ':id' => $inventory['id'],
            ]);

            $batchAllocations = moveBatchStockFEFO(
                $pdo,
                $item['productId'],
                $variantId,
                $inventoryTier,
                null,
                (int) $item['quantity']
            );

            $movementId = bin2hex(random_bytes(16));
            insertStockMovement($pdo, [
                'id' => $movementId,
                'productId' => $item['productId'],
                'variantId' => $variantId,
                'movementType' => 'sale',
                'fromTier' => $inventoryTier,
                'toTier' => null,
                'quantity' => $item['quantity'],
                'reason' => 'Order fulfillment',
                'notes' => buildBatchAllocationMovementNotes('order_item', (string) $item['id'], $batchAllocations),
                'performedBy' => $userId,
            ]);

            $stmt = $pdo->prepare("SELECT wholesaleQty, retailQty, shelfQty, wholesaleReorderLevel, retailRestockLevel, shelfRestockLevel FROM inventory_levels WHERE id = :id");
            $stmt->execute([':id' => $inventory['id']]);
            $updatedInventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($updatedInventory) {
                // Check shelf stock alert
                if ($updatedInventory['shelfQty'] <= $updatedInventory['shelfRestockLevel']) {
                    $alertId = bin2hex(random_bytes(16));
                    $message = "Shelf stock level for ordered item {$item['productId']} is low: {$updatedInventory['shelfQty']} remaining (threshold: {$updatedInventory['shelfRestockLevel']}).";
                    $stmt = $pdo->prepare('INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, "low_shelf", "high", "Low Shelf Stock Alert", ?, ?)');
                    $stmt->execute([$alertId, $message, $item['productId']]);
                }

                // Check retail stock alert
                if ($updatedInventory['retailQty'] <= $updatedInventory['retailRestockLevel']) {
                    $alertId = bin2hex(random_bytes(16));
                    $message = "Retail stock level for ordered item {$item['productId']} is low: {$updatedInventory['retailQty']} remaining (threshold: {$updatedInventory['retailRestockLevel']}).";
                    $stmt = $pdo->prepare('INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, "low_retail", "medium", "Low Retail Stock Alert", ?, ?)');
                    $stmt->execute([$alertId, $message, $item['productId']]);
                }

                // Check wholesale stock alert - only if reorder level is set (> 0)
                if ($updatedInventory['wholesaleReorderLevel'] > 0 && $updatedInventory['wholesaleQty'] <= $updatedInventory['wholesaleReorderLevel']) {
                    $alertId = bin2hex(random_bytes(16));
                    $message = "Wholesale stock level for ordered item {$item['productId']} is low: {$updatedInventory['wholesaleQty']} remaining (threshold: {$updatedInventory['wholesaleReorderLevel']}).";
                    $stmt = $pdo->prepare('INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, "low_wholesale", "medium", "Low Wholesale Stock Alert", ?, ?)');
                    $stmt->execute([$alertId, $message, $item['productId']]);
                }
            }
        }
    }

    if ($shouldRestoreStock) {
        $stmt = $pdo->prepare('SELECT id, productId, variantId, quantity FROM order_items WHERE orderId = ?');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $variantId = normalizeInventoryVariantId($item['variantId'] ?? null);
            $inventory = getOrCreateInventoryLevel($pdo, $item['productId'], $variantId, true);

            $stmt = $pdo->prepare("UPDATE inventory_levels SET {$inventoryTier}Qty = {$inventoryTier}Qty + :quantity WHERE id = :id");
            $stmt->execute([
                ':quantity' => $item['quantity'],
                ':id' => $inventory['id'],
            ]);

            $recordedAllocations = fetchRecordedBatchAllocations(
                $pdo,
                'sale',
                'order_item',
                (string) $item['id'],
                (string) $item['productId'],
                $variantId
            );

            restoreBatchStock(
                $pdo,
                (string) $item['productId'],
                $variantId,
                $inventoryTier,
                (int) $item['quantity'],
                $recordedAllocations
            );
        }
    }

    $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $stmt->execute([$status, $orderId]);

    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $user['name'] ?? 'Unknown';

    $activityId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO activity_logs (id, userId, userName, action, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $activityId,
        $userId,
        $userName,
        'order_status_update',
        "Order {$orderId} status changed from {$previousStatus} to {$status} (source: {$orderSource})",
    ]);

    $pdo->commit();

    echo json_encode(['success' => true, 'orderId' => $orderId, 'status' => $status]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Status update failed: ' . $e->getMessage()]);
}
?>
