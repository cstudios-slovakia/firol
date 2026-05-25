<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Billing\SeatSync;
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
     * Send an invitation to a technician. Only an `account_invites` row
     * is created here — neither `users` nor `account_users` is touched.
     * The invitee confirms via the link in the email, which calls
     * {@see InviteController::accept} to create the user (if needed) and
     * attach them to the account.
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

        // Seat cap is checked here for early feedback, but the real
        // enforcement happens at accept time — pending invites do not
        // hold seats.
        $maxSelfService = SeatSync::maxSelfServiceTechnicians();
        $activeNow      = SeatSync::countActiveTechnicians($accountId);
        if ($activeNow + 1 > $maxSelfService) {
            Response::error(
                'Predplatné je obmedzené na ' . $maxSelfService . ' technikov. Pre väčší tím nás prosím kontaktujte — pripravíme individuálnu cenovú ponuku.',
                422,
                ['code' => 'seat_cap_exceeded', 'max' => $maxSelfService],
            );
        }

        $pdo = Db::pdo();

        // Refuse a duplicate invite to someone who is already actively on
        // the account.
        $check = $pdo->prepare(
            'SELECT 1 FROM account_users au
             JOIN   users u ON u.id = au.user_id
             WHERE  au.account_id = ? AND u.email = ? AND au.is_active = 1'
        );
        $check->execute([$accountId, $email]);
        if ($check->fetchColumn() !== false) {
            Response::error('Tento používateľ je už členom tímu.', 409);
        }

        // If there's already an open invite for the same email on this
        // account, replace it with a fresh token rather than stacking
        // multiple pendings.
        $pdo->prepare(
            "UPDATE account_invites
             SET    cancelled_at = NOW()
             WHERE  account_id = ? AND email = ?
             AND    accepted_at IS NULL AND declined_at IS NULL AND cancelled_at IS NULL"
        )->execute([$accountId, $email]);

        $token   = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO account_invites (account_id, email, fullname, phone, token, invited_by_user_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $accountId,
            $email,
            trim($fullname),
            $phone,
            $token,
            Tenant::currentUserId(),
            $expires,
        ]);
        $inviteId = (int) $pdo->lastInsertId();

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

        Response::json([
            'invite' => self::loadInvite($accountId, $inviteId),
            // Token still surfaced so the inviter can copy the link
            // manually if the email bounces or the user can't find it.
            'invite_token' => $token,
        ], 201);
    }

    /**
     * Pending invites for the active account. Accepted/declined/cancelled
     * rows are filtered out — they're history, not actionable.
     */
    public static function indexInvites(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $stmt = Db::pdo()->prepare(
            "SELECT id, email, fullname, phone, expires_at, created_at
             FROM   account_invites
             WHERE  account_id = ?
               AND  accepted_at IS NULL
               AND  declined_at IS NULL
               AND  cancelled_at IS NULL
               AND  expires_at > NOW()
             ORDER  BY created_at DESC"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();

        $items = array_map(static fn(array $r) => [
            'id'         => (int) $r['id'],
            'email'      => (string) $r['email'],
            'fullname'   => (string) $r['fullname'],
            'phone'      => $r['phone'],
            'expires_at' => $r['expires_at'],
            'created_at' => $r['created_at'],
        ], $rows);

        Response::json(['items' => $items]);
    }

    /** @param array<string, string> $params */
    public static function cancelInvite(Request $req, array $params): void
    {
        Csrf::require($req);
        self::requireMainUser();
        $accountId = Tenant::currentAccountId();
        $inviteId  = (int) ($params['id'] ?? 0);

        Db::pdo()->prepare(
            "UPDATE account_invites
             SET    cancelled_at = NOW()
             WHERE  id = ? AND account_id = ?
               AND  accepted_at IS NULL AND declined_at IS NULL AND cancelled_at IS NULL"
        )->execute([$inviteId, $accountId]);

        Response::noContent();
    }

    /** @return array<string, mixed>|null */
    private static function loadInvite(int $accountId, int $inviteId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, email, fullname, phone, expires_at, created_at
             FROM   account_invites
             WHERE  id = ? AND account_id = ?'
        );
        $stmt->execute([$inviteId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'email'      => (string) $row['email'],
            'fullname'   => (string) $row['fullname'],
            'phone'      => $row['phone'],
            'expires_at' => $row['expires_at'],
            'created_at' => $row['created_at'],
        ];
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

        // Re-activating a technician needs to respect the self-service cap
        // just like a fresh invite — otherwise an admin could route around
        // it by deactivating + re-activating.
        if ($isActive && !$member['is_active']) {
            $maxSelfService = SeatSync::maxSelfServiceTechnicians();
            $activeNow      = SeatSync::countActiveTechnicians($accountId);
            if ($activeNow + 1 > $maxSelfService) {
                Response::error(
                    'Predplatné je obmedzené na ' . $maxSelfService . ' technikov. Pre väčší tím nás prosím kontaktujte — pripravíme individuálnu cenovú ponuku.',
                    422,
                    ['code' => 'seat_cap_exceeded', 'max' => $maxSelfService],
                );
            }
        }

        Db::pdo()->prepare(
            'UPDATE account_users SET is_active = ? WHERE account_id = ? AND user_id = ?'
        )->execute([$isActive ? 1 : 0, $accountId, $userId]);

        SeatSync::recompute($accountId);

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

        SeatSync::recompute($accountId);

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
