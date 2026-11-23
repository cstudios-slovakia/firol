<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Db;

/**
 * MariaDB advisory locks (GET_LOCK) of the billing code: the per-account
 * seat lock, subscription lock and checkout lock.
 *
 * GET_LOCK names are global to the database SERVER, not to one database, so
 * every name carries the database name — production and a staging copy on
 * the same server must not wait for each other's locks.
 */
final class BillingLock
{
    /** The server-wide lock name of $name in this app's database (at most 64 characters). */
    public static function name(string $name): string
    {
        $db = (string) ($_ENV['DB_NAME'] ?? '');
        $full = $db !== '' ? $db . '.' . $name : $name;
        // MariaDB / MySQL refuse names longer than 64 characters: keep the
        // distinctive end and a hash of the whole.
        return strlen($full) <= 64 ? $full : substr(md5($full), 0, 12) . '.' . substr($full, -51);
    }

    /** Wait up to $timeout seconds for the lock; true when it is held. */
    public static function acquire(string $name, int $timeout): bool
    {
        $stmt = Db::pdo()->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([self::name($name), $timeout]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public static function release(string $name): void
    {
        Db::pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([self::name($name)]);
    }
}
