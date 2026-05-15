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
