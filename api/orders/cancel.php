<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['orderId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: orderId is required']);
    exit;
}

$orderId = trim($data['orderId']);

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    // Get order with row locking
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    if ($order['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['error' => 'Order cannot be cancelled because it is already "' . $order['status'] . '"']);
        exit;
    }

    // Update order status
    $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled', updatedAt = NOW() WHERE id = ?");
    $stmt->execute([$orderId]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (id, userId, action, module, description, details, createdAt)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        bin2hex(random_bytes(8)),
        $userId,
        'cancel_order',
        'orders',
        'Cancelled order ' . $order['orderNo'],
        'Customer: ' . $order['customerName'],
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'status' => 'cancelled'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to cancel order: ' . $e->getMessage()]);
}
