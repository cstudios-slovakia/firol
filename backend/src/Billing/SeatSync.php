<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Db;
use Firol\Stripe\StripeClient;
use Firol\Stripe\StripeShape;

/**
 * Keeps the Stripe subscription's extra-technician item in line with the
 * number of active technicians on the account.
 *
 * Model:
 *  - The base plan is the env-configured Stripe Price (monthly / yearly) and
 *    includes `accounts.included_technicians` technicians — the main user
 *    counts as one of them.
 *  - Every technician beyond that is charged on a second item of the same
 *    subscription, created under the fixed product
 *    STRIPE_PRODUCT_EXTRA_TECHNICIAN with an inline price from
 *    `system_settings.price_per_extra_technician_cents` (a monthly amount;
 *    the yearly item costs EXTRA_YEARLY_MONTHS × that). The item exists only
 *    while the quantity is above zero.
 *  - The price is fixed when the item is created. Later changes only touch its
 *    quantity, so a new admin price reaches new items (checkout, first extra
 *    technician on a subscription) but not existing ones (see
 *    bin/reprice-yearly-extra-seats.php).
 *  - `accounts.extra_technicians` is the WANTED quantity (active technicians
 *    above the included count). It is never copied back from Stripe: Stripe is
 *    told to match it. Without a Stripe subscription (local trial) only the
 *    column is updated and checkout reads it.
 *
 * Repair: recompute() and reconcile() both read the subscription from Stripe
 * and push the difference, so a failed call is corrected by the next roster
 * change, the next customer.subscription.* webhook, checkout-sync, or the
 * daily bin/billing-daily.php run — whichever comes first. Duplicate extra
 * items (left by an old race) are merged into one.
 *
 * Concurrency: every Stripe round-trip for an account runs under a per-account
 * MariaDB advisory lock (GET_LOCK), so two roster changes in the same second
 * cannot both create an extra item.
 */
final class SeatSync
{
    /**
     * Months a yearly extra technician costs. The public price list sells
     * the yearly plan as 10 months paid for 12, and the extra technician
     * on a yearly plan follows it (13,50 € a month → 135 € a year).
     */
    public const EXTRA_YEARLY_MONTHS = 10;

    /** metadata.firol_line_kind of the extra-technician item. */
    public const LINE_KIND = 'extra_technician';

    /**
     * Name of the inline product every Checkout created for the extra line
     * before STRIPE_PRODUCT_EXTRA_TECHNICIAN existed (each Checkout made a new
     * product of this name). Recognises those legacy items when the item's
     * product is expanded.
     */
    public const LEGACY_PRODUCT_NAME = 'POapp — extra technik';

    /** Retrieve options that expand what classifyItems() can use. */
    public const RETRIEVE_EXPAND = ['expand' => ['items.data.price.product']];

    /** Seconds to wait for another request holding the account's seat lock. */
    private const LOCK_TIMEOUT = 20;

    /** Subscription statuses whose items are never changed. */
    private const FROZEN_STATUSES = ['canceled', 'incomplete', 'incomplete_expired'];

    /**
     * Re-sync the extra-technician item for one account after a roster change
     * (invite accepted / re-activated / deactivated / removed / deleted, or a
     * per-account included override).
     *
     * Non-fatal on Stripe errors — the DB keeps the wanted quantity, and the
     * next webhook, checkout-sync or the daily reconcile pushes it to Stripe.
     */
    public static function recompute(int $accountId): void
    {
        try {
            self::reconcile($accountId);
        } catch (\Throwable $e) {
            error_log('[seat-sync] account=' . $accountId . ' err=' . $e->getMessage());
        }
    }

