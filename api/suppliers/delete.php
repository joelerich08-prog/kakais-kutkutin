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
$supplierId = trim((string) (($data['id'] ?? '') ?: ($_POST['id'] ?? '')));

if ($supplierId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Supplier id is required']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE supplierId = ?');
    $stmt->execute([$supplierId]);
    if ((int) $stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot delete a supplier that is still assigned to products']);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = ?');
    $stmt->execute([$supplierId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Supplier not found']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
