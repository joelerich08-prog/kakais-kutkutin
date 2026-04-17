<?php
require_once __DIR__ . '/../middleware/cors.php';

session_start();

$cart = $_SESSION['cart'] ?? [];
echo json_encode($cart);
