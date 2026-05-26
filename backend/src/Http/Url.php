<?php

declare(strict_types=1);

namespace Firol\Http;

/**
 * Small URL helpers shared across modules. Lives in Firol\Http so we don't
 * have two divergent implementations of the same "where does the app live"
 * helper in Mailer and StripeClient.
 */
final class Url
{
    /**
     * Base URL the app is served from — read from APP_BASE_URL in .env.
     * Falls back to the local Vite dev server so things keep working
     * before .env is populated. Always returned without a trailing slash.
     */
    public static function appBase(): string
    {
        $url = trim((string) ($_ENV['APP_BASE_URL'] ?? ''));
        return $url !== '' ? rtrim($url, '/') : 'http://localhost:5173';
    }
}
