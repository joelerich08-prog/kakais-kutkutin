<?php
/**
 * CORS Middleware for API endpoints
 * Handles Cross-Origin Resource Sharing headers and preflight requests
 */

// Get allowed origins from environment variable or use defaults
$defaultOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:3001',
    'http://127.0.0.1:3001',
];

$envOrigins = getenv('CORS_ALLOWED_ORIGINS');
if ($envOrigins) {
    $allowedOrigins = array_map('trim', explode(',', $envOrigins));
} else {
    $allowedOrigins = $defaultOrigins;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

// Enable session cookies for cross-site requests in local dev and supported environments.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
// For local dev (HTTP), use Lax. For production (HTTPS), use None to allow cross-origin cookies.
$sameSite = $secure ? 'None' : 'Lax';

// Determine the session cookie domain - use empty to match current host only for better compatibility
$cookieDomain = '';

ini_set('session.cookie_secure', $secure ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', $sameSite);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => $secure,
    'httponly' => true,
    'samesite' => $sameSite,
]);

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Cache-Bust');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

// Set content type for JSON responses
header('Content-Type: application/json');
