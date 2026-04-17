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
if (!$input || !isset($input['batchId']) || !isset($input['tier']) || !isset($input['quantityChange'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload. batchId, tier, and quantityChange are required.']);
    exit;
}

$batchId = trim($input['batchId']);
$tier = trim($input['tier']);
$quantityChange = (int)$input['quantityChange'];

if (!in_array($tier, ['wholesale', 'retail', 'shelf'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid tier. Must be wholesale, retail, or shelf.']);
    exit;
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM product_batches WHERE id = ? FOR UPDATE');
    $stmt->execute([$batchId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        http_response_code(404);
        echo json_encode(['error' => 'Batch not found.']);
        exit;
    }

    $column = $tier . 'Qty';
    $currentQty = (int)$batch[$column];
    $newQty = $currentQty + $quantityChange;

    if ($newQty < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient batch stock for this operation.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE product_batches SET {$column} = ? WHERE id = ?");
    $stmt->execute([$newQty, $batchId]);

    $pdo->commit();

    echo json_encode(['success' => true, 'batchId' => $batchId, 'tier' => $tier, 'newQty' => $newQty]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update batch stock: ' . $e->getMessage()]);
}
