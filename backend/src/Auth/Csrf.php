<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Http\Request;
use Firol\Http\Response;

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

    public static function require(Request $request): void
    {
        $token = $request->header('X-CSRF-Token');
        if ($token === null || !self::verify($token)) {
            Response::error('Invalid CSRF token', 403);
        }
    }
}
