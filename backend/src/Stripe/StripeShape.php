<?php

declare(strict_types=1);

namespace Firol\Stripe;

/**
 * Reads Stripe objects whatever API version they were rendered in.
 *
 * API calls of this app are pinned to StripeClient::API_VERSION, but webhook
 * payloads arrive in the account's default version (2026-04-22.dahlia in test
 * on 17. 11. 2026), and several fields moved between the two:
 *
 *  - Subscription.current_period_end moved onto each subscription item.
 *  - Invoice.subscription moved to Invoice.parent.subscription_details.subscription.
 *  - InvoiceLineItem.proration / .type / .subscription_item moved under
 *    InvoiceLineItem.parent.(subscription_item_details|invoice_item_details).
 *  - InvoiceLineItem.price moved to InvoiceLineItem.pricing.price_details
 *    (price id and product id only).
 *
 * Every accessor takes a plain array (StripeObject::toArray()) and tries the
 * old field first, then the new one.
 */
final class StripeShape
{
    /**
     * @param array<string, mixed>|\Stripe\StripeObject $obj
     * @return array<string, mixed>
     */
    public static function arr(array|\Stripe\StripeObject $obj): array
    {
        return is_array($obj) ? $obj : $obj->toArray();
    }

    /**
     * End of the current paid period of a subscription (unix time), or null.
     * New versions carry it per item; the base item's (or the latest) wins.
     *
     * @param array<string, mixed> $sub
     */
    public static function subscriptionPeriodEnd(array $sub): ?int
    {
        if (isset($sub['current_period_end']) && is_int($sub['current_period_end'])) {
            return $sub['current_period_end'];
        }
        $best = null;
        foreach ((array) ($sub['items']['data'] ?? []) as $item) {
            $end = $item['current_period_end'] ?? null;
            if (is_int($end) && ($best === null || $end > $best)) {
                $best = $end;
            }
        }
        return $best;
    }

    /** @param array<string, mixed> $invoice */
    public static function invoiceSubscriptionId(array $invoice): ?string
    {
        $sub = $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
        if (is_array($sub)) {
            $sub = $sub['id'] ?? null;
        }
        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /** @param array<string, mixed> $line */
    public static function lineIsProration(array $line): bool
    {
        if (array_key_exists('proration', $line)) {
            return (bool) $line['proration'];
        }
        $parent = (array) ($line['parent'] ?? []);
        return (bool) ($parent['subscription_item_details']['proration']
            ?? $parent['invoice_item_details']['proration']
            ?? false);
    }

    /**
     * A line billed by the subscription itself (as opposed to a pending
     * invoice item, e.g. a proration created by a quantity change).
     *
     * @param array<string, mixed> $line
     */
    public static function lineIsSubscription(array $line): bool
    {
        if (isset($line['type']) && is_string($line['type'])) {
            return $line['type'] === 'subscription';
        }
        return (string) ($line['parent']['type'] ?? '') === 'subscription_item_details';
    }

    /** @param array<string, mixed> $line */
    public static function linePriceId(array $line): ?string
    {
        $price = $line['price'] ?? ($line['pricing']['price_details']['price'] ?? null);
        if (is_array($price)) {
            $price = $price['id'] ?? null;
        }
        return is_string($price) && $price !== '' ? $price : null;
    }

    /** @param array<string, mixed> $line */
    public static function lineProductId(array $line): ?string
    {
        $product = null;
        if (isset($line['price']) && is_array($line['price'])) {
            $product = $line['price']['product'] ?? null;
        } elseif (isset($line['pricing']['price_details']['product'])) {
            $product = $line['pricing']['price_details']['product'];
        }
        if (is_array($product)) {
            $product = $product['id'] ?? null;
        }
        return is_string($product) && $product !== '' ? $product : null;
    }

    /**
     * @param array<string, mixed> $line
     * @return array{0: int, 1: int}|null
     */
    public static function linePeriod(array $line): ?array
    {
        $start = $line['period']['start'] ?? null;
        $end   = $line['period']['end'] ?? null;
        if (!is_int($start) || !is_int($end) || $start <= 0 || $end <= 0) {
            return null;
        }
        return [$start, $end];
    }

    /** @param array<string, mixed> $item */
    public static function itemProductId(array $item): ?string
    {
        $product = $item['price']['product'] ?? null;
        if (is_array($product)) {
            $product = $product['id'] ?? null;
        }
        return is_string($product) && $product !== '' ? $product : null;
    }

    /** @param array<string, mixed> $item */
    public static function itemInterval(array $item): ?string
    {
        $interval = $item['price']['recurring']['interval'] ?? ($item['plan']['interval'] ?? null);
        return is_string($interval) && $interval !== '' ? $interval : null;
    }
}
