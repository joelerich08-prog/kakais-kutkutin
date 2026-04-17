<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized - Admin access required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT
            id,
            email,
            name,
            role,
            avatar,
            NULL as phone,
            isActive,
            CASE WHEN isActive = 1 THEN 'active' ELSE 'inactive' END as status,
            createdAt,
            lastLogin
        FROM users
        ORDER BY name ASC
    ");

    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user['isActive'] = (bool) $user['isActive'];
        $user['createdAt'] = date('c', strtotime($user['createdAt']));
        if ($user['lastLogin']) {
            $user['lastLogin'] = date('c', strtotime($user['lastLogin']));
        }
    }

    echo json_encode($users);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
