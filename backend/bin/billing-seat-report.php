<?php

declare(strict_types=1);

/**
 * Report only — changes nothing anywhere.
 *
 *     php backend/bin/billing-seat-report.php [--account=ID] [--all]
 *
 * Lists accounts whose active technicians exceed the included count while
 * their Stripe subscription carries no extra-technician item, or a lower
 * quantity: „NOT CHARGED". Until 17. 11. 2026 Stripe refused the first extra
 * item on a live subscription and the next webhook reset the count to 0, so
 * these accounts used technicians they did not pay for. What to do about the
 * past is the owner's decision; the next roster change, webhook or daily run
 * charges them from now on.
 *
 * Also flagged: a higher Stripe quantity than wanted („OVERCHARGED"),
 * duplicate extra items, a legacy extra item recognised by the name of an
 * old Checkout's inline product („POapp — extra technik" / „Firol — extra
 * technik"), an UNVERIFIED item (only the id recorded on the account says it
 * is an extra item — the reconcile never touches it and leaves the whole
 * subscription to a person), and a customer with more than one live
 * subscription (the report then says which one it checked and why).
 * --all prints every checked account, not only the flagged ones.
 *
 * Meant to run against production right after a deploy with
 * BILLING_RECONCILE=off (nothing is pushed to Stripe yet), before the owner
 * decides and the switch goes on — see the README deploy checklist.
 *
 * Reads the database and Stripe (GET requests only).
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Billing\SeatSync;
use Firol\Billing\SubscriptionStatus;
use Firol\Db;
use Firol\Stripe\StripeClient;
use Firol\Stripe\StripeShape;

$options = getopt('', ['account:', 'all']);
$onlyAccount = null;
if (isset($options['account'])) {
    if (!is_string($options['account']) || !ctype_digit($options['account'])) {
        fwrite(STDERR, "--account must be a numeric account id.\n");
        exit(2);
    }
    $onlyAccount = (int) $options['account'];
}
$all = isset($options['all']);

if (!StripeClient::isConfigured()) {
    fwrite(STDERR, "STRIPE_SECRET_KEY is not set.\n");
    exit(2);
}
$stripe = StripeClient::get();
echo 'Stripe mode: ', StripeClient::isTestKey() ? 'TEST' : 'LIVE',
    ', reconcile ', SeatSync::pushEnabled() ? 'ON (seat changes are pushed to Stripe)' : 'OFF (BILLING_RECONCILE=off — nothing is pushed)', "\n";
$baseProducts = SeatSync::baseProductIds();
if ($baseProducts === null) {
    fwrite(STDERR, "The products of STRIPE_PRICE_MONTHLY / _YEARLY could not be read from Stripe.\n");
    exit(2);
}

$sql = "SELECT id, invoice_company_name, included_technicians, stripe_customer_id,
               stripe_subscription_id, stripe_status, stripe_extra_subscription_item_id,
               stripe_base_subscription_item_id, billing_period
        FROM   accounts
        WHERE  stripe_customer_id IS NOT NULL AND stripe_customer_id <> ''";
$params = [];
if ($onlyAccount !== null) {
    $sql .= ' AND id = ?';
    $params[] = $onlyAccount;
}
$stmt = Db::pdo()->prepare($sql . ' ORDER BY id');
$stmt->execute($params);

$flagged = 0;
$errors  = 0;
foreach ($stmt->fetchAll() as $a) {
    $accountId = (int) $a['id'];
    $active = SeatSync::countActiveTechnicians($accountId);
    $wanted = max(0, $active - (int) $a['included_technicians']);
    $label = sprintf('account %d (%s): active %d, included %d, wanted extra %d',
        $accountId, (string) $a['invoice_company_name'], $active, (int) $a['included_technicians'], $wanted);

    try {
        // The same reading as the daily run (SeatSync::liveSubscriptions):
        // every live subscription of the customer, expanded, newest first.
        $live = SeatSync::liveSubscriptions((string) $a['stripe_customer_id']);
    } catch (\Throwable $e) {
        $errors++;
        echo "  $label: ERROR reading Stripe: ", $e->getMessage(), "\n";
        continue;
    }

    $flags = [];
    if (count($live) > 1) {
        $flags[] = 'MORE THAN ONE LIVE SUBSCRIPTION (' . implode(', ', array_map(static fn (array $s): string => $s['id'] . ' ' . $s['status']
            . ($s['extra_qty'] > 0 ? ' with ' . $s['extra_qty'] . ' extra' : ''), $live)) . ')';
    }

    $current = null;
    foreach ($live as $s) {
        if ($s['id'] === $a['stripe_subscription_id']) {
            $current = $s;
        }
    }
    if (count($live) > 1) {
        $flags[] = $current !== null
            ? "checked {$current['id']} — the one recorded on the account"
            : 'checked ' . $live[0]['id'] . ' — none is recorded on the account (db ' . ($a['stripe_subscription_id'] ?: '-') . '), the newest live one';
    }
    $current ??= $live[0] ?? null;

    if ($current === null) {
        if ($all) {
            echo "  $label: no live subscription (db status " . ($a['stripe_status'] ?? '-') . ")\n";
        }
        if ($flags !== []) {
            $flagged++;
            echo "  $label: ", implode('; ', $flags), "\n";
        }
        continue;
    }

    $data = $current;
    $split = SeatSync::classifyItems((array) ($data['items']['data'] ?? []), (string) ($a['stripe_extra_subscription_item_id'] ?? ''), $baseProducts,
        (string) ($a['stripe_base_subscription_item_id'] ?? ''));
    $qty   = array_sum(array_map(static fn (array $i): int => (int) ($i['quantity'] ?? 0), $split['extras']));
    $unit  = isset($split['extras'][0]['price']['unit_amount']) ? (int) $split['extras'][0]['price']['unit_amount'] : null;
    $interval = $split['base'] !== null ? StripeShape::itemInterval($split['base']) : '?';

    if ($wanted > $qty) {
        $flags[] = $qty === 0
            ? "NOT CHARGED — no extra item for $wanted technician(s)"
            : "NOT CHARGED — Stripe quantity $qty, wanted $wanted";
    } elseif ($qty > $wanted) {
        $flags[] = "OVERCHARGED — Stripe quantity $qty, wanted $wanted";
    }
    if (count($split['extras']) > 1) {
        $flags[] = 'DUPLICATE extra items: ' . implode(', ', array_map(static fn (array $i): string => $i['id'] . '×' . (int) $i['quantity'], $split['extras']));
    }
    foreach ($split['extras'] as $item) {
        if (SeatSync::isLegacyNamedItem($item)) {
            $flags[] = "LEGACY item {$item['id']} recognised by its product name „{$item['price']['product']['name']}\" (old Checkout, not under STRIPE_PRODUCT_EXTRA_TECHNICIAN)";
        }
    }
    foreach ($split['unverified'] as $item) {
        $flags[] = "UNVERIFIED item {$item['id']} (qty " . (int) ($item['quantity'] ?? 0) . ', price ' . (string) ($item['price']['id'] ?? '?')
            . ') — only the recorded id calls it an extra item; the reconcile leaves this subscription to a person';
    }
    foreach ($split['unknown'] as $item) {
        $flags[] = "UNRECOGNISED item {$item['id']} (qty " . (int) ($item['quantity'] ?? 0) . ', price ' . (string) ($item['price']['id'] ?? '?')
            . ') — neither the plan nor a marked extra item (an old extra item?); the reconcile leaves this subscription to a person';
    }
    foreach ($split['notes'] as $note) {
        if (str_contains($note, 'base item') || str_contains($note, 'base-plan') || str_contains($note, 'unrecognised')) {
            $flags[] = $note;
        }
    }

    $line = sprintf('%s — sub %s %s %s, Stripe extra qty %d%s', $label, $current['id'], $current['status'], $interval,
        $qty, $unit !== null ? ' at ' . number_format($unit / 100, 2, ',', ' ') . ' €' : '');
    if ($flags !== []) {
        $flagged++;
        echo "  $line\n    ", implode("\n    ", $flags), "\n";
    } elseif ($all) {
        echo "  $line: ok\n";
    }
}

echo "Done. $flagged flagged account(s), $errors error(s).\n";
exit($errors > 0 ? 1 : 0);
