<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/cors.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id, type, priority, title, message, productId, isRead, createdAt FROM alerts ORDER BY createdAt DESC');
    $stmt->execute();
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($alerts);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load alerts: ' . $e->getMessage()]);
}
