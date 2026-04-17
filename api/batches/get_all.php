<?php
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
    $stmt = $pdo->prepare('SELECT * FROM product_batches ORDER BY expirationDate ASC');
    $stmt->execute();
    $batches = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batches[] = [
            'id' => $row['id'],
            'productId' => $row['productId'],
            'variantId' => $row['variantId'],
            'batchNumber' => $row['batchNumber'],
            'expirationDate' => $row['expirationDate'],
            'manufacturingDate' => $row['manufacturingDate'],
            'receivedDate' => $row['receivedDate'],
            'wholesaleQty' => (int)$row['wholesaleQty'],
            'retailQty' => (int)$row['retailQty'],
            'shelfQty' => (int)$row['shelfQty'],
            'initialQty' => (int)$row['initialQty'],
            'costPrice' => (float)$row['costPrice'],
            'supplierId' => $row['supplierId'],
            'invoiceNumber' => $row['invoiceNumber'],
            'status' => $row['status'],
            'notes' => $row['notes'],
        ];
    }

    echo json_encode($batches);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch batches: ' . $e->getMessage()]);
}
