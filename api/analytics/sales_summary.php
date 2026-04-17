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
$period = isset($_GET['period']) ? trim($_GET['period']) : '14';

if ($startDate && $endDate) {
    $startDate = date('Y-m-d 00:00:00', strtotime($startDate));
    $endDate = date('Y-m-d 23:59:59', strtotime($endDate));
} elseif (in_array($period, ['7', '14', '30'], true)) {
    $days = (int)$period;
    $startDate = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days"));
    $endDate = date('Y-m-d 23:59:59');
} else {
    $startDate = date('Y-m-d 00:00:00', strtotime('-13 days'));
    $endDate = date('Y-m-d 23:59:59');
}

try {
    $pdo = Database::getInstance();

    $dailyStmt = $pdo->prepare(
        'SELECT DATE(createdAt) AS date, SUM(total) AS sales, COUNT(*) AS transactions
         FROM transactions
         WHERE createdAt >= ? AND createdAt <= ?
         GROUP BY DATE(createdAt)
         ORDER BY DATE(createdAt) ASC'
    );
    $dailyStmt->execute([$startDate, $endDate]);
    $dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    $dailyProfitStmt = $pdo->prepare(
        'SELECT DATE(t.createdAt) AS date, SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit
         FROM transaction_items ti
         JOIN transactions t ON t.id = ti.transactionId
         LEFT JOIN products p ON p.id = ti.productId
         WHERE t.createdAt >= ? AND t.createdAt <= ?
         GROUP BY DATE(t.createdAt)
         ORDER BY DATE(t.createdAt) ASC'
    );
    $dailyProfitStmt->execute([$startDate, $endDate]);
    $dailyProfitRows = $dailyProfitStmt->fetchAll(PDO::FETCH_ASSOC);
    $dailyProfitMap = [];
    foreach ($dailyProfitRows as $profitRow) {
        $dailyProfitMap[$profitRow['date']] = (float)($profitRow['profit'] ?? 0);
    }

    $paymentStmt = $pdo->prepare(
        'SELECT LOWER(paymentType) AS paymentType, SUM(total) AS total, COUNT(*) AS count
         FROM transactions
         WHERE createdAt >= ? AND createdAt <= ?
         GROUP BY LOWER(paymentType)'
    );
    $paymentStmt->execute([$startDate, $endDate]);
    $paymentRows = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentProfitStmt = $pdo->prepare(
        'SELECT LOWER(t.paymentType) AS paymentType, SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit
         FROM transaction_items ti
         JOIN transactions t ON t.id = ti.transactionId
         LEFT JOIN products p ON p.id = ti.productId
         WHERE t.createdAt >= ? AND t.createdAt <= ?
         GROUP BY LOWER(t.paymentType)'
    );
    $paymentProfitStmt->execute([$startDate, $endDate]);
    $paymentProfitRows = $paymentProfitStmt->fetchAll(PDO::FETCH_ASSOC);
    $paymentProfitMap = [];
    foreach ($paymentProfitRows as $profitRow) {
        $paymentProfitMap[$profitRow['paymentType']] = (float)($profitRow['profit'] ?? 0);
    }

    $profitStmt = $pdo->prepare(
        'SELECT SUM(ti.quantity * COALESCE(p.costPrice, 0)) AS totalCost,
                SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS totalProfit,
                SUM(CASE WHEN p.costPrice IS NOT NULL AND p.costPrice > 0 THEN ti.quantity ELSE 0 END) AS itemsWithCost
         FROM transaction_items ti
         JOIN transactions t ON t.id = ti.transactionId
         LEFT JOIN products p ON p.id = ti.productId
         WHERE t.createdAt >= ? AND t.createdAt <= ?'
    );
    $profitStmt->execute([$startDate, $endDate]);
    $profitRow = $profitStmt->fetch(PDO::FETCH_ASSOC);
    $totalCost = (float)($profitRow['totalCost'] ?? 0);
    $totalProfit = (float)($profitRow['totalProfit'] ?? 0);
    $itemsWithCost = (int)($profitRow['itemsWithCost'] ?? 0);

    $startDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);
    $interval = $startDateObj->diff($endDateObj);
    $days = (int)$interval->days + 1;

    $previousStart = (clone $startDateObj)->modify("-{$days} days")->format('Y-m-d 00:00:00');
    $previousEnd = (clone $startDateObj)->modify('-1 day')->format('Y-m-d 23:59:59');

    $previousStmt = $pdo->prepare(
        'SELECT SUM(total) AS total FROM transactions WHERE createdAt >= ? AND createdAt <= ?'
    );
    $previousStmt->execute([$previousStart, $previousEnd]);
    $previousRow = $previousStmt->fetch(PDO::FETCH_ASSOC);
    $previousPeriodSales = (float)($previousRow['total'] ?? 0);

    $salesData = [];
    $current = new DateTime($startDate);
    while ($current <= $endDateObj) {
        $key = $current->format('Y-m-d');
        $row = array_filter($dailyRows, fn($entry) => $entry['date'] === $key);
        $entry = array_values($row)[0] ?? null;
        $salesData[] = [
            'date' => $current->format('M j'),
            'sales' => $entry ? (float)$entry['sales'] : 0,
            'transactions' => $entry ? (int)$entry['transactions'] : 0,
            'profit' => $dailyProfitMap[$key] ?? 0,
        ];
        $current->modify('+1 day');
    }

    $paymentTypes = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
    ];

    $paymentData = [];
    foreach ($paymentTypes as $key => $label) {
        $paymentData[$key] = [
            'type' => $label,
            'total' => 0.0,
            'count' => 0,
        ];
    }

    foreach ($paymentRows as $paymentRow) {
        $type = $paymentRow['paymentType'];
        if (!isset($paymentData[$type])) {
            continue;
        }
        $paymentData[$type]['total'] = (float)$paymentRow['total'];
        $paymentData[$type]['count'] = (int)$paymentRow['count'];
        $paymentData[$type]['profit'] = $paymentProfitMap[$type] ?? 0.0;
    }

    // Get top products by revenue
    $topProductsStmt = $pdo->prepare(
        'SELECT ti.productId, p.name, SUM(ti.quantity * ti.unitPrice) AS revenue, SUM(ti.quantity) AS quantity
         FROM transaction_items ti
         JOIN transactions t ON t.id = ti.transactionId
         LEFT JOIN products p ON p.id = ti.productId
         WHERE t.createdAt >= ? AND t.createdAt <= ?
         GROUP BY ti.productId, p.name
         ORDER BY revenue DESC
         LIMIT 10'
    );
    $topProductsStmt->execute([$startDate, $endDate]);
    $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get category breakdown
    $categoryStmt = $pdo->prepare(
        'SELECT c.name, SUM(ti.quantity * ti.unitPrice) AS revenue, COUNT(DISTINCT t.id) AS transactions, SUM(ti.quantity) AS items
         FROM transaction_items ti
         JOIN transactions t ON t.id = ti.transactionId
         JOIN products p ON p.id = ti.productId
         JOIN categories c ON c.id = p.categoryId
         WHERE t.createdAt >= ? AND t.createdAt <= ?
         GROUP BY c.id, c.name
         ORDER BY revenue DESC'
    );
    $categoryStmt->execute([$startDate, $endDate]);
    $categoryData = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate daily stats
    $bestDay = null;
    $worstDay = null;
    foreach ($salesData as $day) {
        if ($day['sales'] > 0) {
            if (!$bestDay || $day['sales'] > $bestDay['sales']) {
                $bestDay = $day;
            }
            if (!$worstDay || $day['sales'] < $worstDay['sales']) {
                $worstDay = $day;
            }
        }
    }

    $avgDailySales = count($salesData) > 0 ? array_reduce($salesData, fn($c, $d) => $c + $d['sales'], 0) / count($salesData) : 0;
    $avgDailyTransactions = count($salesData) > 0 ? array_reduce($salesData, fn($c, $d) => $c + $d['transactions'], 0) / count($salesData) : 0;

    $response = [
        'salesData' => $salesData,
        'paymentData' => array_values($paymentData),
        'totalSales' => array_reduce($salesData, fn($carry, $item) => $carry + $item['sales'], 0.0),
        'totalCost' => $totalCost,
        'totalProfit' => $totalProfit,
        'itemsWithCost' => $itemsWithCost,
        'totalTransactions' => array_reduce($salesData, fn($carry, $item) => $carry + $item['transactions'], 0),
        'previousPeriodSales' => $previousPeriodSales,
        'topProducts' => $topProducts,
        'categoryData' => $categoryData,
        'bestDay' => $bestDay,
        'worstDay' => $worstDay,
        'avgDailySales' => $avgDailySales,
        'avgDailyTransactions' => $avgDailyTransactions,
        'periodDays' => $days,
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load sales analytics: ' . $e->getMessage()]);
}
