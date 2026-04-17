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
if (!$input || !isset($input['batchId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload. batchId is required.']);
    exit;
}

$batchId = trim($input['batchId']);
$reason = isset($input['reason']) ? trim($input['reason']) : null;

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

    $notes = $batch['notes'] ? $batch['notes'] . ' | ' : '';
    $notes .= $reason ? 'Disposed: ' . $reason : 'Disposed';

    $stmt = $pdo->prepare('UPDATE product_batches SET status = "disposed", wholesaleQty = 0, retailQty = 0, shelfQty = 0, notes = ? WHERE id = ?');
    $stmt->execute([$notes, $batchId]);

    $pdo->commit();

    echo json_encode(['success' => true, 'batchId' => $batchId, 'status' => 'disposed']);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to dispose batch: ' . $e->getMessage()]);
}
