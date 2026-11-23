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
    /**
     * API version of every call this app makes. Webhook payloads arrive in
     * the Stripe account's default version instead (see StripeShape), so code
     * that reads a payload must not assume this shape.
     */
    public const API_VERSION = '2024-04-10';

    private static ?SdkClient $instance = null;

    /** Test hook: replace the SDK client (null restores the env-built one). */
    public static function swap(?SdkClient $client): void
    {
        self::$instance = $client;
    }

    public static function isConfigured(): bool
    {
        return (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '') !== '';
    }

    /** True for a Stripe test-mode secret key (sk_test_… or rk_test_…). */
    public static function isTestKey(): bool
    {
        $key = (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'rk_test_');
    }

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
            'stripe_version' => self::API_VERSION,
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

    /**
     * The env-configured base Price IDs (monthly and yearly), without empties.
     *
     * @return list<string>
     */
    public static function basePriceIds(): array
    {
        return array_values(array_filter([
            (string) ($_ENV['STRIPE_PRICE_MONTHLY'] ?? ''),
            (string) ($_ENV['STRIPE_PRICE_YEARLY']  ?? ''),
        ], static fn (string $id): bool => $id !== ''));
    }

    /**
     * Product every extra-technician item is created under
     * (STRIPE_PRODUCT_EXTRA_TECHNICIAN), or '' when it is not configured.
     * Subscription items cannot create a product inline, and a fixed product
     * is how the extra item is told apart from the base item.
     */
    public static function extraTechnicianProductId(): string
    {
        return trim((string) ($_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] ?? ''));
    }

    public static function appBaseUrl(): string
    {
        return \Firol\Http\Url::appBase();
    }
}
