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
            Response::error('Platobná brána Stripe nie je na serveri nastavená.', 500);
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
            Response::error('Webhook platobnej brány Stripe nie je na serveri nastavený.', 500);
        }
        return $secret;
    }

    public static function priceFor(string $billingPeriod): string
    {
        $key = $billingPeriod === 'yearly' ? 'STRIPE_PRICE_YEARLY' : 'STRIPE_PRICE_MONTHLY';
        $price = (string) ($_ENV[$key] ?? '');
        if ($price === '') {
            Response::error("Platobná brána Stripe nemá na serveri nastavené „{$key}“.", 500);
        }
        return $price;
    }

    public static function appBaseUrl(): string
    {
        return \Firol\Http\Url::appBase();
    }
}
