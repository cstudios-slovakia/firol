<?php

declare(strict_types=1);

/*
 * Front controller — vstupný bod pre všetky HTTP požiadavky.
 * Lokálny dev: webroot je backend/public/, autoload je v ../vendor/.
 * Produkcia (Websupport shared hosting): index.php aj vendor/ skončia v jednom
 * adresári (cstudios.ninja/sub/firol/), preto skúšame oba relatívne paths.
 */

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

// .env (rovnaká logika — koreň projektu vs. webroot na prod)
$envCandidates = [
    dirname(__DIR__),
    __DIR__,
];

foreach ($envCandidates as $dir) {
    if (is_file($dir . '/.env')) {
        \Dotenv\Dotenv::createImmutable($dir)->safeLoad();
        break;
    }
}

header('Content-Type: application/json; charset=utf-8');

if (isset($_SERVER['PATH_INFO'])) {
    $path = $_SERVER['PATH_INFO'];
} else {
    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Strip script path prefix (e.g. /sub/firol/index.php → remove that prefix).
    $path = $script && str_starts_with($uri, $script)
        ? (substr($uri, strlen($script)) ?: '/')
        : $uri;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Triviálny "router" — nahradí sa neskôr proper routerom.
if ($path === '/api/health' && $method === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'php' => PHP_VERSION,
        'time' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode([
    'error' => 'Not Found',
    'path' => $path,
    'method' => $method,
    'debug' => [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null,
        'PATH_INFO' => $_SERVER['PATH_INFO'] ?? null,
        'PHP_SELF' => $_SERVER['PHP_SELF'] ?? null,
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? null,
    ],
], JSON_UNESCAPED_UNICODE);
