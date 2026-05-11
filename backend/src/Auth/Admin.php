<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Db;
use Firol\Http\Response;

/**
 * Lightweight admin gate. There's a single product owner, so we don't
 * need a roles table — `ADMIN_EMAIL` in `.env` designates the admin
 * account. Multiple emails can be comma-separated.
 */
final class Admin
{
    public static function isAdmin(int $userId): bool
    {
        $emails = self::adminEmails();
        if ($emails === []) {
            return false;
        }
        $stmt = Db::pdo()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        return in_array(strtolower((string) $row['email']), $emails, true);
    }

    public static function require(): int
    {
        $userId = Tenant::currentUserId();
        if (!self::isAdmin($userId)) {
            Response::error('Admin access required', 403);
        }
        return $userId;
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
