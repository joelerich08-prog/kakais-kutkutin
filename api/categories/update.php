<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized - Admin access required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$categoryId = trim((string) ($data['id'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
$isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true;

if ($categoryId === '' || $name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Category id and name are required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Category not found']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ?');
    $stmt->execute([$name, $categoryId]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'A category with this name already exists']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE categories SET name = ?, description = ?, isActive = ? WHERE id = ?');
    $stmt->execute([$name, $description !== '' ? $description : null, $isActive ? 1 : 0, $categoryId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
