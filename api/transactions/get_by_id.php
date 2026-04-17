<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get transaction ID from query string
$transactionId = isset($_GET['id']) ? trim($_GET['id']) : null;

if (!$transactionId) {
    http_response_code(400);
    echo json_encode(['error' => 'Transaction ID is required']);
    exit;
}

$pdo = Database::getInstance();

try {
    // Get transaction with items
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            GROUP_CONCAT(
                JSON_OBJECT(
                    'id', ti.id,
                    'productId', ti.productId,
                    'productName', ti.productName,
                    'variantId', ti.variantId,
                    'variantName', ti.variantName,
                    'quantity', ti.quantity,
                    'unitPrice', ti.unitPrice,
                    'discount', ti.discount,
                    'total', ti.total
                ) SEPARATOR '|'
            ) as itemsJson
        FROM transactions t
        LEFT JOIN transaction_items ti ON t.id = ti.transactionId
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$transactionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
        exit;
    }

    $items = [];
    if ($row['itemsJson']) {
        $itemsJsonArray = explode('|', $row['itemsJson']);
        foreach ($itemsJsonArray as $itemJson) {
            $item = json_decode($itemJson, true);
            if ($item) {
                $items[] = $item;
            }
        }
    }

    echo json_encode([
        'id' => $row['id'],
        'invoiceNo' => $row['invoiceNo'],
        'items' => $items,
        'subtotal' => (float)$row['subtotal'],
        'discount' => (float)$row['discount'],
        'total' => (float)$row['total'],
        'paymentType' => $row['paymentType'],
        'cashierId' => $row['cashierId'],
        'status' => $row['status'] ?? 'completed',
        'createdAt' => $row['createdAt']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch transaction: ' . $e->getMessage()]);
}
