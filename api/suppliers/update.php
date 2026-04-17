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

$supplierId = trim((string) ($data['id'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));
$contactPerson = trim((string) ($data['contactPerson'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$address = trim((string) ($data['address'] ?? ''));
$isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true;

if ($supplierId === '' || $name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Supplier id and company name are required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
    $stmt->execute([$supplierId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Supplier not found']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(name) = LOWER(?) AND id <> ?');
    $stmt->execute([$name, $supplierId]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'A supplier with this name already exists']);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE suppliers
         SET name = ?, contactPerson = ?, phone = ?, email = ?, address = ?, isActive = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        $contactPerson !== '' ? $contactPerson : null,
        $phone !== '' ? $phone : null,
        $email !== '' ? $email : null,
        $address !== '' ? $address : null,
        $isActive ? 1 : 0,
        $supplierId,
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
