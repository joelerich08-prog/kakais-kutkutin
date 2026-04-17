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

$name = trim((string) ($data['name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');
$role = (string) ($data['role'] ?? 'cashier');

$validRoles = ['admin', 'stockman', 'cashier'];
if ($name === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Name, email, and password are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid email address is required']);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 8 characters']);
    exit;
}

if (!in_array($role, $validRoles, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user role']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'A user with this email already exists']);
        exit;
    }

    $userId = bin2hex(random_bytes(16));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $createdAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO users (id, email, name, role, password_hash, isActive, createdAt) VALUES (?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([$userId, $email, $name, $role, $passwordHash, $createdAt]);

    echo json_encode(['success' => true, 'id' => $userId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
