<?php
/**
 * Health Check Endpoint
 * Verifies database connection and API availability
 */

require_once __DIR__ . '/middleware/cors.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

try {
    // Test database connection
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT 1 as status");
    $stmt->execute();
    $result = $stmt->fetch();

    // Get system info
    $response = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'database' => [
            'status' => 'connected',
            'test_query' => $result['status'] === 1
        ],
        'php_version' => PHP_VERSION,
        'server' => [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'unknown'
        ]
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'unhealthy',
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ]);
}
?>