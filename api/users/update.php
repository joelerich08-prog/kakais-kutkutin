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
$name = trim((string) ($data['name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$role = (string) ($data['role'] ?? 'cashier');

$validRoles = ['admin', 'stockman', 'cashier'];
if ($userId === '' || $name === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'User, name, and email are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid email address is required']);
    exit;
}

if (!in_array($role, $validRoles, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user role']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'A user with this email already exists']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?');
    $stmt->execute([$name, $email, $role, $userId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
