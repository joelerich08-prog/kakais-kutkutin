<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/check_permissions.php';

function buildVariantSkuBase(string $productSku, string $variantName): string
{
    $variantPart = strtoupper(preg_replace('/[^A-Z0-9]/', '', $variantName) ?? '');
    $variantPart = substr($variantPart !== '' ? $variantPart : 'VARIANT', 0, 6);

    return strtoupper($productSku) . '-' . $variantPart;
}

function generateVariantSku(PDO $pdo, string $productId, string $variantName, string $excludeVariantId): string
{
    $stmt = $pdo->prepare('SELECT sku FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $productSku = $stmt->fetchColumn();

    if (!$productSku) {
        throw new RuntimeException('Base product not found');
    }

    $baseSku = buildVariantSkuBase((string) $productSku, $variantName);
    $candidate = $baseSku;
    $suffix = 1;

    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE sku = ? AND id <> ?');
        $stmt->execute([$candidate, $excludeVariantId]);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $suffix += 1;
        $candidate = $baseSku . '-' . $suffix;
    }
}

session_start();

requirePermission('products', 'edit');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$variantId = trim((string) ($data['id'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));
$priceAdjustment = isset($data['priceAdjustment']) ? (float) $data['priceAdjustment'] : 0.0;

if ($variantId === '' || $name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Variant id and name are required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, productId FROM product_variants WHERE id = ? FOR UPDATE');
    $stmt->execute([$variantId]);
    $variant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$variant) {
        throw new RuntimeException('Variant not found');
    }

    $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE productId = ? AND LOWER(name) = LOWER(?) AND id <> ?');
    $stmt->execute([$variant['productId'], $name, $variantId]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('This variant name already exists for the selected product');
    }

    $generatedSku = generateVariantSku($pdo, $variant['productId'], $name, $variantId);

    $stmt = $pdo->prepare('UPDATE product_variants SET name = ?, priceAdjustment = ?, sku = ? WHERE id = ?');
    $stmt->execute([
        $name,
        $priceAdjustment,
        $generatedSku,
        $variantId,
    ]);

    // Keep the variant inventory metadata timestamp in sync with variant updates
    $stmt = $pdo->prepare('UPDATE inventory_levels SET updatedAt = NOW() WHERE productId = ? AND variantId = ?');
    $stmt->execute([
        $variant['productId'],
        $variantId,
    ]);

    $pdo->commit();

    echo json_encode(['success' => true, 'sku' => $generatedSku]);
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
