<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/transaction-validation.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$cashierId = trim((string)$_SESSION['user_id']);
if ($cashierId === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: invalid cashier session']);
    exit;
}

$pdo = Database::getInstance();
$stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
$stmt->execute([$cashierId]);
if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: cashier account not found']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['items']) || !isset($data['paymentType']) || !isset($data['subtotal']) || !isset($data['discount']) || !isset($data['total'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: missing required fields']);
    exit;
}

// Validate transaction data
$validationErrors = validateTransaction($data);
if (!empty($validationErrors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'details' => $validationErrors]);
    exit;
}

$items = $data['items'];
$paymentType = $data['paymentType'];
$subtotal = (float)$data['subtotal'];
$discount = (float)$data['discount'];
$total = (float)$data['total'];
$invoiceNo = isset($data['invoiceNo']) ? trim($data['invoiceNo']) : '';

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    if ($invoiceNo === '') {
        $invoiceNo = 'POS-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
    $transactionId = bin2hex(random_bytes(16));
    $createdAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO transactions (id, invoiceNo, subtotal, discount, total, paymentType, cashierId, status, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?)");
    $stmt->execute([$transactionId, $invoiceNo, $subtotal, $discount, $total, $paymentType, $cashierId, $createdAt]);

    $transactionItems = [];

    foreach ($items as $item) {
        if (!isset($item['productId']) || !isset($item['quantity']) || !isset($item['unitPrice'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid item data']);
            exit;
        }

        $productId = trim($item['productId']);
        $variantId = isset($item['variantId']) ? trim($item['variantId']) : null;
        $productName = isset($item['productName']) ? trim($item['productName']) : '';
        $variantName = isset($item['variantName']) ? trim($item['variantName']) : null;
        $quantity = (int)$item['quantity'];
        $unitPrice = (float)$item['unitPrice'];
        $tier = isset($item['tier']) ? trim($item['tier']) : 'shelf';
        $unitType = isset($item['unitType']) ? trim($item['unitType']) : null;

        $tierColumns = [
            'wholesale' => 'wholesaleQty',
            'retail' => 'retailQty',
            'shelf' => 'shelfQty',
        ];

        if (!isset($tierColumns[$tier])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid tier specified for item stock adjustment']);
            exit;
        }

        if ($quantity <= 0 || $unitPrice < 0 || $productId === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid item values']);
            exit;
        }

        if ($productName === '') {
            $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $productName = $prod['name'];
            }
        }

        if ($variantId && $variantName === null) {
            $stmt = $pdo->prepare("SELECT name FROM product_variants WHERE id = ?");
            $stmt->execute([$variantId]);
            $var = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($var) {
                $variantName = $var['name'];
            }
        }

        $itemId = bin2hex(random_bytes(16));
        $itemSubtotal = $quantity * $unitPrice;

        $stmt = $pdo->prepare("INSERT INTO transaction_items (id, transactionId, productId, variantId, productName, variantName, quantity, unitPrice, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$itemId, $transactionId, $productId, $variantId, $productName, $variantName, $quantity, $unitPrice, $itemSubtotal]);

        $transactionItems[] = [
            'id' => $itemId,
            'productId' => $productId,
            'variantId' => $variantId,
            'productName' => $productName,
            'variantName' => $variantName,
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'subtotal' => $itemSubtotal,
        ];

        $inventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);
        $inventoryId = $inventory['id'];

        // Handle combined shelf + retail deduction for packs
        if ($unitType === 'pack') {
            $shelfQty = (int) $inventory['shelfQty'];
            $retailQty = (int) $inventory['retailQty'];
            $totalAvailable = $shelfQty + $retailQty;

            if ($totalAvailable < $quantity) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Insufficient stock for product (shelf + retail)']);
                exit;
            }

            // Deduct from shelf first
            $deductFromShelf = min($shelfQty, $quantity);
            $deductFromRetail = $quantity - $deductFromShelf;

            if ($deductFromShelf > 0) {
                $stmt = $pdo->prepare("UPDATE inventory_levels SET shelfQty = shelfQty - :quantity WHERE id = :id");
                $stmt->execute([':quantity' => $deductFromShelf, ':id' => $inventoryId]);
            }

            if ($deductFromRetail > 0) {
                $stmt = $pdo->prepare("UPDATE inventory_levels SET retailQty = retailQty - :quantity WHERE id = :id");
                $stmt->execute([':quantity' => $deductFromRetail, ':id' => $inventoryId]);
            }

            // Record stock movements for combined deduction
            $batchAllocations = [];
            if ($deductFromShelf > 0) {
                $shelfBatches = moveBatchStockFEFO($pdo, $productId, $variantId, 'shelf', null, $deductFromShelf);
                $batchAllocations = array_merge($batchAllocations, $shelfBatches);
            }
            if ($deductFromRetail > 0) {
                $retailBatches = moveBatchStockFEFO($pdo, $productId, $variantId, 'retail', null, $deductFromRetail);
                $batchAllocations = array_merge($batchAllocations, $retailBatches);
            }

            insertStockMovement($pdo, [
                'id' => bin2hex(random_bytes(16)),
                'productId' => $productId,
                'variantId' => $variantId,
                'movementType' => 'sale',
                'fromTier' => 'shelf', // Primary tier for movement record
                'toTier' => null,
                'quantity' => $quantity,
                'reason' => 'POS checkout (pack - combined shelf+retail)',
                'notes' => buildBatchAllocationMovementNotes('transaction_item', $itemId, $batchAllocations),
                'performedBy' => $cashierId,
            ]);
        } else {
            // Standard single-tier deduction
            $quantityColumn = $tierColumns[$tier];

            if ((int) $inventory[$quantityColumn] < $quantity) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Insufficient stock for product']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE inventory_levels SET {$quantityColumn} = {$quantityColumn} - :quantity WHERE id = :id");
            $stmt->execute([
                ':quantity' => $quantity,
                ':id' => $inventoryId,
            ]);

            $batchAllocations = moveBatchStockFEFO($pdo, $productId, $variantId, $tier, null, $quantity);

            insertStockMovement($pdo, [
                'id' => bin2hex(random_bytes(16)),
                'productId' => $productId,
                'variantId' => $variantId,
                'movementType' => 'sale',
                'fromTier' => $tier,
                'toTier' => null,
                'quantity' => $quantity,
                'reason' => 'POS checkout',
                'notes' => buildBatchAllocationMovementNotes('transaction_item', $itemId, $batchAllocations),
                'performedBy' => $cashierId,
            ]);
        }

        $stmt = $pdo->prepare("SELECT wholesaleQty, retailQty, shelfQty, wholesaleReorderLevel, retailRestockLevel, shelfRestockLevel FROM inventory_levels WHERE id = :id");
        $stmt->execute([':id' => $inventoryId]);
        $updatedInventory = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($updatedInventory) {
            // Check shelf stock alert (existing logic, but now using low_shelf type for clarity)
            if ($updatedInventory['shelfQty'] <= $updatedInventory['shelfRestockLevel']) {
                $alertId = bin2hex(random_bytes(16));
                $message = "Shelf stock level for {$productName}" . ($variantName ? " ({$variantName})" : "") . " is low: {$updatedInventory['shelfQty']} remaining (threshold: {$updatedInventory['shelfRestockLevel']}).";
                $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_shelf', 'high', 'Low Shelf Stock Alert', ?, ?)");
                $stmt->execute([$alertId, $message, $productId]);
            }

            // Check retail stock alert (new logic for retail tier)
            if ($updatedInventory['retailQty'] <= $updatedInventory['retailRestockLevel']) {
                $alertId = bin2hex(random_bytes(16));
                $message = "Retail stock level for {$productName}" . ($variantName ? " ({$variantName})" : "") . " is low: {$updatedInventory['retailQty']} remaining (threshold: {$updatedInventory['retailRestockLevel']}).";
                $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_retail', 'medium', 'Low Retail Stock Alert', ?, ?)");
                $stmt->execute([$alertId, $message, $productId]);
            }

            // Check wholesale stock alert (new logic for wholesale tier) - only if reorder level is set (> 0)
            if ($updatedInventory['wholesaleReorderLevel'] > 0 && $updatedInventory['wholesaleQty'] <= $updatedInventory['wholesaleReorderLevel']) {
                $alertId = bin2hex(random_bytes(16));
                $message = "Wholesale stock level for {$productName}" . ($variantName ? " ({$variantName})" : "") . " is low: {$updatedInventory['wholesaleQty']} remaining (threshold: {$updatedInventory['wholesaleReorderLevel']}).";
                $stmt = $pdo->prepare("INSERT INTO alerts (id, type, priority, title, message, productId) VALUES (?, 'low_wholesale', 'medium', 'Low Wholesale Stock Alert', ?, ?)");
                $stmt->execute([$alertId, $message, $productId]);
            }
        }
    }

    $pdo->commit();

    $response = [
        'id' => $transactionId,
        'invoiceNo' => $invoiceNo,
        'items' => $transactionItems,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $total,
        'paymentType' => $paymentType,
        'cashierId' => $cashierId,
        'status' => 'completed',
        'createdAt' => $createdAt,
    ];

    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
}
?>
