<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$email = trim($data['email']);
$password = $data['password'];

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

$pdo = Database::getInstance();

// Fetch user
$stmt = $pdo->prepare("SELECT id, email, name, role, password_hash, avatar, isActive, createdAt, lastLogin FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

if (!$user['isActive']) {
    http_response_code(403);
    echo json_encode(['error' => 'Account is inactive']);
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// Fetch permissions
$permissions = [];
$stmt = $pdo->prepare("SELECT module, action, allowed FROM role_permissions WHERE role = ? ORDER BY module, action");
$stmt->execute([$user['role']]);
$perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($perms as $perm) {
    $module = $perm['module'];
    $action = $perm['action'];
    $permissions[$module] = $permissions[$module] ?? [
        'view' => false,
        'create' => false,
        'edit' => false,
        'delete' => false,
    ];
    $permissions[$module][$action] = (bool)$perm['allowed'];
}

// Store session securely
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['permissions'] = $permissions;

// Update lastLogin
$stmt = $pdo->prepare("UPDATE users SET lastLogin = NOW() WHERE id = ?");
$stmt->execute([$user['id']]);

// Log successful login activity
$activityId = bin2hex(random_bytes(16));
$stmt = $pdo->prepare("INSERT INTO activity_logs (id, userId, userName, action, details) VALUES (?, ?, ?, 'login', ?)");
$stmt->execute([
    $activityId,
    $user['id'],
    $user['name'],
    "User logged in from IP address: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')
]);

$response = [
    'id' => $user['id'],
    'email' => $user['email'],
    'name' => $user['name'],
    'role' => $user['role'],
    'avatar' => $user['avatar'],
    'isActive' => (bool)$user['isActive'],
    'createdAt' => $user['createdAt'],
    'lastLogin' => $user['lastLogin'],
    'permissions' => $permissions,
];

echo json_encode($response);
?>
