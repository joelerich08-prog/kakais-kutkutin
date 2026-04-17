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

// Validate input
if (!$data || !isset($data['productId']) || !isset($data['tier']) || !isset($data['quantityChange']) || !isset($data['reason'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: productId, tier, quantityChange, and reason are required']);
    exit;
}

$productId = trim($data['productId']);
$tier = trim($data['tier']);
$quantityChange = (int)$data['quantityChange'];
$reason = trim($data['reason']);
$notes = isset($data['notes']) ? trim($data['notes']) : '';
$variantId = normalizeInventoryVariantId($data['variantId'] ?? null);

// Validate tier
if (!in_array($tier, ['wholesale', 'retail', 'shelf'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid tier: must be wholesale, retail, or shelf']);
    exit;
}

if ($quantityChange === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: quantityChange cannot be zero']);
    exit;
}

if (empty($reason)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: reason is required']);
    exit;
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userRow['name'] ?? 'Unknown';

    // Get inventory with row locking
    $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);
    $inventoryId = $inventory['id'];

    // Get current stock for the tier
    $currentStock = 0;
    if ($tier === 'wholesale') {
        $currentStock = $inventory['wholesaleQty'];
    } elseif ($tier === 'retail') {
        $currentStock = $inventory['retailQty'];
    } else {
        $currentStock = $inventory['shelfQty'];
    }

    // Check if we have enough stock for removal
    if ($quantityChange < 0 && abs($quantityChange) > $currentStock) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient stock: cannot remove ' . abs($quantityChange) . ' units, only ' . $currentStock . ' available']);
        exit;
    }

    // Calculate new quantity
    $newQty = $currentStock + $quantityChange;

    // Update inventory based on tier
    if ($tier === 'wholesale') {
        $stmt = $pdo->prepare("UPDATE inventory_levels SET wholesaleQty = ?, updatedAt = NOW() WHERE id = ?");
    } elseif ($tier === 'retail') {
        $stmt = $pdo->prepare("UPDATE inventory_levels SET retailQty = ?, updatedAt = NOW() WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE inventory_levels SET shelfQty = ?, updatedAt = NOW() WHERE id = ?");
    }
    $stmt->execute([$newQty, $inventoryId]);

    // Check for low stock alerts after adjustment
    $stmt = $pdo->prepare("SELECT wholesaleQty, retailQty, shelfQty, wholesaleReorderLevel, retailRestockLevel, shelfRestockLevel FROM inventory_levels WHERE id = ?");
    $stmt->execute([$inventoryId]);
    $updatedInventory = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($updatedInventory) {
        // Check shelf stock alert
        if ($updatedInventory['shelfQty'] <= $updatedInventory['shelfRestockLevel']) {
            $alertId = bin2hex(random_bytes(16));
            $message = "Shelf stock level adjusted below threshold for product {$productId}: {$updatedInventory['shelfQty']} remaining (threshold: {$updatedInventory['shelfRestockLevel']}).";
            $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_shelf', 'high', 'Low Shelf Stock Alert', ?, ?)");
            $stmt->execute([$alertId, $message, $productId]);
        }

        // Check retail stock alert
        if ($updatedInventory['retailQty'] <= $updatedInventory['retailRestockLevel']) {
            $alertId = bin2hex(random_bytes(16));
            $message = "Retail stock level adjusted below threshold for product {$productId}: {$updatedInventory['retailQty']} remaining (threshold: {$updatedInventory['retailRestockLevel']}).";
            $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_retail', 'medium', 'Low Retail Stock Alert', ?, ?)");
            $stmt->execute([$alertId, $message, $productId]);
        }

        // Check wholesale stock alert - only if reorder level is set (> 0)
        if ($updatedInventory['wholesaleReorderLevel'] > 0 && $updatedInventory['wholesaleQty'] <= $updatedInventory['wholesaleReorderLevel']) {
            $alertId = bin2hex(random_bytes(16));
            $message = "Wholesale stock level adjusted below threshold for product {$productId}: {$updatedInventory['wholesaleQty']} remaining (threshold: {$updatedInventory['wholesaleReorderLevel']}).";
            $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_wholesale', 'medium', 'Low Wholesale Stock Alert', ?, ?)");
            $stmt->execute([$alertId, $message, $productId]);
        }
    }

    // Record stock movement. Signed quantity preserves whether stock was added or removed.
    insertStockMovement($pdo, [
        'id' => bin2hex(random_bytes(8)),
        'productId' => $productId,
        'variantId' => $variantId,
        'movementType' => 'adjustment',
        'fromTier' => $tier,
        'toTier' => null,
        'quantity' => $quantityChange,
        'reason' => $reason,
        'notes' => $notes,
        'performedBy' => $userId,
    ]);

    // Log activity
    $action = $quantityChange > 0 ? 'Added' : 'Removed';
    $tierNames = ['wholesale' => 'Wholesale', 'retail' => 'Retail', 'shelf' => 'Store Shelf'];
    $tierName = $tierNames[$tier];

    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (id, userId, userName, action, details, createdAt)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        bin2hex(random_bytes(8)),
        $userId,
        $userName,
        'adjust_stock',
        $action . ' ' . abs($quantityChange) . ' unit(s) ' . ($quantityChange > 0 ? 'to' : 'from') . ' ' . $tierName .
            ' | Product ID: ' . $productId . ' | Reason: ' . $reason . ' | Notes: ' . $notes
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock adjusted successfully',
        'productId' => $productId,
        'tier' => $tier,
        'previousQuantity' => $currentStock,
        'newQuantity' => $newQty,
        'change' => $quantityChange
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to adjust stock: ' . $e->getMessage()]);
}
