<?php

declare(strict_types=1);

/**
 * Billing check against Stripe TEST mode with test clocks (fix list of
 * 17. 11. 2026, docs/prompts/billing-fixes-2026-11.md).
 *
 *     docker compose exec php php bin/test-billing-stripe.php [--only=a,b,…] [--keep] [--capture=DIR]
 *
 * Refuses anything but an sk_test_ key and APP_ENV=production. Every Stripe
 * object it creates hangs on a test clock (customer, subscription, invoices),
 * with generic names and example.com addresses, and the clocks are deleted at
 * the end (--keep leaves them for a look in the dashboard). The one exception
 * is scenario F's legacy price, created with an inline product exactly as an
 * old Checkout did; it is archived at the end. Throwaway accounts are created
 * in the local database and deleted at the end.
 *
 * iDoklad is never called: its credentials are removed from this process,
 * IDOKLAD_ISSUE_MODE is forced to `prepare`, and the webhook replay (which
 * runs in PHP-FPM with backend/.env) is refused unless that env would only
 * prepare as well.
 *
 * Scenarios — all through the app's own code paths (SeatSync::recompute as
 * every roster change calls it, BillingController::handleSubscriptionUpsert
 * as checkout-sync and the webhook call it, checkoutLineItems as Checkout
 * builds them):
 *   A  monthly: subscribe with 2 technicians, add a 3rd (item created, qty 1),
 *      a 4th (qty 2), remove both (item deleted), add again (new item qty 1);
 *      the renewal invoice carries the prorations.
 *   B  yearly: add a technician 4 months in; the renewal invoice carries the
 *      proration next to the new year.
 *   C  trial: a technician added during the Stripe trial — item created, no
 *      proration; the 0 € trial invoice.
 *   D  two additions at the same moment (two processes) — one item, qty 2.
 *   E  a failed SeatSync call, then the reconcile repairs it; duplicate extra
 *      items are merged.
 *   F  a changed base price id: the base item is never taken for the extra
 *      item, a legacy extra item (inline product) is still recognised.
 *   G  an old subscription's events do not overwrite the new subscription.
 *   R  replay of every captured event through the real webhook (HTTP, signed):
 *      the state comes out right, no 0 € invoice, the period text, credit
 *      invoices held as needs_review, duplicate and out-of-order deliveries.
 *   P  (decision 3, report only) a renewal whose card fails: what Stripe does
 *      to current_period_end and the status under the default retry settings.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Billing\SeatSync;
use Firol\Controllers\BillingController;
use Firol\Db;
use Firol\Idoklad\IssuePolicy;
use Firol\Stripe\StripeClient;
use Firol\Stripe\StripeShape;

// ── Guards ──────────────────────────────────────────────────────────────
if (strtolower((string) ($_ENV['APP_ENV'] ?? '')) === 'production') {
    fwrite(STDERR, "Refusing to run with APP_ENV=production.\n");
    exit(1);
}
if (!str_starts_with((string) ($_ENV['STRIPE_SECRET_KEY'] ?? ''), 'sk_test_')) {
    fwrite(STDERR, "Refusing: STRIPE_SECRET_KEY is not a test-mode key (sk_test_…).\n");
    exit(1);
}
foreach (['IDOKLAD_CLIENT_ID', 'IDOKLAD_CLIENT_SECRET'] as $name) {
    unset($_ENV[$name], $_SERVER[$name]);
    putenv($name);
}
$_ENV['IDOKLAD_ISSUE_MODE'] = 'prepare';
unset($_ENV['IDOKLAD_ALLOW_TEST_EVENTS']);
if (StripeClient::extraTechnicianProductId() === '') {
    fwrite(STDERR, "STRIPE_PRODUCT_EXTRA_TECHNICIAN is not set (a test-mode product id).\n");
    exit(1);
}

// Child mode for scenario D: one roster recompute, as a request would run it.
foreach ($argv as $arg) {
    if (preg_match('/^--child-recompute=(\d+)$/', $arg, $m)) {
        SeatSync::recompute((int) $m[1]);
        exit(0);
    }
}

$options = getopt('', ['only:', 'keep', 'capture:']);
$only    = isset($options['only']) ? array_map('strtoupper', explode(',', (string) $options['only'])) : null;
$keep    = isset($options['keep']);
$capture = isset($options['capture']) ? rtrim((string) $options['capture'], '/') : null;
$run     = static fn (string $s): bool => $only === null || in_array($s, $only, true);

$stripe = StripeClient::get();
$pdo    = Db::pdo();
$t0     = time();
$tag    = 'bt' . date('His');

$failures = 0;
$expect = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
        echo '       expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . "\n       got      " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
};
$note = static function (string $line): void { echo "     · $line\n"; };

// ── Cleanup ─────────────────────────────────────────────────────────────
$clocks = [];
$accounts = [];
$users = [];
$customers = [];
$archive = [];
register_shutdown_function(static function () use (&$clocks, &$accounts, &$users, &$archive, $keep, $stripe, $pdo): void {
    foreach ($accounts as $id) {
        $pdo->prepare('DELETE FROM invoices WHERE account_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
    }
    if ($users !== []) {
        $in = implode(',', array_fill(0, count($users), '?'));
        $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($users);
    }
    foreach ($archive as [$kind, $id]) {
        try {
            if ($kind === 'price') {
                // A price made with product_data is its product's default
                // price, which cannot be archived — unset that first.
                $price = $stripe->prices->retrieve($id);
                $stripe->products->update((string) $price->product, ['default_price' => '']);
                $stripe->prices->update($id, ['active' => false]);
            } else {
                $stripe->products->update($id, ['active' => false]);
            }
        } catch (\Throwable $e) {
            echo "cleanup: could not archive $kind $id: {$e->getMessage()}\n";
        }
    }
    if ($keep) {
        echo 'Test clocks kept: ' . implode(', ', $clocks) . "\n";
        return;
    }
    $deleted = 0;
    foreach ($clocks as $id) {
        try {
            $stripe->testHelpers->testClocks->delete($id);
            $deleted++;
        } catch (\Throwable $e) {
            echo "cleanup: could not delete test clock $id: {$e->getMessage()}\n";
        }
    }
    echo "Cleanup: $deleted of " . count($clocks) . " test clock(s) deleted, " . count($accounts) . " local account(s) removed.\n";
});

// ── Helpers ─────────────────────────────────────────────────────────────
$newClock = static function (string $name) use ($stripe, &$clocks, $tag): object {
    $clock = $stripe->testHelpers->testClocks->create(['frozen_time' => time(), 'name' => "$tag $name"]);
    $clocks[] = $clock->id;
    return $clock;
};
$advance = static function (object $clock, int $to) use ($stripe): void {
    $stripe->testHelpers->testClocks->advance($clock->id, ['frozen_time' => $to]);
    for ($i = 0; $i < 120; $i++) {
        usleep(1_500_000);
        $c = $stripe->testHelpers->testClocks->retrieve($clock->id);
        if ($c->status === 'ready') {
            $clock->frozen_time = $c->frozen_time;
            return;
        }
        if ($c->status === 'internal_failure') {
            throw new RuntimeException("test clock {$clock->id} failed to advance");
        }
    }
    throw new RuntimeException("test clock {$clock->id} did not finish advancing");
};
$clockNow = static fn (object $clock): int => (int) $clock->frozen_time;

$newAccount = static function (object $clock, string $name, string $card = 'pm_card_visa') use ($stripe, $pdo, &$accounts, &$users, &$customers, $tag): array {
    $n = count($accounts) + 1;
    $customer = $stripe->customers->create([
        'email'      => "billing-test-$tag-$n@example.com",
        'name'       => "Billing test $n",
        'test_clock' => $clock->id,
    ]);
    $pm = $stripe->paymentMethods->attach($card, ['customer' => $customer->id]);
    $stripe->customers->update($customer->id, ['invoice_settings' => ['default_payment_method' => $pm->id]]);
    $customers[] = $customer->id;

    $pdo->prepare("INSERT INTO users (fullname, email, password_hash) VALUES (?, ?, '')")
        ->execute(["Billing test $n", "billing-test-$tag-$n-main@example.com"]);
    $userId = (int) $pdo->lastInsertId();
    $users[] = $userId;
    $pdo->prepare(
        "INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city, invoice_ico,
                               main_user_id, subscription_end_date, included_technicians, stripe_customer_id)
         VALUES (?, 'Test 1', '000 00', 'Test', '00000000', ?, ?, 2, ?)"
    )->execute(["Billing test $n ($name)", $userId, date('Y-m-d', time() + 30 * 86400), $customer->id]);
    $accountId = (int) $pdo->lastInsertId();
    $accounts[] = $accountId;
    $pdo->prepare("INSERT INTO account_users (account_id, user_id, role, is_active) VALUES (?, ?, 'main', 1)")
        ->execute([$accountId, $userId]);
    return ['id' => $accountId, 'customer' => $customer->id, 'n' => $n];
};

// A technician joins (as an accepted invite does) and the roster is re-synced.
$addTech = static function (array $acc, bool $sync = true) use ($pdo, &$users, $tag): int {
    static $k = 0;
    $k++;
    $pdo->prepare("INSERT INTO users (fullname, email, password_hash) VALUES (?, ?, '')")
        ->execute(["Technik $k", "billing-test-$tag-tech$k@example.com"]);
    $uid = (int) $pdo->lastInsertId();
    $users[] = $uid;
    $pdo->prepare("INSERT INTO account_users (account_id, user_id, role, is_active) VALUES (?, ?, 'technician', 1)")
        ->execute([$acc['id'], $uid]);
    if ($sync) {
        SeatSync::recompute($acc['id']);
    }
    return $uid;
};
// A technician is removed (as TeamController::destroy does).
$removeTech = static function (array $acc, int $uid) use ($pdo): void {
    $pdo->prepare('DELETE FROM account_users WHERE account_id = ? AND user_id = ?')->execute([$acc['id'], $uid]);
    SeatSync::recompute($acc['id']);
};

// The subscription a completed Checkout creates, with the app's line items,
// then the app's own sync (what checkout-sync / the webhook run).
$subscribe = static function (array $acc, string $period, ?int $trialEnd = null) use ($stripe, $pdo): object {
    $extra = SeatSync::wantedExtra($acc['id'], 2);
    $items = BillingController::checkoutLineItems($period, $extra);
    $params = [
        'customer' => $acc['customer'],
        'items'    => $items,
        'metadata' => ['firol_account_id' => (string) $acc['id'], 'billing_period' => $period],
    ];
    if ($trialEnd !== null) {
        $params['trial_end'] = $trialEnd;
    }
    $sub = $stripe->subscriptions->create($params);
    BillingController::handleSubscriptionUpsert($sub);
    return $sub;
};

$extrasOf = static function (string $subId, ?string $recorded = null) use ($stripe): array {
    $sub = StripeShape::arr($stripe->subscriptions->retrieve($subId, SeatSync::RETRIEVE_EXPAND));
    $split = SeatSync::classifyItems((array) $sub['items']['data'], (string) $recorded);
    return [
        'count' => count($split['extras']),
        'qty'   => array_sum(array_map(static fn ($i) => (int) $i['quantity'], $split['extras'])),
        'items' => $split['extras'],
        'base'  => $split['base'],
        'all'   => (array) $sub['items']['data'],
    ];
};
$accountRow = static function (int $id) use ($pdo): array {
    $s = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: [];
};
$pendingItems = static function (string $customer) use ($stripe): array {
    $out = [];
    foreach ($stripe->invoiceItems->all(['customer' => $customer, 'pending' => true, 'limit' => 50])->data as $ii) {
        $out[] = (int) $ii->amount;
    }
    return $out;
};
$invoicesOf = static function (string $customer) use ($stripe): array {
    $list = $stripe->invoices->all(['customer' => $customer, 'limit' => 20]);
    $out = array_map(static fn ($i) => StripeShape::arr($i), $list->data);
    usort($out, static fn ($a, $b) => $a['created'] <=> $b['created']);
    return $out;
};
$lineSummary = static function (array $invoice): string {
    $parts = [];
    foreach ((array) $invoice['lines']['data'] as $l) {
        $parts[] = sprintf('%s%s %+.2f', StripeShape::lineIsProration($l) ? 'proration ' : '', (string) ($l['description'] ?? ''), $l['amount'] / 100);
    }
    return implode(' | ', $parts);
};
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ') . ' €';

$product = StripeClient::extraTechnicianProductId();
echo "Stripe TEST mode, extra product $product, base prices " . implode(' / ', StripeClient::basePriceIds()) . "\n\n";

// ── A: monthly ─────────────────────────────────────────────────────────
if ($run('A')) {
    echo "A — monthly: add, add, remove both, add again\n";
    $clock = $newClock('A monthly');
    $acc = $newAccount($clock, 'A monthly');
    $t1 = $addTech($acc, false); // 2 technicians = the included count
    $sub = $subscribe($acc, 'monthly');
    $expect('A subscribe with 2 technicians: no extra item', $extrasOf($sub->id)['count'], 0);

    $t2 = $addTech($acc);
    $x = $extrasOf($sub->id);
    $expect('A 3rd technician: extra item created', $x['count'], 1);
    $expect('A 3rd technician: quantity 1', $x['qty'], 1);
    $expect('A extra item under the configured product', StripeShape::itemProductId($x['items'][0] ?? []), $product);
    $expect('A DB records the item', (string) $accountRow($acc['id'])['stripe_extra_subscription_item_id'], (string) ($x['items'][0]['id'] ?? ''));
    $itemA1 = (string) ($x['items'][0]['id'] ?? '');

    $advance($clock, $clockNow($clock) + 10 * 86400);
    $t3 = $addTech($acc);
    $x = $extrasOf($sub->id);
    $expect('A 4th technician: same item, quantity 2', [$x['count'], $x['qty'], (string) ($x['items'][0]['id'] ?? '')], [1, 2, $itemA1]);

    $advance($clock, $clockNow($clock) + 5 * 86400);
    $removeTech($acc, $t2);
    $removeTech($acc, $t3);
    $expect('A both removed: item deleted', $extrasOf($sub->id)['count'], 0);
    $expect('A DB item cleared', $accountRow($acc['id'])['stripe_extra_subscription_item_id'], null);

    $t4 = $addTech($acc);
    $x = $extrasOf($sub->id);
    $expect('A added again: new item, quantity 1', [$x['count'], $x['qty']], [1, 1]);
    $note('pending invoice items (prorations, cents): ' . json_encode($pendingItems($acc['customer'])));

    $advance($clock, $clockNow($clock) + 20 * 86400);
    $inv = $invoicesOf($acc['customer']);
    $renewal = end($inv);
    $note('renewal invoice: total ' . $eur((int) $renewal['total']) . ', paid ' . $eur((int) $renewal['amount_paid']) . ', status ' . $renewal['status']);
    $note('lines: ' . $lineSummary($renewal));
    $hasProration = false;
    foreach ((array) $renewal['lines']['data'] as $l) {
        $hasProration = $hasProration || StripeShape::lineIsProration($l);
    }
    $expect('A prorations land on the renewal invoice', $hasProration, true);
    echo "\n";
}

// ── B: yearly ───────────────────────────────────────────────────────────
if ($run('B')) {
    echo "B — yearly: a technician 4 months in\n";
    $clock = $newClock('B yearly');
    $acc = $newAccount($clock, 'B yearly');
    $addTech($acc, false);
    $sub = $subscribe($acc, 'yearly');
    $expect('B billing_period from the subscription', $accountRow($acc['id'])['billing_period'], 'yearly');
    $advance($clock, $clockNow($clock) + 122 * 86400);
    $addTech($acc);
    $x = $extrasOf($sub->id);
    $expect('B extra item created, yearly interval', [$x['count'], StripeShape::itemInterval($x['items'][0] ?? [])], [1, 'year']);
    $expect('B unit amount = yearly price', (int) ($x['items'][0]['price']['unit_amount'] ?? 0), SeatSync::pricePerExtraYearlyCents());
    $pending = $pendingItems($acc['customer']);
    $note('pending proration (cents): ' . json_encode($pending) . ' — not charged now, waits for the renewal');
    $expect('B no invoice for the addition yet', count($invoicesOf($acc['customer'])), 1);
    $advance($clock, $clockNow($clock) + 245 * 86400);
    $inv = $invoicesOf($acc['customer']);
    $renewal = end($inv);
    $note('renewal invoice: total ' . $eur((int) $renewal['total']) . ', lines: ' . $lineSummary($renewal));
    $expect('B renewal carries the proration', in_array(true, array_map(static fn ($l) => StripeShape::lineIsProration($l), (array) $renewal['lines']['data']), true), true);
    echo "\n";
}

// ── C: trial ────────────────────────────────────────────────────────────
if ($run('C')) {
    echo "C — technician added during the Stripe trial\n";
    $clock = $newClock('C trial');
    $acc = $newAccount($clock, 'C trial');
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly', $clockNow($clock) + 10 * 86400);
    $expect('C status trialing', $accountRow($acc['id'])['stripe_status'], 'trialing');
    $addTech($acc);
    $x = $extrasOf($sub->id);
    $expect('C extra item created during trial', [$x['count'], $x['qty']], [1, 1]);
    $expect('C no proration during trial', $pendingItems($acc['customer']), []);
    $inv = $invoicesOf($acc['customer']);
    $expect('C trial-start invoice is 0 €', [(int) ($inv[0]['total'] ?? -1), (int) ($inv[0]['amount_paid'] ?? -1)], [0, 0]);
    $advance($clock, $clockNow($clock) + 11 * 86400);
    $inv = $invoicesOf($acc['customer']);
    $first = end($inv);
    $note('first paid invoice after the trial: ' . $eur((int) $first['total']) . ' — ' . $lineSummary($first));
    echo "\n";
}

// ── D: concurrency ──────────────────────────────────────────────────────
if ($run('D')) {
    echo "D — two additions at the same moment\n";
    $clock = $newClock('D concurrency');
    $acc = $newAccount($clock, 'D concurrency');
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly');
    $addTech($acc, false);
    $addTech($acc, false);
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $procs[] = proc_open([PHP_BINARY, __FILE__, '--child-recompute=' . $acc['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    foreach ($procs as $i => $p) {
        stream_get_contents($pipes[$i][1]);
        stream_get_contents($pipes[$i][2]);
        proc_close($p);
    }
    $x = $extrasOf($sub->id);
    $expect('D one extra item with quantity 2', [$x['count'], $x['qty']], [1, 2]);
    echo "\n";
}

// ── E: failure, reconcile, duplicates ───────────────────────────────────
if ($run('E')) {
    echo "E — a failed SeatSync call, then the reconcile\n";
    $clock = $newClock('E reconcile');
    $acc = $newAccount($clock, 'E reconcile');
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly');
    $real = $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'];
    $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] = 'prod_doesnotexist_' . $tag;
    $addTech($acc); // Stripe refuses the unknown product; recompute only logs it
    $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] = $real;
    $expect('E after the failed call: nothing on Stripe', $extrasOf($sub->id)['count'], 0);
    $expect('E DB keeps the wanted quantity', (int) $accountRow($acc['id'])['extra_technicians'], 1);
    $r = SeatSync::reconcile($acc['id']);
    $x = $extrasOf($sub->id);
    $expect('E reconcile creates the item (qty 1)', [$r['action'], $x['count'], $x['qty']], ['changed', 1, 1]);

    // An old race left two extra items: the reconcile merges them.
    $stripe->subscriptionItems->create([
        'subscription' => $sub->id, 'quantity' => 1, 'proration_behavior' => 'none',
        'price_data' => SeatSync::extraPriceData('monthly'),
        'metadata' => ['firol_account_id' => (string) $acc['id'], 'firol_line_kind' => SeatSync::LINE_KIND],
    ]);
    $expect('E duplicate set up: two extra items', $extrasOf($sub->id)['count'], 2);
    $addTech($acc, false); // wanted 2
    $r = SeatSync::reconcile($acc['id']);
    $x = $extrasOf($sub->id);
    $expect('E duplicates merged into one item, qty 2', [$x['count'], $x['qty']], [1, 2]);
    $note('reconcile notes: ' . implode('; ', $r['notes']));

    // The webhook path repairs too: drop the DB-side wanted change on Stripe and let the sync run.
    $stripe->subscriptionItems->update((string) $x['items'][0]['id'], ['quantity' => 1, 'proration_behavior' => 'none']);
    BillingController::handleSubscriptionUpsert($stripe->subscriptions->retrieve($sub->id));
    $expect('E subscription sync pushes the wanted quantity back (2)', $extrasOf($sub->id)['qty'], 2);
    echo "\n";
}

// ── F: changed base price id, legacy item ───────────────────────────────
if ($run('F')) {
    echo "F — changed base price id and a legacy extra item\n";
    $clock = $newClock('F base price');
    $acc = $newAccount($clock, 'F base price');
    $addTech($acc, false);
    // A legacy extra item: an inline product named as old Checkouts named it.
    $legacyPrice = $stripe->prices->create([
        'currency' => 'eur', 'unit_amount' => SeatSync::pricePerExtraCents(), 'recurring' => ['interval' => 'month'],
        'product_data' => ['name' => SeatSync::LEGACY_PRODUCT_NAME],
    ]);
    $archive[] = ['price', $legacyPrice->id];
    $archive[] = ['product', (string) $legacyPrice->product];
    $sub = $stripe->subscriptions->create([
        'customer' => $acc['customer'],
        'items'    => [['price' => StripeClient::priceFor('monthly'), 'quantity' => 1], ['price' => $legacyPrice->id, 'quantity' => 1]],
    ]);
    $addTech($acc, false); // 3 active = 1 extra, as on Stripe
    $items = StripeShape::arr($sub)['items']['data'];
    $baseItemId = '';
    foreach ($items as $it) {
        if ($it['price']['id'] === StripeClient::priceFor('monthly')) {
            $baseItemId = $it['id'];
        }
    }
    // What the old webhook did after a base price change: it recorded the
    // BASE item as the extra item. And the env now names a new base price.
    $realMonthly = $_ENV['STRIPE_PRICE_MONTHLY'];
    $_ENV['STRIPE_PRICE_MONTHLY'] = 'price_changed_' . $tag;
    $pdo->prepare('UPDATE accounts SET stripe_subscription_id = ?, stripe_status = ?, billing_period = ?, stripe_extra_subscription_item_id = ? WHERE id = ?')
        ->execute([$sub->id, 'active', 'monthly', $baseItemId, $acc['id']]);

    $x = $extrasOf($sub->id, $baseItemId);
    $expect('F legacy item recognised by its product name, base item not', [$x['count'], (string) ($x['base']['id'] ?? '')], [1, $baseItemId]);
    BillingController::handleSubscriptionUpsert($sub);
    $t = $addTech($acc);
    $x = $extrasOf($sub->id);
    $baseNow = null;
    foreach ($x['all'] as $it) {
        if ($it['id'] === $baseItemId) {
            $baseNow = $it;
        }
    }
    $expect('F base item untouched (qty 1, same price)', [(int) ($baseNow['quantity'] ?? 0), (string) ($baseNow['price']['id'] ?? '')], [1, $realMonthly]);
    $expect('F legacy item carries the change (qty 2), no new item', [$x['count'], $x['qty']], [1, 2]);
    $removeTech($acc, $t);
    $x = $extrasOf($sub->id);
    $expect('F removal lowers the legacy item, base untouched', [$x['qty'], count($x['all'])], [1, 2]);
    $_ENV['STRIPE_PRICE_MONTHLY'] = $realMonthly;
    echo "\n";
}

// ── G: old subscription's events ────────────────────────────────────────
$oldSubEvents = [];
if ($run('G')) {
    echo "G — an old subscription does not overwrite the new one\n";
    $clock = $newClock('G two subs');
    $acc = $newAccount($clock, 'G two subs');
    $subA = $subscribe($acc, 'monthly');
    $stripe->subscriptions->cancel($subA->id);
    BillingController::handleSubscriptionDeleted($subA);
    $expect('G first subscription canceled in DB', $accountRow($acc['id'])['stripe_status'], 'canceled');
    $subB = $subscribe($acc, 'yearly');
    $expect('G new subscription taken over after the old one ended', $accountRow($acc['id'])['stripe_subscription_id'], $subB->id);
    BillingController::handleSubscriptionUpsert($subA);
    BillingController::handleSubscriptionDeleted($subA);
    $row = $accountRow($acc['id']);
    $expect('G old subscription events ignored', [$row['stripe_subscription_id'], $row['stripe_status'], $row['billing_period']], [$subB->id, 'active', 'yearly']);
    $oldSubEvents = [$acc['id'], $subA->id, $subB->id];
    echo "\n";
}

// ── P: failed renewal (decision 3) ──────────────────────────────────────
if ($run('P')) {
    echo "P — renewal with a failing card (report for owner decision 3)\n";
    $clock = $newClock('P failed renewal');
    $acc = $newAccount($clock, 'P failed renewal');
    $sub = $subscribe($acc, 'monthly');
    $s = StripeShape::arr($stripe->subscriptions->retrieve($sub->id));
    $note('start: status ' . $s['status'] . ', current_period_end ' . date('Y-m-d', StripeShape::subscriptionPeriodEnd($s)));
    $fail = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', ['customer' => $acc['customer']]);
    $stripe->customers->update($acc['customer'], ['invoice_settings' => ['default_payment_method' => $fail->id]]);
    foreach ([32, 36, 41, 48, 55, 62, 75] as $day) {
        $advance($clock, (int) $s['start_date'] + $day * 86400);
        $now = StripeShape::arr($stripe->subscriptions->retrieve($sub->id));
        $inv = $invoicesOf($acc['customer']);
        $last = end($inv);
        $note(sprintf('day %d: status %s, current_period_end %s, last invoice %s %s attempts %d next attempt %s',
            $day, $now['status'], date('Y-m-d', (int) StripeShape::subscriptionPeriodEnd($now)),
            $last['status'], $eur((int) $last['amount_due']), (int) $last['attempt_count'],
            $last['next_payment_attempt'] ? date('Y-m-d', (int) $last['next_payment_attempt']) : '-'));
        if (in_array($now['status'], ['canceled', 'unpaid'], true)) {
            break;
        }
    }
    BillingController::handleSubscriptionUpsert($sub);
    $row = $accountRow($acc['id']);
    $note('app stores: stripe_status ' . $row['stripe_status'] . ', subscription_end_date ' . $row['subscription_end_date']);
    echo "\n";
}

// ── R: webhook replay ───────────────────────────────────────────────────
if ($run('R') && $accounts !== []) {
    echo "R — replay of the captured events through the real webhook\n";
    $fpmEnv = \Dotenv\Dotenv::parse((string) file_get_contents(__DIR__ . '/../.env'));
    $fpmMode = strtolower((string) ($fpmEnv['IDOKLAD_ISSUE_MODE'] ?? ''));
    $fpmProd = strtolower((string) ($fpmEnv['APP_ENV'] ?? '')) === 'production';
    $fpmTest = in_array(strtolower((string) ($fpmEnv['IDOKLAD_ALLOW_TEST_EVENTS'] ?? '')), ['1', 'true', 'yes'], true);
    if ($fpmProd || $fpmMode === 'send' || $fpmTest) {
        echo "SKIP — backend/.env would let PHP-FPM send to iDoklad (APP_ENV / IDOKLAD_ISSUE_MODE / IDOKLAD_ALLOW_TEST_EVENTS).\n";
    } else {
        $secret = (string) $_ENV['STRIPE_WEBHOOK_SECRET'];
        $post = static function (array $event) use ($secret): int {
            $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ts = time();
            $sig = hash_hmac('sha256', "$ts.$body", $secret);
            $ch = curl_init('http://nginx/api/billing/webhook');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Stripe-Signature: t=$ts,v1=$sig"],
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code;
        };

        // Every event of our customers since the start.
        $events = [];
        foreach ($stripe->events->all(['created' => ['gte' => $t0 - 60], 'limit' => 100])->autoPagingIterator() as $ev) {
            $o = $ev->data->object;
            $cust = (string) ($o->customer ?? ($o->object === 'customer' ? $o->id : ''));
            if (in_array($cust, $customers, true)) {
                $events[] = $ev->toArray();
            }
        }
        usort($events, static fn ($a, $b) => [$a['created'], $a['id']] <=> [$b['created'], $b['id']]);
        $versions = array_count_values(array_map(static fn ($e) => (string) ($e['api_version'] ?? '?'), $events));
        $note(count($events) . ' events, API versions ' . json_encode($versions));

        if ($capture !== null) {
            @mkdir($capture, 0775, true);
            $saved = [];
            foreach ($events as $e) {
                $k = $e['type'];
                if (in_array($k, ['customer.subscription.updated', 'invoice.paid'], true) && !isset($saved[$k])) {
                    $isRich = $k !== 'invoice.paid' || count($e['data']['object']['lines']['data'] ?? []) > 1;
                    if ($isRich) {
                        file_put_contents("$capture/$k.json", json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        $saved[$k] = true;
                    }
                }
            }
            $note('captured: ' . implode(', ', array_keys($saved)) . " → $capture");
        }

        // Expected end state: the subscription the app holds, with its status
        // as Stripe has it now (a clock may have moved it on, e.g. trialing →
        // active, without an in-process sync), and the wanted extra count.
        $expected = [];
        foreach ($accounts as $id) {
            $r = $accountRow($id);
            $status = $r['stripe_subscription_id'] ? (string) $stripe->subscriptions->retrieve((string) $r['stripe_subscription_id'])->status : null;
            $expected[$id] = [$r['stripe_subscription_id'], $status, $r['billing_period'], (int) $r['extra_technicians']];
        }
        $in = implode(',', array_fill(0, count($accounts), '?'));
        $pdo->prepare("UPDATE accounts SET stripe_subscription_id = NULL, stripe_status = NULL, billing_period = NULL,
                                           subscription_end_date = '2000-01-01' WHERE id IN ($in)")->execute($accounts);
        $pdo->prepare("DELETE FROM invoices WHERE account_id IN ($in)")->execute($accounts);

        $codes = [];
        foreach ($events as $e) {
            $codes[] = $post($e);
        }
        $expect('R every event answered 204', array_values(array_unique($codes)), [204]);
        sleep(3); // invoices are issued after the response

        foreach ($accounts as $id) {
            $r = $accountRow($id);
            $got = [$r['stripe_subscription_id'], $r['stripe_status'], $r['billing_period'], (int) $r['extra_technicians']];
            $expect("R account $id end state matches", $got, $expected[$id]);
        }

        // Duplicate delivery: skipped.
        $dup = $events[array_key_last($events)];
        $code = $post($dup);
        $st = $pdo->prepare('SELECT status, attempts FROM stripe_webhook_events WHERE event_id = ?');
        $st->execute([$dup['id']]);
        $expect('R duplicate delivery: 204, processed once', [$code, $st->fetch()], [204, ['status' => 'done', 'attempts' => 1]]);

        // Out of order: the oldest subscription.updated again → state unchanged.
        foreach ($events as $e) {
            if ($e['type'] === 'customer.subscription.updated') {
                $cust = (string) $e['data']['object']['customer'];
                $acctId = (int) $pdo->query('SELECT id FROM accounts WHERE stripe_customer_id = ' . $pdo->quote($cust))->fetchColumn();
                $before = $accountRow($acctId);
                $e['id'] = $e['id'] . '_late';
                $post($e);
                $after = $accountRow($acctId);
                $expect('R late (out-of-order) subscription.updated changes nothing', [$after['stripe_status'], $after['extra_technicians'], $after['stripe_extra_subscription_item_id']], [$before['stripe_status'], $before['extra_technicians'], $before['stripe_extra_subscription_item_id']]);
                break;
            }
        }

        // An old subscription's event after the new one.
        if ($oldSubEvents !== []) {
            [$gAcc, $subAId, $subBId] = $oldSubEvents;
            foreach ($events as $e) {
                if (($e['data']['object']['id'] ?? '') === $subAId && str_starts_with($e['type'], 'customer.subscription.')) {
                    $e['id'] = $e['id'] . '_old';
                    $post($e);
                }
            }
            $expect('R old subscription events leave the new one', $accountRow($gAcc)['stripe_subscription_id'], $subBId);
        }

        // Invoices: no 0 € row, credits held, the rest prepared (never sent).
        $rows = $pdo->prepare("SELECT i.*, a.invoice_company_name FROM invoices i JOIN accounts a ON a.id = i.account_id WHERE i.account_id IN ($in) ORDER BY i.id");
        $rows->execute($accounts);
        $rows = $rows->fetchAll();
        $zero = array_filter($rows, static fn ($r) => (int) $r['stripe_total_cents'] === 0 && (int) $r['stripe_amount_paid_cents'] === 0);
        $expect('R no invoice row for a 0 € Stripe invoice', count($zero), 0);
        $statuses = array_count_values(array_map(static fn ($r) => $r['status'] . ($r['review_reason'] ? '/' . $r['review_reason'] : ''), $rows));
        $note('invoice rows: ' . json_encode($statuses));
        $expect('R nothing issued or sending (iDoklad untouched)', array_values(array_filter(array_keys($statuses), static fn ($s) => in_array($s, ['issued', 'sending', 'error'], true))), []);
        foreach ($rows as $r) {
            $inv = json_decode((string) $r['stripe_invoice_json'], true);
            $payload = json_decode((string) $r['idoklad_payload_json'], true);
            $wantDesc = \Firol\Idoklad\InvoiceIssuer::deriveDescription($inv);
            if ($r['status'] === 'needs_review') {
                $expect("R row {$r['id']} held: credit/discount ({$r['review_reason']})", in_array($r['review_reason'], ['credit', 'discount', 'mismatch'], true), true);
                $note(sprintf('  needs_review %s: total %s paid %s — %s', $r['stripe_invoice_id'], $eur((int) $r['stripe_total_cents']), $eur((int) $r['stripe_amount_paid_cents']), $r['error_message']));
            } else {
                $expect("R row {$r['id']} prepared, description from the first non-proration line", [$r['status'], $payload['Description'] ?? null], ['prepared', $wantDesc]);
                $note(sprintf('  prepared %s %s: %s', $r['stripe_invoice_id'], $eur((int) $r['stripe_total_cents']), json_encode(array_map(static fn ($i) => $i['Name'] . ' ' . $i['Amount'] . '×' . $i['UnitPrice'], $payload['Items'] ?? []), JSON_UNESCAPED_UNICODE)));
            }
        }
    }
    echo "\n";
}

echo $failures === 0 ? "All checks passed.\n" : "$failures check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