    /**
     * Bring the account's Stripe subscription to the wanted extra quantity and
     * record what is on it. Throws on a Stripe error (after the wanted quantity
     * was saved), so callers that report — the daily command — can count it.
     *
     * @return array{action: string, wanted: int, before: int, after: int, notes: list<string>}
     */
    public static function reconcile(int $accountId, bool $dryRun = false): array
    {
        $result = ['action' => 'none', 'wanted' => 0, 'before' => 0, 'after' => 0, 'notes' => []];

        $locked = self::lock($accountId);
        try {
            $account = self::saveWanted($accountId, $dryRun);
            if ($account === null) {
                $result['notes'][] = 'account not found';
                return $result;
            }
            $wanted = (int) $account['wanted'];
            $result['wanted'] = $wanted;

            $subId = (string) ($account['stripe_subscription_id'] ?? '');
            if ($subId === '') {
                $result['notes'][] = 'no Stripe subscription (checkout reads the wanted quantity)';
                return $result;
            }
            if (!StripeClient::isConfigured()) {
                $result['notes'][] = 'Stripe not configured';
                return $result;
            }
            if (!$locked) {
                // Another request has held the lock for longer than
                // LOCK_TIMEOUT. Pushing now could race it; the wanted quantity
                // is saved and the next run picks it up.
                throw new \RuntimeException('seat lock busy — Stripe not touched');
            }

            $stripe = StripeClient::get();
            $sub    = StripeShape::arr($stripe->subscriptions->retrieve($subId, self::RETRIEVE_EXPAND));
            $status = (string) ($sub['status'] ?? '');
            if (in_array($status, self::FROZEN_STATUSES, true)) {
                $result['notes'][] = "subscription $subId is $status — not changed";
                return $result;
            }

            $split = self::classifyItems(
                (array) ($sub['items']['data'] ?? []),
                (string) ($account['stripe_extra_subscription_item_id'] ?? ''),
            );
            $extras = $split['extras'];
            $base   = $split['base'];
            $before = array_sum(array_map(static fn (array $i): int => (int) ($i['quantity'] ?? 0), $extras));
            $result['before'] = $before;
            $result['notes']  = array_merge($result['notes'], $split['notes']);

            $interval = $base !== null ? StripeShape::itemInterval($base) : null;
            if ($interval === null && $extras !== []) {
                $interval = StripeShape::itemInterval($extras[0]);
            }

            // Which extra item stays: the one under the configured product,
            // else the first. Any other is a duplicate and is removed.
            $keep = null;
            $productId = StripeClient::extraTechnicianProductId();
            foreach ($extras as $item) {
                if ($productId !== '' && StripeShape::itemProductId($item) === $productId) {
                    $keep = $item;
                    break;
                }
            }
            $keep ??= $extras[0] ?? null;
            $duplicates = array_values(array_filter(
                $extras,
                static fn (array $i): bool => $keep !== null && $i['id'] !== $keep['id'],
            ));

            $actions = [];
            foreach ($duplicates as $dup) {
                if (!$dryRun) {
                    $stripe->subscriptionItems->delete((string) $dup['id'], ['proration_behavior' => 'create_prorations']);
                }
                $actions[] = ($dryRun ? 'would merge' : 'merged') . ' duplicate ' . $dup['id'] . ' (qty ' . (int) ($dup['quantity'] ?? 0) . ')';
            }

            $keepQty = $keep !== null ? (int) ($keep['quantity'] ?? 0) : 0;
            if ($wanted <= 0) {
                if ($keep !== null) {
                    if (!$dryRun) {
                        $stripe->subscriptionItems->delete((string) $keep['id'], ['proration_behavior' => 'create_prorations']);
                    }
                    $actions[] = ($dryRun ? 'would delete ' : 'deleted ') . $keep['id'];
                    $keep = null;
                }
            } elseif ($keep === null) {
                if ($interval !== 'month' && $interval !== 'year') {
                    throw new \RuntimeException("subscription $subId: base item interval unknown — extra item not created");
                }
                if (!$dryRun) {
                    $created = $stripe->subscriptionItems->create([
                        'subscription'       => $subId,
                        'price_data'         => self::extraPriceData($interval === 'year' ? 'yearly' : 'monthly'),
                        'quantity'           => $wanted,
                        'proration_behavior' => 'create_prorations',
                        'metadata'           => [
                            'firol_account_id' => (string) $accountId,
                            'firol_line_kind'  => self::LINE_KIND,
                        ],
                    ]);
                    $keep = StripeShape::arr($created);
                }
                $actions[] = ($dryRun ? 'would create' : 'created') . " extra item qty $wanted";
            } elseif ($keepQty !== $wanted) {
                if (!$dryRun) {
                    $keep = StripeShape::arr($stripe->subscriptionItems->update((string) $keep['id'], [
                        'quantity'           => $wanted,
                        'proration_behavior' => 'create_prorations',
                    ]));
                }
                $actions[] = ($dryRun ? 'would set ' : 'set ') . $keep['id'] . " qty $keepQty → $wanted";
            }
            $result['after'] = $dryRun ? $before : $wanted;

            if (!$dryRun) {
                Db::pdo()->prepare(
                    'UPDATE accounts
                     SET    stripe_extra_subscription_item_id = ?,
                            stripe_extra_unit_amount_cents    = ?,
                            stripe_extra_interval             = ?,
                            stripe_base_unit_amount_cents     = ?
                     WHERE  id = ?'
                )->execute([
                    $keep !== null ? (string) $keep['id'] : null,
                    $keep !== null && isset($keep['price']['unit_amount']) ? (int) $keep['price']['unit_amount'] : null,
                    $keep !== null ? StripeShape::itemInterval($keep) : null,
                    $base !== null && isset($base['price']['unit_amount']) ? (int) $base['price']['unit_amount'] : null,
                    $accountId,
                ]);
            }

            if ($actions !== []) {
                $result['action'] = $dryRun ? 'would_change' : 'changed';
                $result['notes']  = array_merge($result['notes'], $actions);
                error_log("[seat-sync] account=$accountId sub=$subId wanted=$wanted before=$before: " . implode('; ', $actions));
            }
            return $result;
        } finally {
            if ($locked) {
                self::unlock($accountId);
            }
        }
    }

