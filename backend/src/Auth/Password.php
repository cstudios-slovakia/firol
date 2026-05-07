<?php

declare(strict_types=1);

namespace Firol\Auth;

/**
 * Thin wrapper over PHP's password hashing API. Algorithm picked by PHP
 * (`PASSWORD_DEFAULT`) so it can rotate over time without code changes.
 */
final class Password
{
    public const MIN_LENGTH = 8;

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function isStrongEnough(string $plain): bool
    {
        return strlen($plain) >= self::MIN_LENGTH;
    }
}
