<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Http\Response;
use Firol\Storage\Storage;

/**
 * Tiny file-based sliding-window rate limiter.
 *
 * Keyed by an arbitrary identifier (IP, email…); the implementation stores
 * one JSON file per key under backend/storage/ratelimit/. No DB, no Redis —
 * we run on a single PHP-FPM box, so the FS is good enough.
 *
 * Window is enforced by trimming timestamps older than `windowSeconds`
 * before each insert; once the surviving list reaches `maxAttempts` we
 * 429 the caller.
 */
final class RateLimit
{
    /**
     * Increment the counter for $key and 429 if it has exceeded the budget.
     * Pass the identifier you want to rate-limit on (IP, email, both).
     */
    public static function hit(string $key, int $maxAttempts = 5, int $windowSeconds = 900): void
    {
        $dir  = Storage::root() . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . hash('sha256', $key) . '.json';

        $now      = time();
        $attempts = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $attempts = array_values(array_filter(
                        $decoded,
                        static fn ($t) => is_int($t) && $t > $now - $windowSeconds,
                    ));
                }
            }
        }

        if (count($attempts) >= $maxAttempts) {
            $retryAfter = $windowSeconds - ($now - (int) $attempts[0]);
            header('Retry-After: ' . max(1, $retryAfter));
            Response::error('Príliš veľa pokusov, skús znova o chvíľu.', 429);
        }

        $attempts[] = $now;
        @file_put_contents($path, json_encode($attempts), LOCK_EX);
    }

    /**
     * Drop the counter after a successful auth so a legitimate user who
     * fat-fingered their password a few times isn't punished afterwards.
     */
    public static function clear(string $key): void
    {
        $dir  = Storage::root() . '/ratelimit';
        $path = $dir . '/' . hash('sha256', $key) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
