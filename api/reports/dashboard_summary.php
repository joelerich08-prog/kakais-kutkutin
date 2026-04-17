<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT SUM(total) AS todaySales FROM transactions WHERE DATE(createdAt) = ? AND status = 'completed'");
    $stmt->execute([$today]);
    $salesRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $todaySales = (float)($salesRow['todaySales'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS todayProfit
         FROM transaction_items ti
         JOIN transactions t ON ti.transactionId = t.id
         LEFT JOIN products p ON ti.productId = p.id
         WHERE DATE(t.createdAt) = ? AND t.status = 'completed'"
    );
    $stmt->execute([$today]);
    $profitRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $todayProfit = (float)($profitRow['todayProfit'] ?? 0);

    // Calculate low stock using wholesale threshold logic:
    // wholesaleReorderLevel > 0 && wholesaleQty <= wholesaleReorderLevel && wholesaleQty > 0
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS lowStockCount 
        FROM inventory_levels 
        WHERE wholesaleReorderLevel > 0 
        AND wholesaleQty <= wholesaleReorderLevel
        AND wholesaleQty > 0
    ");
    $stmt->execute();
    $stockRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $lowStockCount = (int)($stockRow['lowStockCount'] ?? 0);
    $stmt = $pdo->prepare("\n        SELECT COUNT(*) AS outOfStockCount
        FROM inventory_levels
        WHERE (wholesaleQty * packsPerBox * pcsPerPack + retailQty * pcsPerPack + shelfQty) = 0
    ");
    $stmt->execute();
    $outOfStockRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $outOfStockCount = (int)($outOfStockRow['outOfStockCount'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT p.id AS productId, p.name AS productName, SUM(ti.quantity) AS totalQuantity
         FROM transaction_items ti
         JOIN transactions t ON ti.transactionId = t.id
         JOIN products p ON ti.productId = p.id
         WHERE DATE(t.createdAt) = ? AND t.status = 'completed'
         GROUP BY p.id, p.name
         ORDER BY totalQuantity DESC
         LIMIT 5"
    );
    $stmt->execute([$today]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'todaySales' => $todaySales,
        'todayProfit' => $todayProfit,
        'lowStockCount' => $lowStockCount,
        'outOfStockCount' => $outOfStockCount,
        'topProducts' => $topProducts,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to calculate dashboard summary', 'details' => $e->getMessage()]);
}
?>