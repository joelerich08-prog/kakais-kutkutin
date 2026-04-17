<?php
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = Database::getInstance();

$defaultStoreSettings = [
    'name' => 'Sari-Sari Store',
    'address' => '123 Main Street, Barangay Centro',
    'city' => 'Manila',
    'postalCode' => '1000',
    'phone' => '+63 912 345 6789',
    'email' => 'store@example.com',
    'taxId' => '123-456-789-000',
    'currency' => 'PHP',
    'timezone' => 'Asia/Manila',
    'businessHours' => [
        'open' => '06:00',
        'close' => '22:00',
    ],
];

$defaultPOSSettings = [
    'quickAddMode' => true,
    'showProductImages' => true,
    'autoPrintReceipt' => false,
    'enableCashPayment' => true,
    'enableGCashPayment' => true,
];

try {
    $stmt = $pdo->query('SELECT * FROM store_settings LIMIT 1');
    $storeRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($storeRow) {
        $storeSettings = [
            'name' => $storeRow['name'],
            'address' => $storeRow['address'],
            'city' => $storeRow['city'],
            'postalCode' => $storeRow['postalCode'],
            'phone' => $storeRow['phone'],
            'email' => $storeRow['email'],
            'taxId' => $storeRow['taxId'],
            'currency' => $storeRow['currency'],
            'timezone' => $storeRow['timezone'],
            'businessHours' => [
                'open' => $storeRow['businessHoursOpen'],
                'close' => $storeRow['businessHoursClose'],
            ],
        ];
    } else {
        $storeSettings = $defaultStoreSettings;
    }

    $stmt = $pdo->query('SELECT * FROM pos_settings LIMIT 1');
    $posRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($posRow) {
        $posSettings = [
            'quickAddMode' => (bool)$posRow['quickAddMode'],
            'showProductImages' => (bool)$posRow['showProductImages'],
            'autoPrintReceipt' => (bool)$posRow['autoPrintReceipt'],
            'enableCashPayment' => (bool)$posRow['enableCashPayment'],
            'enableGCashPayment' => (bool)$posRow['enableGCashPayment'],
        ];
    } else {
        $posSettings = $defaultPOSSettings;
    }

    $permissions = [];
    $stmt = $pdo->query('SELECT role, module, action, allowed FROM role_permissions ORDER BY role, module, action');
    $rolePermRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rolePermRows as $perm) {
        $role = $perm['role'];
        $module = $perm['module'];
        $action = $perm['action'];

        if (!isset($permissions[$role])) {
            $permissions[$role] = [];
        }

        if (!isset($permissions[$role][$module])) {
            $permissions[$role][$module] = [
                'view' => false,
                'create' => false,
                'edit' => false,
                'delete' => false,
            ];
        }

        $permissions[$role][$module][$action] = (bool)$perm['allowed'];
    }

    $stmt = $pdo->query('SELECT id, name, type, connectionType, ipAddress, port, isDefault, status, paperSize, lastUsed FROM printer_devices');
    $printerDevices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'store' => $storeSettings,
        'pos' => $posSettings,
        'printers' => $printerDevices,
        'permissions' => (object) $permissions,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load settings', 'details' => $e->getMessage()]);
}