    /**
     * Split subscription items into the base item and the extra-technician
     * item(s).
     *
     * An item is an extra item when its price is under the configured extra
     * product, or the item or its product carries metadata.firol_line_kind =
     * extra_technician, or (product expanded) its product is a legacy inline
     * product named LEGACY_PRODUCT_NAME. As a last resort an item is taken for
     * a legacy extra item by the id recorded on the account — but never when
     * that item is the base item: an item whose price is a configured base
     * price, or the only other item of the subscription, is the base item
     * whatever was recorded. So a changed STRIPE_PRICE_MONTHLY / _YEARLY never
     * turns an old subscriber's base item into the extra item.
     *
     * @param list<array<string, mixed>|\Stripe\StripeObject> $items
     * @return array{base: ?array<string, mixed>, extras: list<array<string, mixed>>, notes: list<string>}
     */
    public static function classifyItems(array $items, string $recordedExtraItemId = ''): array
    {
        $productId  = StripeClient::extraTechnicianProductId();
        $basePrices = StripeClient::basePriceIds();
        $extras = [];
        $rest   = [];
        $notes  = [];
        foreach ($items as $item) {
            $item = StripeShape::arr($item);
            $product = $item['price']['product'] ?? null;
            $isExtra = ($productId !== '' && StripeShape::itemProductId($item) === $productId)
                || (string) ($item['metadata']['firol_line_kind'] ?? '') === self::LINE_KIND
                || (is_array($product) && (
                    (string) ($product['metadata']['firol_line_kind'] ?? '') === self::LINE_KIND
                    || (string) ($product['name'] ?? '') === self::LEGACY_PRODUCT_NAME
                ));
            if ($isExtra) {
                $extras[] = $item;
            } else {
                $rest[] = $item;
            }
        }

        $base = null;
        foreach ($rest as $item) {
            if (in_array((string) ($item['price']['id'] ?? ''), $basePrices, true)) {
                $base = $item;
                break;
            }
        }

        if ($recordedExtraItemId !== '') {
            foreach ($rest as $i => $item) {
                if ((string) $item['id'] !== $recordedExtraItemId) {
                    continue;
                }
                $isBase = ($base !== null && $base['id'] === $item['id'])
                    || ($base === null && count($rest) === 1);
                if ($isBase) {
                    $notes[] = "recorded extra item {$item['id']} is the base item — ignored";
                } else {
                    $extras[] = $item;
                    unset($rest[$i]);
                    $notes[] = "legacy extra item {$item['id']} (not under STRIPE_PRODUCT_EXTRA_TECHNICIAN)";
                }
            }
            $rest = array_values($rest);
        }

        if ($base === null && $rest !== []) {
            $base = $rest[0];
            if (count($rest) > 1) {
                $notes[] = 'more than one unrecognised item; the first is taken as the base item';
            }
        }
        return ['base' => $base, 'extras' => $extras, 'notes' => $notes];
    }

