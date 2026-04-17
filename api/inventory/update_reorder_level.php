<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../auth/check_permissions.php';

session_start();

requirePermission('inventory', 'edit');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$productId = trim((string) ($data['productId'] ?? ''));
$variantId = isset($data['variantId']) ? trim((string) $data['variantId']) : null;
$rawWholesaleReorderLevel = array_key_exists('wholesaleReorderLevel', $data) ? $data['wholesaleReorderLevel'] : null;
$rawRetailRestockLevel = array_key_exists('retailRestockLevel', $data) ? $data['retailRestockLevel'] : null;
$rawShelfRestockLevel = array_key_exists('shelfRestockLevel', $data) ? $data['shelfRestockLevel'] : null;

$wholesaleReorderLevel = filter_var($rawWholesaleReorderLevel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$retailRestockLevel = filter_var($rawRetailRestockLevel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$shelfRestockLevel = filter_var($rawShelfRestockLevel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

if (
    $productId === '' ||
    $rawWholesaleReorderLevel === null ||
    $rawRetailRestockLevel === null ||
    $rawShelfRestockLevel === null ||
    $wholesaleReorderLevel === false ||
    $retailRestockLevel === false ||
    $shelfRestockLevel === false
) {
    http_response_code(400);
    echo json_encode(['error' => 'Product ID and valid wholesale, retail, and shelf restock levels are required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);

    $sql = 'UPDATE inventory_levels SET wholesaleReorderLevel = ?, retailRestockLevel = ?, shelfRestockLevel = ?, updatedAt = NOW() WHERE productId = ? AND ' .
        ($variantId === null || $variantId === '' ? 'variantId IS NULL' : 'variantId = ?');
    $params = [$wholesaleReorderLevel, $retailRestockLevel, $shelfRestockLevel, $productId];
    if ($variantId !== null && $variantId !== '') {
        $params[] = $variantId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $updatedInventory = fetchInventoryLevel($pdo, $productId, $variantId, false);
    $pdo->commit();

    echo json_encode(['success' => true, 'inventory' => $updatedInventory ?: []]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
