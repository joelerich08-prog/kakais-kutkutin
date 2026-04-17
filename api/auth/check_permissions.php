<?php

require_once __DIR__ . '/../middleware/cors.php';

function requirePermission(string $module, string $action): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['permissions']) || !isset($_SESSION['user_id'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden: authentication required']);
        exit;
    }

    $permissions = $_SESSION['permissions'];
    $allowed = false;

    if (isset($permissions[$module]) && isset($permissions[$module][$action])) {
        $allowed = (bool)$permissions[$module][$action];
    }

    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden: insufficient permissions']);
        exit;
    }
}

function hasPermission(string $module, string $action): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['permissions'])) {
        return false;
    }

    return isset($_SESSION['permissions'][$module][$action]) && $_SESSION['permissions'][$module][$action];
}
