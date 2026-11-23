<?php

declare(strict_types=1);

/**
 * One-off: move existing yearly extra-technician items to the new price.
 *
 * Until the fix list of 16. 11. 2026 (round 2, B4) a yearly extra technician
 * was created in Stripe at 12 × the monthly price; the price list sells it at
 * SeatSync::EXTRA_YEARLY_MONTHS × the monthly price. SeatSync only changes the
 * quantity of an existing item, so subscriptions created before the fix would
 * keep the old price for ever. This script replaces the price of those items.
 *
 *     php backend/bin/reprice-yearly-extra-seats.php [--apply] [--offline] [--account=ID] [--allow-increase]
 *
 *   (no flag)        dry run — the default. Reads each item from Stripe
 *                    (read-only) and lists what would change. Changes nothing.
 *   --offline        dry run from the database only, without any Stripe call.
 *   --apply          replaces the prices in Stripe. Cannot be combined with
 *                    --offline.
 *   --account=ID     limit the run to one account (a numeric id; anything
 *                    else is refused, as is any unknown option).
 *   --allow-increase also change an item whose new price is HIGHER. Without
 *                    it such an item is skipped: the terms (5.5) require 30
 *                    days' notice of a price increase.
 *
 * Which accounts: `billing_period = 'yearly'` with a subscription. Which
 * items: the subscription is read from Stripe and its items are classified
 * with SeatSync::classifyItems() — only items it calls extra-technician items
 * are repriced, never the plan item, an UNVERIFIED item or an unrecognised
 * one, whatever id the account recorded (the old webhook could record the
 * plan item as the extra item). A subscription that is cancelled, or set to
 * end at the period end, is skipped (there is no renewal to reprice). Yearly accounts with more active
 * technicians than included but no extra item are listed as „NOT CHARGED"
 * (see also bin/billing-seat-report.php).
 *
 * What changes: the item gets a new inline price — same product, currency,
 * interval and tax behaviour, unit amount = SeatSync::pricePerExtraYearlyCents()
 * — with `proration_behavior = none`. The year already paid stays as it is;
 * the renewal invoice charges the new price. The item id and its quantity do
 * not change, so the accounts table needs no update (the
 * customer.subscription.updated webhook that follows finds the same item).
 *
 * Skipped with a note: an item already at the new amount (so a second run
 * changes nothing), an item whose price is not yearly, a recorded item that is
 * not an extra item. A subscription Stripe cannot read is reported as an
 * error and the run continues; the exit code is then 1.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Stripe\StripeShape;

/**
 * @param object|null $stripe  \Stripe\StripeClient (or anything with the same
 *                             subscriptions->retrieve and subscriptionItems->update);
 *                             null = offline.
 * @param callable(string): void $out
 * @return int number of errors
 */
