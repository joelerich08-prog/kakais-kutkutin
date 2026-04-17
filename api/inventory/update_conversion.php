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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$productId = isset($payload['productId']) ? trim((string) $payload['productId']) : '';
$variantId = normalizeInventoryVariantId($payload['variantId'] ?? null);
$pcsPerPack = isset($payload['pcsPerPack']) ? (int) $payload['pcsPerPack'] : 0;
$packsPerBox = isset($payload['packsPerBox']) ? (int) $payload['packsPerBox'] : 0;

if ($productId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Product ID is required']);
    exit;
}

if ($pcsPerPack <= 0 || $packsPerBox <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Conversion values must be greater than zero']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);

    $stmt = $pdo->prepare(
        'UPDATE inventory_levels
         SET pcsPerPack = :pcsPerPack,
             packsPerBox = :packsPerBox,
             updatedAt = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        ':pcsPerPack' => $pcsPerPack,
        ':packsPerBox' => $packsPerBox,
        ':id' => $inventory['id'],
    ]);

    $updatedInventory = fetchInventoryLevel($pdo, $productId, $variantId, false);
    echo json_encode($updatedInventory ?: []);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update conversion values', 'details' => $e->getMessage()]);
}
