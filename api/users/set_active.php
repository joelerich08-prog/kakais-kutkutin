<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized - Admin access required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$userId = trim((string) ($data['id'] ?? ''));
$isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : null;

if ($userId === '' || $isActive === null) {
    http_response_code(400);
    echo json_encode(['error' => 'User id and status are required']);
    exit;
}

if ($userId === ($_SESSION['user_id'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'You cannot deactivate your own account']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('UPDATE users SET isActive = ? WHERE id = ?');
    $stmt->execute([$isActive ? 1 : 0, $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
