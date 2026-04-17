<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../auth/check_permissions.php';

function buildCategorySkuPrefix(string $categoryName): string
{
    $compact = strtoupper(preg_replace('/[^A-Z0-9]/', '', $categoryName) ?? '');
    return substr($compact !== '' ? $compact : 'PROD', 0, 4);
}

function generateCategorySku(PDO $pdo, string $categoryId): string
{
    $stmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    $categoryName = $stmt->fetchColumn();

    if (!$categoryName) {
        throw new RuntimeException('Selected category does not exist');
    }

    $prefix = buildCategorySkuPrefix((string) $categoryName);
    $likePrefix = $prefix . '-%';

    $stmt = $pdo->prepare('SELECT sku FROM products WHERE categoryId = ? AND sku LIKE ? FOR UPDATE');
    $stmt->execute([$categoryId, $likePrefix]);

    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingSku) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string) $existingSku, $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }

    return sprintf('%s-%03d', $prefix, $max + 1);
}

function buildVariantSkuBase(string $productSku, string $variantName): string
{
    $variantPart = strtoupper(preg_replace('/[^A-Z0-9]/', '', $variantName) ?? '');
    $variantPart = substr($variantPart !== '' ? $variantPart : 'VARIANT', 0, 6);

    return strtoupper($productSku) . '-' . $variantPart;
}

function generateVariantSku(PDO $pdo, string $productId, string $variantName, ?string $excludeVariantId = null): string
{
    $stmt = $pdo->prepare('SELECT sku FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $productSku = $stmt->fetchColumn();

    if (!$productSku) {
        throw new RuntimeException('Selected base product does not exist');
    }

    $baseSku = buildVariantSkuBase((string) $productSku, $variantName);
    $candidate = $baseSku;
    $suffix = 1;

    while (true) {
        $sql = 'SELECT id FROM product_variants WHERE sku = ?';
        $params = [$candidate];
        if ($excludeVariantId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeVariantId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $suffix += 1;
        $candidate = $baseSku . '-' . $suffix;
    }
}

session_start();

requirePermission('products', 'create');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$name = trim((string) ($data['name'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
$categoryId = trim((string) ($data['categoryId'] ?? ''));
$supplierId = trim((string) ($data['supplierId'] ?? ''));
$costPrice = isset($data['costPrice']) ? (float) $data['costPrice'] : null;
$wholesalePrice = isset($data['wholesalePrice']) ? (float) $data['wholesalePrice'] : null;
$retailPrice = isset($data['retailPrice']) ? (float) $data['retailPrice'] : null;
$isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true;
$variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];
$creationType = trim((string) ($data['creationType'] ?? 'base'));
$baseProductId = trim((string) ($data['baseProductId'] ?? ''));
$variantName = trim((string) ($data['variantName'] ?? ''));
$variantPriceAdjustment = isset($data['variantPriceAdjustment']) ? (float) $data['variantPriceAdjustment'] : 0.0;

if (!in_array($creationType, ['base', 'variant'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid creation type']);
    exit;
}

    if ($creationType === 'variant') {
        if ($baseProductId === '' || $variantName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Base product and variant name are required']);
        exit;
    }
    } else {
        if ($name === '' || $categoryId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name and category are required']);
            exit;
        }

    if ($costPrice === null || $wholesalePrice === null || $retailPrice === null) {
        http_response_code(400);
        echo json_encode(['error' => 'All prices are required']);
        exit;
    }

    if ($costPrice < 0 || $wholesalePrice < 0 || $retailPrice < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Prices must be zero or greater']);
        exit;
    }
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    if ($creationType === 'variant') {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$baseProductId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Selected base product does not exist');
        }

        $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE productId = ? AND LOWER(name) = LOWER(?)');
        $stmt->execute([$baseProductId, $variantName]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('This variant already exists for the selected base product');
        }

        $generatedVariantSku = generateVariantSku($pdo, $baseProductId, $variantName);

        $variantId = bin2hex(random_bytes(16));
        $variantStmt = $pdo->prepare(
            'INSERT INTO product_variants (id, productId, name, priceAdjustment, sku) VALUES (?, ?, ?, ?, ?)'
        );
        $variantStmt->execute([
            $variantId,
            $baseProductId,
            $variantName,
            $variantPriceAdjustment,
            $generatedVariantSku,
        ]);

        createInventoryLevel($pdo, $baseProductId, $variantId);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'type' => 'variant',
            'id' => $variantId,
            'productId' => $baseProductId,
            'sku' => $generatedVariantSku,
        ]);
        exit;
    }

    $generatedSku = generateCategorySku($pdo, $categoryId);

    if ($supplierId !== '') {
        $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
        $stmt->execute([$supplierId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Selected supplier does not exist');
        }
    } else {
        $supplierId = null;
    }

    $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
    $stmt->execute([$generatedSku]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('Unable to generate a unique SKU for this category');
    }

    $productId = bin2hex(random_bytes(16));
    $createdAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO products (id, sku, name, description, categoryId, supplierId, costPrice, wholesalePrice, retailPrice, images, isActive, createdAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $productId,
        $generatedSku,
        $name,
        $description !== '' ? $description : null,
        $categoryId,
        $supplierId,
        $costPrice,
        $wholesalePrice,
        $retailPrice,
        json_encode([]),
        $isActive ? 1 : 0,
        $createdAt,
    ]);

    $variantStmt = $pdo->prepare(
        'INSERT INTO product_variants (id, productId, name, priceAdjustment, sku) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($variants as $variant) {
        $newVariantName = trim((string) ($variant['name'] ?? ''));
        if ($newVariantName === '') {
            continue;
        }

        $variantId = bin2hex(random_bytes(16));
        $priceAdjustment = isset($variant['priceAdjustment']) ? (float) $variant['priceAdjustment'] : 0.0;
        $generatedVariantSku = generateVariantSku($pdo, $productId, $newVariantName);

        $variantStmt->execute([
            $variantId,
            $productId,
            $newVariantName,
            $priceAdjustment,
            $generatedVariantSku,
        ]);

        createInventoryLevel($pdo, $productId, $variantId);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'id' => $productId, 'sku' => $generatedSku]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
