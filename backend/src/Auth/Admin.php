<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Db;
use Firol\Http\Response;

/**
 * App-admin gate.
 *
 * Two-tier seed model:
 *   1. ADMIN_EMAIL env (comma-separated) is the immutable bootstrap — these
 *      emails are *always* admin even if the DB row was cleared. Every
 *      lookup also syncs the env emails into users.is_admin so the
 *      promoted state is reflected consistently in the admin UI.
 *   2. users.is_admin (DB) — added/revoked by an existing admin through
 *      the /admin page. This is how the second human admin is granted
 *      access after the env-seeded one is up.
 *
 * App admins are global: they can edit/delete *any* user or account in
 * the system. They are not the same as accounts.main_user_id ("account
 * admin"), which is per-tenant and only manages technicians.
 */
final class Admin
{
    /**
     * Whether the given account is "owned" by an app admin — i.e. its
     * `main_user_id` is an app admin. These accounts behave as if they
     * had a paid subscription: no read-only mode, no expiry banner, and
     * every technician invited onto the account inherits that free
     * access. Used by the Phase 6a dispatcher gate and by the snapshot
     * shapers so the frontend can hide the expiry/trial banners.
     */
    public static function accountIsAdminOwned(int $accountId): bool
    {
        $stmt = Db::pdo()->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $mainUserId = $stmt->fetchColumn();
        if ($mainUserId === false || $mainUserId === null) {
            return false;
        }
        return self::isAdmin((int) $mainUserId);
    }

    public static function isAdmin(int $userId): bool
    {
        $stmt = Db::pdo()->prepare('SELECT email, is_admin FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $email = strtolower((string) $row['email']);
        $isAdminDb = (int) $row['is_admin'] === 1;

        if (in_array($email, self::adminEmails(), true)) {
            // Self-heal: keep the DB flag in sync with the env seed so the
            // admin UI lists env-seeded admins like any other.
            if (!$isAdminDb) {
                Db::pdo()->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')
                    ->execute([$userId]);
            }
            return true;
        }

        // env-seed bootstrap explicitly only ever *adds*. Removing an
        // email from ADMIN_EMAIL does NOT auto-demote — admins promoted
        // through the UI also have is_admin = 1, and we can't distinguish
        // those from a former env seed. Demote via the admin UI instead.
        return $isAdminDb;
    }

    public static function require(): int
    {
        $userId = Tenant::currentUserId();
        if (!self::isAdmin($userId)) {
            Response::error('Admin access required', 403);
        }
        return $userId;
    }

    /**
     * Whether the given email belongs to the immutable env seed. These
     * users cannot be demoted through the admin UI — only edited in .env.
     */
    public static function isEnvSeed(string $email): bool
    {
        return in_array(strtolower(trim($email)), self::adminEmails(), true);
    }

    /** @return list<string> */
    private static function adminEmails(): array
    {
        $raw = trim((string) ($_ENV['ADMIN_EMAIL'] ?? ''));
        if ($raw === '') return [];
        return array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim($e)),
            explode(',', $raw),
        )));
    }
}