function repriceYearlyExtraSeats(PDO $pdo, ?object $stripe, bool $apply, ?int $onlyAccount, callable $out, bool $allowIncrease = false): int
{
    $newAmount = SeatSync::pricePerExtraYearlyCents();
    $mode = $apply ? 'APPLY' : ($stripe === null ? 'DRY RUN (offline, database only)' : 'DRY RUN');
    $out(sprintf(
        "Mode: %s. New yearly extra-technician price: %s EUR (%d cents = %d × %d).",
        $mode,
        number_format($newAmount / 100, 2, '.', ''),
        $newAmount,
        SeatSync::pricePerExtraCents(),
        SeatSync::EXTRA_YEARLY_MONTHS,
    ));

    $sql = "SELECT id, invoice_company_name, stripe_subscription_id,
                   stripe_extra_subscription_item_id, stripe_base_subscription_item_id,
                   extra_technicians, stripe_status, subscription_end_date
            FROM   accounts
            WHERE  billing_period = 'yearly'
              AND  stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''";
    $params = [];
    if ($onlyAccount !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlyAccount;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY id');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out(sprintf('Accounts found: %d.', count($rows)));

    $errors = 0;
    $changed = 0;
    foreach ($rows as $row) {
        $accountId = (int) $row['id'];
        $subId     = (string) $row['stripe_subscription_id'];
        $itemId    = (string) $row['stripe_extra_subscription_item_id'];
        $label = sprintf(
            'account %d (%s) sub=%s item=%s qty(db)=%d status=%s paid until=%s',
            $accountId,
            (string) $row['invoice_company_name'],
            $subId,
            $itemId,
            (int) $row['extra_technicians'],
            (string) ($row['stripe_status'] ?? '-'),
            (string) ($row['subscription_end_date'] ?? '-'),
        );

        if ($stripe === null) {
            if ($itemId === '') {
                continue; // offline: nothing recorded to name
            }
            $out("  $label: would set the price to $newAmount cents/year (current price not read — offline; online the subscription's items are classified first)");
            $changed++;
            continue;
        }

        try {
            $sub = StripeShape::arr($stripe->subscriptions->retrieve($subId, SeatSync::RETRIEVE_EXPAND));
        } catch (\Throwable $e) {
            $errors++;
            $out("  $label: ERROR reading the subscription from Stripe: " . $e->getMessage());
            continue;
        }
        $subStatus = (string) ($sub['status'] ?? '');
        if (in_array($subStatus, ['canceled', 'incomplete_expired', 'incomplete'], true)) {
            $out("  $label: SKIP — the subscription is $subStatus");
            continue;
        }
        if (!empty($sub['cancel_at_period_end'])) {
            $out("  $label: SKIP — the subscription ends at the period end (no renewal to reprice)");
            continue;
        }

        // Only what classifyItems() calls an extra-technician item — never
        // the plan item the old webhook may have recorded as „extra".
        $split = SeatSync::classifyItems((array) ($sub['items']['data'] ?? []), $itemId, null,
            (string) ($row['stripe_base_subscription_item_id'] ?? ''));
        $extraIds = array_map(static fn (array $i): string => (string) $i['id'], $split['extras']);
        if ($itemId !== '' && !in_array($itemId, $extraIds, true)) {
            $out("  $label: NOTE — the recorded item $itemId is not an extra-technician item (the plan item, an UNVERIFIED or unrecognised item, or gone) — it is never repriced");
        }
        if ($split['extras'] === []) {
            $out("  $label: SKIP — no extra-technician item on the subscription");
            continue;
        }

        foreach ($split['extras'] as $item) {
            $thisItem  = (string) $item['id'];
            $price     = (array) ($item['price'] ?? []);
            $oldAmount = (int) ($price['unit_amount'] ?? 0);
            $interval  = (string) ($price['recurring']['interval'] ?? '');
            $product   = $price['product'] ?? '';
            $product   = is_array($product) ? (string) ($product['id'] ?? '') : (string) $product;
            $currency  = (string) ($price['currency'] ?? 'eur');
            $taxBehavior = (string) ($price['tax_behavior'] ?? '');
            $qty       = (int) ($item['quantity'] ?? 0);
            $itemLabel = "$label extra item $thisItem";

            if ($interval !== 'year') {
                $out("  $itemLabel: SKIP — the item's price is not yearly (interval=$interval)");
                continue;
            }
            if ($product === '') {
                $errors++;
                $out("  $itemLabel: ERROR — the item's price has no product");
                continue;
            }
            if ($oldAmount === $newAmount) {
                $out("  $itemLabel: SKIP — already at $newAmount cents/year");
                continue;
            }
            if ($newAmount > $oldAmount && !$allowIncrease) {
                $out("  $itemLabel: SKIP — $oldAmount → $newAmount cents/year would RAISE the price; the terms (5.5) need 30 days' notice. Re-run with --allow-increase once the customer was told.");
                continue;
            }

            $priceData = [
                'currency'    => $currency,
                'product'     => $product,
                'unit_amount' => $newAmount,
                'recurring'   => ['interval' => 'year'],
            ];
            if ($taxBehavior === 'inclusive' || $taxBehavior === 'exclusive') {
                $priceData['tax_behavior'] = $taxBehavior;
            }
            $params = [
                'price_data'         => $priceData,
                'proration_behavior' => 'none',
            ];

            $what = sprintf(
                '%d → %d cents/year × qty %d (product %s, %s)',
                $oldAmount, $newAmount, $qty, $product, $currency,
            );

            if (!$apply) {
                $out("  $itemLabel: would change $what");
                $out('    params: ' . json_encode($params, JSON_UNESCAPED_SLASHES));
                $changed++;
                continue;
            }

            try {
                $stripe->subscriptionItems->update($thisItem, $params);
                $out("  $itemLabel: CHANGED $what");
                $changed++;
            } catch (\Throwable $e) {
                $errors++;
                $out("  $itemLabel: ERROR updating the item: " . $e->getMessage());
            }
        }
    }

    // Yearly accounts above the included count whose subscription has no
    // extra item at all: nothing to reprice, and nothing charged.
    $sql = "SELECT a.id, a.invoice_company_name, a.stripe_subscription_id, a.included_technicians,
                   (SELECT COUNT(*) FROM account_users au WHERE au.account_id = a.id AND au.is_active = 1) AS active
            FROM   accounts a
            WHERE  a.billing_period = 'yearly'
              AND  a.stripe_subscription_id IS NOT NULL AND a.stripe_subscription_id <> ''
              AND  (a.stripe_status IS NULL OR a.stripe_status NOT IN ('canceled', 'incomplete_expired'))
              AND  (a.stripe_extra_subscription_item_id IS NULL OR a.stripe_extra_subscription_item_id = '')";
    $params = [];
    if ($onlyAccount !== null) {
        $sql .= ' AND a.id = ?';
        $params[] = $onlyAccount;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY a.id');
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $extra = (int) $row['active'] - (int) $row['included_technicians'];
        if ($extra > 0) {
            $out(sprintf(
                '  account %d (%s) sub=%s: NOT CHARGED — %d extra technician(s), no extra item on the subscription',
                (int) $row['id'], (string) $row['invoice_company_name'], (string) $row['stripe_subscription_id'], $extra,
            ));
        }
    }

    $out(sprintf(
        'Done. %d item(s) %s, %d error(s).',
        $changed,
        $apply ? 'changed' : 'would change',
        $errors,
    ));
    return $errors;
}

// Allows a test harness to load the function without running the script.
if (defined('FIROL_REPRICE_NO_MAIN')) {
    return;
}

// getopt() silently ignores an option it does not know, so a misspelled
// --acount=5 would run on every account. Check every argument first.
$known = ['--apply', '--offline', '--allow-increase'];
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, $known, true) || preg_match('/^--account=\d+$/', $arg) === 1) {
        continue;
    }
    fwrite(STDERR, "Unknown or invalid argument: $arg\n"
        . "Usage: php bin/reprice-yearly-extra-seats.php [--apply] [--offline] [--account=ID] [--allow-increase]\n");
    exit(2);
}

$options = getopt('', ['apply', 'offline', 'account:', 'allow-increase']);
$apply   = isset($options['apply']);
$offline = isset($options['offline']);
$allowIncrease = isset($options['allow-increase']);
$account = isset($options['account']) ? (int) $options['account'] : null;

if ($apply && $offline) {
    fwrite(STDERR, "--apply needs Stripe; it cannot be combined with --offline.\n");
    exit(2);
}

$stripe = null;
if (!$offline) {
    $secret = (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '');
    if ($secret === '') {
        fwrite(STDERR, "STRIPE_SECRET_KEY is not set. Use --offline for a database-only dry run.\n");
        exit(2);
    }
    $stripe = \Firol\Stripe\StripeClient::get();
}

$errors = repriceYearlyExtraSeats(
    Db::pdo(),
    $stripe,
    $apply,
    $account,
    static function (string $line): void { echo $line, "\n"; },
    $allowIncrease,
);
exit($errors > 0 ? 1 : 0);
