<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Db;
use Firol\Http\Response;

/**
 * Multi-tenancy guard. Every controller that touches domain data MUST
 * resolve the active account through this — there is no other supported
 * way. Returns the integer account id; sends 401 and exits if the request
 * is not authenticated or the session has no active account context.
 *
 * Both rejections go through AuthFailure so the client gets the same Slovak
 * "log in again" message and `session_expired` code it gets from Csrf, and
 * the log records which action ran into it.
 */
final class Tenant
{
    public static function currentUserId(): int
    {
        $id = Session::userId();
        if ($id === null) {
            AuthFailure::reject(
                AuthFailure::SESSION_EXPIRED,
                AuthFailure::MSG_SESSION_EXPIRED,
                401,
                'no authenticated session (idle timeout, logout or dropped cookie)',
            );
        }
        return $id;
    }

    public static function currentAccountId(): int
    {
        // Implicit precondition: an authenticated session always has an
        // active account because login + register both set it.
        self::currentUserId();
        $id = Session::activeAccountId();
        if ($id === null) {
            AuthFailure::reject(
                AuthFailure::NO_ACCOUNT,
                AuthFailure::MSG_SESSION_EXPIRED,
                401,
                'session has a user but no active account context',
            );
        }
        return $id;
    }

    /**
     * Sends 403 unless the signed-in user is the main user of the active
     * account. A platform admin passes too — they deliberately operate across
     * client accounts. Returns the account id so callers can chain it.
     */
    public static function requireMainUser(string $message): int
    {
        $accountId = self::currentAccountId();
        $userId    = self::currentUserId();
        if (Admin::isAdmin($userId)) {
            return $accountId;
        }
        $stmt = Db::pdo()->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        if ((int) $stmt->fetchColumn() !== $userId) {
            Response::error($message, 403);
        }
        return $accountId;
    }
}
