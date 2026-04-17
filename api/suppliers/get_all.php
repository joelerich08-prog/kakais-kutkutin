<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            contactPerson,
            phone,
            email,
            address,
            isActive
        FROM suppliers
        WHERE isActive = 1
        ORDER BY name ASC
    ");

    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($suppliers as &$supplier) {
        $supplier['isActive'] = (bool) $supplier['isActive'];
    }

    echo json_encode($suppliers);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
