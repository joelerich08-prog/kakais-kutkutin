<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/transaction-validation.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get filters from query parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$paymentFilter = isset($_GET['paymentType']) ? trim($_GET['paymentType']) : 'all';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$cashierFilter = isset($_GET['cashierId']) ? trim($_GET['cashierId']) : 'all';
$startDate = isset($_GET['startDate']) ? trim($_GET['startDate']) : null;
$endDate = isset($_GET['endDate']) ? trim($_GET['endDate']) : null;

if ($startDate) {
    $startDate .= ' 00:00:00';
}
if ($endDate) {
    $endDate .= ' 23:59:59';
}

$pdo = Database::getInstance();

try {
    // Build the base query
    $conditions = [];
    $params = [];

    if ($startDate && $endDate) {
        $conditions[] = 't.createdAt >= ? AND t.createdAt <= ?';
        $params[] = $startDate;
        $params[] = $endDate;
    }

    if ($paymentFilter !== 'all') {
        $conditions[] = 't.paymentType = ?';
        $params[] = $paymentFilter;
    }

    if ($statusFilter !== 'all') {
        $conditions[] = 't.status = ?';
        $params[] = $statusFilter;
    }

    if ($cashierFilter !== 'all') {
        $conditions[] = 't.cashierId = ?';
        $params[] = $cashierFilter;
    }

    $whereClause = '';
    if (!empty($conditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    }

    // Get transactions with items
    $stmt = $pdo->prepare("SELECT 
        t.*, 
        GROUP_CONCAT(
            JSON_OBJECT(
                'id', ti.id,
                'productId', ti.productId,
                'productName', ti.productName,
                'variantId', ti.variantId,
                'variantName', ti.variantName,
                'quantity', ti.quantity,
                'unitPrice', ti.unitPrice,
                'costPrice', COALESCE(p.costPrice, 0),
                'discount', 0,
                'total', ti.subtotal,
                'subtotal', ti.subtotal
            ) SEPARATOR '|'
        ) as itemsJson
    FROM transactions t
    LEFT JOIN transaction_items ti ON t.id = ti.transactionId
    LEFT JOIN products p ON p.id = ti.productId
    {$whereClause}
    GROUP BY t.id
    ORDER BY t.createdAt DESC");

    $stmt->execute($params);
    $transactions = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items = [];
        if ($row['itemsJson']) {
            $itemsJsonArray = explode('|', $row['itemsJson']);
            foreach ($itemsJsonArray as $itemJson) {
                $item = json_decode($itemJson, true);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        // Filter by search term if provided
        if ($search !== '') {
            $searchLower = strtolower($search);
            $matchesSearch = stripos($row['invoiceNo'], $search) !== false ||
                array_some($items, function($item) use ($searchLower) {
                    return stripos($item['productName'], $searchLower) !== false ||
                           (isset($item['variantName']) && stripos($item['variantName'], $searchLower) !== false);
                });
            if (!$matchesSearch) {
                continue;
            }
        }

        $transactions[] = [
            'invoiceNo' => $row['invoiceNo'],
            'createdAt' => $row['createdAt'],
            'cashierId' => $row['cashierId'],
            'items' => $items,
            'subtotal' => (float)$row['subtotal'],
            'discount' => (float)$row['discount'],
            'total' => (float)$row['total'],
            'paymentType' => $row['paymentType'],
            'status' => $row['status'] ?? 'completed',
        ];
    }

    // Get cashier names
    $userIds = array_unique(array_column($transactions, 'cashierId'));
    $cashiers = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($userIds));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cashiers[$row['id']] = $row['name'];
        }
    }

    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d-His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Write BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Write header row
    fputcsv($output, [
        'Invoice',
        'Date',
        'Time',
        'Cashier',
        'Items',
        'Payment Method',
        'Status',
        'Subtotal',
        'Discount',
        'Total',
        'Cost',
        'Profit',
    ]);

    // Write data rows
    foreach ($transactions as $txn) {
        $dateTime = new DateTime($txn['createdAt']);
        $items = array_map(function($item) {
            $variant = isset($item['variantName']) ? ' (' . $item['variantName'] . ')' : '';
            return $item['quantity'] . 'x ' . $item['productName'] . $variant;
        }, $txn['items']);

        $totalCost = 0;
        foreach ($txn['items'] as $item) {
            $costPrice = $item['costPrice'] ?? 0;
            $totalCost += $costPrice * $item['quantity'];
        }
        $profit = $txn['total'] - $totalCost;

        fputcsv($output, [
            $txn['invoiceNo'],
            $dateTime->format('Y-m-d'),
            $dateTime->format('H:i:s'),
            $cashiers[$txn['cashierId']] ?? 'Unknown',
            implode('; ', $items),
            ucfirst($txn['paymentType']),
            ucfirst($txn['status']),
            number_format($txn['subtotal'], 2, '.', ''),
            number_format($txn['discount'], 2, '.', ''),
            number_format($txn['total'], 2, '.', ''),
            number_format($totalCost, 2, '.', ''),
            number_format(max(0, $profit), 2, '.', ''),
        ]);
    }

    fclose($output);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to generate CSV: ' . $e->getMessage()]);
}

// Helper function
function array_some(array $array, callable $callback): bool {
    foreach ($array as $item) {
        if ($callback($item)) {
            return true;
        }
    }
    return false;
}
?>
