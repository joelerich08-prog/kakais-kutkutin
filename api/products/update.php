<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../auth/check_permissions.php';

session_start();

requirePermission('products', 'edit');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$productId = trim((string) ($data['id'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
$categoryId = trim((string) ($data['categoryId'] ?? ''));
$costPrice = isset($data['costPrice']) ? (float) $data['costPrice'] : null;
$wholesalePrice = isset($data['wholesalePrice']) ? (float) $data['wholesalePrice'] : null;
$retailPrice = isset($data['retailPrice']) ? (float) $data['retailPrice'] : null;
$isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true;

if ($productId === '' || $name === '' || $categoryId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Product, name, and category are required']);
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

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Product not found');
    }

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Selected category does not exist');
    }

    $stmt = $pdo->prepare(
        'UPDATE products
         SET name = ?, description = ?, categoryId = ?, costPrice = ?, wholesalePrice = ?, retailPrice = ?, isActive = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        $description !== '' ? $description : null,
        $categoryId,
        $costPrice,
        $wholesalePrice,
        $retailPrice,
        $isActive ? 1 : 0,
        $productId,
    ]);

    $pdo->commit();

    echo json_encode(['success' => true]);
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
