<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload. items array is required.']);
    exit;
}

$required = ['subtotal', 'discount', 'total', 'paymentType', 'cashierId'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Invalid payload: {$field} is required."]);
        exit;
    }
}

$items = $input['items'];
$subtotal = (float)$input['subtotal'];
$discount = (float)$input['discount'];
$total = (float)$input['total'];
$paymentType = trim($input['paymentType']);
$cashierId = trim($input['cashierId']);
$status = isset($input['status']) ? trim($input['status']) : 'completed';
$invoiceNo = isset($input['invoiceNo']) ? trim($input['invoiceNo']) : 'INV-' . str_pad((string)rand(100000, 999999), 6, '0', STR_PAD_LEFT);

if (!in_array($paymentType, ['cash', 'gcash'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid paymentType.']);
    exit;
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $transactionId = bin2hex(random_bytes(8));
    $stmt = $pdo->prepare('INSERT INTO transactions (id, invoiceNo, subtotal, discount, total, paymentType, cashierId, status, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $transactionId,
        $invoiceNo,
        $subtotal,
        $discount,
        $total,
        $paymentType,
        $cashierId,
        $status,
    ]);

    foreach ($items as $item) {
        $itemId = bin2hex(random_bytes(8));
        $itemStmt = $pdo->prepare('INSERT INTO transaction_items (id, transactionId, productId, productName, variantId, variantName, quantity, unitPrice, discount, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $itemStmt->execute([
            $itemId,
            $transactionId,
            trim($item['productId']),
            trim($item['productName']),
            isset($item['variantId']) ? trim($item['variantId']) : null,
            isset($item['variantName']) ? trim($item['variantName']) : null,
            (int)$item['quantity'],
            (float)$item['unitPrice'],
            isset($item['discount']) ? (float)$item['discount'] : 0,
            (float)$item['subtotal'],
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'transaction' => [
            'id' => $transactionId,
            'invoiceNo' => $invoiceNo,
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'paymentType' => $paymentType,
            'cashierId' => $cashierId,
            'status' => $status,
            'createdAt' => date('Y-m-d H:i:s'),
        ]
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create transaction: ' . $e->getMessage()]);
}
