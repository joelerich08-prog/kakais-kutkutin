<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    $stmt = $pdo->query(
        'SELECT id, productId, variantId, wholesaleQty, retailQty, shelfQty, wholesaleUnit, retailUnit, shelfUnit, pcsPerPack, packsPerBox, shelfRestockLevel, wholesaleReorderLevel, retailRestockLevel, updatedAt FROM inventory_levels'
    );
    $inventoryLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($inventoryLevels);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch inventory levels', 'details' => $e->getMessage()]);
}
?>