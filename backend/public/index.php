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

use Firol\Controllers\AccountController;
use Firol\Controllers\AdminController;
use Firol\Controllers\AdminPanelController;
use Firol\Controllers\AuthController;
use Firol\Controllers\BillingController;
use Firol\Controllers\CompanyController;
use Firol\Controllers\FacilityController;
use Firol\Controllers\DocumentController;
use Firol\Controllers\FeedbackController;
use Firol\Controllers\InspectionController;
use Firol\Controllers\InspectionItemController;
use Firol\Controllers\InspectorProfileController;
use Firol\Controllers\InviteController;
use Firol\Controllers\TeamController;
use Firol\Controllers\TraineeController;
use Firol\Controllers\TrainingController;
use Firol\Controllers\MeController;
use Firol\Controllers\PublicSettingsController;
use Firol\Auth\Session;
use Firol\Db;
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

$router->get('/api/public/settings',               [PublicSettingsController::class, 'show']);

$router->post('/api/auth/register',                [AuthController::class, 'register']);
$router->post('/api/auth/login',                   [AuthController::class, 'login']);
$router->post('/api/auth/logout',                  [AuthController::class, 'logout']);
$router->post('/api/auth/password-reset/request', [AuthController::class, 'passwordResetRequest']);
$router->post('/api/auth/password-reset/confirm', [AuthController::class, 'passwordResetConfirm']);

$router->get('/api/me',                  [MeController::class, 'show']);
$router->post('/api/me/switch-account',  [MeController::class, 'switchAccount']);

$router->get('/api/account',             [AccountController::class, 'show']);
$router->patch('/api/account',           [AccountController::class, 'update']);
$router->post('/api/account/logo',       [AccountController::class, 'uploadLogo']);
$router->delete('/api/account/logo',     [AccountController::class, 'deleteLogo']);
$router->get('/api/account/logo',        [AccountController::class, 'downloadLogo']);

$router->get('/api/account/users',        [TeamController::class, 'index']);
$router->post('/api/account/users',       [TeamController::class, 'invite']);
$router->patch('/api/account/users/{id}', [TeamController::class, 'update']);
$router->delete('/api/account/users/{id}',[TeamController::class, 'destroy']);
$router->post('/api/account/team-defaults', [TeamController::class, 'setDefault']);

$router->get('/api/account/invites',           [TeamController::class, 'indexInvites']);
$router->delete('/api/account/invites/{id}',   [TeamController::class, 'cancelInvite']);

// Public invite-acceptance endpoints — token possession is the proof
// of intent. Existing-user accept additionally requires a matching
// session (enforced inside the controller).
$router->get('/api/invites/{token}',           [InviteController::class, 'show']);
$router->post('/api/invites/{token}/accept',   [InviteController::class, 'accept']);
$router->post('/api/invites/{token}/decline',  [InviteController::class, 'decline']);

$router->get('/api/admin/settings', [AdminController::class, 'settings']);
$router->patch('/api/admin/settings', [AdminController::class, 'updateSettings']);

$router->post('/api/feedback',              [FeedbackController::class, 'store']);
$router->get('/api/admin/feedback',         [FeedbackController::class, 'index']);
$router->delete('/api/admin/feedback/{id}', [FeedbackController::class, 'destroy']);

$router->get('/api/admin/accounts',         [AdminPanelController::class, 'listAccounts']);
$router->patch('/api/admin/accounts/{id}',  [AdminPanelController::class, 'updateAccount']);
$router->delete('/api/admin/accounts/{id}', [AdminPanelController::class, 'deleteAccount']);
$router->patch('/api/admin/users/{id}',     [AdminPanelController::class, 'updateUser']);
$router->delete('/api/admin/users/{id}',    [AdminPanelController::class, 'deleteUser']);

$router->post('/api/billing/checkout',      [BillingController::class, 'checkout']);
$router->post('/api/billing/checkout-sync', [BillingController::class, 'checkoutSync']);
$router->post('/api/billing/portal',        [BillingController::class, 'portal']);
$router->post('/api/billing/webhook',       [BillingController::class, 'webhook']);
$router->post('/api/billing/cancel',        [BillingController::class, 'cancel']);
$router->post('/api/billing/resume',        [BillingController::class, 'resume']);
$router->get('/api/billing/invoices',       [BillingController::class, 'invoices']);

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
$router->post('/api/documents/{id}/email',           [DocumentController::class, 'emailDocument']);

$router->get('/api/me/inspector-profile',            [InspectorProfileController::class, 'show']);
$router->patch('/api/me/inspector-profile',          [InspectorProfileController::class, 'update']);
$router->post('/api/me/inspector-profile/signature', [InspectorProfileController::class, 'uploadSignature']);
$router->get('/api/me/inspector-profile/signature',  [InspectorProfileController::class, 'downloadSignature']);

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

/*
 * Phase 6a — read-only mode for expired subscriptions.
 *
 * Once accounts.subscription_end_date is in the past the whole tenant
 * goes read-only: the user can browse and download existing PDFs but
 * cannot create or mutate anything. Anchored at the dispatcher so
 * controllers don't each need to remember to call a guard.
 *
 * Whitelist:
 * - GET / HEAD             — reads are always allowed
 * - /api/auth/*            — must be able to log in/out + reset password
 * - /api/me/switch-account — must be able to escape to another tenant
 * - /api/billing/*         — Phase 6b: paying must always work
 * - DELETE on the session  — logout (covered by /api/auth/logout above)
 */
$method = $request->method();
$path   = rtrim($request->path(), '/') ?: '/';
$isMutation = !in_array($method, ['GET', 'HEAD'], true);
$isWhitelisted = (bool) preg_match(
    '#^/api/(auth/|me/switch-account|billing/|admin/|feedback|invites/)#',
    $path,
);
if ($isMutation && !$isWhitelisted) {
    $sessionUserId    = Session::userId();
    $sessionAccountId = Session::activeAccountId();
    // App admins (agency + client) have free, unrestricted access — they
    // never go through Stripe and shouldn't be hit by the 402 guard.
    $isAppAdmin = $sessionUserId !== null && \Firol\Auth\Admin::isAdmin($sessionUserId);
    if ($sessionUserId !== null && $sessionAccountId !== null && !$isAppAdmin) {
        // Admin-owned accounts (main_user is an app admin) get free,
        // unrestricted access for *everyone* on the account — technicians
        // invited by an admin inherit the same paid-like behavior.
        $isAdminOwned = \Firol\Auth\Admin::accountIsAdminOwned($sessionAccountId);
        if (!$isAdminOwned) {
            $stmt = Db::pdo()->prepare(
                'SELECT subscription_end_date FROM accounts WHERE id = ?'
            );
            $stmt->execute([$sessionAccountId]);
            $endDate = $stmt->fetchColumn();
            if (is_string($endDate) && strtotime($endDate) < strtotime('today')) {
                Response::error(
                    'Predplatné vypršalo. Účet je v režime len na čítanie.',
                    402,
                    ['subscription_end_date' => $endDate],
                );
            }
        }
    }
}

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    error_log('[unhandled] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Internal Server Error', 500);
}
