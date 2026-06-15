<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Reads a configuration value from the environment. Prefers $_ENV (where
 * vlucas/phpdotenv loads backend/.env in production) and falls back to
 * getenv()/$_SERVER so values injected directly into the process
 * environment (e.g. docker-compose `environment:` in local dev) are also
 * picked up.
 */
final class Env
{
    public static function get(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        // getenv() returns false when unset; $_ENV/$_SERVER are typed mixed.
        if (!is_string($value) || $value === '') {
            return $default;
        }
        return $value;
    }
}
