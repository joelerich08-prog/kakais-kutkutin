<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized - Admin access required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.sku,
            p.name,
            p.description,
            p.categoryId,
            p.supplierId,
            p.costPrice,
            p.wholesalePrice,
            p.retailPrice,
            p.images,
            p.isActive,
            p.createdAt
        FROM products p
        ORDER BY p.name ASC
    ");

    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $variantsByProductId = [];
    $variantStmt = $pdo->query("
        SELECT
            id,
            productId,
            name,
            priceAdjustment,
            sku
        FROM product_variants
        ORDER BY name ASC
    ");

    foreach ($variantStmt->fetchAll(PDO::FETCH_ASSOC) as $variant) {
        $productId = $variant['productId'];
        if (!isset($variantsByProductId[$productId])) {
            $variantsByProductId[$productId] = [];
        }

        $variantsByProductId[$productId][] = [
            'id' => $variant['id'],
            'name' => $variant['name'],
            'priceAdjustment' => (float) $variant['priceAdjustment'],
            'sku' => $variant['sku'],
        ];
    }

    foreach ($products as &$product) {
        $product['images'] = json_decode($product['images'] ?? '[]', true);
        $product['createdAt'] = date('c', strtotime($product['createdAt']));
        $product['isActive'] = (bool) $product['isActive'];
        $product['variants'] = $variantsByProductId[$product['id']] ?? [];
    }

    echo json_encode($products);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
