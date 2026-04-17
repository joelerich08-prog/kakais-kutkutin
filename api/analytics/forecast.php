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
    $historyDays = 30; // Days to look back for sales data

    $endDate = new DateTime('now');
    $startDate = (clone $endDate)->modify('-' . ($historyDays - 1) . ' days');
    $startKey = $startDate->format('Y-m-d 00:00:00');
    $endKey = $endDate->format('Y-m-d 23:59:59');

    // Get sales data by product for the period
    $salesByProductStmt = $pdo->prepare(
        'SELECT ti.productId, SUM(ti.quantity) AS quantity
         FROM transaction_items ti
         JOIN transactions t ON t.id = ti.transactionId
         WHERE t.createdAt >= ? AND t.createdAt <= ?
         GROUP BY ti.productId'
    );
    $salesByProductStmt->execute([$startKey, $endKey]);
    $productSalesRows = $salesByProductStmt->fetchAll(PDO::FETCH_ASSOC);

    $productSales = [];
    foreach ($productSalesRows as $row) {
        $productSales[$row['productId']] = (int)$row['quantity'];
    }

    // Get current inventory levels
    $stockStmt = $pdo->query(
        'SELECT il.productId, p.name AS productName, il.wholesaleQty, il.packsPerBox, il.pcsPerPack, il.retailQty, il.shelfQty, il.shelfRestockLevel
         FROM inventory_levels il
         LEFT JOIN products p ON p.id = il.productId'
    );
    $stockRows = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

    $stockForecast = [];
    foreach ($stockRows as $row) {
        $totalStock = ((int)$row['wholesaleQty'] * (int)$row['packsPerBox'] * (int)$row['pcsPerPack'])
            + ((int)$row['retailQty'] * (int)$row['pcsPerPack'])
            + (int)$row['shelfQty'];

        $salesQuantity = $productSales[$row['productId']] ?? 0;
        $avgDailySales = $salesQuantity > 0 ? round($salesQuantity / $historyDays, 1) : 0.0;
        $daysUntilStockout = $avgDailySales > 0 ? (int)floor($totalStock / $avgDailySales) : 0;
        $reorderPoint = (int)$row['shelfRestockLevel'];
        $needsReorder = $totalStock <= $reorderPoint;
        $status = $daysUntilStockout <= 3 ? 'critical' : ($daysUntilStockout <= 7 ? 'warning' : 'healthy');

        $turnover = $totalStock > 0 && $avgDailySales > 0
            ? round(($avgDailySales * 365) / $totalStock, 2)
            : 0.0;

        $stockForecast[] = [
            'id' => $row['productId'],
            'name' => $row['productName'] ?? 'Unknown',
            'currentStock' => $totalStock,
            'avgDailySales' => $avgDailySales,
            'daysUntilStockout' => $daysUntilStockout,
            'reorderPoint' => $reorderPoint,
            'inventoryTurnover' => $turnover,
            'needsReorder' => $needsReorder,
            'status' => $status,
        ];
    }

    $response = [
        'stockForecast' => $stockForecast,
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load forecast analytics: ' . $e->getMessage()]);
}
