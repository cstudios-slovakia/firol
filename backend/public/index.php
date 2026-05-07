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

use Firol\Controllers\AuthController;
use Firol\Controllers\CompanyController;
use Firol\Controllers\FacilityController;
use Firol\Controllers\MeController;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Http\Router;

$request = new Request();
$router  = new Router();

$router->get('/api/health', static function (): void {
    Response::json([
        'status' => 'ok',
        'php'    => PHP_VERSION,
        'time'   => date('c'),
    ]);
});

$router->post('/api/auth/register',                [AuthController::class, 'register']);
$router->post('/api/auth/login',                   [AuthController::class, 'login']);
$router->post('/api/auth/logout',                  [AuthController::class, 'logout']);
$router->post('/api/auth/password-reset/request', [AuthController::class, 'passwordResetRequest']);
$router->post('/api/auth/password-reset/confirm', [AuthController::class, 'passwordResetConfirm']);

$router->get('/api/me',                  [MeController::class, 'show']);
$router->post('/api/me/switch-account',  [MeController::class, 'switchAccount']);

$router->get('/api/companies',                      [CompanyController::class, 'index']);
$router->post('/api/companies',                     [CompanyController::class, 'store']);
$router->get('/api/companies/{id}',                 [CompanyController::class, 'show']);
$router->patch('/api/companies/{id}',               [CompanyController::class, 'update']);
$router->delete('/api/companies/{id}',              [CompanyController::class, 'archive']);
$router->post('/api/companies/{id}/facilities',     [FacilityController::class, 'storeUnderCompany']);

$router->get('/api/facilities/{id}',                [FacilityController::class, 'show']);
$router->patch('/api/facilities/{id}',              [FacilityController::class, 'update']);
$router->delete('/api/facilities/{id}',             [FacilityController::class, 'archive']);

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    error_log('[unhandled] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Internal Server Error', 500);
}
