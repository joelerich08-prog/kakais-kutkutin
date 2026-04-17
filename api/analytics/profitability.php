<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/cors.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$startDate = isset($_GET['startDate']) ? trim($_GET['startDate']) : null;
$endDate = isset($_GET['endDate']) ? trim($_GET['endDate']) : null;
$period = isset($_GET['period']) ? trim($_GET['period']) : '30';

if ($startDate && $endDate) {
    $startDate = date('Y-m-d 00:00:00', strtotime($startDate));
    $endDate = date('Y-m-d 23:59:59', strtotime($endDate));
} elseif (in_array($period, ['7', '14', '30', '90'], true)) {
    $days = (int)$period;
    $startDate = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days"));
    $endDate = date('Y-m-d 23:59:59');
} else {
    $startDate = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $endDate = date('Y-m-d 23:59:59');
}

try {
    $pdo = Database::getInstance();

    // Calculate total revenue and basic costs
    $revenueStmt = $pdo->prepare(
        'SELECT
            SUM(ti.subtotal) AS totalRevenue,
            SUM(ti.quantity * COALESCE(p.costPrice, 0)) AS totalCost,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS grossProfit
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        WHERE t.createdAt >= ? AND t.createdAt <= ?'
    );
    $revenueStmt->execute([$startDate, $endDate]);
    $revenueData = $revenueStmt->fetch(PDO::FETCH_ASSOC);

    $totalRevenue = (float)($revenueData['totalRevenue'] ?? 0);
    $totalCost = (float)($revenueData['totalCost'] ?? 0);
    $grossProfit = (float)($revenueData['grossProfit'] ?? 0);
    $profitMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;

    // Category profitability
    $categoryStmt = $pdo->prepare(
        'SELECT
            COALESCE(c.name, "Uncategorized") AS category,
            SUM(ti.subtotal) AS revenue,
            SUM(ti.quantity * COALESCE(p.costPrice, 0)) AS cost,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        LEFT JOIN categories c ON c.id = p.categoryId
        WHERE t.createdAt >= ? AND t.createdAt <= ?
        GROUP BY c.id, c.name
        ORDER BY profit DESC'
    );
    $categoryStmt->execute([$startDate, $endDate]);
    $categoryRows = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryData = [];
    $totalCategoryProfit = array_sum(array_column($categoryRows, 'profit'));

    foreach ($categoryRows as $row) {
        $profit = (float)$row['profit'];
        $revenue = (float)$row['revenue'];
        $categoryData[] = [
            'category' => $row['category'],
            'revenue' => $revenue,
            'profit' => $profit,
            'margin' => $revenue > 0 ? ($profit / $revenue) * 100 : 0,
            'percentage' => $totalCategoryProfit > 0 ? ($profit / $totalCategoryProfit) * 100 : 0,
        ];
    }

    // Top products by profitability
    $productStmt = $pdo->prepare(
        'SELECT
            ti.productId,
            ti.productName,
            SUM(ti.subtotal) AS revenue,
            SUM(ti.quantity * COALESCE(p.costPrice, 0)) AS cost,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit,
            SUM(ti.quantity) AS quantity
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        WHERE t.createdAt >= ? AND t.createdAt <= ?
        GROUP BY ti.productId, ti.productName
        ORDER BY profit DESC
        LIMIT 20'
    );
    $productStmt->execute([$startDate, $endDate]);
    $productRows = $productStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate trends for products (compare with previous period)
    $startDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);
    $interval = $startDateObj->diff($endDateObj);
    $days = (int)$interval->days + 1;
    $previousStart = (clone $startDateObj)->modify("-{$days} days")->format('Y-m-d 00:00:00');
    $previousEnd = (clone $startDateObj)->modify('-1 day')->format('Y-m-d 23:59:59');

    $previousProductStmt = $pdo->prepare(
        'SELECT
            ti.productId,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        WHERE t.createdAt >= ? AND t.createdAt <= ?
        GROUP BY ti.productId'
    );
    $previousProductStmt->execute([$previousStart, $previousEnd]);
    $previousProductRows = $previousProductStmt->fetchAll(PDO::FETCH_ASSOC);

    $previousProfitMap = [];
    foreach ($previousProductRows as $row) {
        $previousProfitMap[$row['productId']] = (float)$row['profit'];
    }

    $productData = [];
    foreach ($productRows as $row) {
        $productId = $row['productId'];
        $currentProfit = (float)$row['profit'];
        $previousProfit = $previousProfitMap[$productId] ?? 0;
        $trend = 0.0;

        if ($previousProfit > 0) {
            $trend = (($currentProfit - $previousProfit) / $previousProfit) * 100;
        } elseif ($currentProfit > 0) {
            $trend = 100.0;
        }

        $revenue = (float)$row['revenue'];
        $productData[] = [
            'productId' => $productId,
            'name' => $row['productName'],
            'revenue' => $revenue,
            'profit' => $currentProfit,
            'margin' => $revenue > 0 ? ($currentProfit / $revenue) * 100 : 0,
            'quantity' => (int)$row['quantity'],
            'trend' => $trend,
        ];
    }

    // Time series data for charts
    $timeSeriesStmt = $pdo->prepare(
        'SELECT
            DATE(t.createdAt) AS date,
            SUM(ti.subtotal) AS revenue,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        WHERE t.createdAt >= ? AND t.createdAt <= ?
        GROUP BY DATE(t.createdAt)
        ORDER BY DATE(t.createdAt) ASC'
    );
    $timeSeriesStmt->execute([$startDate, $endDate]);
    $timeSeriesRows = $timeSeriesStmt->fetchAll(PDO::FETCH_ASSOC);

    $timeSeriesData = [];
    foreach ($timeSeriesRows as $row) {
        $revenue = (float)$row['revenue'];
        $profit = (float)$row['profit'];
        $timeSeriesData[] = [
            'date' => $row['date'],
            'revenue' => $revenue,
            'profit' => $profit,
            'margin' => $revenue > 0 ? ($profit / $revenue) * 100 : 0,
        ];
    }

    // Previous period metrics for comparison
    $previousRevenueStmt = $pdo->prepare(
        'SELECT
            SUM(ti.subtotal) AS totalRevenue,
            SUM(ti.quantity * COALESCE(p.costPrice, 0)) AS totalCost,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS grossProfit
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        WHERE t.createdAt >= ? AND t.createdAt <= ?'
    );
    $previousRevenueStmt->execute([$previousStart, $previousEnd]);
    $previousRevenueData = $previousRevenueStmt->fetch(PDO::FETCH_ASSOC);

    $previousTotalRevenue = (float)($previousRevenueData['totalRevenue'] ?? 0);
    $previousTotalCost = (float)($previousRevenueData['totalCost'] ?? 0);
    $previousGrossProfit = (float)($previousRevenueData['grossProfit'] ?? 0);
    $previousProfitMargin = $previousTotalRevenue > 0 ? ($previousGrossProfit / $previousTotalRevenue) * 100 : 0;

    $previousPeriodMetrics = [
        'totalRevenue' => $previousTotalRevenue,
        'totalCost' => $previousTotalCost,
        'grossProfit' => $previousGrossProfit,
        'profitMargin' => $previousProfitMargin,
    ];

    $metrics = [
        'totalRevenue' => $totalRevenue,
        'totalCost' => $totalCost,
        'grossProfit' => $grossProfit,
        'profitMargin' => $profitMargin,
    ];

    $response = [
        'metrics' => $metrics,
        'categoryData' => $categoryData,
        'productData' => $productData,
        'timeSeriesData' => $timeSeriesData,
        'previousPeriodMetrics' => $previousPeriodMetrics,
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load profitability analytics: ' . $e->getMessage()]);
}