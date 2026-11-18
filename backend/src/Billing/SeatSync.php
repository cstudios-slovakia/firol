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
     * Names of the inline products Checkout created for the extra line before
     * STRIPE_PRODUCT_EXTRA_TECHNICIAN existed (each Checkout made a new
     * product): „Firol — extra technik" from 17. 5. to 28. 7. 2026 (commit
     * 58976b9, renamed in c39d18b), „POapp — extra technik" after that.
     * Recognises those legacy items when the item's product is expanded, and
     * their invoice lines by description. The first name is the current one
     * (Checkout's fallback when the product variable is not set).
     */
    public const LEGACY_PRODUCT_NAMES = ['POapp — extra technik', 'Firol — extra technik'];

    /** system_settings key prefix caching the product of a base price id. */
    private const PRICE_PRODUCT_KEY = 'stripe_price_product:';

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
     * `action`: none | changed | would_change (dry run, or BILLING_RECONCILE
     * off) | manual (left for a person: an item matched only by the recorded
     * id, or no base item). `subscription` is the subscription as read from
     * Stripe (absent when none was read).
     *
     * @return array{action: string, wanted: int, before: int, after: int, notes: list<string>, subscription?: array<string, mixed>}
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
            $result['subscription'] = $sub;
            $status = (string) ($sub['status'] ?? '');
            if (in_array($status, self::FROZEN_STATUSES, true)) {
                $result['notes'][] = "subscription $subId is $status — not changed";
                return $result;
            }

            // Stripe is written only when the reconcile switch is on; with
            // BILLING_RECONCILE=off the wanted quantity is still saved and
            // the difference reported (deploy order: report first).
            $push = !$dryRun && self::pushEnabled();
            $would = static fn (string $done, string $planned): string => $push ? $done : $planned;

            $baseProducts = self::baseProductIds();
            if ($baseProducts === null) {
                throw new \RuntimeException('the products of STRIPE_PRICE_MONTHLY / _YEARLY could not be read from Stripe — nothing changed');
            }
            $productId = StripeClient::extraTechnicianProductId();
            if ($productId !== '' && in_array($productId, $baseProducts, true)) {
                throw new \RuntimeException('STRIPE_PRODUCT_EXTRA_TECHNICIAN is the product of the base plan — nothing changed (fix the variable)');
            }

            $items = (array) ($sub['items']['data'] ?? []);
            $split = self::classifyItems(
                $items,
                (string) ($account['stripe_extra_subscription_item_id'] ?? ''),
                $baseProducts,
            );
            $extras = $split['extras'];
            $base   = $split['base'];
            $before = array_sum(array_map(static fn (array $i): int => (int) ($i['quantity'] ?? 0), $extras));
            $result['before'] = $before;
            $result['notes']  = array_merge($result['notes'], $split['notes']);

            // Items nobody can vouch for are never touched, and while one is
            // on the subscription the count is uncertain (it may be an old
            // extra item) — a person decides; the seat report lists it.
            if ($split['unverified'] !== []) {
                $result['action'] = 'manual';
                $result['notes'][] = 'NOT CHANGED — item(s) ' . implode(', ', array_map(static fn (array $i): string => (string) $i['id'], $split['unverified']))
                    . ' matched only by the recorded id; check the subscription in Stripe';
                error_log("[seat-sync] account=$accountId sub=$subId left for a person: " . implode('; ', $result['notes']));
                return $result;
            }
            if ($base === null && $extras !== []) {
                $result['action'] = 'manual';
                $result['notes'][] = 'NOT CHANGED — no base item recognised, every item looks like an extra item';
                error_log("[seat-sync] account=$accountId sub=$subId left for a person: no base item");
                return $result;
            }

            $interval = $base !== null ? StripeShape::itemInterval($base) : null;

            // Which extra item stays: the one under the configured product,
            // else the first. Any other is a duplicate and is removed.
            $keep = null;
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

            // Never remove or change the last item of a subscription.
            $remaining = count($items);
            $actions = [];
            foreach ($duplicates as $dup) {
                if ($remaining <= 1) {
                    $actions[] = 'kept ' . $dup['id'] . ' (last item of the subscription)';
                    continue;
                }
                if ($push) {
                    $stripe->subscriptionItems->delete((string) $dup['id'], ['proration_behavior' => 'create_prorations']);
                }
                $remaining--;
                $actions[] = $would('merged', 'would merge') . ' duplicate ' . $dup['id'] . ' (qty ' . (int) ($dup['quantity'] ?? 0) . ')';
            }

            $keepQty = $keep !== null ? (int) ($keep['quantity'] ?? 0) : 0;
            if ($wanted <= 0) {
                if ($keep !== null && $remaining <= 1) {
                    $actions[] = 'kept ' . $keep['id'] . ' (last item of the subscription)';
                } elseif ($keep !== null) {
                    $keepId = (string) $keep['id'];
                    if ($push) {
                        $stripe->subscriptionItems->delete($keepId, ['proration_behavior' => 'create_prorations']);
                        $keep = null;
                    }
                    $actions[] = $would('deleted ', 'would delete ') . $keepId;
                }
            } elseif ($keep === null) {
                if ($interval !== 'month' && $interval !== 'year') {
                    throw new \RuntimeException("subscription $subId: base item interval unknown — extra item not created");
                }
                if ($push) {
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
                $actions[] = $would('created', 'would create') . " extra item qty $wanted";
            } elseif ($keepQty !== $wanted) {
                if (count($items) <= 1) {
                    $actions[] = 'kept ' . $keep['id'] . ' (last item of the subscription)';
                } else {
                    $keepId = (string) $keep['id'];
                    if ($push) {
                        $keep = StripeShape::arr($stripe->subscriptionItems->update($keepId, [
                            'quantity'           => $wanted,
                            'proration_behavior' => 'create_prorations',
                        ]));
                    }
                    $actions[] = $would('set ', 'would set ') . $keepId . " qty $keepQty → $wanted";
                }
            }
            $result['after'] = $push ? $wanted : $before;
            if (!$dryRun && !$push && $actions !== []) {
                $result['notes'][] = 'BILLING_RECONCILE=off — Stripe not changed';
            }

            if (!$dryRun) {
                Db::pdo()->prepare(
                    'UPDATE accounts
                     SET    stripe_extra_subscription_item_id = ?,
                            stripe_extra_unit_amount_cents    = ?,
                            stripe_extra_interval             = ?,
                            stripe_base_unit_amount_cents     = ?,
                            stripe_has_discount               = ?
                     WHERE  id = ?'
                )->execute([
                    $keep !== null ? (string) $keep['id'] : null,
                    $keep !== null && isset($keep['price']['unit_amount']) ? (int) $keep['price']['unit_amount'] : null,
                    $keep !== null ? StripeShape::itemInterval($keep) : null,
                    $base !== null && isset($base['price']['unit_amount']) ? (int) $base['price']['unit_amount'] : null,
                    StripeShape::subscriptionHasDiscount($sub) ? 1 : 0,
                    $accountId,
                ]);
            }

            if ($actions !== []) {
                $changed = $push && array_filter($actions, static fn (string $a): bool => !str_starts_with($a, 'kept ')) !== [];
                $result['action'] = $changed ? 'changed' : 'would_change';
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
     * Split subscription items into the base item, the extra-technician
     * item(s) and items nobody can vouch for.
     *
     * Never an extra item: an item whose price is a configured base price
     * (STRIPE_PRICE_MONTHLY / _YEARLY) or whose product is the product of
     * one — whatever else it matches, so a mis-set
     * STRIPE_PRODUCT_EXTRA_TECHNICIAN cannot turn the plan into the extra
     * item.
     *
     * An extra item: its price is under the configured extra product, or the
     * item or its product carries metadata.firol_line_kind =
     * extra_technician, or (product expanded) its product is a legacy inline
     * product named as in LEGACY_PRODUCT_NAMES.
     *
     * Unverified: an item with none of those markers that only the id
     * recorded on the account points to (an extra item of a very old
     * Checkout, or the base item recorded by the old webhook after a base
     * price change). It is never changed or deleted; reconcile() leaves such
     * a subscription to a person and the seat report lists it.
     *
     * @param list<array<string, mixed>|\Stripe\StripeObject> $items
     * @param list<string>|null $baseProducts products of the base prices
     *        (baseProductIds()); read when null
     * @return array{base: ?array<string, mixed>, extras: list<array<string, mixed>>, unverified: list<array<string, mixed>>, notes: list<string>}
     */
    public static function classifyItems(array $items, string $recordedExtraItemId = '', ?array $baseProducts = null): array
    {
        $productId  = StripeClient::extraTechnicianProductId();
        $basePrices = StripeClient::basePriceIds();
        $notes      = [];
        if ($baseProducts === null) {
            $baseProducts = self::baseProductIds();
            if ($baseProducts === null) {
                $baseProducts = [];
                $notes[] = 'products of the base prices unknown (Stripe not readable)';
            }
        }

        $baseItems = [];
        $extras    = [];
        $rest      = [];
        foreach ($items as $item) {
            $item = StripeShape::arr($item);
            $itemProduct = StripeShape::itemProductId($item);
            if (in_array((string) ($item['price']['id'] ?? ''), $basePrices, true)
                || ($itemProduct !== null && in_array($itemProduct, $baseProducts, true))) {
                $baseItems[] = $item;
                continue;
            }
            if (self::hasExtraMarker($item, $productId)) {
                $extras[] = $item;
            } else {
                $rest[] = $item;
            }
        }

        $unverified = [];
        if ($recordedExtraItemId !== '') {
            foreach ($rest as $i => $item) {
                if ((string) $item['id'] !== $recordedExtraItemId) {
                    continue;
                }
                $isBase = $baseItems === [] && count($rest) === 1;
                if ($isBase) {
                    $notes[] = "recorded extra item {$item['id']} is the base item — ignored";
                } else {
                    $unverified[] = $item;
                    unset($rest[$i]);
                    $notes[] = "UNVERIFIED item {$item['id']}: only the recorded id says it is an extra item (no extra product, metadata or legacy name) — never changed";
                }
            }
            $rest = array_values($rest);
        }
        foreach ($recordedExtraItemId !== '' ? $baseItems : [] as $item) {
            if ((string) $item['id'] === $recordedExtraItemId) {
                $notes[] = "recorded extra item {$item['id']} is the base item — ignored";
            }
        }

        $base = $baseItems[0] ?? null;
        if (count($baseItems) > 1) {
            $notes[] = 'more than one base-plan item: ' . implode(', ', array_map(static fn (array $i): string => (string) $i['id'], $baseItems));
        }
        if ($base === null && $rest !== []) {
            $base = $rest[0];
            if (count($rest) > 1) {
                $notes[] = 'more than one unrecognised item; the first is taken as the base item';
            }
        }
        return ['base' => $base, 'extras' => $extras, 'unverified' => $unverified, 'notes' => $notes];
    }

    /**
     * Whether an item carries a mark of an extra-technician item, and which:
     * the configured product, metadata, or a legacy product name.
     *
     * @param array<string, mixed> $item
     */
    private static function hasExtraMarker(array $item, string $productId): bool
    {
        $product = $item['price']['product'] ?? null;
        return ($productId !== '' && StripeShape::itemProductId($item) === $productId)
            || (string) ($item['metadata']['firol_line_kind'] ?? '') === self::LINE_KIND
            || (is_array($product) && (
                (string) ($product['metadata']['firol_line_kind'] ?? '') === self::LINE_KIND
                || in_array((string) ($product['name'] ?? ''), self::LEGACY_PRODUCT_NAMES, true)
            ));
    }

    /**
     * Whether an item was recognised only by a legacy product name (a seat
     * report flag: an old Checkout's inline product).
     *
     * @param array<string, mixed> $item
     */
    public static function isLegacyNamedItem(array $item): bool
    {
        $product = $item['price']['product'] ?? null;
        $productId = StripeClient::extraTechnicianProductId();
        return is_array($product)
            && in_array((string) ($product['name'] ?? ''), self::LEGACY_PRODUCT_NAMES, true)
            && !($productId !== '' && StripeShape::itemProductId($item) === $productId);
    }

    /**
     * Products of the configured base prices (STRIPE_PRICE_MONTHLY /
     * _YEARLY), or null when Stripe could not be read. A price's product
     * never changes, so each lookup is cached in system_settings.
     *
     * @return list<string>|null
     */
    public static function baseProductIds(): ?array
    {
        static $memo = [];
        $out = [];
        foreach (StripeClient::basePriceIds() as $priceId) {
            if (isset($memo[$priceId])) {
                $out[] = $memo[$priceId];
                continue;
            }
            $key = self::PRICE_PRODUCT_KEY . $priceId;
            try {
                $stmt = Db::pdo()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
                $stmt->execute([$key]);
                $cached = $stmt->fetchColumn();
                if (is_string($cached) && $cached !== '') {
                    $memo[$priceId] = $cached;
                    $out[] = $cached;
                    continue;
                }
                if (!StripeClient::isConfigured()) {
                    return null;
                }
                $price = StripeClient::get()->prices->retrieve($priceId);
                $product = is_string($price->product) ? $price->product : (string) ($price->product->id ?? '');
                if ($product === '') {
                    return null;
                }
                Db::pdo()->prepare(
                    'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                )->execute([$key, $product]);
                $memo[$priceId] = $product;
                $out[] = $product;
            } catch (\Throwable $e) {
                error_log("[seat-sync] product of price $priceId: " . $e->getMessage());
                return null;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Whether seat changes are pushed to Stripe. BILLING_RECONCILE=off (or
     * false / 0) stops every push — webhooks still record the state, the
     * daily run only reports — so the seat report can be run against
     * production after a deploy before anything is charged.
     */
    public static function pushEnabled(): bool
    {
        $v = strtolower(trim((string) ($_ENV['BILLING_RECONCILE'] ?? '')));
        return !in_array($v, ['off', 'false', '0', 'no'], true);
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
     * Price of the extra-technician line of a Checkout Session. Normally
     * extraPriceData() (the fixed product). When STRIPE_PRODUCT_EXTRA_TECHNICIAN
     * is not set — or is set to the base plan's product, which would make the
     * line unrecognisable — Checkout still works with an inline product
     * (Checkout accepts product_data, subscription items do not) named as
     * the first of LEGACY_PRODUCT_NAMES and marked with firol_line_kind, so
     * classifyItems() recognises the item. ConfigCheck reports the variable.
     *
     * @return array<string, mixed>
     */
    public static function checkoutExtraPriceData(string $billingPeriod): array
    {
        $productId = StripeClient::extraTechnicianProductId();
        if ($productId !== '' && !in_array($productId, self::baseProductIds() ?? [], true)) {
            return self::extraPriceData($billingPeriod);
        }
        error_log('[seat-sync] Checkout uses an inline extra-technician product: STRIPE_PRODUCT_EXTRA_TECHNICIAN '
            . ($productId === '' ? 'is not set' : 'is the base plan product'));
        return [
            'currency'     => 'eur',
            'product_data' => [
                'name'     => self::LEGACY_PRODUCT_NAMES[0],
                'metadata' => ['firol_line_kind' => self::LINE_KIND],
            ],
            'unit_amount'  => $billingPeriod === 'yearly' ? self::pricePerExtraYearlyCents() : self::pricePerExtraCents(),
            'recurring'    => ['interval' => $billingPeriod === 'yearly' ? 'year' : 'month'],
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
