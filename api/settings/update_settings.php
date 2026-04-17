<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$store = isset($data['store']) && is_array($data['store']) ? $data['store'] : null;
$pos = isset($data['pos']) && is_array($data['pos']) ? $data['pos'] : null;
$printers = isset($data['printers']) && is_array($data['printers']) ? $data['printers'] : null;
$permissions = isset($data['permissions']) && is_array($data['permissions']) ? $data['permissions'] : null;
$validRoles = ['admin', 'stockman', 'cashier'];
$validModules = ['dashboard', 'pos', 'inventory', 'products', 'suppliers', 'reports', 'users', 'settings'];
$validActions = ['view', 'create', 'edit', 'delete'];

$pdo = Database::getInstance();

try {
    $pdo->beginTransaction();

    if ($store !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO store_settings (id, name, address, city, postalCode, phone, email, taxId, currency, timezone, businessHoursOpen, businessHoursClose) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address), city = VALUES(city), postalCode = VALUES(postalCode), phone = VALUES(phone), email = VALUES(email), taxId = VALUES(taxId), currency = VALUES(currency), timezone = VALUES(timezone), businessHoursOpen = VALUES(businessHoursOpen), businessHoursClose = VALUES(businessHoursClose)'
        );
        $stmt->execute([
            'default',
            $store['name'] ?? '',
            $store['address'] ?? '',
            $store['city'] ?? '',
            $store['postalCode'] ?? '',
            $store['phone'] ?? '',
            $store['email'] ?? '',
            $store['taxId'] ?? '',
            $store['currency'] ?? '',
            $store['timezone'] ?? '',
            $store['businessHours']['open'] ?? '',
            $store['businessHours']['close'] ?? '',
        ]);
    }

    if ($pos !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO pos_settings (id, quickAddMode, showProductImages, autoPrintReceipt, enableCashPayment, enableGCashPayment) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quickAddMode = VALUES(quickAddMode), showProductImages = VALUES(showProductImages), autoPrintReceipt = VALUES(autoPrintReceipt), enableCashPayment = VALUES(enableCashPayment), enableGCashPayment = VALUES(enableGCashPayment)'
        );
        $stmt->execute([
            'default',
            $pos['quickAddMode'] ? 1 : 0,
            $pos['showProductImages'] ? 1 : 0,
            $pos['autoPrintReceipt'] ? 1 : 0,
            $pos['enableCashPayment'] ? 1 : 0,
            $pos['enableGCashPayment'] ? 1 : 0,
        ]);
    }

    if ($printers !== null) {
        $stmt = $pdo->prepare('DELETE FROM printer_devices');
        $stmt->execute();

        $insertPrinter = $pdo->prepare(
            'INSERT INTO printer_devices (id, name, type, connectionType, ipAddress, port, isDefault, status, paperSize, lastUsed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
        );

        foreach ($printers as $printer) {
            $insertPrinter->execute([
                $printer['id'] ?? bin2hex(random_bytes(8)),
                $printer['name'] ?? '',
                $printer['type'] ?? 'receipt',
                $printer['connectionType'] ?? 'usb',
                $printer['ipAddress'] ?? null,
                isset($printer['port']) ? (int)$printer['port'] : null,
                isset($printer['isDefault']) && $printer['isDefault'] ? 1 : 0,
                $printer['status'] ?? 'offline',
                $printer['paperSize'] ?? '80mm',
                isset($printer['lastUsed']) ? $printer['lastUsed'] : null,
            ]);
        }
    }

    if ($permissions !== null) {
        $deleteRoleStmt = $pdo->prepare('DELETE FROM role_permissions WHERE role = ?');
        $insertPermissionStmt = $pdo->prepare('INSERT INTO role_permissions (role, module, action, allowed) VALUES (?, ?, ?, ?)');

        foreach ($permissions as $role => $modules) {
            if (!is_string($role) || !in_array($role, $validRoles, true) || !is_array($modules)) {
                continue;
            }

            $deleteRoleStmt->execute([$role]);

            foreach ($modules as $module => $actions) {
                if (!is_string($module) || !in_array($module, $validModules, true) || !is_array($actions)) {
                    continue;
                }

                foreach ($actions as $action => $allowed) {
                    if (!is_string($action) || !in_array($action, $validActions, true)) {
                        continue;
                    }

                    $insertPermissionStmt->execute([
                        $role,
                        $module,
                        $action,
                        $allowed ? 1 : 0,
                    ]);
                }
            }
        }
    }

    $pdo->commit();

    // Reload permissions in session to reflect any changes
    $responseData = ['success' => true];
    if (isset($_SESSION['user_role'])) {
        $permissions = [];
        $stmt = $pdo->prepare("SELECT module, action, allowed FROM role_permissions WHERE role = ? ORDER BY module, action");
        $stmt->execute([$_SESSION['user_role']]);
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($perms as $perm) {
            $module = $perm['module'];
            $action = $perm['action'];
            $permissions[$module] = $permissions[$module] ?? [
                'view' => false,
                'create' => false,
                'edit' => false,
                'delete' => false,
            ];
            $permissions[$module][$action] = (bool)$perm['allowed'];
        }

        $_SESSION['permissions'] = $permissions;
        $responseData['permissions'] = $permissions;
    }

    echo json_encode($responseData);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save settings', 'details' => $e->getMessage()]);
}
?>