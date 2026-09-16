<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Http\Response;

/**
 * Single exit point for "you are not allowed to do this because of your
 * session" failures — expired login, missing account context, stale CSRF
 * token.
 *
 * Exists because these three used to answer with three different, English,
 * mutually indistinguishable messages ("Invalid CSRF token", "Unauthorized",
 * "No active account"). The user saw "invalid token" and the log said nothing
 * about which action had failed or why. Every rejection now carries:
 *
 *   - a stable machine `code` the SPA branches on (see the constants below),
 *   - a Slovak message that is safe to show as-is,
 *   - a log line naming the action (method + path) and the concrete cause.
 *
 * Status codes are meaningful to the client:
 *   401 — the session is gone; re-authentication is the only way forward.
 *   403 — the session is fine, only the page's CSRF token went stale; the
 *         SPA re-syncs it from /api/me and replays the request silently.
 */
final class AuthFailure
{
    /** No (longer any) authenticated session — the user must log in again. */
    public const SESSION_EXPIRED = 'session_expired';
    /** Authenticated, but the session carries no active account context. */
    public const NO_ACCOUNT = 'no_active_account';
    /** Authenticated, but the request's CSRF token doesn't match the session. */
    public const CSRF_INVALID = 'csrf_invalid';

    public const MSG_SESSION_EXPIRED = 'Prihlásenie vypršalo, prihláste sa znova.';
    public const MSG_CSRF_INVALID    = 'Bezpečnostný token stránky vypršal. Skús akciu zopakovať.';

    /** Logs the failure with the action that hit it, then ends the request. */
    public static function reject(string $code, string $message, int $status, string $cause): never
    {
        self::log($code, $cause);
        Response::error($message, $status, ['code' => $code]);
    }

    /**
     * Acceptance criterion: "the error is written to the log naming the action
     * and the cause". Kept to one grep-able line per failure.
     */
    public static function log(string $code, string $cause): void
    {
        error_log(sprintf(
            '[auth-failure] code=%s action=%s %s user=%s account=%s cause=%s ua=%s',
            $code,
            $_SERVER['REQUEST_METHOD'] ?? '-',
            self::path(),
            self::describe(Session::userId()),
            self::describe(Session::activeAccountId()),
            $cause,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 120),
        ));
    }

    /**
     * The route path as the app knows it. Production transports it as
     * `?path=`; locally it is the request URI. Mirrors Request::path() without
     * needing a Request instance, so Tenant (which has none) can log too.
     */
    private static function path(): string
    {
        if (isset($_GET['path']) && is_string($_GET['path']) && $_GET['path'] !== '') {
            return $_GET['path'];
        }
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return is_string($uri) && $uri !== '' ? $uri : '/';
    }

    private static function describe(?int $id): string
    {
        return $id === null ? '-' : (string) $id;
    }
}
