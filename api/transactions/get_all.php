<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = Database::getInstance();

try {
    // Get all transactions with their items
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
                    'discount', 0,
                    'subtotal', ti.subtotal,
                    'total', ti.subtotal
                ) SEPARATOR '|'
            ) as itemsJson
        FROM transactions t
        LEFT JOIN transaction_items ti ON t.id = ti.transactionId
        GROUP BY t.id
        ORDER BY t.createdAt DESC
        LIMIT 1000
    ");
    $stmt->execute();
    $transactions = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
        
        $transactions[] = [
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
        ];
    }

    echo json_encode($transactions);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch transactions: ' . $e->getMessage()]);
}
