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
 *   F  a changed base price id (a new plan price under a new product) while
 *      the old webhook recorded the base item as the extra one: the base item
 *      is never changed or deleted; a legacy extra item (inline product named
 *      „POapp — extra technik" and, from 17. 5. to 28. 7. 2026, „Firol —
 *      extra technik") is still recognised and carries the changes, down to 0.
 *   G  an old subscription's events do not overwrite the new subscription.
 *   R  replay of every captured event through the real webhook (HTTP, signed):
 *      the state comes out right, no 0 € invoice, the period text, credit
 *      invoices held as needs_review, duplicate and out-of-order deliveries;
 *      then everything again and everything in reverse order — same end
 *      state, no invoice row twice, nothing sent.
 *   P  (decision 3, report only) a renewal whose card fails: what Stripe does
 *      to current_period_end and the status under the default retry settings.
 *
 * Round 2 (docs/prompts/billing-fixes-2026-11-round-2.md):
 *   H  renewals after quantity changes, recorded as the iDoklad payload
 *      (recording stub): H1 monthly 3rd technician created + 4th (qty 1→2),
 *      H2 yearly the same, H3 a legacy „Firol" item qty 1→2, H4 the first
 *      extra technician only, H5 duplicate items merged (net 0 → no line) —
 *      all issued with netted lines summing to the amount paid; H6 a real
 *      removal is still held as a credit.
 *   K  promotion codes: a 20 % and a 3 € coupon, first invoice and a renewal
 *      with an extra technician added — issued, lines net of the discount;
 *      the account knows the next charge is „pred zľavou".
 *   L  item-2 safety: L2 STRIPE_PRODUCT_EXTRA_TECHNICIAN set to the plan's
 *      own product (nothing changed, admin error, Checkout falls back to an
 *      inline product that Stripe accepts), L3 an item only the recorded id
 *      points to (left for a person, listed by the seat report), L4 the last
 *      item of a subscription is never removed.
 *   S  subscription state: S1 a newer paid subscription wins over an older
 *      unpaid one (and the admin delete lists both), S2 the daily run writes
 *      a stale status from Stripe, S3 Checkout decides on Stripe's status
 *      when the DB says „active" for a cancelled subscription (over HTTP).
 *   W  BILLING_RECONCILE=off: nothing pushed, the seat report lists the gap,
 *      the daily run only reports; switched on → repaired.
 *   O  a row the old code left `pending` without a snapshot: rebuilt from
 *      Stripe, held for a person, then issued by „Vystaviť znova".
 *
 * Round 3 (docs/prompts/billing-fixes-2026-11-round-3.md). A test clock
 * freezes time — two calls in one step get the same second — so where the
 * time between calls matters the clock is advanced between them:
 *   H5 the merge with the clock advanced 5 s between the reconcile's delete
 *      and update (SeatSync::$afterStripeWrite): one proration date, the
 *      renewal issued with no proration line; H7 the merge an hour after
 *      the duplicate appeared; H8 add then remove in one period, positive
 *      net — issued, one line without a count; H9 the same with a negative
 *      net — held, alerted once by the daily run, an admin error naming the
 *      account and the amount.
 *   K3 a coupon on the customer: the account knows the next charge is
 *      „pred zľavou".
 *   L5 Checkout with a STRIPE_PRODUCT_EXTRA_TECHNICIAN Stripe refuses: one
 *      retry with the inline product, the session opens.
 *   T  two subscriptions end to end: an unpaid recorded one with an extra
 *      item and a paid one at Stripe — a roster change pushes nothing to the
 *      unpaid one, the daily run takes the paid one over and puts the extra
 *      technicians on it, the old one keeps its item (never changed), one
 *      alert, an admin error naming the account; T2 an incomplete
 *      subscription does not move the paid-until date.
 * The child processes (seat report, daily run) run with the iDoklad recorder
 * prepended, blank iDoklad credentials and IDOKLAD_ISSUE_MODE=prepare.
 */

require __DIR__ . '/lib/idoklad-recorder.php'; // before the autoloader: no real iDoklad client in this process
require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Billing\SeatSync;
use Firol\Controllers\BillingController;
use Firol\Db;
use Firol\Idoklad\IDokladClient;
use Firol\Idoklad\InvoiceIssuer;
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
unset($_ENV['IDOKLAD_ALLOW_TEST_EVENTS'], $_ENV['BILLING_RECONCILE']);
$_ENV['SMTP_HOST'] = '';
IDokladClient::assertStub();
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
$coupons = [];
$sessions = [];
register_shutdown_function(static function () use (&$clocks, &$accounts, &$users, &$archive, &$coupons, &$sessions, $keep, $stripe, $pdo): void {
    foreach ($sessions as $id) {
        try {
            $stripe->checkout->sessions->expire($id);
        } catch (\Throwable $e) {
            echo "cleanup: could not expire checkout session $id: {$e->getMessage()}\n";
        }
    }
    foreach ($coupons as $id) {
        try {
            $stripe->coupons->delete($id);
        } catch (\Throwable $e) {
            echo "cleanup: could not delete coupon $id: {$e->getMessage()}\n";
        }
    }
    foreach ($archive as [$kind, $id]) {
        if ($kind === 'price') {
            $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute(['stripe_price_product:' . $id]);
        }
    }
    foreach ($accounts as $id) {
        $pdo->prepare('DELETE FROM invoices WHERE account_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM billing_operator_alerts WHERE alert_key LIKE ?')->execute(['%:' . $id . ':%']);
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

// Round 2 helpers ─ record a Stripe invoice as the webhook does and issue it
// to the RECORDING STUB; returns the outcome and the payload that would go to
// iDoklad.
$issueRecorded = static function (array $acc, array $invoice) use ($pdo): array {
    $rowId = InvoiceIssuer::record($acc['id'], $invoice, false);
    $s = $pdo->prepare('SELECT * FROM invoices WHERE stripe_invoice_id = ?');
    if ($rowId === null) {
        $s->execute([$invoice['id']]);
        $row = $s->fetch() ?: [];
        return ['status' => $row['status'] ?? 'no row', 'reason' => $row['review_reason'] ?? null,
                'detail' => $row['error_message'] ?? null, 'items' => [], 'sum' => null, 'row' => $row['id'] ?? null];
    }
    $rec = new IDokladClient();
    $st  = (new InvoiceIssuer($rec))->issue($rowId);
    $sent = $rec->lastInvoicePost() ?? [];
    $items = array_map(static fn ($i) => [$i['Name'], $i['Amount'], $i['UnitPrice']], $sent['Items'] ?? []);
    $sum = (int) round(array_sum(array_map(static fn ($i) => $i['Amount'] * $i['UnitPrice'] * 100, $sent['Items'] ?? [])));
    return ['status' => $st, 'reason' => null, 'detail' => null, 'items' => $items, 'sum' => $sum, 'row' => $rowId];
};
$showRecorded = static function (string $label, array $invoice, array $r) use ($note, $eur, $lineSummary): void {
    $note(sprintf('%s: Stripe total %s, paid %s → %s%s', $label, $eur((int) $invoice['total']), $eur((int) $invoice['amount_paid']),
        $r['status'], $r['reason'] ? " ({$r['reason']}: {$r['detail']})" : ''));
    $note('  Stripe lines: ' . $lineSummary($invoice));
    foreach ($r['items'] as [$name, $amount, $unit]) {
        $note(sprintf('  iDoklad item: %s — %d × %s €', $name, $amount, number_format($unit, 2, ',', ' ')));
    }
    if ($r['sum'] !== null) {
        $note('  iDoklad items sum ' . $eur($r['sum']));
    }
};
$byReason = static function (string $customer, string $reason) use ($invoicesOf): ?array {
    $found = null;
    foreach ($invoicesOf($customer) as $inv) {
        if (($inv['billing_reason'] ?? '') === $reason && ($inv['status'] ?? '') === 'paid') {
            $found = $inv;
        }
    }
    return $found;
};
// A throwaway price under a new product, archived at the end.
$throwawayPrice = static function (string $name, int $amount, string $interval = 'month', array $productMeta = []) use ($stripe, &$archive): object {
    $price = $stripe->prices->create([
        'currency' => 'eur', 'unit_amount' => $amount, 'recurring' => ['interval' => $interval],
        'product_data' => array_filter(['name' => $name, 'metadata' => $productMeta]),
    ]);
    $archive[] = ['price', $price->id];
    $archive[] = ['product', (string) $price->product];
    return $price;
};
// A child process: the iDoklad recorder is prepended (auto_prepend_file — it
// IS the iDoklad client there too), the iDoklad credentials are blank and
// the mode is `prepare` (the child reads backend/.env itself; these win).
$runScript = static function (string $script, array $args, array $env = []): array {
    $env += ['IDOKLAD_CLIENT_ID' => '', 'IDOKLAD_CLIENT_SECRET' => '', 'IDOKLAD_ISSUE_MODE' => 'prepare', 'IDOKLAD_ALLOW_TEST_EVENTS' => ''];
    $p = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/lib/idoklad-recorder.php', __DIR__ . '/' . $script, ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};

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
    foreach (SeatSync::LEGACY_PRODUCT_NAMES as $fi => $legacyName) {
        $fl = 'F' . ($fi + 1);
        echo "$fl — changed base price id and a legacy extra item „{$legacyName}\"\n";
        $clock = $newClock("$fl base price");
        $acc = $newAccount($clock, "$fl base price");
        $addTech($acc, false);
        // A legacy extra item: an inline product named as old Checkouts named it.
        $legacyPrice = $throwawayPrice($legacyName, SeatSync::pricePerExtraCents());
        $realMonthly = $_ENV['STRIPE_PRICE_MONTHLY'];
        $sub = $stripe->subscriptions->create([
            'customer' => $acc['customer'],
            'items'    => [['price' => $realMonthly, 'quantity' => 1], ['price' => $legacyPrice->id, 'quantity' => 1]],
        ]);
        $addTech($acc, false); // 3 active = 1 extra, as on Stripe
        $baseItemId = '';
        foreach (StripeShape::arr($sub)['items']['data'] as $it) {
            if ($it['price']['id'] === $realMonthly) {
                $baseItemId = $it['id'];
            }
        }
        // The plan's price changed in env (a new price under a new product),
        // and the old webhook recorded the BASE item as the extra item.
        $newPlan = $throwawayPrice("Plan (test) $tag", 1900);
        $_ENV['STRIPE_PRICE_MONTHLY'] = $newPlan->id;
        $pdo->prepare('UPDATE accounts SET stripe_subscription_id = ?, stripe_status = ?, billing_period = ?, stripe_extra_subscription_item_id = ? WHERE id = ?')
            ->execute([$sub->id, 'active', 'monthly', $baseItemId, $acc['id']]);

        $x = $extrasOf($sub->id, $baseItemId);
        $expect("$fl legacy item recognised by its product name, base item not", [$x['count'], (string) ($x['base']['id'] ?? '')], [1, $baseItemId]);
        $baseOf = static function (array $x) use ($baseItemId): ?array {
            foreach ($x['all'] as $it) {
                if ($it['id'] === $baseItemId) {
                    return $it;
                }
            }
            return null;
        };
        BillingController::handleSubscriptionUpsert($sub);
        $t = $addTech($acc);
        $x = $extrasOf($sub->id);
        $expect("$fl base item untouched (qty 1, same price)", [(int) ($baseOf($x)['quantity'] ?? 0), (string) ($baseOf($x)['price']['id'] ?? '')], [1, $realMonthly]);
        $expect("$fl legacy item carries the change (qty 2), no new item", [$x['count'], $x['qty']], [1, 2]);
        $removeTech($acc, $t);
        $x = $extrasOf($sub->id);
        $expect("$fl removal lowers the legacy item, base untouched", [$x['qty'], count($x['all'])], [1, 2]);
        // Down to the included count: the legacy item goes, the base stays.
        $pdo->prepare("DELETE FROM account_users WHERE account_id = ? AND role = 'technician' LIMIT 1")->execute([$acc['id']]);
        SeatSync::recompute($acc['id']);
        $x = $extrasOf($sub->id);
        $expect("$fl wanted 0: legacy item deleted, base item still there (qty 1)", [$x['count'], count($x['all']), (int) ($baseOf($x)['quantity'] ?? 0)], [0, 1, 1]);
        $_ENV['STRIPE_PRICE_MONTHLY'] = $realMonthly;
        echo "\n";
    }
}

// ── G: old subscription's events ────────────────────────────────────────
$oldSubEvents = [];
$rSkip = []; // accounts whose replayed end state is not comparable (see S1)
$rNoPeriod = []; // accounts whose subscription has no plan item: replayed without a billing period (see L4)
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

// ── H: renewals after quantity changes (round 2, item 1) ────────────────
if ($run('H')) {
    echo "H — renewals after quantity changes, recorded iDoklad payloads\n";
    $assertIssued = static function (string $label, array $invoice, array $r) use ($expect, $showRecorded): void {
        $showRecorded($label, $invoice, $r);
        $expect("$label issued, iDoklad items sum to the amount paid", [$r['status'], $r['sum']], ['issued', (int) $invoice['amount_paid']]);
    };

    // H1 monthly: 3rd technician (item created), 4th (qty 1→2).
    $clock = $newClock('H1 monthly');
    $acc = $newAccount($clock, 'H1 monthly');
    $addTech($acc, false);
    $subscribe($acc, 'monthly');
    $advance($clock, $clockNow($clock) + 3 * 86400);
    $addTech($acc);
    $advance($clock, $clockNow($clock) + 10 * 86400);
    $addTech($acc);
    $advance($clock, $clockNow($clock) + 20 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H1 monthly renewal', $inv, $r);
    // Round 3: every proration of the extra technicians in the period is one
    // line (round 2 had one line per change).
    $expect('H1 one netted line „(+2 technici)" for the two additions', count(array_filter($r['items'], static fn ($i) => str_contains($i[0], 'pomerná časť (+2 technici)'))), 1);

    // H2 yearly: the same, months apart.
    $clock = $newClock('H2 yearly');
    $acc = $newAccount($clock, 'H2 yearly');
    $addTech($acc, false);
    $subscribe($acc, 'yearly');
    $advance($clock, $clockNow($clock) + 30 * 86400);
    $addTech($acc);
    $advance($clock, $clockNow($clock) + 90 * 86400);
    $addTech($acc);
    $advance($clock, $clockNow($clock) + 250 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H2 yearly renewal', $inv, $r);

    // H3 a legacy item (inline product of a Firol-era Checkout), qty 1→2.
    $clock = $newClock('H3 legacy');
    $acc = $newAccount($clock, 'H3 legacy');
    $addTech($acc, false);
    $addTech($acc, false);
    $legacyPrice = $throwawayPrice(SeatSync::LEGACY_PRODUCT_NAMES[1], SeatSync::pricePerExtraCents());
    $sub = $stripe->subscriptions->create([
        'customer' => $acc['customer'],
        'items'    => [['price' => StripeClient::priceFor('monthly'), 'quantity' => 1], ['price' => $legacyPrice->id, 'quantity' => 1]],
    ]);
    BillingController::handleSubscriptionUpsert($sub);
    $legacyId = (string) ($extrasOf($sub->id)['items'][0]['id'] ?? '');
    $advance($clock, $clockNow($clock) + 12 * 86400);
    $addTech($acc);
    $x = $extrasOf($sub->id);
    $expect('H3 legacy item raised to qty 2, no new item', [$x['count'], $x['qty'], (string) ($x['items'][0]['id'] ?? '')], [1, 2, $legacyId]);
    $advance($clock, $clockNow($clock) + 20 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H3 legacy renewal', $inv, $r);
    $expect('H3 legacy lines named as extra technician', count(array_filter($r['items'], static fn ($i) => str_starts_with($i[0], 'POapp — extra technik'))), 2);

    // H4 the first extra technician only.
    $clock = $newClock('H4 first extra');
    $acc = $newAccount($clock, 'H4 first extra');
    $addTech($acc, false);
    $subscribe($acc, 'monthly');
    $h4 = $acc;
    $advance($clock, $clockNow($clock) + 14 * 86400);
    $addTech($acc);
    $advance($clock, $clockNow($clock) + 18 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H4 first extra renewal', $inv, $r);

    // H5 duplicate extra items merged by the reconcile.
    $clock = $newClock('H5 merge');
    $acc = $newAccount($clock, 'H5 merge');
    $addTech($acc, false);
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly'); // extra qty 1 from Checkout's line items
    $advance($clock, $clockNow($clock) + 9 * 86400);
    $stripe->subscriptionItems->create([
        'subscription' => $sub->id, 'quantity' => 1, 'proration_behavior' => 'none',
        'price_data' => SeatSync::extraPriceData('monthly'),
        'metadata' => ['firol_account_id' => (string) $acc['id'], 'firol_line_kind' => SeatSync::LINE_KIND],
    ]);
    $addTech($acc, false); // wanted 2 = what the two items carry
    // Real time passes between the reconcile's two Stripe calls; on a test
    // clock it does only when the clock is advanced — 5 s after the delete.
    $writes = [];
    SeatSync::$afterStripeWrite = static function (string $what) use ($clock, $advance, $clockNow, &$writes): void {
        $writes[] = $what . ' @' . $clockNow($clock);
        if (str_starts_with($what, 'delete')) {
            $advance($clock, $clockNow($clock) + 5);
        }
    };
    try {
        $rr = SeatSync::reconcile($acc['id']);
    } finally {
        SeatSync::$afterStripeWrite = null;
    }
    $x = $extrasOf($sub->id);
    $note('H5 reconcile: ' . implode('; ', $rr['notes']) . ' — Stripe writes: ' . implode(', ', $writes));
    $expect('H5 merged into one item, qty 2', [$x['count'], $x['qty']], [1, 2]);
    $starts = [];
    foreach ($stripe->invoiceItems->all(['customer' => $acc['customer'], 'pending' => true, 'limit' => 50])->data as $ii) {
        $starts[(int) $ii->period->start] = true;
    }
    $expect('H5 the delete and the update 5 s apart share one proration date (one period start)', count($starts), 1);
    $advance($clock, $clockNow($clock) + 23 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H5 renewal after a merge', $inv, $r);
    $expect('H5 the merge nets to 0: no proration line', count(array_filter($r['items'], static fn ($i) => str_contains($i[0], 'pomerná časť'))), 0);

    // H7 the merge an hour after the duplicate appeared (a racing roster
    // change created it with prorations).
    $clock = $newClock('H7 late merge');
    $acc = $newAccount($clock, 'H7 late merge');
    $addTech($acc, false);
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly'); // extra qty 1
    $advance($clock, $clockNow($clock) + 9 * 86400);
    $stripe->subscriptionItems->create([
        'subscription' => $sub->id, 'quantity' => 1, 'proration_behavior' => 'create_prorations',
        'price_data' => SeatSync::extraPriceData('monthly'),
        'metadata' => ['firol_account_id' => (string) $acc['id'], 'firol_line_kind' => SeatSync::LINE_KIND],
    ]);
    $advance($clock, $clockNow($clock) + 3600);
    $rr = SeatSync::reconcile($acc['id']); // wanted 1: the duplicate goes
    $note('H7 reconcile: ' . implode('; ', $rr['notes']));
    $expect('H7 merged an hour later: one item, qty 1', [$extrasOf($sub->id)['count'], $extrasOf($sub->id)['qty']], [1, 1]);
    $advance($clock, $clockNow($clock) + 23 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H7 renewal after a late merge', $inv, $r);
    $late = array_values(array_filter($r['items'], static fn ($i) => str_contains($i[0], 'pomerná časť')));
    $expect('H7 the late merge leaves at most one proration line, worded without a count', count($late) <= 1 && ($late === [] || !str_contains($late[0][0], 'technik)')), true);

    // H8 add then remove in one period: positive net.
    $clock = $newClock('H8 add-remove plus');
    $acc = $newAccount($clock, 'H8 add-remove plus');
    $addTech($acc, false);
    $subscribe($acc, 'monthly');
    $advance($clock, $clockNow($clock) + 3 * 86400);
    $t = $addTech($acc);
    $advance($clock, $clockNow($clock) + 7 * 86400);
    $removeTech($acc, $t);
    $advance($clock, $clockNow($clock) + 22 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $assertIssued('H8 renewal after add-then-remove (positive net)', $inv, $r);
    $plus = array_values(array_filter($r['items'], static fn ($i) => str_contains($i[0], 'pomerná časť')));
    $expect('H8 one proration line, positive, without a count', [count($plus), ($plus[0][2] ?? 0) > 0, str_contains($plus[0][0] ?? '', 'technik)')], [1, true, false]);

    // H9 add and remove in one period: negative net — held, alerted once.
    $clock = $newClock('H9 add-remove minus');
    $acc = $newAccount($clock, 'H9 add-remove minus');
    $addTech($acc, false);
    $t1 = $addTech($acc, false);
    $t2 = $addTech($acc, false);
    $subscribe($acc, 'monthly'); // extra qty 2
    $advance($clock, $clockNow($clock) + 3 * 86400);
    $addTech($acc); // 2 → 3
    $advance($clock, $clockNow($clock) + 2 * 86400);
    $removeTech($acc, $t1); // 3 → 2
    $removeTech($acc, $t2); // 2 → 1 (same second: one proration date per run anyway)
    $advance($clock, $clockNow($clock) + 27 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $showRecorded('H9 renewal after add-and-remove (negative net)', $inv, $r);
    $expect('H9 negative net held as a credit', [$r['status'], $r['reason']], ['needs_review', 'credit']);
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key');
    $expect('H9 admin ERROR naming the account and the amount paid', [$checks['invoices_held']['level'] ?? null,
        str_contains((string) ($checks['invoices_held']['message'] ?? ''), '(#' . $acc['id'] . ') ' . $eur((int) $inv['amount_paid']))], ['error', true]);
    [, $out1] = $runScript('billing-daily.php', ['--only=invoices', '--account=' . $acc['id']]);
    [, $out2] = $runScript('billing-daily.php', ['--only=invoices', '--account=' . $acc['id']]);
    $expect('H9 the daily run alerts it once (the webhook-time alert had no mail here), not again',
        [str_contains($out1, "row={$r['row']} held (credit) — operator alerted"), str_contains($out2, "row={$r['row']}")], [true, false]);

    // H6 a real removal: still a credit, held.
    $clock = $newClock('H6 removal');
    $acc = $newAccount($clock, 'H6 removal');
    $addTech($acc, false);
    $t = $addTech($acc, false);
    $addTech($acc, false);
    $subscribe($acc, 'monthly'); // extra qty 2
    $advance($clock, $clockNow($clock) + 10 * 86400);
    $removeTech($acc, $t);
    $advance($clock, $clockNow($clock) + 22 * 86400);
    $inv = $byReason($acc['customer'], 'subscription_cycle');
    $r = $issueRecorded($acc, $inv);
    $showRecorded('H6 renewal after a removal', $inv, $r);
    $expect('H6 removal credit held for review (credit)', [$r['status'], $r['reason']], ['needs_review', 'credit']);
    echo "\n";
}

// ── O: a row the old code left pending (round 2, item 5) ────────────────
if ($run('O') && isset($h4)) {
    echo "O — a pending row of the old code, without a snapshot\n";
    $first = $byReason($h4['customer'], 'subscription_create');
    $pdo->prepare("INSERT INTO invoices (account_id, stripe_invoice_id, amount_cents, currency, status, issued_at) VALUES (?, ?, ?, 'EUR', 'pending', NOW())")
        ->execute([$h4['id'], $first['id'], (int) $first['amount_paid']]);
    $rowId = (int) $pdo->lastInsertId();
    $rowOf = static function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: [];
    };
    $expect('O no „Vystaviť" while the snapshot is missing', InvoiceIssuer::manualIssueAllowed($rowOf($rowId)), false);
    $rec = new IDokladClient();
    $st = (new InvoiceIssuer($rec))->issue($rowId); // what the daily run does with it
    $row = $rowOf($rowId);
    $expect('O rebuilt from Stripe by invoice id, held: uncertain_send, nothing sent', [$st, $row['review_reason'], $row['stripe_invoice_json'] !== null, $rec->invoicePosts()], ['needs_review', 'uncertain_send', true, 0]);
    $note('O message: ' . $row['error_message']);
    $expect('O after the rebuild „Vystaviť znova" is offered', InvoiceIssuer::manualIssueAllowed($row), true);
    $st = (new InvoiceIssuer($rec))->issue($rowId, true); // the admin, after checking iDoklad
    $expect('O „Vystaviť znova" issues it (to the recorder)', [$st, $rec->invoicePosts()], ['issued', 1]);
    echo "\n";
}

// ── K: promotion codes (round 2, item 4) ────────────────────────────────
if ($run('K')) {
    echo "K — promotion codes\n";
    $pct = $stripe->coupons->create(['percent_off' => 20, 'duration' => 'forever', 'name' => "$tag 20 %"]);
    $fix = $stripe->coupons->create(['amount_off' => 300, 'currency' => 'eur', 'duration' => 'forever', 'name' => "$tag 3 EUR"]);
    $coupons[] = $pct->id;
    $coupons[] = $fix->id;
    foreach (['K1 20 %' => $pct->id, 'K2 3 €' => $fix->id] as $label => $couponId) {
        $clock = $newClock($label);
        $acc = $newAccount($clock, $label);
        $addTech($acc, false);
        $addTech($acc, false); // 3 technicians: one extra from the start
        $sub = $stripe->subscriptions->create([
            'customer'  => $acc['customer'],
            'items'     => BillingController::checkoutLineItems('monthly', SeatSync::wantedExtra($acc['id'], 2)),
            'discounts' => [['coupon' => $couponId]],
            'metadata'  => ['firol_account_id' => (string) $acc['id'], 'billing_period' => 'monthly'],
        ]);
        BillingController::handleSubscriptionUpsert($sub);
        $expect("$label account knows the next charge is before the discount", (int) $accountRow($acc['id'])['stripe_has_discount'], 1);
        $inv = $byReason($acc['customer'], 'subscription_create');
        $r = $issueRecorded($acc, $inv);
        $showRecorded("$label first invoice", $inv, $r);
        $expect("$label first invoice issued, items sum to the amount paid", [$r['status'], $r['sum']], ['issued', (int) $inv['amount_paid']]);
        $advance($clock, $clockNow($clock) + 11 * 86400);
        $addTech($acc);
        $advance($clock, $clockNow($clock) + 21 * 86400);
        $inv = $byReason($acc['customer'], 'subscription_cycle');
        $r = $issueRecorded($acc, $inv);
        $showRecorded("$label renewal with an extra technician added", $inv, $r);
        $expect("$label renewal issued, items sum to the amount paid", [$r['status'], $r['sum']], ['issued', (int) $inv['amount_paid']]);
        $pro = array_values(array_filter($r['items'], static fn ($i) => str_contains($i[0], 'pomerná časť')));
        $expect("$label the proration line of the discounted subscription says „po zľave\"", $pro !== [] && str_contains($pro[0][0], 'po zľave'), true);
    }

    // K3 a coupon on the customer, not on the subscription.
    $clock = $newClock('K3 customer coupon');
    $acc = $newAccount($clock, 'K3 customer coupon');
    $stripe->customers->update($acc['customer'], ['coupon' => $pct->id]);
    $sub = $subscribe($acc, 'monthly');
    $expect('K3 a coupon on the customer: the account knows the next charge is before the discount', (int) $accountRow($acc['id'])['stripe_has_discount'], 1);
    echo "\n";
}

// ── L: base item never taken for an extra item (round 2, item 2) ────────
if ($run('L')) {
    echo "L — the plan's item and items nobody can vouch for are never changed\n";
    $itemQty = static function (string $subId) use ($stripe): array {
        $out = [];
        foreach (StripeShape::arr($stripe->subscriptions->retrieve($subId))['items']['data'] as $it) {
            $out[$it['id']] = [(string) $it['price']['id'], (int) $it['quantity']];
        }
        ksort($out);
        return $out;
    };

    // L2 STRIPE_PRODUCT_EXTRA_TECHNICIAN set to the plan's own product.
    $clock = $newClock('L2 mis-set product');
    $acc = $newAccount($clock, 'L2 mis-set product');
    $addTech($acc, false);
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly'); // base + a real extra item qty 1
    $before = $itemQty($sub->id);
    $realProduct = $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'];
    $baseProduct = (string) $stripe->prices->retrieve(StripeClient::priceFor('monthly'))->product;
    $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] = $baseProduct;
    $x = $extrasOf($sub->id);
    $expect('L2 the plan item stays the base item (never an extra item)', [(string) ($x['base']['price']['id'] ?? ''), in_array((string) ($x['base']['id'] ?? ''), array_column($x['items'], 'id'), true)], [StripeClient::priceFor('monthly'), false]);
    $addTech($acc); // wanted 2
    try {
        SeatSync::reconcile($acc['id']);
        $refused = '';
    } catch (\Throwable $e) {
        $refused = $e->getMessage();
    }
    $note('L2 reconcile: ' . $refused);
    $expect('L2 reconcile refuses, nothing on Stripe changed', [str_contains($refused, 'product of the base plan'), $itemQty($sub->id)], [true, $before]);
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key');
    $expect('L2 admin check: error for the extra product', $checks['stripe_product_extra']['level'] ?? null, 'error');
    $note('L2 admin check: ' . ($checks['stripe_product_extra']['message'] ?? ''));
    $lines = BillingController::checkoutLineItems('monthly', 1);
    $expect('L2 Checkout falls back to an inline product', isset($lines[1]['price_data']['product_data']) && !isset($lines[1]['price_data']['product']), true);
    $session = $stripe->checkout->sessions->create([
        'mode' => 'subscription', 'customer' => $acc['customer'], 'line_items' => $lines,
        'success_url' => 'https://example.com/ok', 'cancel_url' => 'https://example.com/cancel',
    ]);
    $sessions[] = $session->id;
    $expect('L2 Stripe accepts that Checkout session', (string) $session->status, 'open');
    unset($_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN']);
    $lines = BillingController::checkoutLineItems('yearly', 2);
    $session = $stripe->checkout->sessions->create([
        'mode' => 'subscription', 'customer' => $acc['customer'], 'line_items' => $lines,
        'success_url' => 'https://example.com/ok', 'cancel_url' => 'https://example.com/cancel',
    ]);
    $sessions[] = $session->id;
    $expect('L2 variable missing: Checkout with an inline product still opens (no 500)', [(string) $session->status, $lines[1]['price_data']['product_data']['name'] ?? null], ['open', SeatSync::LEGACY_PRODUCT_NAMES[0]]);
    $inline = $throwawayPrice(SeatSync::LEGACY_PRODUCT_NAMES[0], 1000, 'month', ['firol_line_kind' => SeatSync::LINE_KIND]);
    $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] = $realProduct;
    $split = SeatSync::classifyItems([['id' => 'si_inline', 'quantity' => 1, 'price' => StripeShape::arr($stripe->prices->retrieve($inline->id, ['expand' => ['product']]))]]);
    $expect('L2 an item of that inline product is recognised as an extra item', count($split['extras']), 1);

    // L3 an item only the recorded id points to.
    $clock = $newClock('L3 unverified');
    $acc = $newAccount($clock, 'L3 unverified');
    $addTech($acc, false);
    $addTech($acc, false);
    $odd = $throwawayPrice("Something else (test) $tag", 500);
    $sub = $stripe->subscriptions->create([
        'customer' => $acc['customer'],
        'items'    => [['price' => StripeClient::priceFor('monthly'), 'quantity' => 1], ['price' => $odd->id, 'quantity' => 1]],
    ]);
    $oddItem = '';
    foreach (StripeShape::arr($sub)['items']['data'] as $it) {
        if ($it['price']['id'] === $odd->id) {
            $oddItem = $it['id'];
        }
    }
    $pdo->prepare('UPDATE accounts SET stripe_subscription_id = ?, stripe_status = ?, billing_period = ?, stripe_extra_subscription_item_id = ? WHERE id = ?')
        ->execute([$sub->id, 'active', 'monthly', $oddItem, $acc['id']]);
    $before = $itemQty($sub->id);
    $addTech($acc, false); // wanted 2
    $rr = SeatSync::reconcile($acc['id']);
    $note('L3 reconcile: ' . implode('; ', $rr['notes']));
    $expect('L3 left for a person, nothing on Stripe changed', [$rr['action'], $itemQty($sub->id)], ['manual', $before]);
    [, $out] = $runScript('billing-seat-report.php', ['--account=' . $acc['id']]);
    $expect('L3 the seat report lists the UNVERIFIED item', str_contains($out, "UNVERIFIED item $oddItem"), true);

    // L4 the last item of a subscription.
    $clock = $newClock('L4 last item');
    $acc = $newAccount($clock, 'L4 last item');
    $sub = $stripe->subscriptions->create([
        'customer' => $acc['customer'],
        'items'    => [['price_data' => SeatSync::extraPriceData('monthly'), 'quantity' => 1, 'metadata' => ['firol_line_kind' => SeatSync::LINE_KIND]]],
    ]);
    $pdo->prepare('UPDATE accounts SET stripe_subscription_id = ?, stripe_status = ?, billing_period = ? WHERE id = ?')
        ->execute([$sub->id, 'active', 'monthly', $acc['id']]);
    $before = $itemQty($sub->id);
    $rr = SeatSync::reconcile($acc['id']); // wanted 0 would delete the only item
    $expect('L4 the only item is never deleted', [$rr['action'], $itemQty($sub->id)], ['manual', $before]);
    // The replay (R) reads the period from the plan item; this subscription
    // has none, so the replayed account keeps no billing period.
    $rNoPeriod[] = $acc['id'];

    // L5 a STRIPE_PRODUCT_EXTRA_TECHNICIAN Stripe refuses (mistyped,
    // archived or from the other mode): Checkout retries once inline.
    $clock = $newClock('L5 refused product');
    $acc = $newAccount($clock, 'L5 refused product');
    $realProduct = $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'];
    $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] = 'prod_doesnotexist_' . $tag;
    try {
        $session = BillingController::createCheckoutSession([
            'mode' => 'subscription', 'customer' => $acc['customer'],
            'success_url' => 'https://example.com/ok', 'cancel_url' => 'https://example.com/cancel',
        ], 'monthly', 2);
        $sessions[] = $session->id;
        $lineItems = $stripe->checkout->sessions->allLineItems($session->id, ['limit' => 5, 'expand' => ['data.price.product']]);
        $names = array_map(static fn ($li) => (string) ($li->price->product->name ?? ''), $lineItems->data);
        $expect('L5 Stripe refused the product, Checkout retried with the inline product and opened',
            [(string) $session->status, in_array(SeatSync::LEGACY_PRODUCT_NAMES[0], $names, true)], ['open', true]);
    } catch (\Throwable $e) {
        $expect('L5 Checkout with a refused product opens', $e->getMessage(), 'no error');
    } finally {
        $_ENV['STRIPE_PRODUCT_EXTRA_TECHNICIAN'] = $realProduct;
    }

    // L6 the reprice script (dry run, never --apply) on a yearly account
    // whose recorded extra item is the PLAN item: only the real extra item
    // is considered.
    $clock = $newClock('L6 reprice');
    $acc = $newAccount($clock, 'L6 reprice');
    $addTech($acc, false);
    $addTech($acc, false);
    $sub = $subscribe($acc, 'yearly'); // plan + extra qty 1
    $x = $extrasOf($sub->id);
    $planItem = (string) ($x['base']['id'] ?? '');
    $extraItem = (string) ($x['items'][0]['id'] ?? '');
    $pdo->prepare('UPDATE accounts SET stripe_extra_subscription_item_id = ? WHERE id = ?')->execute([$planItem, $acc['id']]);
    [$code, $out] = $runScript('reprice-yearly-extra-seats.php', ['--account=' . $acc['id']]);
    $note('L6 reprice: ' . trim(implode(' | ', array_filter(explode("\n", $out), static fn ($l) => str_contains($l, 'account ')))));
    $expect('L6 reprice dry run: the recorded plan item is never repriced, only the extra item is looked at',
        [$code, str_contains($out, "the recorded item $planItem is not an extra-technician item"), str_contains($out, "extra item $extraItem:"), str_contains($out, "extra item $planItem")],
        [0, true, true, false]);
    echo "\n";
}

// ── T: two subscriptions end to end (round 3, item 4) ───────────────────
if ($run('T')) {
    echo "T — an unpaid recorded subscription and a paid one at Stripe\n";
    $clock = $newClock('T two subs');
    $acc = $newAccount($clock, 'T two subs');
    $addTech($acc, false);
    $addTech($acc, false);
    $subA = $subscribe($acc, 'monthly'); // A carries 1 extra technician
    $fail = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', ['customer' => $acc['customer']]);
    $stripe->customers->update($acc['customer'], ['invoice_settings' => ['default_payment_method' => $fail->id]]);
    $advance($clock, $clockNow($clock) + 33 * 86400);
    BillingController::handleSubscriptionUpsert($subA);
    $good = $stripe->paymentMethods->attach('pm_card_visa', ['customer' => $acc['customer']]);
    $stripe->customers->update($acc['customer'], ['invoice_settings' => ['default_payment_method' => $good->id]]);
    // B is created at Stripe; no event of it reaches the app.
    $subB = $stripe->subscriptions->create(['customer' => $acc['customer'], 'items' => [['price' => StripeClient::priceFor('monthly'), 'quantity' => 1]]]);
    $expect('T set-up: A recorded and past_due, B active at Stripe only',
        [$accountRow($acc['id'])['stripe_subscription_id'], $accountRow($acc['id'])['stripe_status'], (string) $stripe->subscriptions->retrieve($subB->id)->status],
        [$subA->id, 'past_due', 'active']);
    $aBefore = $extrasOf($subA->id);
    $addTech($acc); // a roster change: wanted 2
    $expect('T roster change: nothing pushed to the unpaid A, nothing on B yet, left for the daily run (manual)',
        [$extrasOf($subA->id)['qty'], $extrasOf($subB->id)['qty'], $accountRow($acc['id'])['seat_sync_state']], [$aBefore['qty'], 0, 'manual']);
    [, $out] = $runScript('billing-daily.php', ['--only=seats', '--account=' . $acc['id']]);
    $note('T daily: ' . trim(implode(' | ', array_filter(explode("\n", $out), static fn ($l) => str_contains($l, 'account=')))));
    $row = $accountRow($acc['id']);
    $expect('T daily run: B taken over, the extra technicians on B, A keeps its item (never changed)',
        [$row['stripe_subscription_id'], $row['stripe_status'], $extrasOf($subB->id)['qty'], $extrasOf($subA->id)['qty'], (string) $stripe->subscriptions->retrieve($subA->id)->status],
        [$subB->id, 'active', 2, $aBefore['qty'], 'past_due']);
    $expect('T one take-over alert and one two-subscriptions alert, mailed by the daily run',
        [\Firol\Billing\OperatorAlert::wasSent("takeover:{$acc['id']}:{$subB->id}"), str_contains($out, 'live subscriptions — operator alert: sent')], [true, true]);
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key');
    $expect('T admin ERROR naming the account and that A still carries an extra item',
        [$checks['subscriptions_multiple']['level'] ?? null, str_contains((string) ($checks['subscriptions_multiple']['message'] ?? ''), "(#{$acc['id']}): používa sa {$subB->id}"),
         str_contains((string) ($checks['subscriptions_multiple']['message'] ?? ''), "{$subA->id} (past_due, nesie {$aBefore['qty']} extra technik(ov))")],
        ['error', true, true]);
    [, $out] = $runScript('billing-daily.php', ['--only=seats', '--account=' . $acc['id']]);
    $expect('T the next daily run alerts nothing again', [str_contains($out, 'operator alert: sent'), str_contains($out, 'operator alert: already')], [false, true]);
    $rSkip[] = $acc['id'];
    $pdo->prepare('DELETE FROM billing_operator_alerts WHERE alert_key LIKE ?')->execute(['%:' . $acc['id'] . ':%']);

    // T2 an incomplete subscription (nothing paid) never moves the paid-until date.
    $clock = $newClock('T2 incomplete');
    $acc = $newAccount($clock, 'T2 incomplete');
    $endBefore = (string) $accountRow($acc['id'])['subscription_end_date'];
    $inc = $stripe->subscriptions->create([
        'customer' => $acc['customer'], 'items' => [['price' => StripeClient::priceFor('monthly'), 'quantity' => 1]],
        'payment_behavior' => 'default_incomplete',
    ]);
    BillingController::handleSubscriptionUpsert($inc);
    $row = $accountRow($acc['id']);
    $expect('T2 an incomplete subscription is recorded, the paid-until date stays',
        [(string) $inc->status, $row['stripe_status'], (string) $row['subscription_end_date']], ['incomplete', 'incomplete', $endBefore]);
    echo "\n";
}

// ── S: subscription state (round 2, item 6) ─────────────────────────────
if ($run('S')) {
    echo "S — subscription state\n";
    // S1 an older unpaid subscription and a newer paid one.
    $clock = $newClock('S1 two subs');
    $acc = $newAccount($clock, 'S1 two subs');
    $subA = $subscribe($acc, 'monthly');
    $fail = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', ['customer' => $acc['customer']]);
    $stripe->customers->update($acc['customer'], ['invoice_settings' => ['default_payment_method' => $fail->id]]);
    $advance($clock, $clockNow($clock) + 33 * 86400);
    BillingController::handleSubscriptionUpsert($subA);
    $expect('S1 the first subscription is past_due', $accountRow($acc['id'])['stripe_status'], 'past_due');
    $good = $stripe->paymentMethods->attach('pm_card_visa', ['customer' => $acc['customer']]);
    $stripe->customers->update($acc['customer'], ['invoice_settings' => ['default_payment_method' => $good->id]]);
    $subB = $stripe->subscriptions->create(['customer' => $acc['customer'], 'items' => [['price' => StripeClient::priceFor('monthly'), 'quantity' => 1]]]);
    BillingController::handleSubscriptionUpsert($subB);
    $row = $accountRow($acc['id']);
    $expect('S1 the newer paid subscription takes over', [$row['stripe_subscription_id'], $row['stripe_status']], [$subB->id, 'active']);
    BillingController::handleSubscriptionUpsert($subA);
    $expect('S1 a later event of the unpaid one is ignored', $accountRow($acc['id'])['stripe_subscription_id'], $subB->id);
    $addTech($acc);
    $addTech($acc);
    $expect('S1 the extra technician goes onto the paid subscription, not the unpaid one', [$extrasOf($subB->id)['qty'], $extrasOf($subA->id)['qty']], [1, 0]);
    $live = array_column((new ReflectionMethod(\Firol\Controllers\AdminPanelController::class, 'liveSubscriptions'))->invoke(null, $acc['id']), 'status', 'id');
    ksort($live);
    $want = [$subA->id => 'past_due', $subB->id => 'active'];
    ksort($want);
    $expect('S1 the admin account delete sees both live subscriptions', $live, $want);
    $rSkip[] = $acc['id'];

    // S2 a lost event: the DB still says active, Stripe has cancelled it.
    // (A is cancelled too: a live A would be taken over by the daily run —
    // the webhook's rule, a new subscription after the current one ended.)
    $stripe->subscriptions->cancel($subA->id);
    $stripe->subscriptions->cancel($subB->id);
    [$code, $out] = $runScript('billing-daily.php', ['--only=seats', '--account=' . $acc['id']]);
    $note('S2 daily: ' . trim(implode(' | ', array_filter(explode("\n", $out), static fn ($l) => str_contains($l, 'account=')))));
    $expect('S2 the daily run writes Stripe\'s status', $accountRow($acc['id'])['stripe_status'], 'canceled');

    // S3 Checkout with a stale „active" in the DB (over HTTP, as the main user).
    $clock = $newClock('S3 checkout');
    $acc = $newAccount($clock, 'S3 checkout');
    $sub = $subscribe($acc, 'monthly');
    $stripe->subscriptions->cancel($sub->id);
    $pdo->prepare("UPDATE accounts SET stripe_status = 'active' WHERE id = ?")->execute([$acc['id']]);
    $pw = 'Pw-' . bin2hex(random_bytes(4)) . '-123';
    $mainId = (int) $pdo->query('SELECT main_user_id FROM accounts WHERE id = ' . (int) $acc['id'])->fetchColumn();
    $pdo->prepare('UPDATE users SET password_hash = ?, vop_version = ?, vop_accepted_at = NOW(), privacy_version = ?, privacy_accepted_at = NOW() WHERE id = ?')
        ->execute([\Firol\Auth\Password::hash($pw), \Firol\Legal\Terms::VOP_VERSION, \Firol\Legal\Terms::PRIVACY_VERSION, $mainId]);
    $email = (string) $pdo->query('SELECT email FROM users WHERE id = ' . $mainId)->fetchColumn();
    $cookie = tempnam(sys_get_temp_dir(), 'billing-s3');
    $http = static function (string $method, string $path, ?array $body, array $headers = []) use ($cookie): array {
        $ch = curl_init('http://nginx' . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers)]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($out, true)];
    };
    [, $login] = $http('POST', '/api/auth/login', ['email' => $email, 'password' => $pw]);
    [$code, $res] = $http('POST', '/api/billing/checkout', ['billing_period' => 'monthly'], ['X-CSRF-Token: ' . (string) ($login['csrfToken'] ?? '')]);
    if (isset($res['id'])) {
        $sessions[] = (string) $res['id'];
    }
    @unlink($cookie);
    $expect('S3 Checkout opens (Stripe says canceled, not the stale DB „active")', [$code, isset($res['url'])], [200, true]);
    $expect('S3 …and the DB now has Stripe\'s status', $accountRow($acc['id'])['stripe_status'], 'canceled');
    echo "\n";
}

// ── W: the reconcile switch (round 2, item 7) ───────────────────────────
if ($run('W')) {
    echo "W — BILLING_RECONCILE=off, then on\n";
    $clock = $newClock('W switch');
    $acc = $newAccount($clock, 'W switch');
    $addTech($acc, false);
    $sub = $subscribe($acc, 'monthly'); // 2 technicians, no extra item
    $_ENV['BILLING_RECONCILE'] = 'off';
    $addTech($acc); // a roster change with the switch off
    BillingController::handleSubscriptionUpsert($sub); // a webhook with the switch off
    $expect('W switch off: nothing pushed, the DB keeps the wanted count', [$extrasOf($sub->id)['count'], (int) $accountRow($acc['id'])['extra_technicians']], [0, 1]);
    [, $out] = $runScript('billing-seat-report.php', ['--account=' . $acc['id']], ['BILLING_RECONCILE' => 'off']);
    $expect('W the seat report lists the gap (NOT CHARGED) and says the switch is off', [str_contains($out, 'NOT CHARGED'), str_contains($out, 'reconcile OFF')], [true, true]);
    [, $out] = $runScript('billing-daily.php', ['--only=seats', '--account=' . $acc['id']], ['BILLING_RECONCILE' => 'off']);
    $expect('W the daily run only reports', [str_contains($out, 'would_change'), $extrasOf($sub->id)['count']], [true, 0]);
    unset($_ENV['BILLING_RECONCILE']);
    [, $out] = $runScript('billing-daily.php', ['--only=seats', '--account=' . $acc['id']]);
    $x = $extrasOf($sub->id);
    $expect('W switch on: the daily run repairs it (item qty 1)', [$x['count'], $x['qty']], [1, 1]);
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
            $status = null;
            $cancel = 0;
            if ($r['stripe_subscription_id']) {
                $live = $stripe->subscriptions->retrieve((string) $r['stripe_subscription_id']);
                $status = (string) $live->status;
                $cancel = !in_array($status, ['canceled', 'incomplete_expired'], true) && $live->cancel_at_period_end ? 1 : 0;
            }
            $expected[$id] = [$r['stripe_subscription_id'], $status, in_array($id, $rNoPeriod, true) ? null : $r['billing_period'], (int) $r['extra_technicians'], $cancel];
        }
        $endState = static function (string $label) use ($accounts, &$rSkip, $accountRow, &$expected, $expect, $note): void {
            foreach ($accounts as $id) {
                if (in_array($id, $rSkip, true)) {
                    continue;
                }
                $r = $accountRow($id);
                $got = [$r['stripe_subscription_id'], $r['stripe_status'], $r['billing_period'], (int) $r['extra_technicians'], (int) $r['stripe_cancel_at_period_end']];
                $expect("R $label: account $id end state matches", $got, $expected[$id]);
            }
            if ($rSkip !== []) {
                $note("$label: not compared (two subscriptions, the reset forgets which one was current): accounts " . implode(', ', $rSkip));
            }
        };
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

        $endState('first pass');

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
                $sum = (int) round(array_sum(array_map(static fn ($i) => $i['Amount'] * $i['UnitPrice'] * 100, $payload['Items'] ?? [])));
                $expect("R row {$r['id']} payload sums to the amount paid", $sum, (int) $r['stripe_amount_paid_cents']);
                $note(sprintf('  prepared %s %s: %s', $r['stripe_invoice_id'], $eur((int) $r['stripe_total_cents']), json_encode(array_map(static fn ($i) => $i['Name'] . ' ' . $i['Amount'] . '×' . $i['UnitPrice'], $payload['Items'] ?? []), JSON_UNESCAPED_UNICODE)));
            }
        }

        // Round 2: everything again (new event ids — Stripe re-sending it all)
        // and then everything in reverse order: same end state, no invoice
        // row twice, nothing sent, no cancel flag left on a cancelled account.
        $rowCount = static function () use ($pdo, $in, $accounts): int {
            $s = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE account_id IN ($in)");
            $s->execute($accounts);
            return (int) $s->fetchColumn();
        };
        $rowsBefore = $rowCount();
        foreach (['again' => $events, 'reverse' => array_reverse($events)] as $label => $list) {
            $codes = [];
            foreach ($list as $e) {
                $e['id'] .= "_$label";
                $codes[] = $post($e);
            }
            $expect("R $label: every event answered 204", array_values(array_unique($codes)), [204]);
            sleep(3);
            $endState($label);
            $expect("R $label: no new invoice row (each Stripe invoice recorded once)", $rowCount(), $rowsBefore);
            $s = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE account_id IN ($in) AND status IN ('issued', 'sending', 'error')");
            $s->execute($accounts);
            $expect("R $label: nothing issued or sending (iDoklad untouched)", (int) $s->fetchColumn(), 0);
        }
    }
    echo "\n";
}

echo $failures === 0 ? "All checks passed.\n" : "$failures check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
