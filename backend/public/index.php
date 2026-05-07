<?php

declare(strict_types=1);

/*
 * Front controller — entry point for HTTP requests to the PHP backend.
 * Local dev: served directly by nginx, web root = backend/public/.
 * Production: included from frontend/public/api.php (which ends up at
 * <docroot>/api.php after Vite build).
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

header('Content-Type: application/json; charset=utf-8');

// Prod transports the route via ?path=… because the docroot has no rewriter.
// Dev hits this script directly through nginx with the route in REQUEST_URI.
$path = $_GET['path']
    ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)
    ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/api/health' && $method === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'php' => PHP_VERSION,
        'time' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found', 'path' => $path], JSON_UNESCAPED_UNICODE);
