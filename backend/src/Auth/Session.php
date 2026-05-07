<?php

declare(strict_types=1);

namespace Firol\Auth;

/**
 * Session wrapper with secure defaults. Cookie is HttpOnly, SameSite=Lax,
 * Secure on production. The session carries:
 *   - user_id     : int  (logged-in user)
 *   - account_id  : int  (active account context — used by Tenant guard)
 *   - csrf_token  : string
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isProd = ($_ENV['APP_ENV'] ?? 'local') === 'production';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $isProd,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('firol_session');
        session_start();
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function setUserId(int $id): void
    {
        self::start();
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
