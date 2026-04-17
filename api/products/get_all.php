<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

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
            p.createdAt,
            c.name as categoryName,
            s.name as supplierName
        FROM products p
        LEFT JOIN categories c ON p.categoryId = c.id
        LEFT JOIN suppliers s ON p.supplierId = s.id
        WHERE p.isActive = 1
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
