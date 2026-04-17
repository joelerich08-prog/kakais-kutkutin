<?php
require_once __DIR__ . '/../middleware/cors.php';

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$data || !isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: items array is required']);
    exit;
}

if (!isset($data['supplierId']) || !isset($data['supplier']) || !isset($data['invoiceNumber'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: supplierId, supplier, and invoiceNumber are required']);
    exit;
}

$items = $data['items'];
$supplierId = trim($data['supplierId']);
$supplier = trim($data['supplier']);
$invoiceNumber = trim($data['invoiceNumber']);
$notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

if ($supplierId === '' || $supplier === '' || $invoiceNumber === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Supplier and invoice number are required']);
    exit;
}

// Validate each item
foreach ($items as $item) {
    if (!isset($item['productId']) || !isset($item['quantity']) || !isset($item['cost']) || !isset($item['batchNumber']) || !isset($item['expirationDate'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item: productId, quantity, cost, batchNumber, and expirationDate are required']);
        exit;
    }
    if ($item['quantity'] <= 0 || $item['cost'] < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item: quantity must be > 0 and cost must be >= 0']);
        exit;
    }

    $expirationDate = strtotime($item['expirationDate']);
    if ($expirationDate === false || $expirationDate <= time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item: expirationDate must be a valid future date']);
        exit;
    }

    if (isset($item['manufacturingDate']) && trim((string) $item['manufacturingDate']) !== '') {
        $manufacturingDate = strtotime($item['manufacturingDate']);
        if ($manufacturingDate === false || $manufacturingDate > time()) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid item: manufacturingDate must be a valid past date']);
            exit;
        }
        if ($manufacturingDate >= $expirationDate) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid item: manufacturingDate must be earlier than expirationDate']);
            exit;
        }
    }
}

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id = ?");
    $stmt->execute([$supplierId]);
    $supplierRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplierRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Supplier not found']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userRow['name'] ?? 'Unknown';

    $totalItems = 0;
    foreach ($items as $item) {
        $productId = trim($item['productId']);
        $variantId = normalizeInventoryVariantId($item['variantId'] ?? null);
        $quantity = (int)$item['quantity'];
        $cost = (float)$item['cost'];
        $tier = isset($item['tier']) ? trim($item['tier']) : 'wholesale';
        $batchNumber = trim((string) $item['batchNumber']);
        $expirationDate = date('Y-m-d', strtotime($item['expirationDate']));
        $manufacturingDate = isset($item['manufacturingDate']) && trim((string) $item['manufacturingDate']) !== ''
            ? date('Y-m-d', strtotime($item['manufacturingDate']))
            : null;

        // Validate tier
        if (!in_array($tier, ['wholesale', 'retail', 'shelf'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid tier: must be wholesale, retail, or shelf']);
            exit;
        }

        // Verify product exists
        $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Product not found: ' . $productId]);
            exit;
        }

        // Get or create inventory level for the matching product / variant
        $currentInventory = getOrCreateInventoryLevel($pdo, $productId, $variantId, true);

        if ($tier === 'wholesale') {
            $newQty = $currentInventory['wholesaleQty'] + $quantity;
            $stmt = $pdo->prepare("UPDATE inventory_levels SET wholesaleQty = ?, updatedAt = NOW() WHERE id = ?");
        } elseif ($tier === 'retail') {
            $newQty = $currentInventory['retailQty'] + $quantity;
            $stmt = $pdo->prepare("UPDATE inventory_levels SET retailQty = ?, updatedAt = NOW() WHERE id = ?");
        } else {
            $newQty = $currentInventory['shelfQty'] + $quantity;
            $stmt = $pdo->prepare("UPDATE inventory_levels SET shelfQty = ?, updatedAt = NOW() WHERE id = ?");
        }
        $stmt->execute([$newQty, $currentInventory['id']]);

        // Record stock movement
        insertStockMovement($pdo, [
            'id' => bin2hex(random_bytes(8)),
            'productId' => $productId,
            'variantId' => $variantId,
            'movementType' => 'receive',
            'fromTier' => null,
            'toTier' => $tier,
            'quantity' => $quantity,
            'reason' => 'Stock received from supplier',
            'notes' => 'Supplier: ' . $supplier . ' | Invoice: ' . $invoiceNumber . ($notes !== '' ? ' | Notes: ' . $notes : ''),
            'performedBy' => $userId,
        ]);

        // Create product batch record using the submitted batch metadata
        $batchId = bin2hex(random_bytes(8));
        $wholesaleQty = $tier === 'wholesale' ? $quantity : 0;
        $retailQty = $tier === 'retail' ? $quantity : 0;
        $shelfQty = $tier === 'shelf' ? $quantity : 0;

        $stmt = $pdo->prepare("
            INSERT INTO product_batches (id, productId, variantId, batchNumber, expirationDate, 
                                         manufacturingDate, receivedDate, wholesaleQty, retailQty, shelfQty, initialQty,
                                         costPrice, supplierId, invoiceNumber, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, 'active', ?)
        ");
        $stmt->execute([
            $batchId,
            $productId,
            $variantId,
            $batchNumber,
            $expirationDate,
            $manufacturingDate,
            $wholesaleQty,
            $retailQty,
            $shelfQty,
            $quantity,
            $cost,
            $supplierId,
            $invoiceNumber,
            'Supplier: ' . $supplierRow['name'] . ($notes !== '' ? ' | Notes: ' . $notes : ''),
        ]);

        $totalItems += $quantity;
    }

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (id, userId, userName, action, details, createdAt)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        bin2hex(random_bytes(8)),
        $userId,
        $userName,
        'receive_stock',
        'Received ' . $totalItems . ' units across ' . count($items) . ' product(s) | Supplier: ' . $supplier . ' | Invoice: ' . $invoiceNumber . ($notes !== '' ? ' | Notes: ' . $notes : ''),
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock received successfully',
        'totalItems' => $totalItems,
        'itemsCount' => count($items)
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to receive stock: ' . $e->getMessage()]);
}
