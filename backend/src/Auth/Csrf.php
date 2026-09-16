<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Http\Request;

/**
 * CSRF token bound to the session. Issued lazily on first read; verified for
 * state-changing requests via the `X-CSRF-Token` header.
 *
 * Public auth endpoints (register, login, password-reset/*) skip verification
 * because the user has no session yet.
 */
final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(string $token): bool
    {
        Session::start();
        $expected = $_SESSION['csrf_token'] ?? null;
        return is_string($expected) && hash_equals($expected, $token);
    }

    /**
     * Gate for every state-changing request.
     *
     * The three ways this fails look identical from the outside but mean very
     * different things, and conflating them is what produced the unexplained
     * "invalid token" reports:
     *
     *   1. The session idled out and nothing could resume it. The CSRF token
     *      died with the session, so a mismatch here is a *symptom* — the
     *      cause is the expired login. Answer 401 so the SPA signs the user
     *      out cleanly (after parking their unsaved work as a draft) instead
     *      of showing a token error it can do nothing about.
     *   2. The session was silently renewed from the "remember me" cookie
     *      mid-visit. That mints a fresh token, so the long-open page is
     *      holding the previous one. The user is still logged in — answer 403
     *      with CSRF_INVALID and the SPA re-syncs from /api/me and replays the
     *      request without the user ever seeing it.
     *   3. A genuine cross-site request with no/bad token. Same 403; the
     *      attacker's page can't read /api/me to recover.
     */
    public static function require(Request $request): void
    {
        Session::start();
        $token = $request->header('X-CSRF-Token');

        if (Session::userId() === null) {
            AuthFailure::reject(
                AuthFailure::SESSION_EXPIRED,
                AuthFailure::MSG_SESSION_EXPIRED,
                401,
                'session gone before csrf check (idle timeout or dropped cookie)',
            );
        }

        if ($token === null) {
            AuthFailure::reject(
                AuthFailure::CSRF_INVALID,
                AuthFailure::MSG_CSRF_INVALID,
                403,
                'X-CSRF-Token header missing',
            );
        }

        if (!self::verify($token)) {
            AuthFailure::reject(
                AuthFailure::CSRF_INVALID,
                AuthFailure::MSG_CSRF_INVALID,
                403,
                isset($_SESSION['csrf_token'])
                    ? 'token mismatch (session renewed since the page loaded)'
                    : 'session carries no csrf token yet',
            );
        }
    }
}
