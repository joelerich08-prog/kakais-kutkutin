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
$contactPerson = trim((string) ($data['contactPerson'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$address = trim((string) ($data['address'] ?? ''));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Company name is required']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(name) = LOWER(?)');
    $stmt->execute([$name]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'A supplier with this name already exists']);
        exit;
    }

    $supplierId = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        'INSERT INTO suppliers (id, name, contactPerson, phone, email, address, isActive) VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $supplierId,
        $name,
        $contactPerson !== '' ? $contactPerson : null,
        $phone !== '' ? $phone : null,
        $email !== '' ? $email : null,
        $address !== '' ? $address : null,
    ]);

    echo json_encode(['success' => true, 'id' => $supplierId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
