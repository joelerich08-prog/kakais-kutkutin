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
            description,
            parentId,
            isActive
        FROM categories
        WHERE isActive = 1
        ORDER BY name ASC
    ");

    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($categories as &$category) {
        $category['isActive'] = (bool) $category['isActive'];
    }

    echo json_encode($categories);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
