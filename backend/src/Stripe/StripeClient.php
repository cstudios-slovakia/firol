<?php

declare(strict_types=1);

namespace Firol\Stripe;

use Firol\Http\Response;
use Stripe\StripeClient as SdkClient;

/**
 * Lazy Stripe SDK accessor. Reads the secret key from env on first call
 * and memoizes the configured client. We deliberately do not configure
 * the SDK statically (Stripe::setApiKey) so test suites can swap the
 * instance.
 */
final class StripeClient
{
    private static ?SdkClient $instance = null;

    public static function get(): SdkClient
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $secret = (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        if ($secret === '') {
            Response::error('Stripe is not configured on the server', 500);
        }
        self::$instance = new SdkClient([
            'api_key'        => $secret,
            // Pin the API version so a Stripe-side default change doesn't
            // silently alter response shapes. Bump deliberately.
            'stripe_version' => '2024-04-10',
        ]);
        return self::$instance;
    }

    public static function webhookSecret(): string
    {
        $secret = (string) ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            Response::error('Stripe webhook secret not configured', 500);
        }
        return $secret;
    }

    public static function priceFor(string $billingPeriod): string
    {
        $key = $billingPeriod === 'yearly' ? 'STRIPE_PRICE_YEARLY' : 'STRIPE_PRICE_MONTHLY';
        $price = (string) ($_ENV[$key] ?? '');
        if ($price === '') {
            Response::error("Stripe $key not configured", 500);
        }
        return $price;
    }

    public static function appBaseUrl(): string
    {
        $url = (string) ($_ENV['APP_BASE_URL'] ?? '');
        return $url !== '' ? rtrim($url, '/') : 'http://localhost:5173';
    }
}
