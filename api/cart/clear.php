<?php
require_once __DIR__ . '/../middleware/cors.php';

session_start();

$_SESSION['cart'] = [];

echo json_encode(['success' => true]);
