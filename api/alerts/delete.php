<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/cors.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'No alert IDs provided']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM alerts WHERE id IN ($placeholders)");
    $stmt->execute($ids);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete alerts: ' . $e->getMessage()]);
}
