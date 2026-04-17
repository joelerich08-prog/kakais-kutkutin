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

// Validate input
if (!$data || !isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: items array is required']);
    exit;
}

if (!isset($data['customerName']) || !isset($data['customerPhone']) || !isset($data['total']) || (!isset($data['orderSource']) && !isset($data['source']))) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: customerName, customerPhone, total, and source are required']);
    exit;
}

$customerName = trim($data['customerName']);
$customerPhone = trim($data['customerPhone']);
$total = (float)$data['total'];
$orderSource = trim((string) ($data['orderSource'] ?? $data['source']));
$notes = isset($data['notes']) ? trim($data['notes']) : null;
$items = $data['items'];

// Validate
if (strlen($customerName) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Customer name must be at least 2 characters']);
    exit;
}

if ($total <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Order total must be greater than 0']);
    exit;
}

if (!in_array($orderSource, ['facebook', 'sms', 'website'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid orderSource: must be facebook, sms, or website']);
    exit;
}

// Validate items
foreach ($items as $item) {
    if (!isset($item['productId']) || !isset($item['quantity']) || !isset($item['unitPrice'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item: productId, quantity, and unitPrice are required']);
        exit;
    }
    if ($item['quantity'] <= 0 || $item['unitPrice'] <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item: quantity and unitPrice must be positive']);
        exit;
    }
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    // Create order
    $orderId = bin2hex(random_bytes(16));
    $orderNo = 'ORD-' . date('ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO orders (id, orderNo, source, customerName, customerPhone, total, status, notes, createdAt)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $stmt->execute([
        $orderId,
        $orderNo,
        $orderSource,
        $customerName,
        $customerPhone,
        $total,
        $notes
    ]);

    // Create order items
    foreach ($items as $item) {
        $itemId = bin2hex(random_bytes(16));
        $variantId = isset($item['variantId']) && trim((string) $item['variantId']) !== '' ? trim((string) $item['variantId']) : null;
        $variantName = isset($item['variantName']) && trim((string) $item['variantName']) !== '' ? trim((string) $item['variantName']) : null;
        $itemStmt = $pdo->prepare("
            INSERT INTO order_items (id, orderId, productId, variantId, productName, variantName, quantity, unitPrice)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $itemStmt->execute([
            $itemId,
            $orderId,
            trim($item['productId']),
            $variantId,
            isset($item['productName']) ? trim((string) $item['productName']) : '',
            $variantName,
            (int)$item['quantity'],
            (float)$item['unitPrice'],
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'id' => $orderId,
        'orderNo' => $orderNo,
        'source' => $orderSource,
        'customerName' => $customerName,
        'customerPhone' => $customerPhone,
        'items' => $items,
        'total' => $total,
        'status' => 'pending',
        'notes' => $notes,
        'createdAt' => date('c')
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create order: ' . $e->getMessage()]);
}
