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
use Firol\Controllers\DocumentController;
use Firol\Controllers\InspectionController;
use Firol\Controllers\InspectionItemController;
use Firol\Controllers\InspectorProfileController;
use Firol\Controllers\TraineeController;
use Firol\Controllers\TrainerController;
use Firol\Controllers\TrainingController;
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

$router->get('/api/inspections',                    [InspectionController::class, 'index']);
$router->post('/api/inspections',                   [InspectionController::class, 'store']);
$router->get('/api/inspections/{id}',               [InspectionController::class, 'show']);
$router->patch('/api/inspections/{id}',             [InspectionController::class, 'updateBasic']);
$router->delete('/api/inspections/{id}',            [InspectionController::class, 'archive']);
$router->post('/api/inspections/{id}/repeat',       [InspectionController::class, 'repeat']);
$router->post('/api/inspections/{id}/items',        [InspectionItemController::class, 'store']);
$router->patch('/api/inspections/{id}/items/{item_id}',  [InspectionItemController::class, 'update']);
$router->delete('/api/inspections/{id}/items/{item_id}', [InspectionItemController::class, 'destroy']);
$router->post('/api/inspections/{id}/generate-pdf',  [DocumentController::class, 'generateForInspection']);
$router->get('/api/inspections/{id}/documents',      [DocumentController::class, 'indexForInspection']);
$router->get('/api/documents/{id}/download',         [DocumentController::class, 'download']);

$router->get('/api/me/inspector-profile',            [InspectorProfileController::class, 'show']);
$router->patch('/api/me/inspector-profile',          [InspectorProfileController::class, 'update']);
$router->post('/api/me/inspector-profile/signature', [InspectorProfileController::class, 'uploadSignature']);
$router->get('/api/me/inspector-profile/signature',  [InspectorProfileController::class, 'downloadSignature']);

$router->get('/api/trainers',                       [TrainerController::class, 'index']);
$router->post('/api/trainers',                      [TrainerController::class, 'store']);
$router->get('/api/trainers/{id}',                  [TrainerController::class, 'show']);
$router->patch('/api/trainers/{id}',                [TrainerController::class, 'update']);
$router->delete('/api/trainers/{id}',               [TrainerController::class, 'archive']);
$router->post('/api/trainers/{id}/signature',       [TrainerController::class, 'uploadSignature']);
$router->get('/api/trainers/{id}/signature',        [TrainerController::class, 'downloadSignature']);

$router->get('/api/trainings',                      [TrainingController::class, 'index']);
$router->post('/api/trainings',                     [TrainingController::class, 'store']);
$router->get('/api/trainings/{id}',                 [TrainingController::class, 'show']);
$router->patch('/api/trainings/{id}',               [TrainingController::class, 'update']);
$router->delete('/api/trainings/{id}',              [TrainingController::class, 'archive']);
$router->post('/api/trainings/{id}/trainees',       [TraineeController::class, 'store']);
$router->delete('/api/trainings/{id}/trainees/{trainee_id}', [TraineeController::class, 'destroy']);
$router->get('/api/trainees/{id}/signature',        [TraineeController::class, 'downloadSignature']);
$router->post('/api/trainings/{id}/generate-pdf',   [DocumentController::class, 'generateForTraining']);
$router->get('/api/trainings/{id}/documents',       [DocumentController::class, 'indexForTraining']);

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    error_log('[unhandled] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Internal Server Error', 500);
}
