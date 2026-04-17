<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['email']) || !isset($data['password']) || !isset($data['name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email, password, and name are required']);
    exit;
}

$email = trim($data['email']);
$password = $data['password'];
$name = trim($data['name']);
$role = isset($data['role']) && in_array($data['role'], ['admin', 'stockman', 'cashier'], true) ? $data['role'] : 'cashier';

if (empty($email) || empty($password) || empty($name)) {
    http_response_code(400);
    echo json_encode(['error' => 'All fields are required']);
    exit;
}

$pdo = Database::getInstance();

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already registered']);
        exit;
    }

    session_regenerate_id(true);
    $userId = bin2hex(random_bytes(16));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $createdAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO users (id, email, name, role, password_hash, isActive, createdAt) VALUES (?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([$userId, $email, $name, $role, $passwordHash, $createdAt]);

    $permissions = [];
    if ($role === 'customer') {
        $permissions = [
            'dashboard' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'pos' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'inventory' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'products' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
            'suppliers' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'reports' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'users' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'settings' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        ];
    } else {
        $stmt = $pdo->prepare('SELECT module, action, allowed FROM role_permissions WHERE role = ? ORDER BY module, action');
        $stmt->execute([$role]);
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
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['permissions'] = $permissions;

    $response = [
        'id' => $userId,
        'email' => $email,
        'name' => $name,
        'role' => $role,
        'avatar' => null,
        'isActive' => true,
        'createdAt' => $createdAt,
        'lastLogin' => null,
        'permissions' => $permissions,
    ];

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
}
?>
