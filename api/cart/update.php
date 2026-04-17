<?php
require_once __DIR__ . '/../middleware/cors.php';

session_start();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['items']) || !is_array($input['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload. items array is required.']);
    exit;
}

$items = $input['items'];
$_SESSION['cart'] = $items;

echo json_encode(['success' => true, 'items' => $items]);
