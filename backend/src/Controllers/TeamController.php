<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Password;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use PDO;

/**
 * Team management — the technicians attached to the active account.
 *
 * Permission model: only the account's main user can mutate the roster.
 * Everyone attached to the account can list members so the account
 * switcher / inspector dropdowns elsewhere have something to render.
 *
 * Invitations reuse the existing password_resets table — the same flow
 * we use for "I forgot my password" doubles as "set your initial
 * password" for newly invited technicians. Real email transport is
 * Phase 7; for now we log the link via error_log() so the inviter can
 * fish it out of the container logs and forward it manually.
 */
final class TeamController
{
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();

        $stmt = Db::pdo()->prepare(
            'SELECT u.id, u.fullname, u.email, u.phone,
                    au.role, au.is_active, au.created_at,
                    a.main_user_id = u.id AS is_main
             FROM   account_users au
             JOIN   users    u ON u.id = au.user_id
             JOIN   accounts a ON a.id = au.account_id
             WHERE  au.account_id = ?
             ORDER  BY a.main_user_id = u.id DESC, au.is_active DESC, u.fullname ASC'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();

        $items = array_map(static fn(array $r) => [
            'id'         => (int) $r['id'],
            'fullname'   => (string) $r['fullname'],
            'email'      => (string) $r['email'],
            'phone'      => $r['phone'],
            'role'       => (string) $r['role'],
            'is_active'  => (bool) $r['is_active'],
            'is_main'    => (bool) $r['is_main'],
            'created_at' => $r['created_at'],
        ], $rows);

        Response::json(['items' => $items]);
    }

    /**
     * Invite a technician by email. If the email already exists in the
     * users table we just attach that user — they keep their existing
     * password. Otherwise we create a stub user with an unusable password
     * hash and issue a password-reset token so they can set one.
     */
    public static function invite(Request $req): void
    {
        Csrf::require($req);
        self::requireMainUser();
        $accountId = Tenant::currentAccountId();

        $fullname = $req->jsonString('fullname');
        $email    = $req->jsonString('email');
        $phone    = $req->jsonString('phone');

        if ($fullname === null || trim($fullname) === '') {
            Response::error('Field required: fullname', 422);
        }
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error('Valid email required', 422);
        }
        $email = strtolower(trim($email));

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $userId = $stmt->fetchColumn();

            $invitedNew = false;
            if ($userId === false) {
                // Random 64-char password the invitee can never guess —
                // they must go through the reset link to set their own.
                $stub = bin2hex(random_bytes(32));
                $pdo->prepare(
                    'INSERT INTO users (fullname, email, phone, password_hash) VALUES (?, ?, ?, ?)'
                )->execute([trim($fullname), $email, $phone, Password::hash($stub)]);
                $userId = (int) $pdo->lastInsertId();
                $invitedNew = true;
            } else {
                $userId = (int) $userId;
            }

            // Re-attach gracefully: if the user was previously deactivated
            // on this account, flip them back to active instead of
            // refusing the invite.
            $linkStmt = $pdo->prepare(
                'SELECT is_active FROM account_users WHERE account_id = ? AND user_id = ?'
            );
            $linkStmt->execute([$accountId, $userId]);
            $existing = $linkStmt->fetchColumn();

            if ($existing === false) {
                $pdo->prepare(
                    'INSERT INTO account_users (account_id, user_id, role, is_active)
                     VALUES (?, ?, ?, 1)'
                )->execute([$accountId, $userId, 'technician']);
            } elseif ((int) $existing === 1) {
                $pdo->rollBack();
                Response::error('User already on this account', 409);
            } else {
                $pdo->prepare(
                    'UPDATE account_users SET is_active = 1
                     WHERE  account_id = ? AND user_id = ?'
                )->execute([$accountId, $userId]);
            }

            // Issue an invite token only when the user is fresh — an
            // existing user already knows their password.
            $token = null;
            if ($invitedNew) {
                $token   = bin2hex(random_bytes(32));
                $expires = (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
                $pdo->prepare(
                    'INSERT INTO password_resets (token, user_id, expires_at) VALUES (?, ?, ?)'
                )->execute([$token, $userId, $expires]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($token !== null) {
            $ctx = $pdo->prepare(
                'SELECT u.fullname AS inviter_name, a.invoice_company_name AS account_name
                 FROM   users u, accounts a
                 WHERE  u.id = ? AND a.id = ?'
            );
            $ctx->execute([Tenant::currentUserId(), $accountId]);
            $row = $ctx->fetch() ?: ['inviter_name' => 'Kolega', 'account_name' => 'Firol'];

            \Firol\Mail\Mailer::send(
                \Firol\Mail\Templates\InviteEmail::build(
                    $email,
                    (string) $row['inviter_name'],
                    (string) $row['account_name'],
                    $token,
                )
            );
        }

        Response::json([
            'item' => self::loadMember($accountId, (int) $userId),
            // Token still surfaced so the inviter can copy the link
            // manually if the email bounces or the user can't find it.
            'invite_token' => $token,
        ], 201);
    }

    /** @param array<string, string> $params */
    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        self::requireMainUser();
        $accountId = Tenant::currentAccountId();
        $userId    = (int) ($params['id'] ?? 0);

        $isActive = $req->jsonBool('is_active');
        if ($isActive === null) {
            Response::error('Field required: is_active', 422);
        }

        $member = self::loadMember($accountId, $userId);
        if ($member === null) {
            Response::error('Member not found', 404);
        }
        if ($member['is_main']) {
            Response::error('Cannot deactivate the main user', 409);
        }
        if ($userId === Tenant::currentUserId()) {
            Response::error('You cannot deactivate yourself', 409);
        }

        Db::pdo()->prepare(
            'UPDATE account_users SET is_active = ? WHERE account_id = ? AND user_id = ?'
        )->execute([$isActive ? 1 : 0, $accountId, $userId]);

        Response::json(['item' => self::loadMember($accountId, $userId)]);
    }

    /** @param array<string, string> $params */
    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        self::requireMainUser();
        $accountId = Tenant::currentAccountId();
        $userId    = (int) ($params['id'] ?? 0);

        $member = self::loadMember($accountId, $userId);
        if ($member === null) {
            Response::error('Member not found', 404);
        }
        if ($member['is_main']) {
            Response::error('Cannot remove the main user', 409);
        }
        if ($userId === Tenant::currentUserId()) {
            Response::error('You cannot remove yourself', 409);
        }

        Db::pdo()->prepare(
            'DELETE FROM account_users WHERE account_id = ? AND user_id = ?'
        )->execute([$accountId, $userId]);

        Response::noContent();
    }

    /** @return array<string, mixed>|null */
    private static function loadMember(int $accountId, int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.id, u.fullname, u.email, u.phone,
                    au.role, au.is_active, au.created_at,
                    a.main_user_id = u.id AS is_main
             FROM   account_users au
             JOIN   users    u ON u.id = au.user_id
             JOIN   accounts a ON a.id = au.account_id
             WHERE  au.account_id = ? AND u.id = ?'
        );
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'fullname'   => (string) $row['fullname'],
            'email'      => (string) $row['email'],
            'phone'      => $row['phone'],
            'role'       => (string) $row['role'],
            'is_active'  => (bool) $row['is_active'],
            'is_main'    => (bool) $row['is_main'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function requireMainUser(): void
    {
        $userId    = Tenant::currentUserId();
        $accountId = Tenant::currentAccountId();
        $stmt = Db::pdo()->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $mainId = $stmt->fetchColumn();
        if ((int) $mainId !== $userId) {
            Response::error('Only the main user can manage the team', 403);
        }
    }
}