    /**
     * Save the wanted extra quantity (active technicians above the included
     * count) and return the account row with `wanted`.
     *
     * @return array<string, mixed>|null
     */
    private static function saveWanted(int $accountId, bool $dryRun): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, included_technicians, extra_technicians,
                    stripe_subscription_id, stripe_extra_subscription_item_id,
                    billing_period, stripe_status
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            return null;
        }
        $wanted = self::wantedExtra($accountId, (int) $account['included_technicians']);
        if (!$dryRun && $wanted !== (int) $account['extra_technicians']) {
            Db::pdo()->prepare('UPDATE accounts SET extra_technicians = ? WHERE id = ?')
                ->execute([$wanted, $accountId]);
        }
        $account['wanted'] = $wanted;
        return $account;
    }

    /** Active technicians above the included count. */
    public static function wantedExtra(int $accountId, int $included): int
    {
        return max(0, self::countActiveTechnicians($accountId) - $included);
    }

    private static function lock(int $accountId): bool
    {
        $stmt = Db::pdo()->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute(['firol_seats_' . $accountId, self::LOCK_TIMEOUT]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function unlock(int $accountId): void
    {
        Db::pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute(['firol_seats_' . $accountId]);
    }

    /**
     * Counts active technicians on the account (the main user is always
     * active and is counted).
     */
    public static function countActiveTechnicians(int $accountId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM account_users
             WHERE  account_id = ? AND is_active = 1'
        );
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Current extra-technician monthly price in cents, from
     * `system_settings`. The yearly price comes from
     * pricePerExtraYearlyCents() — callers never derive it themselves.
     */
    public static function pricePerExtraCents(): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings
             WHERE  setting_key = 'price_per_extra_technician_cents' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : 1000;
    }

    /**
     * Current extra-technician yearly price in cents:
     * monthly × EXTRA_YEARLY_MONTHS.
     */
    public static function pricePerExtraYearlyCents(): int
    {
        return self::yearlyFromMonthlyCents(self::pricePerExtraCents());
    }

    /** Yearly extra-technician amount for a given monthly amount, in cents. */
    public static function yearlyFromMonthlyCents(int $monthlyCents): int
    {
        return $monthlyCents * self::EXTRA_YEARLY_MONTHS;
    }

    public static function defaultIncludedTechnicians(): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings
             WHERE  setting_key = 'default_included_technicians' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? max(1, (int) $val) : 2;
    }

    public static function maxSelfServiceTechnicians(): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT setting_value FROM system_settings
             WHERE  setting_key = 'max_self_service_technicians' LIMIT 1"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? max(1, (int) $val) : 20;
    }

    /**
     * Inline price for an extra-technician item of the given billing period,
     * under the fixed product STRIPE_PRODUCT_EXTRA_TECHNICIAN. Valid both for
     * a subscription item and for a Checkout line item. Yearly = monthly ×
     * EXTRA_YEARLY_MONTHS.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the product is not configured
     */
    public static function extraPriceData(string $billingPeriod): array
    {
        $productId = StripeClient::extraTechnicianProductId();
        if ($productId === '') {
            throw new \RuntimeException('STRIPE_PRODUCT_EXTRA_TECHNICIAN is not set');
        }
        $interval   = $billingPeriod === 'yearly' ? 'year' : 'month';
        $unitAmount = $billingPeriod === 'yearly'
            ? self::pricePerExtraYearlyCents()
            : self::pricePerExtraCents();

        return [
            'currency'    => 'eur',
            'product'     => $productId,
            'unit_amount' => $unitAmount,
            'recurring'   => ['interval' => $interval],
        ];
    }

    /**
     * The next regular charge of the account in cents, without prorations:
     * the base item plus the wanted extra technicians at the unit amount on
     * the Stripe item (or today's price when there is no item yet). Null when
     * there is no subscription or its base amount is not known yet.
     *
     * @param array<string, mixed> $account accounts row
     */
    public static function nextChargeCents(array $account): ?int
    {
        if ((string) ($account['stripe_subscription_id'] ?? '') === ''
            || !isset($account['stripe_base_unit_amount_cents'])) {
            return null;
        }
        $base  = (int) $account['stripe_base_unit_amount_cents'];
        $extra = (int) ($account['extra_technicians'] ?? 0);
        return $base + $extra * self::extraUnitCents($account);
    }

    /**
     * Unit amount of one extra technician for this account: the amount on its
     * Stripe item when one exists, else today's price for its billing period.
     *
     * @param array<string, mixed> $account accounts row
     */
    public static function extraUnitCents(array $account): int
    {
        if (isset($account['stripe_extra_unit_amount_cents'])
            && (string) ($account['stripe_extra_subscription_item_id'] ?? '') !== '') {
            return (int) $account['stripe_extra_unit_amount_cents'];
        }
        return ($account['billing_period'] ?? '') === 'yearly'
            ? self::pricePerExtraYearlyCents()
            : self::pricePerExtraCents();
    }
}
