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
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload.']);
    exit;
}

$required = ['productId', 'batchNumber', 'expirationDate', 'receivedDate', 'initialQty', 'costPrice', 'supplierId'];
foreach ($required as $field) {
    if (!isset($input[$field]) || $input[$field] === '') {
        http_response_code(400);
        echo json_encode(['error' => "Invalid payload: {$field} is required."]);
        exit;
    }
}

$productId = trim($input['productId']);
$variantId = isset($input['variantId']) ? trim($input['variantId']) : null;
$batchNumber = trim($input['batchNumber']);
$expirationDate = trim($input['expirationDate']);
$manufacturingDate = isset($input['manufacturingDate']) ? trim($input['manufacturingDate']) : null;
$receivedDate = trim($input['receivedDate']);
$wholesaleQty = isset($input['wholesaleQty']) ? (int)$input['wholesaleQty'] : 0;
$retailQty = isset($input['retailQty']) ? (int)$input['retailQty'] : 0;
$shelfQty = isset($input['shelfQty']) ? (int)$input['shelfQty'] : 0;
$initialQty = (int)$input['initialQty'];
$costPrice = (float)$input['costPrice'];
$supplierId = trim($input['supplierId']);
$invoiceNumber = isset($input['invoiceNumber']) ? trim($input['invoiceNumber']) : null;
$notes = isset($input['notes']) ? trim($input['notes']) : null;

$status = 'active';
$today = new DateTime();
$expiry = DateTime::createFromFormat('Y-m-d', $expirationDate);
if ($expiry instanceof DateTime) {
    $days = (int)$today->diff($expiry)->format('%r%a');
    if ($days <= 0) {
        $status = 'expired';
    } elseif ($days <= 30) {
        $status = 'expiring_soon';
    }
}

$pdo = Database::getInstance();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO product_batches (id, productId, variantId, batchNumber, expirationDate, manufacturingDate, receivedDate, wholesaleQty, retailQty, shelfQty, initialQty, costPrice, supplierId, invoiceNumber, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $batchId = bin2hex(random_bytes(8));
    $stmt->execute([
        $batchId,
        $productId,
        $variantId,
        $batchNumber,
        $expirationDate,
        $manufacturingDate,
        $receivedDate,
        $wholesaleQty,
        $retailQty,
        $shelfQty,
        $initialQty,
        $costPrice,
        $supplierId,
        $invoiceNumber,
        $status,
        $notes,
    ]);

    echo json_encode([
        'success' => true,
        'batch' => [
            'id' => $batchId,
            'productId' => $productId,
            'variantId' => $variantId,
            'batchNumber' => $batchNumber,
            'expirationDate' => $expirationDate,
            'manufacturingDate' => $manufacturingDate,
            'receivedDate' => $receivedDate,
            'wholesaleQty' => $wholesaleQty,
            'retailQty' => $retailQty,
            'shelfQty' => $shelfQty,
            'initialQty' => $initialQty,
            'costPrice' => $costPrice,
            'supplierId' => $supplierId,
            'invoiceNumber' => $invoiceNumber,
            'status' => $status,
            'notes' => $notes,
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create batch: ' . $e->getMessage()]);
}
