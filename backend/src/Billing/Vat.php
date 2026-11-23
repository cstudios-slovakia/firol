<?php

declare(strict_types=1);

namespace Firol\Billing;

/**
 * VAT of the subscription (owner's decision of 18. 11. 2026): list prices are
 * net, the customer pays the price plus Slovak VAT.
 *
 *  - VAT_RATE_PERCENT (env, default 23) is the one rate setting: the screens,
 *    the e-mails and the iDoklad payload use it, and ConfigCheck checks the
 *    Stripe tax rate against it.
 *  - STRIPE_TAX_RATE_ID (env, one per Stripe mode like
 *    STRIPE_PRODUCT_EXTRA_TECHNICIAN) is a manual, EXCLUSIVE Stripe tax rate
 *    with that percentage. Checkout sets it as the subscription's default
 *    tax rate, and the reconcile (daily run, webhooks) puts it on every
 *    live subscription that lacks it (behind BILLING_RECONCILE). Stripe then
 *    adds the tax to every line of every invoice — plan, extra technicians,
 *    prorations of additions and removals — on the amount after discounts,
 *    rounded per line (test clock, 19. 11. 2026).
 *
 * Foreign customers and reverse charge are out of scope: every customer pays
 * Slovak VAT.
 */
final class Vat
{
    public const DEFAULT_PERCENT = 23.0;

    /** The VAT rate in percent (VAT_RATE_PERCENT, else 23). */
    public static function percent(): float
    {
        $raw = trim((string) ($_ENV['VAT_RATE_PERCENT'] ?? ''));
        if ($raw === '' || !is_numeric(str_replace(',', '.', $raw))) {
            return self::DEFAULT_PERCENT;
        }
        return (float) str_replace(',', '.', $raw);
    }

    /** Whether VAT_RATE_PERCENT is set to a number (ConfigCheck). */
    public static function percentIsSet(): bool
    {
        $raw = trim((string) ($_ENV['VAT_RATE_PERCENT'] ?? ''));
        return $raw !== '' && is_numeric(str_replace(',', '.', $raw));
    }

    /**
     * VAT of a net amount in cents, rounded half away from zero — Stripe's
     * rounding of one line (a negative line gives negative VAT).
     */
    public static function of(int $netCents, ?float $percent = null): int
    {
        return (int) round($netCents * ($percent ?? self::percent()) / 100);
    }

    /** Net amount plus its VAT, in cents. */
    public static function gross(int $netCents, ?float $percent = null): int
    {
        return $netCents + self::of($netCents, $percent);
    }

    /** STRIPE_TAX_RATE_ID, or '' when not configured. */
    public static function stripeTaxRateId(): string
    {
        return trim((string) ($_ENV['STRIPE_TAX_RATE_ID'] ?? ''));
    }

    /** „23 %" / „20,5 %". */
    public static function label(?float $percent = null): string
    {
        $p = $percent ?? self::percent();
        return rtrim(rtrim(number_format($p, 2, ',', ''), '0'), ',') . ' %';
    }
}
