<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

// Log logout activity before clearing session
if (isset($_SESSION['user_id']) && isset($_SESSION['user_name'])) {
    $pdo = Database::getInstance();
    $activityId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO activity_logs (id, userId, userName, action, details) VALUES (?, ?, ?, 'logout', ?)");
    $stmt->execute([
        $activityId,
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        "User logged out from IP address: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')
    ]);
}

// Clear session data and remove the session cookie.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

echo json_encode(['success' => true]);
?>