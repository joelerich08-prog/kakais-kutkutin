<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/cors.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $today = date('Y-m-d');

    // Today's sales
    $stmt = $pdo->prepare("SELECT SUM(total) as todaySales FROM transactions WHERE DATE(createdAt) = ? AND status = 'completed'");
    $stmt->execute([$today]);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);
    $todaySales = (float)($sales['todaySales'] ?? 0);

    // Today's transactions count
    $stmt = $pdo->prepare("SELECT COUNT(*) as todayTransactions FROM transactions WHERE DATE(createdAt) = ? AND status = 'completed'");
    $stmt->execute([$today]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    $todayTransactions = (int)$trans['todayTransactions'];

    // Today's profit
    $stmt = $pdo->prepare(
        "SELECT SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) as todayProfit
         FROM transaction_items ti
         JOIN transactions t ON ti.transactionId = t.id
         LEFT JOIN products p ON ti.productId = p.id
         WHERE DATE(t.createdAt) = ? AND t.status = 'completed'"
    );
    $stmt->execute([$today]);
    $profit = $stmt->fetch(PDO::FETCH_ASSOC);
    $todayProfit = (float)($profit['todayProfit'] ?? 0);

    // Low stock count - using wholesale threshold logic
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as lowStockCount 
         FROM inventory_levels 
         WHERE wholesaleReorderLevel > 0 
         AND wholesaleQty <= wholesaleReorderLevel
         AND wholesaleQty > 0"
    );
    $stmt->execute();
    $lowStock = $stmt->fetch(PDO::FETCH_ASSOC);
    $lowStockCount = (int)($lowStock['lowStockCount'] ?? 0);

    // Pending orders count
    $stmt = $pdo->prepare("SELECT COUNT(*) as pendingOrders FROM orders WHERE status = 'pending'");
    $stmt->execute();
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);
    $pendingOrders = (int)($pending['pendingOrders'] ?? 0);

    // Top 5 selling items today
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, SUM(ti.quantity) as totalSold
         FROM transaction_items ti
         JOIN transactions t ON ti.transactionId = t.id
         JOIN products p ON ti.productId = p.id
         WHERE DATE(t.createdAt) = ? AND t.status = 'completed'
         GROUP BY p.id, p.name
         ORDER BY totalSold DESC
         LIMIT 5"
    );
    $stmt->execute([$today]);
    $topItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $response = [
        'todaySales' => $todaySales,
        'todayTransactions' => $todayTransactions,
        'todayProfit' => $todayProfit,
        'lowStockCount' => $lowStockCount,
        'pendingOrders' => $pendingOrders,
        'topItems' => $topItems
    ];

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
}
?>