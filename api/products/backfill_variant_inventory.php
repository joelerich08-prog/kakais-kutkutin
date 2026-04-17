<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../auth/check_permissions.php';

session_start();

requirePermission('products', 'edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $productStmt = $pdo->query(
        'SELECT
            p.id AS productId,
            p.name AS productName
        FROM products p
        LEFT JOIN inventory_levels il
            ON il.productId = p.id
           AND il.variantId IS NULL
        WHERE il.id IS NULL
        ORDER BY p.name ASC
        FOR UPDATE'
    );

    $missingProducts = $productStmt->fetchAll(PDO::FETCH_ASSOC);

    $variantStmt = $pdo->query(
        'SELECT
            pv.id AS variantId,
            pv.productId,
            pv.name AS variantName,
            p.name AS productName
        FROM product_variants pv
        INNER JOIN products p ON p.id = pv.productId
        LEFT JOIN inventory_levels il
            ON il.productId = pv.productId
           AND il.variantId = pv.id
        WHERE il.id IS NULL
        ORDER BY p.name ASC, pv.name ASC
        FOR UPDATE'
    );

    $missingVariants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);
    $createdProducts = [];
    $createdVariants = [];

    foreach ($missingProducts as $product) {
        createInventoryLevel($pdo, $product['productId'], null);

        $createdProducts[] = [
            'productId' => $product['productId'],
            'productName' => $product['productName'],
        ];
    }

    foreach ($missingVariants as $variant) {
        createInventoryLevel($pdo, $variant['productId'], $variant['variantId']);

        $createdVariants[] = [
            'productId' => $variant['productId'],
            'productName' => $variant['productName'],
            'variantId' => $variant['variantId'],
            'variantName' => $variant['variantName'],
        ];
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => count($createdProducts) === 0 && count($createdVariants) === 0
            ? 'Everything is already up to date.'
            : 'Products were synced successfully.',
        'missingCount' => count($missingProducts) + count($missingVariants),
        'createdCount' => count($createdProducts) + count($createdVariants),
        'createdProductsCount' => count($createdProducts),
        'createdVariantsCount' => count($createdVariants),
        'createdProducts' => $createdProducts,
        'createdVariants' => $createdVariants,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
