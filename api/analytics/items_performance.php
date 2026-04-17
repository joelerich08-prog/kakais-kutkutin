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
} elseif (in_array($period, ['7', '14', '30'], true)) {
    $days = (int)$period;
    $startDate = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days"));
    $endDate = date('Y-m-d 23:59:59');
} else {
    $startDate = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $endDate = date('Y-m-d 23:59:59');
}

try {
    $pdo = Database::getInstance();

    $currentStmt = $pdo->prepare(
        'SELECT
            ti.productId,
            ti.productName,
            COALESCE(c.name, "Unknown") AS category,
            SUM(ti.quantity) AS quantitySold,
            SUM(ti.subtotal) AS revenue,
            SUM(ti.quantity * COALESCE(p.costPrice, 0)) AS cost,
            SUM((ti.unitPrice - COALESCE(p.costPrice, 0)) * ti.quantity) AS profit
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        LEFT JOIN products p ON p.id = ti.productId
        LEFT JOIN categories c ON c.id = p.categoryId
        WHERE t.createdAt >= ? AND t.createdAt <= ?
        GROUP BY ti.productId'
    );
    $currentStmt->execute([$startDate, $endDate]);
    $currentRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC);

    $startDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);
    $interval = $startDateObj->diff($endDateObj);
    $days = (int)$interval->days + 1;
    $previousStart = (clone $startDateObj)->modify("-{$days} days")->format('Y-m-d 00:00:00');
    $previousEnd = (clone $startDateObj)->modify('-1 day')->format('Y-m-d 23:59:59');

    $previousStmt = $pdo->prepare(
        'SELECT
            ti.productId,
            SUM(ti.quantity) AS quantitySold,
            SUM(ti.subtotal) AS revenue
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transactionId
        WHERE t.createdAt >= ? AND t.createdAt <= ?
        GROUP BY ti.productId'
    );
    $previousStmt->execute([$previousStart, $previousEnd]);
    $previousRows = $previousStmt->fetchAll(PDO::FETCH_ASSOC);

    $previousMap = [];
    foreach ($previousRows as $row) {
        $previousMap[$row['productId']] = [
            'quantitySold' => (int)$row['quantitySold'],
            'revenue' => (float)$row['revenue'],
        ];
    }

    $items = [];
    foreach ($currentRows as $row) {
        $quantitySold = (int)$row['quantitySold'];
        $revenue = (float)$row['revenue'];
        $cost = (float)$row['cost'];
        $profit = (float)$row['profit'];
        $previous = $previousMap[$row['productId']] ?? ['quantitySold' => 0, 'revenue' => 0.0];
        $trend = 0.0;

        if ($previous['revenue'] > 0) {
            $trend = (($revenue - $previous['revenue']) / $previous['revenue']) * 100;
        } elseif ($revenue > 0) {
            $trend = 100.0;
        }

        $items[] = [
            'productId' => $row['productId'],
            'name' => $row['productName'],
            'category' => $row['category'],
            'quantitySold' => $quantitySold,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'profitMargin' => $revenue > 0 ? ($profit / $revenue) * 100 : 0.0,
            'avgCost' => $quantitySold > 0 ? $cost / $quantitySold : 0.0,
            'avgPrice' => $quantitySold > 0 ? $revenue / $quantitySold : 0.0,
            'trend' => $trend,
        ];
    }

    usort($items, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    $response = [
        'items' => $items,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'previousPeriodStart' => $previousStart,
        'previousPeriodEnd' => $previousEnd,
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load item analytics: ' . $e->getMessage()]);
}
