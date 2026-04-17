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

$name = trim((string) ($data['name'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Category name is required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?)');
    $stmt->execute([$name]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'A category with this name already exists']);
        exit;
    }

    $categoryId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO categories (id, name, description, isActive) VALUES (?, ?, ?, 1)');
    $stmt->execute([$categoryId, $name, $description !== '' ? $description : null]);

    echo json_encode(['success' => true, 'id' => $categoryId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
