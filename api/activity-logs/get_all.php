<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

$userRole = $_SESSION['user_role'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$userId || !in_array($userRole, ['admin', 'stockman'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // For stockman, only show their own activity logs
    $whereClause = $userRole === 'stockman' ? "WHERE al.userId = ?" : "";
    $params = $userRole === 'stockman' ? [$userId] : [];
    
    $stmt = $pdo->prepare("
        SELECT
            al.id,
            al.userId,
            al.userName,
            COALESCE(u.role, 'admin') as userRole,
            al.action,
            CASE
                WHEN al.action IN ('receive_stock', 'adjust_stock', 'breakdown', 'transfer') THEN 'inventory'
                WHEN al.action IN ('order_status_update', 'transaction_refund') OR al.action LIKE 'order_%' OR al.action LIKE 'transaction_%' THEN 'orders'
                WHEN al.action IN ('login', 'logout') THEN 'auth'
                WHEN al.action LIKE 'user_%' THEN 'users'
                WHEN al.action LIKE 'settings_%' THEN 'settings'
                ELSE LOWER(SUBSTRING_INDEX(al.action, '_', 1))
            END as module,
            al.details,
            '' as ipAddress,
            al.createdAt as timestamp
        FROM activity_logs al
        LEFT JOIN users u ON al.userId = u.id
        {$whereClause}
        ORDER BY al.createdAt DESC
        LIMIT 1000
    ");
    
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as &$log) {
        $log['timestamp'] = date('c', strtotime($log['timestamp']));
    }

    echo json_encode($logs);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
