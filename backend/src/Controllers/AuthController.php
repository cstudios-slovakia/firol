<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Password;
use Firol\Auth\RateLimit;
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
        if ($billingPeriod !== 'monthly' && $billingPeriod !== 'yearly' && $billingPeriod !== 'trial') {
            Response::error('billing_period must be "trial", "monthly" or "yearly"', 422);
        }

        $pdo = Db::pdo();

        // Email must be unique across the whole system — except for "pending"
        // placeholders pre-created by an inspection import. Those represent a
        // technician who was attributed work before they had an account; we
        // let the real person claim the row here instead of rejecting them.
        $stmt = $pdo->prepare('SELECT id, is_pending FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();
        $claimUserId  = null;
        if ($existingUser !== false) {
            if ((int) $existingUser['is_pending'] !== 1) {
                Response::error('Email already registered', 409);
            }
            $claimUserId = (int) $existingUser['id'];
        }

        // Trial path: grant trial_days of free access. Paid path: no trial —
        // subscription_end_date lands on today, the account is in the
        // forced-paywall state and the frontend routes the user through
        // /onboarding/billing → Stripe before letting them into the app.
        $trialDays = $billingPeriod === 'trial'
            ? self::settingInt($pdo, 'trial_days', 14)
            : 0;
        $trialEnd  = (new \DateTimeImmutable('today'))->modify("+{$trialDays} days")->format('Y-m-d');

        // Persist the chosen plan only when the user committed to one.
        // Trial users decide later in Settings.
        $storedBillingPeriod = $billingPeriod === 'trial' ? null : $billingPeriod;

        $pdo->beginTransaction();
        try {
            if ($claimUserId !== null) {
                // Claim the import-created placeholder: set the real name,
                // phone and password and drop the pending flag in place so
                // every inspection already pointing at this user id stays
                // attributed to them.
                $pdo->prepare(
                    'UPDATE users SET fullname = ?, phone = ?, password_hash = ?, is_pending = 0
                     WHERE id = ?'
                )->execute([$fullname, $phone, Password::hash($password), $claimUserId]);
                $userId = $claimUserId;
            } else {
                $insertUser = $pdo->prepare(
                    'INSERT INTO users (fullname, email, phone, password_hash) VALUES (?, ?, ?, ?)'
                );
                $insertUser->execute([$fullname, $email, $phone, Password::hash($password)]);
                $userId = (int) $pdo->lastInsertId();
            }

            // Seed `included_technicians` from the current admin default so
            // the account picks up the global setting at registration time
            // (later changes to the default don't retroactively touch
            // existing accounts — admins override per-account if needed).
            $defaultIncluded = self::settingInt($pdo, 'default_included_technicians', 2);
            $insertAccount = $pdo->prepare(
                'INSERT INTO accounts (invoice_company_name, subscription_end_date, main_user_id, billing_period, included_technicians)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertAccount->execute([$invoiceCompanyName, $trialEnd, $userId, $storedBillingPeriod, $defaultIncluded]);
            $accountId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO account_users (account_id, user_id, role, is_active) VALUES (?, ?, ?, 1)'
            )->execute([$accountId, $userId, 'main']);

            // A claimed placeholder may already be linked (inactive) to the
            // account(s) that imported their inspections. Activate those links
            // now so they become a working technician there; collect the ids
            // to re-sync seat billing once the transaction commits.
            $reactivatedAccountIds = [];
            if ($claimUserId !== null) {
                $sel = $pdo->prepare(
                    'SELECT account_id FROM account_users WHERE user_id = ? AND is_active = 0'
                );
                $sel->execute([$userId]);
                $reactivatedAccountIds = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
                if ($reactivatedAccountIds !== []) {
                    $pdo->prepare(
                        'UPDATE account_users SET is_active = 1 WHERE user_id = ? AND is_active = 0'
                    )->execute([$userId]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($reactivatedAccountIds as $reactivatedAccountId) {
            \Firol\Billing\SeatSync::recompute($reactivatedAccountId);
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

        // Rate-limit by IP + email separately so a single attacker IP can't
        // burn through every account, and a single victim email can't be
        // brute-forced from many IPs.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        RateLimit::hit('login:ip:' . $ip);
        RateLimit::hit('login:email:' . strtolower(trim($email)));

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !Password::verify($password, (string) $user['password_hash'])) {
            Response::error('Invalid email or password', 401);
        }

        // Drop both counters now that we know the credentials matched.
        RateLimit::clear('login:ip:' . $ip);
        RateLimit::clear('login:email:' . strtolower(trim($email)));

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

            \Firol\Mail\Mailer::send(
                \Firol\Mail\Templates\PasswordResetEmail::build($email, $token)
            );
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
                    a.stripe_status, a.stripe_subscription_id, a.stripe_cancel_at_period_end,
                    a.billing_period, a.stripe_customer_id,
                    a.invoice_street, a.invoice_postal_code, a.invoice_city, a.invoice_ico
             FROM   accounts a
             JOIN   account_users au ON au.account_id = a.id
             WHERE  au.user_id = ? AND au.is_active = 1
             ORDER  BY a.id ASC'
        );
        $accStmt->execute([$userId]);
        $accountsRaw = $accStmt->fetchAll();

        // Surface derived flags so the frontend can gate UI without shipping
        // raw billing internals. `subscription_state` collapses the various
        // Stripe states into the three buckets the UI actually distinguishes:
        // no subscription (just free trial), trial with a paid sub waiting,
        // or an already-active paid subscription.
        $accounts = array_map(static function (array $a): array {
            $a['has_billing_details'] =
                trim((string) ($a['invoice_street']      ?? '')) !== '' &&
                trim((string) ($a['invoice_postal_code'] ?? '')) !== '' &&
                trim((string) ($a['invoice_city']        ?? '')) !== '' &&
                trim((string) ($a['invoice_ico']         ?? ''));
            unset($a['invoice_street'], $a['invoice_postal_code'], $a['invoice_city'], $a['invoice_ico']);

            $status = $a['stripe_status'] ?? null;
            $subId  = $a['stripe_subscription_id'] ?? null;
            $a['subscription_state'] = match (true) {
                $status === 'active'     => 'active',
                $status === 'trialing'   => 'trial_paid',
                $status === 'past_due'   => 'past_due',
                $status === 'canceled'   => 'canceled',
                $status === 'incomplete' || $status === 'incomplete_expired' => 'incomplete',
                $subId !== null && $subId !== ''                              => 'has_subscription',
                default                                                       => 'none',
            };
            // Don't leak the raw subscription id — UI doesn't need it.
            $a['stripe_cancel_at_period_end'] = (bool) ($a['stripe_cancel_at_period_end'] ?? false);
            // Admin-owned accounts behave as fully paid for every member.
            // Surface the flag so the UI can hide the trial/expiry banners
            // even when subscription_end_date is in the past.
            $a['admin_owned'] = \Firol\Auth\Admin::isAdmin((int) ($a['main_user_id'] ?? 0));
            unset($a['stripe_subscription_id']);
            return $a;
        }, $accountsRaw);

        return [
            'user'            => $user ?: null,
            'accounts'        => $accounts,
            'activeAccountId' => $accountId,
            'csrfToken'       => Csrf::token(),
            'isAdmin'         => \Firol\Auth\Admin::isAdmin($userId),
        ];
    }
}
