<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id, email, name, role, avatar, isActive, createdAt, lastLogin FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $response = [
        'id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'role' => $user['role'],
        'avatar' => $user['avatar'],
        'isActive' => (bool)$user['isActive'],
        'createdAt' => $user['createdAt'],
        'lastLogin' => $user['lastLogin'],
        'permissions' => isset($_SESSION['permissions']) ? $_SESSION['permissions'] : new stdClass(),
    ];

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to verify session', 'details' => $e->getMessage()]);
}
?>