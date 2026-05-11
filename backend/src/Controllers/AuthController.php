<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Password;
use Firol\Auth\Session;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Stripe\StripeClient;
use PDO;

final class AuthController
{
    public static function register(Request $req): void
    {
        $fullname            = $req->jsonString('fullname');
        $email               = $req->jsonString('email');
        $phone               = $req->jsonString('phone');
        $password            = $req->jsonString('password');
        $invoiceCompanyName  = $req->jsonString('invoice_company_name');
        $billingPeriod       = $req->jsonString('billing_period');

        if ($fullname === null || $fullname === '')           { Response::error('Field required: fullname',             422); }
        if ($email === null || !self::isValidEmail($email))   { Response::error('Field required or invalid: email',     422); }
        if ($password === null || !Password::isStrongEnough($password)) {
            Response::error('Password must be at least ' . Password::MIN_LENGTH . ' characters', 422);
        }
        if ($invoiceCompanyName === null || $invoiceCompanyName === '') {
            Response::error('Field required: invoice_company_name', 422);
        }
        if ($billingPeriod !== 'monthly' && $billingPeriod !== 'yearly') {
            Response::error('billing_period must be "monthly" or "yearly"', 422);
        }

        $pdo = Db::pdo();

        // Email must be unique across the whole system.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() !== false) {
            Response::error('Email already registered', 409);
        }

        $trialDays = self::settingInt($pdo, 'trial_days', 14);
        $trialEnd  = (new \DateTimeImmutable('today'))->modify("+{$trialDays} days")->format('Y-m-d');

        $pdo->beginTransaction();
        try {
            $insertUser = $pdo->prepare(
                'INSERT INTO users (fullname, email, phone, password_hash) VALUES (?, ?, ?, ?)'
            );
            $insertUser->execute([$fullname, $email, $phone, Password::hash($password)]);
            $userId = (int) $pdo->lastInsertId();

            $insertAccount = $pdo->prepare(
                'INSERT INTO accounts (invoice_company_name, subscription_end_date, main_user_id, billing_period)
                 VALUES (?, ?, ?, ?)'
            );
            $insertAccount->execute([$invoiceCompanyName, $trialEnd, $userId, $billingPeriod]);
            $accountId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO account_users (account_id, user_id, role, is_active) VALUES (?, ?, ?, 1)'
            )->execute([$accountId, $userId, 'main']);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Session::setUserId($userId);
        Session::setActiveAccountId($accountId);

        // Best-effort: provision a Stripe Customer up front so the first
        // Checkout call doesn't need to do it inline. If Stripe is down
        // or misconfigured we still want the account to exist — the
        // first /api/billing/checkout call will retry via ensureCustomer.
        try {
            $customer = StripeClient::get()->customers->create([
                'email'    => $email,
                'name'     => $invoiceCompanyName,
                'metadata' => [
                    'firol_account_id' => (string) $accountId,
                    'firol_user_name'  => $fullname,
                ],
            ]);
            $pdo->prepare('UPDATE accounts SET stripe_customer_id = ? WHERE id = ?')
                ->execute([$customer->id, $accountId]);
        } catch (\Throwable $e) {
            error_log("[register] stripe customer create failed: " . $e->getMessage());
        }

        error_log("[register] user_id={$userId} account_id={$accountId} billing_period={$billingPeriod}");

        Response::json(self::meSnapshot($pdo, $userId, $accountId), 201);
    }

    public static function login(Request $req): void
    {
        $email    = $req->jsonString('email');
        $password = $req->jsonString('password');

        if ($email === null || $password === null) {
            Response::error('Email and password required', 422);
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !Password::verify($password, (string) $user['password_hash'])) {
            Response::error('Invalid email or password', 401);
        }

        $userId = (int) $user['id'];

        // Pick an active account: first row from account_users.
        $accStmt = $pdo->prepare(
            'SELECT account_id FROM account_users WHERE user_id = ? AND is_active = 1
             ORDER BY account_id ASC LIMIT 1'
        );
        $accStmt->execute([$userId]);
        $accountId = $accStmt->fetchColumn();

        if ($accountId === false) {
            Response::error('User has no active account', 403);
        }

        Session::setUserId($userId);
        Session::setActiveAccountId((int) $accountId);

        Response::json(self::meSnapshot($pdo, $userId, (int) $accountId));
    }

    public static function logout(Request $req): void
    {
        Csrf::require($req);
        Session::destroy();
        Response::noContent();
    }

    public static function passwordResetRequest(Request $req): void
    {
        $email = $req->jsonString('email');
        if ($email === null || !self::isValidEmail($email)) {
            Response::error('Valid email required', 422);
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId !== false) {
            $token = bin2hex(random_bytes(32));
            $expires = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

            $pdo->prepare(
                'INSERT INTO password_resets (token, user_id, expires_at) VALUES (?, ?, ?)'
            )->execute([$token, $userId, $expires]);

            // Real email transport (Mailgun/SES/SMTP) is Phase 7. For now we
            // log the link so devs can copy it from the container logs.
            error_log("[password-reset] email={$email} token={$token} expires={$expires}");
        }

        // Always respond 204 — don't leak whether the email exists.
        Response::noContent();
    }

    public static function passwordResetConfirm(Request $req): void
    {
        $token       = $req->jsonString('token');
        $newPassword = $req->jsonString('password');

        if ($token === null || $token === '') {
            Response::error('Token required', 422);
        }
        if ($newPassword === null || !Password::isStrongEnough($newPassword)) {
            Response::error('Password must be at least ' . Password::MIN_LENGTH . ' characters', 422);
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT user_id, expires_at, used_at FROM password_resets WHERE token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || $row['used_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
            Response::error('Token invalid or expired', 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([Password::hash($newPassword), $row['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token = ?')
                ->execute([$token]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::noContent();
    }

    private static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function settingInt(PDO $pdo, string $key, int $default): int
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }

    /**
     * Builds the same payload as `GET /api/me`. Kept here so the auth
     * endpoints can return it directly after register/login without
     * re-routing to MeController.
     *
     * @return array<string, mixed>
     */
    public static function meSnapshot(PDO $pdo, int $userId, int $accountId): array
    {
        $userStmt = $pdo->prepare('SELECT id, fullname, email, phone FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();

        $accStmt = $pdo->prepare(
            'SELECT a.id, a.invoice_company_name, a.subscription_end_date, a.main_user_id,
                    a.stripe_status, a.billing_period, a.stripe_customer_id
             FROM   accounts a
             JOIN   account_users au ON au.account_id = a.id
             WHERE  au.user_id = ? AND au.is_active = 1
             ORDER  BY a.id ASC'
        );
        $accStmt->execute([$userId]);
        $accounts = $accStmt->fetchAll();

        return [
            'user'            => $user ?: null,
            'accounts'        => $accounts,
            'activeAccountId' => $accountId,
            'csrfToken'       => Csrf::token(),
        ];
    }
}
