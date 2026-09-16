<?php

declare(strict_types=1);

namespace Firol\Auth;

/**
 * Session wrapper with secure defaults. Cookie is HttpOnly, SameSite=Lax,
 * Secure on production. The session carries:
 *   - user_id     : int  (logged-in user)
 *   - account_id  : int  (active account context — used by Tenant guard)
 *   - csrf_token  : string
 *   - last_seen   : int  (unix ts — keeps the idle window sliding, see touch())
 */
final class Session
{
    /**
     * How long a session survives without a request. PHP's default is 24
     * minutes, which is shorter than a single inspection: a technician who
     * filled in items for half an hour came back to a dead session, and
     * because the CSRF token dies with it the next save answered "invalid
     * token". Twelve hours covers a working day; the cookie itself still
     * expires when the browser closes, and "remember me" remains the only
     * way to stay signed in across restarts.
     */
    private const IDLE_LIFETIME = 12 * 3600;

    /**
     * Rewrite the session at most this often to slide the idle window.
     * PHP's GC keys off the session file's mtime and `session.lazy_write`
     * skips rewriting an unchanged session, so a stream of read-only
     * requests would let an actively-used session age out anyway.
     */
    private const TOUCH_INTERVAL = 300;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isProd = ($_ENV['APP_ENV'] ?? 'local') === 'production';

        // Must precede session_start(). Note this only governs sessions
        // collected by *this* app — a co-hosted PHP app sharing the default
        // save path could still GC our files on its own shorter setting.
        ini_set('session.gc_maxlifetime', (string) self::IDLE_LIFETIME);

        session_set_cookie_params([
            // 0 = browser-session cookie. Staying signed in across browser
            // restarts is what RememberToken is for.
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $isProd,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('firol_session');
        session_start();
        self::touch();
    }

    /**
     * Bump `last_seen` when it is stale enough, which forces PHP to write the
     * session file and so pushes its GC deadline forward. Throttled so we
     * aren't rewriting the file on every poll.
     */
    private static function touch(): void
    {
        $now  = time();
        $seen = isset($_SESSION['last_seen']) ? (int) $_SESSION['last_seen'] : 0;
        if ($now - $seen >= self::TOUCH_INTERVAL) {
            $_SESSION['last_seen'] = $now;
        }
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function setUserId(int $id): void
    {
        self::start();
        // Rotate the session ID whenever a user is bound to the session
        // (login / register / password reset confirm). Defeats session
        // fixation: an attacker who guesses/sets a pre-auth ID can't reuse
        // it once we issue a fresh one on successful authentication.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
    }

    public static function activeAccountId(): ?int
    {
        self::start();
        return isset($_SESSION['account_id']) ? (int) $_SESSION['account_id'] : null;
    }

    public static function setActiveAccountId(int $id): void
    {
        self::start();
        $_SESSION['account_id'] = $id;
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
        session_destroy();
    }
}
