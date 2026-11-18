<?php

declare(strict_types=1);

/**
 * Billing checks that need neither iDoklad nor a Stripe test clock (fix lists
 * of 17. 11. 2026, docs/prompts/billing-fixes-2026-11.md and
 * billing-fixes-2026-11-round-2.md):
 *
 *   1. IssuePolicy — what is sent and what is only prepared; a test-mode
 *      Stripe invoice is never sent. The policy matrix (APP_ENV ×
 *      IDOKLAD_ISSUE_MODE × Stripe key) with what the admin check says: a
 *      live key without `send` and a prepared live invoice are errors.
 *   2. Captured webhook payloads in API version 2026-04-22.dahlia
 *      (bin/fixtures/stripe-dahlia): fields read whatever the version.
 *   3. Which Stripe invoices become an iDoklad invoice (0 €, credit, netted
 *      proration pairs, promotion-code discounts) and the payload built for
 *      them.
 *   4. InvoiceIssuer end to end with a RECORDING STUB in place of iDoklad: the
 *      payload that would be posted, a failure and the retry cap, an
 *      uncertain POST, a 2xx without an invoice Id, a credit invoice held for
 *      review, a row of the old code without a snapshot, the policy path
 *      with the client built from env.
 *   5. bin/reprice-yearly-extra-seats.php refuses a bad or misspelled option;
 *      bin/billing-daily.php with a live key outside production refuses only
 *      the Stripe writes, stamps and alerts the refusal once; partial runs do
 *      not stamp the last run.
 *  5b. The daily run's invoice step as a child process (recorder prepended):
 *      a held row whose alert failed is alerted once, a prepared / skipped
 *      live row is tried once per run (not twice) and issued once allowed,
 *      a webhook event Stripe no longer has becomes `gone`.
 *
 * Round 3 (billing-fixes-2026-11-round-3.md) adds to part 3: proration
 * lines netted per subscription and kind over the whole invoice (a merge
 * with the calls 3 s apart, a merge an hour later, add-then-remove with a
 * positive and a negative net), the plan never an extra line, the „3 ×"
 * and „po zľave" texts, classifyItems after a base price change, the
 * reconcile switch values and the next charge while nothing is pushed; to
 * part 4: a held paid row and a skipped live row as admin errors, the daily
 * claim only from `prepared`.
 *   6. Over HTTP (nginx → PHP-FPM): a team member who is not the main user
 *      gets 403 from every billing action; a webhook event that fails is
 *      handled again on Stripe's retry instead of being skipped (needs an
 *      sk_test_ key — Stripe answers 404 for the made-up subscription).
 *
 *     docker compose exec php php bin/test-billing-invoices.php   (refuses anything but an sk_test_ key)
 *
 * iDoklad is never called: bin/lib/idoklad-recorder.php is loaded before the
 * autoloader and IS Firol\Idoklad\IDokladClient in this process (checked),
 * and the credentials are removed as well. Mail is switched off in this
 * process. Parts 1–4 run in one transaction that is rolled back; part 6
 * creates throwaway rows and deletes them. Local / staging only.
 */

require __DIR__ . '/lib/idoklad-recorder.php'; // before the autoloader: no real iDoklad client in this process
require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Auth\Password;
use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Idoklad\IDokladClient;
use Firol\Idoklad\IDokladTransportException;
use Firol\Idoklad\InvoiceIssuer;
use Firol\Idoklad\IssuePolicy;
use Firol\Stripe\StripeShape;

if (strtolower((string) ($_ENV['APP_ENV'] ?? '')) === 'production') {
    fwrite(STDERR, "Refusing to run in production.\n");
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
$_ENV['SMTP_HOST'] = '';
IDokladClient::assertStub();

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

$withEnv = static function (array $env, callable $fn): mixed {
    $saved = [];
    foreach ($env as $k => $v) {
        $saved[$k] = $_ENV[$k] ?? null;
        if ($v === null) {
            unset($_ENV[$k]);
        } else {
            $_ENV[$k] = $v;
        }
    }
    try {
        return $fn();
    } finally {
        foreach ($saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
};

// ── 1. IssuePolicy ──────────────────────────────────────────────────────
echo "1 — IssuePolicy\n";
$p = static fn (array $env, ?bool $live) => $withEnv($env, static fn () => IssuePolicy::decide($live)['send']);
$expect('local, default mode, live invoice → prepare', $p(['APP_ENV' => 'local', 'IDOKLAD_ISSUE_MODE' => null, 'IDOKLAD_ALLOW_TEST_EVENTS' => null], true), false);
$expect('production, default mode, live invoice → send', $p(['APP_ENV' => 'production', 'IDOKLAD_ISSUE_MODE' => null, 'IDOKLAD_ALLOW_TEST_EVENTS' => null], true), true);
$expect('production, test-mode invoice → never sent', $p(['APP_ENV' => 'production', 'IDOKLAD_ISSUE_MODE' => null, 'IDOKLAD_ALLOW_TEST_EVENTS' => null], false), false);
$expect('production, livemode unknown → not sent', $p(['APP_ENV' => 'production', 'IDOKLAD_ISSUE_MODE' => null, 'IDOKLAD_ALLOW_TEST_EVENTS' => null], null), false);
$expect('local with IDOKLAD_ISSUE_MODE=send, test-mode invoice → not sent', $p(['APP_ENV' => 'local', 'IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_ALLOW_TEST_EVENTS' => null], false), false);
$expect('…plus IDOKLAD_ALLOW_TEST_EVENTS=true → sent', $p(['APP_ENV' => 'local', 'IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_ALLOW_TEST_EVENTS' => 'true'], false), true);
$expect('production with IDOKLAD_ISSUE_MODE=prepare → not sent', $p(['APP_ENV' => 'production', 'IDOKLAD_ISSUE_MODE' => 'prepare', 'IDOKLAD_ALLOW_TEST_EVENTS' => null], true), false);
$expect('IDOKLAD_DRAFT_MODE is not read', $p(['APP_ENV' => 'local', 'IDOKLAD_ISSUE_MODE' => null, 'IDOKLAD_DRAFT_MODE' => 'false'], true), false);
$expect('live key alone does not imply send (APP_ENV=local)', $p(['APP_ENV' => 'local', 'IDOKLAD_ISSUE_MODE' => null, 'STRIPE_SECRET_KEY' => 'sk_live_fake'], true), false);

// The matrix of round 2, item 3: what is sent for a live Stripe invoice and
// what „Kontrola fakturácie" says about it. A wrong APP_ENV on a live key
// must show as an ERROR, never as OK.
echo "     · policy matrix for a LIVE Stripe invoice (send? / admin check level of the iDoklad mode):\n";
$levelOf = static function (array $checks, array $keys): string {
    $worst = 'ok';
    foreach ($checks as $c) {
        if (in_array($c['key'], $keys, true) && ($c['level'] === 'error' || ($c['level'] === 'warn' && $worst === 'ok'))) {
            $worst = $c['level'];
        }
    }
    return $worst;
};
$matrix = [];
foreach (['sk_test_fake' => 'test key', 'sk_live_fake' => 'LIVE key'] as $keyValue => $keyLabel) {
    foreach (['production', 'prod', 'local', ''] as $appEnv) {
        foreach ([null, 'send', 'prepare'] as $mode) {
            [$send, $level] = $withEnv(
                ['STRIPE_SECRET_KEY' => $keyValue, 'APP_ENV' => $appEnv, 'IDOKLAD_ISSUE_MODE' => $mode,
                 'IDOKLAD_ALLOW_TEST_EVENTS' => null, 'STRIPE_PRODUCT_EXTRA_TECHNICIAN' => null,
                 'IDOKLAD_CLIENT_ID' => 'x', 'IDOKLAD_CLIENT_SECRET' => 'x'],
                static fn () => [IssuePolicy::decide(true)['send'], $levelOf(\Firol\Billing\ConfigCheck::run(), ['idoklad', 'idoklad_live_mode'])],
            );
            $matrix[] = [$keyLabel, $appEnv, $mode, $send, $level];
            printf("       %-8s APP_ENV=%-10s IDOKLAD_ISSUE_MODE=%-8s → %-7s check %s\n",
                $keyLabel, $appEnv === '' ? '(empty)' : $appEnv, $mode ?? '(unset)', $send ? 'SEND' : 'prepare', strtoupper($level));
        }
    }
}
$liveNotSent = array_filter($matrix, static fn ($r) => $r[0] === 'LIVE key' && !$r[3]);
$expect('matrix: every live-key row that does not send is an admin ERROR', array_values(array_unique(array_map(static fn ($r) => $r[4], $liveNotSent))), ['error']);
$expect('matrix: live key + APP_ENV=prod (not „production"), mode unset → not sent, ERROR', [$matrix[15][3], $matrix[15][4]], [false, 'error']);
$expect('matrix: live key + APP_ENV=production + IDOKLAD_ISSUE_MODE=send → sent, OK', [$matrix[13][3], $matrix[13][4]], [true, 'ok']);
$expect('matrix: a live key with send is always sent', array_values(array_unique(array_map(static fn ($r) => $r[3], array_filter($matrix, static fn ($r) => $r[0] === 'LIVE key' && $r[2] === 'send')))), [true]);
echo "\n";

// ── 2. dahlia payloads ──────────────────────────────────────────────────
echo "2 — captured payloads in API version 2026-04-22.dahlia\n";
$fx = __DIR__ . '/fixtures/stripe-dahlia';
$subEvent = json_decode((string) file_get_contents("$fx/customer.subscription.updated.json"), true);
$invEvent = json_decode((string) file_get_contents("$fx/invoice.paid.json"), true);
$expect('fixtures are dahlia', [$subEvent['api_version'], $invEvent['api_version']], ['2026-04-22.dahlia', '2026-04-22.dahlia']);
$sub = $subEvent['data']['object'];
$expect('subscription has no root current_period_end in dahlia', array_key_exists('current_period_end', $sub), false);
$itemEnds = array_map(static fn ($i) => $i['current_period_end'], $sub['items']['data']);
$expect('period end read from the items', StripeShape::subscriptionPeriodEnd($sub), max($itemEnds));
$extraProduct = null;
foreach ($sub['items']['data'] as $item) {
    if (($item['metadata']['firol_line_kind'] ?? '') === SeatSync::LINE_KIND) {
        $extraProduct = StripeShape::itemProductId($item);
    }
}
$basePrice = null;
foreach ($sub['items']['data'] as $item) {
    if (($item['metadata']['firol_line_kind'] ?? '') !== SeatSync::LINE_KIND) {
        $basePrice = $item['price']['id'];
    }
}
$split = $withEnv(['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => $extraProduct, 'STRIPE_PRICE_MONTHLY' => $basePrice],
    static fn () => SeatSync::classifyItems($sub['items']['data'], '', []));
$expect('dahlia subscription: base and one extra item told apart', [count($split['extras']), $split['base']['price']['id'] ?? null], [1, $basePrice]);

$inv = $invEvent['data']['object'];
$expect('invoice has no root subscription in dahlia', array_key_exists('subscription', $inv), false);
$expect('invoice subscription read from parent', StripeShape::invoiceSubscriptionId($inv), $sub['id'] === StripeShape::invoiceSubscriptionId($inv) ? $sub['id'] : StripeShape::invoiceSubscriptionId($inv));
$prorations = array_values(array_filter($inv['lines']['data'], static fn ($l) => StripeShape::lineIsProration($l)));
$expect('proration read from line parent (7 of 9)', count($prorations), 7);
$expect('line price/product read from pricing', StripeShape::lineProductId($inv['lines']['data'][8]) !== null && StripeShape::linePriceId($inv['lines']['data'][8]) !== null, true);
// Round 3: an addition, a quantity change, a removal and two merges in one
// period net to +1 technician (+11,66 €) — one line, issued (round 2 held it
// as a credit: the removal's lines formed a negative group of their own).
$expect('captured renewal (add, change, remove in one period) → issue, one netted line', [
    InvoiceIssuer::classify($inv)['decision'],
    $withEnv(['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => $extraProduct], static fn () => array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($inv))[0]),
], ['issue', ['POapp — extra technik, pomerná časť (+1 technik) (17.11.–17.12.2026)', 1166]]);
$base = null;
foreach ($inv['lines']['data'] as $l) {
    if (!StripeShape::lineIsProration($l)) {
        $base = $l;
        break;
    }
}
$per = static fn (int $a, int $b): string => date('Y', $a) === date('Y', $b)
    ? ' (' . date('d.m.', $a) . '–' . date('d.m.Y', $b) . ')'
    : ' (' . date('d.m.Y', $a) . ' – ' . date('d.m.Y', $b) . ')';
$want = 'POapp — predplatné' . $per($base['period']['start'], $base['period']['end']);
$first = $inv['lines']['data'][0];
$expect('description period from the first non-proration line, not lines[0]', InvoiceIssuer::deriveDescription($inv), $want);
$expect('…lines[0] is a proration with another period', [StripeShape::lineIsProration($first), $first['period']['start'] !== $base['period']['start']], [true, true]);
echo "\n";

// ── 3. classification and payload ───────────────────────────────────────
echo "3 — which Stripe invoices are invoiced\n";
$period = ['start' => strtotime('2026-12-17'), 'end' => strtotime('2027-01-17')];
$line = static fn (int $amount, string $desc, bool $proration = false, int $qty = 1, ?array $per = null, int $discount = 0, string $item = 'si_x') => [
    'amount' => $amount, 'description' => $desc, 'quantity' => $qty, 'proration' => $proration,
    'type' => $proration ? 'invoiceitem' : 'subscription', 'period' => $per ?? $period,
    'subscription' => 'sub_test', 'subscription_item' => $item,
    'price' => ['id' => 'price_x', 'product' => $desc === 'extra' ? 'prod_extra_test' : 'prod_base_test'],
    'discount_amounts' => $discount > 0 ? [['amount' => $discount, 'discount' => 'di_test']] : [],
];
$invoice = static fn (int $total, int $paid, array $lines, array $more = []) => array_merge([
    'id' => 'in_test_' . bin2hex(random_bytes(4)), 'object' => 'invoice', 'currency' => 'eur', 'livemode' => false,
    'total' => $total, 'amount_paid' => $paid, 'lines' => ['data' => $lines, 'has_more' => false],
    'status_transitions' => ['paid_at' => strtotime('2026-12-17 10:00')],
], $more);
$c = static fn (array $i) => InvoiceIssuer::classify($i)['decision'] . (InvoiceIssuer::classify($i)['reason'] !== '' ? '/' . InvoiceIssuer::classify($i)['reason'] : '');
$expect('0 € trial-start invoice → no invoice', $c($invoice(0, 0, [$line(0, 'base')])), 'skip/zero');
$expect('19 € paid in full → issue', $c($invoice(1900, 1900, [$line(1900, 'base')])), 'issue');
$expect('19 € paid 9 € by card, rest from credit → review', $c($invoice(1900, 900, [$line(1900, 'base')])), 'review/credit');
$expect('fully covered by credit (paid 0) → review', $c($invoice(1900, 0, [$line(1900, 'base')])), 'review/credit');
$expect('negative total → review', $c($invoice(-500, 0, [$line(-500, 'extra', true)])), 'review/credit');
$expect('positive total with a credit line → review', $c($invoice(2400, 2400, [$line(1900, 'base'), $line(1000, 'extra'), $line(-500, 'extra', true)])), 'review/credit');
$expect('lines ≠ total with no discount to explain it (tax?) → review', $c($invoice(1520, 1520, [$line(1900, 'base')])), 'review/mismatch');

// Round 2, item 1: proration pairs of one change are one charge.
$change = ['start' => strtotime('2026-12-01'), 'end' => strtotime('2026-12-17')];
$xEnv = ['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test'];
$qtyUp = $invoice(534 + 1900 + 2000, 534 + 1900 + 2000, [
    $line(-534, 'extra', true, 1, $change), $line(1068, 'extra', true, 2, $change),
    $line(1900, 'base'), $line(2000, 'extra', false, 2),
]);
$expect('quantity 1→2 (Unused 1× / Remaining 2×) → issue', $withEnv($xEnv, static fn () => $c($qtyUp)), 'issue');
$expect('…netted into one line „(+1 technik)"', $withEnv($xEnv, static fn () => array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($qtyUp))), [
    ['POapp — extra technik, pomerná časť (+1 technik) (01.12.–17.12.2026)', 534],
    ['POapp — predplatné (17.12.2026 – 17.01.2027)', 1900],
    ['POapp — extra technik (17.12.2026 – 17.01.2027)', 2000],
]);
$expect('…payload: 2 extra technicians as 2 × 10 €', $withEnv($xEnv, static fn () => array_map(static fn ($i) => [$i['Amount'], $i['UnitPrice']], InvoiceIssuer::buildPayload(1, $qtyUp)['Items'])), [[1, 5.34], [1, 19.0], [2, 10.0]]);
$merge = $invoice(1900 + 2000, 1900 + 2000, [
    $line(-534, 'extra', true, 1, $change, 0, 'si_dup'), $line(-534, 'extra', true, 1, $change, 0, 'si_keep'), $line(1068, 'extra', true, 2, $change, 0, 'si_keep'),
    $line(1900, 'base'), $line(2000, 'extra', false, 2, null, 0, 'si_keep'),
]);
$expect('duplicate items merged (net 0) → no proration line, issue', $withEnv($xEnv, static fn () => [$c($merge), count(InvoiceIssuer::invoiceLines($merge))]), ['issue', 2]);
$removal = $invoice(1900 + 1000 - 534, 1900 + 1000 - 534, [
    $line(-1068, 'extra', true, 2, $change), $line(534, 'extra', true, 1, $change),
    $line(1900, 'base'), $line(1000, 'extra'),
]);
$expect('a real removal (2→1 nets to −5,34 €) is still a credit → review', $withEnv($xEnv, static fn () => $c($removal)), 'review/credit');
$expect('3 technicians added at once → „(+3 technici)"', $withEnv($xEnv, static fn () => InvoiceIssuer::invoiceLines($invoice(1602, 1602, [$line(-534, 'extra', true, 1, $change), $line(2136, 'extra', true, 4, $change)]))[0]['name']),
    'POapp — extra technik, pomerná časť (+3 technici) (01.12.–17.12.2026)');

// Round 3, item 1: the proration lines of one subscription and kind are
// netted over the whole invoice, not per proration period.
$at = static fn (string $start) => ['start' => strtotime($start), 'end' => strtotime('2026-12-17')];
$mergeApart = $invoice(1900 + 2000, 1900 + 2000, [
    $line(-700, 'extra', true, 1, $at('2026-12-01 10:00:00'), 0, 'si_dup'),   // delete of the duplicate
    $line(-700, 'extra', true, 1, $at('2026-12-01 10:00:03'), 0, 'si_keep'),  // update 3 s later: Unused 1×
    $line(1400, 'extra', true, 2, $at('2026-12-01 10:00:03'), 0, 'si_keep'),  //                  Remaining 2×
    $line(1900, 'base'), $line(2000, 'extra', false, 2, null, 0, 'si_keep'),
]);
$expect('merge with the two calls 3 s apart (groups −7,00 / +7,00 in round 2) → issue, no proration line',
    $withEnv($xEnv, static fn () => [$c($mergeApart), count(InvoiceIssuer::invoiceLines($mergeApart))]), ['issue', 2]);
$mergeLate = $invoice(2 + 1900 + 1000, 2 + 1900 + 1000, [
    $line(867, 'extra', true, 1, $at('2026-12-01 10:00'), 0, 'si_dup'),   // the duplicate appeared
    $line(-865, 'extra', true, 1, $at('2026-12-01 11:00'), 0, 'si_dup'),  // merged an hour later
    $line(1900, 'base'), $line(1000, 'extra'),
]);
$expect('merge an hour after the duplicate appeared (−8,65 / +8,67) → issue, +0,02 € worded without a count',
    $withEnv($xEnv, static fn () => [$c($mergeLate), array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($mergeLate))[0]]),
    ['issue', ['POapp — extra technik, pomerná časť (01.12.–17.12.2026)', 2]]);
$addRemovePlus = $invoice(500 + 1900, 500 + 1900, [
    $line(1000, 'extra', true, 1, $at('2026-11-17')), $line(-500, 'extra', true, 1, $at('2026-12-02')), $line(1900, 'base'),
]);
$expect('add then remove in one period, positive net (+5,00 €) → issue, one line without a count',
    $withEnv($xEnv, static fn () => [$c($addRemovePlus), array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($addRemovePlus))[0]]),
    ['issue', ['POapp — extra technik, pomerná časť (17.11.–17.12.2026)', 500]]);
$addRemoveMinus = $invoice(1900 + 1000 - 167, 1900 + 1000 - 167, [
    $line(333, 'extra', true, 1, $at('2026-12-07')),                                       // a 3rd extra technician
    $line(-1000, 'extra', true, 2, $at('2026-12-02')), $line(500, 'extra', true, 1, $at('2026-12-02')), // 2 → 1 earlier
    $line(1900, 'base'), $line(1000, 'extra'),
]);
$expect('add and remove in one period, negative net (−1,67 €) → still held as a credit',
    $withEnv($xEnv, static fn () => $c($addRemoveMinus)), 'review/credit');
$expect('…the held reason is Slovak', str_starts_with($withEnv($xEnv, static fn () => InvoiceIssuer::classify($addRemoveMinus)['detail']), 'dobropis -1,67 €'), true);

// Round 3, item 6: a line under a base price is never an extra line.
$guard = $line(1900, 'x');
$guard['description'] = '1 × POapp — extra technik (at €19.00 / month)';
$guard['price'] = ['id' => 'price_base_guard', 'product' => 'prod_whatever'];
$expect('a base-price line with a legacy name in its description is the plan',
    $withEnv(['STRIPE_PRICE_MONTHLY' => 'price_base_guard'], static fn () => InvoiceIssuer::invoiceLines($invoice(1900, 1900, [$guard]))[0]['kind']), 'base');
$guard['price'] = ['id' => 'price_x', 'product' => 'prod_extra_test'];
$expect('…also when STRIPE_PRODUCT_EXTRA_TECHNICIAN is that line\'s product but its price is a base price',
    $withEnv(['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test', 'STRIPE_PRICE_MONTHLY' => 'price_x'], static fn () => InvoiceIssuer::invoiceLines($invoice(1900, 1900, [$guard]))[0]['kind']), 'base');

// Round 3, item 6: texts of discounted lines.
$three = $invoice(1900 + 2750, 1900 + 2750, [$line(1900, 'base'), $line(3000, 'extra', false, 3, null, 250)],
    ['total_discount_amounts' => [['amount' => 250, 'discount' => 'di_test']]]);
$expect('a discounted line that does not divide by the quantity → 1 ks with the count in the name',
    $withEnv($xEnv, static fn () => array_map(static fn ($i) => [$i['Name'], $i['Amount'], $i['UnitPrice']], InvoiceIssuer::buildPayload(1, $three)['Items'])[1]),
    ['POapp — 3 × extra technik, po zľave (17.12.2026 – 17.01.2027)', 1, 27.5]);
$proDisc = $line(534, 'extra', true, 1, $change);
$proDisc['discountable'] = true;
$discRenewal = $invoice(534 + 1710 + 890, 534 + 1710 + 890, [$proDisc, $line(1900, 'base', false, 1, null, 190), $line(1000, 'extra', false, 1, null, 110)],
    ['total_discount_amounts' => [['amount' => 300, 'discount' => 'di_test']]]);
$expect('a proration of a discounted subscription is named „po zľave" like the plan line',
    $withEnv($xEnv, static fn () => [$c($discRenewal), InvoiceIssuer::invoiceLines($discRenewal)[0]['name']]),
    ['issue', 'POapp — extra technik, pomerná časť (+1 technik), po zľave (01.12.–17.12.2026)']);

// Round 3, item 6: classifyItems after a base price change.
$item = static fn (string $id, string $price, string $product, array $meta = []) => ['id' => $id, 'quantity' => 1, 'metadata' => $meta, 'price' => ['id' => $price, 'product' => $product, 'recurring' => ['interval' => 'month']]];
$cls = static fn (array $items, string $recExtra = '', string $recBase = '') => $withEnv(
    ['STRIPE_PRICE_MONTHLY' => 'price_plan_new', 'STRIPE_PRICE_YEARLY' => 'price_plan_new_y', 'STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test'],
    static function () use ($items, $recExtra, $recBase) {
        $s = SeatSync::classifyItems($items, $recExtra, [], $recBase);
        return [$s['base']['id'] ?? null, array_column($s['extras'], 'id'), array_column($s['unknown'], 'id')];
    });
$other = $item('si_other', 'price_other', 'prod_other');
$oldPlan = $item('si_plan', 'price_plan_old', 'prod_plan_old');
$expect('changed base price, an unrelated item listed first: the remembered plan item is the base, the other is unknown',
    $cls([$other, $oldPlan], '', 'si_plan'), ['si_plan', [], ['si_other']]);
$expect('…without a remembered plan item: no base, both unknown (left for a person, no next charge)',
    $cls([$other, $oldPlan]), [null, [], ['si_other', 'si_plan']]);
$expect('one unrecognised item and nothing else: it is the plan (as before)', $cls([$oldPlan]), ['si_plan', [], []]);
$expect('an unmarked old extra item next to the plan, not recorded on the account → unknown (never a second extra item beside it)',
    $cls([$item('si_base', 'price_plan_new', 'prod_plan'), $item('si_oldx', 'price_oldx', 'prod_oldx')]), ['si_base', [], ['si_oldx']]);

// Round 3, item 5: the reconcile switch accepts only on / off.
$sw = static fn (?string $v) => $withEnv(['BILLING_RECONCILE' => $v], static fn () => [SeatSync::reconcileSwitch(), SeatSync::pushEnabled()]);
$expect('BILLING_RECONCILE unset / on / off / OFF / disabled / false',
    [$sw(null), $sw('on'), $sw('off'), $sw('OFF'), $sw('disabled'), $sw('false')],
    [['on', true], ['on', true], ['off', false], ['off', false], ['invalid', false], ['invalid', false]]);
$expect('…an invalid value is an admin error', $withEnv(['BILLING_RECONCILE' => 'disabled'], static fn () => array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key')['billing_reconcile'] ?? null), 'error');

// Round 3, item 5: „Ďalšia fakturácia" from Stripe's quantity while nothing is pushed.
$acct = ['stripe_subscription_id' => 'sub_x', 'stripe_base_unit_amount_cents' => 1900, 'extra_technicians' => 2,
         'stripe_extra_quantity' => 1, 'stripe_extra_subscription_item_id' => 'si_x', 'stripe_extra_unit_amount_cents' => 1000, 'seat_sync_state' => 'ok'];
$expect('next charge: switch on → wanted count (19 + 2 × 10)', $withEnv(['BILLING_RECONCILE' => null], static fn () => SeatSync::nextChargeCents($acct)), 3900);
$expect('…switch off → Stripe\'s count (19 + 1 × 10)', $withEnv(['BILLING_RECONCILE' => 'off'], static fn () => SeatSync::nextChargeCents($acct)), 2900);
$expect('…account left for a person → Stripe\'s count', $withEnv(['BILLING_RECONCILE' => null], static fn () => SeatSync::nextChargeCents(['seat_sync_state' => 'manual'] + $acct)), 2900);
$expect('…no plan item recognised → no next charge', SeatSync::nextChargeCents(['stripe_base_unit_amount_cents' => null] + $acct), null);

// Round 2, item 4: promotion codes — lines priced net of their discounts.
$pct = $invoice(1520 + 800, 1520 + 800, [$line(1900, 'base', false, 1, null, 380), $line(1000, 'extra', false, 1, null, 200)],
    ['total_discount_amounts' => [['amount' => 580, 'discount' => 'di_test']]]);
$expect('20 % coupon: lines net of discount_amounts → issue', $withEnv($xEnv, static fn () => $c($pct)), 'issue');
$expect('…payload sums to the amount paid, names say „po zľave"', $withEnv($xEnv, static function () use ($pct) {
    $items = InvoiceIssuer::buildPayload(1, $pct)['Items'];
    return [round(array_sum(array_map(static fn ($i) => $i['Amount'] * $i['UnitPrice'], $items)), 2), array_column($items, 'Name')];
}), [23.2, ['POapp — predplatné, po zľave (17.12.2026 – 17.01.2027)', 'POapp — extra technik, po zľave (17.12.2026 – 17.01.2027)']]);
$fixed = $invoice(2400, 2400, [$line(1900, 'base'), $line(1000, 'extra')], ['total_discount_amounts' => [['amount' => 500, 'discount' => 'di_test']]]);
$expect('discount carried by no line → one „Zľava" line, issue', $withEnv($xEnv, static fn () => [$c($fixed), array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($fixed))[2] ?? null]), ['issue', ['Zľava', -500]]);

$payload = $withEnv(['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test'], static fn () => InvoiceIssuer::buildPayload(1, $invoice(3567, 3567, [
    $line(667, 'extra', true, 1, ['start' => strtotime('2026-12-07'), 'end' => strtotime('2026-12-17')]),
    $line(1900, 'base'),
    $line(1000, 'extra'),
])));
$expect('payload: one item per line', array_map(static fn ($i) => [$i['Name'], $i['Amount'], $i['UnitPrice']], $payload['Items']), [
    ['POapp — extra technik, pomerná časť (+1 technik) (07.12.–17.12.2026)', 1, 6.67],
    ['POapp — predplatné (17.12.2026 – 17.01.2027)', 1, 19.0],
    ['POapp — extra technik (17.12.2026 – 17.01.2027)', 1, 10.0],
]);
$expect('payload: header period from the subscription line', $payload['Description'], 'POapp — predplatné (17.12.2026 – 17.01.2027)');
$expect('payload: taxing date = payment date', $payload['DateOfTaxing'], '2026-12-17');
foreach (SeatSync::LEGACY_PRODUCT_NAMES as $legacyName) {
    $legacy = $line(1000, 'x');
    $legacy['description'] = '1 × ' . $legacyName . ' (at €10.00 / month)';
    $expect("legacy extra line „{$legacyName}\" named as extra technician", InvoiceIssuer::buildPayload(1, $invoice(1000, 1000, [$legacy]))['Items'][0]['Name'], 'POapp — extra technik (17.12.2026 – 17.01.2027)');
}
echo "\n";

// ── 4. InvoiceIssuer with a recording stub ──────────────────────────────
echo "4 — InvoiceIssuer end to end, iDoklad replaced by a recorder\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO users (fullname, email, password_hash) VALUES ('Invoice test', ?, '')")->execute(['invoice-test-' . bin2hex(random_bytes(3)) . '@example.com']);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city, invoice_ico, main_user_id, subscription_end_date)
                   VALUES ('Invoice test s.r.o.', 'Test 1', '000 00', 'Test', '00000000', ?, '2027-01-01')")->execute([$uid]);
    $acc = (int) $pdo->lastInsertId();
    $row = static function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    };

    // Default (prepare): nothing is sent, the payload is stored.
    $i1 = $invoice(2900, 2900, [$line(1900, 'base'), $line(1000, 'extra')]);
    $id1 = InvoiceIssuer::record($acc, $i1, false);
    $expect('record: paid invoice → pending row', $id1 !== null ? $row($id1)['status'] : null, 'pending');
    $expect('record: the same invoice again → nothing new', InvoiceIssuer::record($acc, $i1, false), null);
    $st = (new InvoiceIssuer())->issue($id1);
    $r = $row($id1);
    $expect('prepare mode: status prepared, payload stored, nothing sent', [$st, $r['idoklad_invoice_id'], json_decode((string) $r['idoklad_payload_json'], true)['Note'] ?? null], ['prepared', null, 'Stripe invoice ' . $i1['id']]);

    // Even with IDOKLAD_ISSUE_MODE=send, a test-mode invoice is only prepared.
    $i2 = $invoice(1900, 1900, [$line(1900, 'base')]);
    $id2 = InvoiceIssuer::record($acc, $i2, false);
    $st = $withEnv(['IDOKLAD_ISSUE_MODE' => 'send'], static fn () => (new InvoiceIssuer())->issue($id2));
    $expect('send mode + livemode=false: prepared, never sent', [$st, str_contains((string) $row($id2)['error_message'], 'testovacia platba')], ['prepared', true]);
    $expect('round 3: preparing a row is not an attempt', [(int) $row($id1)['attempts'], (int) $row($id2)['attempts']], [0, 0]);

    // The send path, recorded.
    $rec = new IDokladClient();
    $i3 = $invoice(3567, 3567, [
        $line(667, 'extra', true, 1, ['start' => strtotime('2026-12-07'), 'end' => strtotime('2026-12-17')]),
        $line(1900, 'base'),
        $line(1000, 'extra'),
    ], ['livemode' => true]);
    $id3 = InvoiceIssuer::record($acc, $i3, true);
    $st = $withEnv(['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test'], static fn () => (new InvoiceIssuer($rec))->issue($id3));
    $sent = $rec->lastInvoicePost();
    $expect('recorder: issued, number stored', [$st, $row($id3)['document_number']], ['issued', 'FV-TEST-1']);
    $expect('recorder: calls made', array_map(static fn ($c) => $c[0] . ' ' . $c[1], $rec->calls), ['POST Contacts', 'GET IssuedInvoices/Default', 'POST IssuedInvoices']);
    $expect('recorded payload: partner, template numbering kept, read-only keys dropped',
        [$sent['PartnerId'] ?? null, $sent['DocumentSerialNumber'] ?? null, array_key_exists('Id', $sent ?? []), array_key_exists('PaymentStatus', $sent ?? [])],
        [555, 42, false, false]);
    $expect('recorded payload: items carry the template VAT fields', array_map(static fn ($i) => [$i['VatRateType'], $i['PriceType'], array_key_exists('Id', $i)], $sent['Items']), [[2, 1, false], [2, 1, false], [2, 1, false]]);
    $sum = array_sum(array_map(static fn ($i) => $i['Amount'] * $i['UnitPrice'], $sent['Items']));
    $expect('recorded payload: items add up to the Stripe total', round($sum, 2), 35.67);
    echo "     · recorded iDoklad payload:\n";
    foreach (explode("\n", (string) json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) as $l) {
        echo "       $l\n";
    }

    // A failing iDoklad: error, retried up to the cap, then left for a person.
    $rec4 = new IDokladClient();
    $rec4->failInvoicePost = new RuntimeException('iDoklad POST IssuedInvoices failed: HTTP 400 — validation');
    $id4 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuer4 = new InvoiceIssuer($rec4);
    $statuses = [];
    for ($k = 0; $k < 4; $k++) {
        $statuses[] = $issuer4->issue($id4);
    }
    $expect('failing iDoklad: error, 3 attempts, the 4th automatic run does nothing', [$statuses, (int) $row($id4)['attempts']], [['error', 'error', 'error', 'error'], 3]);
    $rec4->failInvoicePost = null;
    $expect('manual „Vystaviť znova" past the cap issues it', [$issuer4->issue($id4, true), (int) $row($id4)['attempts']], ['issued', 4]);

    // No definite answer to the POST: held, never re-sent automatically.
    $rec5 = new IDokladClient();
    $rec5->failInvoicePost = new IDokladTransportException('iDoklad transport error: Operation timed out');
    $id5 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuer5 = new InvoiceIssuer($rec5);
    $expect('timeout on the POST: needs_review / uncertain_send', [$issuer5->issue($id5), $row($id5)['review_reason']], ['needs_review', 'uncertain_send']);
    $posts = count(array_filter($rec5->calls, static fn ($c) => $c[1] === 'IssuedInvoices'));
    $issuer5->issue($id5);
    $expect('…an automatic run does not post again', count(array_filter($rec5->calls, static fn ($c) => $c[1] === 'IssuedInvoices')), $posts);

    // Credit and zero invoices.
    $idc = InvoiceIssuer::record($acc, $ic = $invoice(1900, 900, [$line(1900, 'base')], ['livemode' => true, 'starting_balance' => -1000, 'ending_balance' => 0]), true);
    $s = $pdo->prepare('SELECT status, review_reason, stripe_total_cents, stripe_amount_paid_cents FROM invoices WHERE stripe_invoice_id = ?');
    $s->execute([$ic['id']]);
    $expect('credit used: held as needs_review with the Stripe numbers', [$idc, $s->fetch()], [null, ['status' => 'needs_review', 'review_reason' => 'credit', 'stripe_total_cents' => 1900, 'stripe_amount_paid_cents' => 900]]);
    $iz = $invoice(0, 0, [$line(0, 'base')]);
    InvoiceIssuer::record($acc, $iz, false);
    $s->execute([$iz['id']]);
    $expect('0 € invoice: no row', $s->fetch(), false);

    // Round 2, item 5: a 2xx answer without an invoice Id is not „issued".
    $rec6 = new IDokladClient();
    $rec6->invoicePostAnswer = ['DocumentNumber' => ''];
    $id6 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuer6 = new InvoiceIssuer($rec6);
    $expect('2xx without Id: needs_review / uncertain_send, not issued', [$issuer6->issue($id6), $row($id6)['review_reason'], $row($id6)['idoklad_invoice_id']], ['needs_review', 'uncertain_send', null]);
    $issuer6->issue($id6);
    $expect('…not posted again automatically', $rec6->invoicePosts(), 1);
    $expect('…the admin can „Vystaviť znova" after checking iDoklad', InvoiceIssuer::manualIssueAllowed($row($id6)), true);

    // Round 2, item 5: rows the old code left without a snapshot.
    $pdo->prepare("INSERT INTO invoices (account_id, stripe_invoice_id, amount_cents, currency, status, issued_at)
                   VALUES (?, ?, 1900, 'EUR', 'pending', NOW())")->execute([$acc, 'in_legacy_doesnotexist_' . bin2hex(random_bytes(3))]);
    $idL = (int) $pdo->lastInsertId();
    $expect('legacy pending row without snapshot: no „Vystaviť" button', InvoiceIssuer::manualIssueAllowed($row($idL)), false);
    $recL = new IDokladClient();
    $st = (new InvoiceIssuer($recL))->issue($idL);
    $expect('…an automatic run never sends it; Stripe has no such invoice → missing_snapshot', [$st, $row($idL)['review_reason'], $recL->invoicePosts()], ['needs_review', 'missing_snapshot', 0]);
    $expect('…the message says to check iDoklad first', str_contains((string) $row($idL)['error_message'], 'najprv skontroluj v iDoklade, či faktúra už neexistuje'), true);
    $expect('…still no button (it cannot work until the snapshot is rebuilt)', InvoiceIssuer::manualIssueAllowed($row($idL)), false);

    // The real policy path: no injected API, the client comes from env —
    // and in this process that is the recorder (loaded before the autoloader).
    IDokladClient::$shared = new IDokladClient();
    $id7 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $st = $withEnv(['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => 'x', 'IDOKLAD_CLIENT_SECRET' => 'x'], static fn () => (new InvoiceIssuer())->issue($id7));
    $expect('policy send + live invoice + client from env → issued to the recorder', [$st, IDokladClient::$shared->invoicePosts()], ['issued', 1]);

    // Round 2, item 3: a prepared row of a live invoice is an admin error.
    $id8 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $st = $withEnv(['APP_ENV' => 'prod', 'IDOKLAD_ISSUE_MODE' => null], static fn () => (new InvoiceIssuer())->issue($id8));
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key');
    $expect('live invoice under APP_ENV=prod → prepared, and the admin check shows an ERROR', [$st, $checks['invoices_prepared_live'] ?? 'missing'], ['prepared', 'error']);
    $expect('…with „Vystaviť" offered', InvoiceIssuer::manualIssueAllowed($row($id8)), true);
    $expect('…the alert for it says to set IDOKLAD_ISSUE_MODE=send first (the button cannot work in prepare mode)',
        $withEnv(['APP_ENV' => 'prod', 'IDOKLAD_ISSUE_MODE' => null], static fn () => str_starts_with(\Firol\Idoklad\InvoiceAlert::action($row($id8)), 'Najprv nastav IDOKLAD_ISSUE_MODE=send')), true);

    // Round 3, item 2: a held paid row is an admin ERROR naming the account
    // and the amount; its alert is tried at once (mail is off here, so
    // operator_alerted_at stays NULL and the daily run repeats it).
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key');
    $expect('held paid rows: admin ERROR naming the account and the amount', [
        $checks['invoices_held']['level'] ?? null,
        str_contains((string) ($checks['invoices_held']['message'] ?? ''), 'Invoice test s.r.o. (#' . $acc . ') 9,00 €, dobropis / kredit'),
    ], ['error', true]);
    $s = $pdo->prepare('SELECT operator_alerted_at FROM invoices WHERE stripe_invoice_id = ?');
    $s->execute([$ic['id']]);
    $expect('…its alert failed (no SMTP here): operator_alerted_at NULL, left for the daily run', $s->fetchColumn(), null);

    // Round 3, item 2: a live row skipped because iDoklad has no credentials.
    $id9 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $st = $withEnv(['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => null, 'IDOKLAD_CLIENT_SECRET' => null], static fn () => (new InvoiceIssuer())->issue($id9));
    $checks = $withEnv(['APP_ENV' => 'production', 'IDOKLAD_CLIENT_ID' => null, 'IDOKLAD_CLIENT_SECRET' => null], static fn () => array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key'));
    $expect('live row with iDoklad not configured → skipped (no attempt used), admin ERROR; „iDoklad not configured" is an ERROR in production',
        [$st, (int) $row($id9)['attempts'], $checks['invoices_skipped_live'] ?? null, $checks['idoklad'] ?? null], ['skipped', 0, 'error', 'error']);

    // Round 3, item 5: the daily run claims a live row only from its status —
    // a row an admin moved to uncertain_send in between is never posted.
    $rec10 = new IDokladClient();
    $id10 = InvoiceIssuer::record($acc, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $pdo->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'uncertain_send' WHERE id = ?")->execute([$id10]);
    $expect('daily claim only from „prepared": a row moved to uncertain_send is not posted',
        [(new InvoiceIssuer($rec10))->issue($id10, true, ['prepared']), $rec10->invoicePosts()], ['needs_review', 0]);
} finally {
    $pdo->rollBack();
}
echo "\n";

// ── 5. reprice script options ───────────────────────────────────────────
echo "5 — bin/reprice-yearly-extra-seats.php refuses bad options\n";
$runScript = static function (array $args): int {
    $p = proc_open([PHP_BINARY, __DIR__ . '/reprice-yearly-extra-seats.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($p);
};
$expect('misspelled --acount=5 refused', $runScript(['--offline', '--acount=5']), 2);
$expect('non-numeric --account=abc refused', $runScript(['--offline', '--account=abc']), 2);
$expect('--offline --account=999999 runs (dry, database only)', $runScript(['--offline', '--account=999999']), 0);
// bin/billing-daily.php as a child process: with the iDoklad recorder
// prepended (auto_prepend_file — it IS the iDoklad client there too), blank
// iDoklad credentials and IDOKLAD_ISSUE_MODE=prepare unless a check says
// otherwise. The child reads backend/.env itself; these values win.
$daily = static function (array $env, array $args): array {
    $env += ['IDOKLAD_CLIENT_ID' => '', 'IDOKLAD_CLIENT_SECRET' => '', 'IDOKLAD_ISSUE_MODE' => 'prepare', 'IDOKLAD_ALLOW_TEST_EVENTS' => ''];
    $p = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/lib/idoklad-recorder.php', __DIR__ . '/billing-daily.php', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};
$settingOf = static function (string $key) use ($pdo): ?string {
    $s = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
};
// Round 3, item 3: a live key outside production refuses only the Stripe
// writes; the run is stamped, alerted, and the checks still run.
$lastRunBefore = $settingOf(\Firol\Billing\ConfigCheck::DAILY_RUN_KEY);
[$code, , $err] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--dry-run', '--only=seats', '--account=999999']);
$expect('billing-daily with a live key and APP_ENV=local: Stripe writes refused, exit 2 (dry run: nothing stamped)',
    [$code, str_contains($err, 'LIVE key'), $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY)], [2, true, null]);
[$code, $out] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--only=invoices', '--account=999999']);
$refusedStamp = $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY);
$checks = array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key');
$expect('…a real run: the invoice step still runs, the refusal is stamped and is an admin ERROR',
    [$code, str_contains($out, 'invoices:'), $refusedStamp !== null, $checks['daily_refused'] ?? null], [2, true, true, 'error']);
$expect('…one operator alert for it (mailed by the child, remembered)', \Firol\Billing\OperatorAlert::wasSent('daily_refused'), true);
[$code, $out] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--only=invoices', '--account=999999']);
$expect('…a second refused run does not alert again', [$code, str_contains($out, 'operator alert: already')], [2, true]);
[$code] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--dry-run', '--only=seats', '--account=999999', '--allow-live']);
$expect('…--allow-live lets it run (dry run, a missing account, nothing read)', $code, 0);
[$code] = $daily([], ['--only=invoices', '--account=999999']);
$expect('…a run that is not refused removes the stamp and the alert memory',
    [$code, $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY), \Firol\Billing\OperatorAlert::wasSent('daily_refused')], [0, null, false]);
$expect('partial runs (--dry-run, --only, --account, refused) never stamp the last run', $settingOf(\Firol\Billing\ConfigCheck::DAILY_RUN_KEY), $lastRunBefore);
// A stale last run with a live key: an error whatever APP_ENV says.
$pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, '2000-01-01 00:00:00')
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([\Firol\Billing\ConfigCheck::DAILY_RUN_KEY]);
try {
    $levels = $withEnv(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], static fn () => array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key'));
} finally {
    if ($lastRunBefore === null) {
        $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([\Firol\Billing\ConfigCheck::DAILY_RUN_KEY]);
    } else {
        $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([$lastRunBefore, \Firol\Billing\ConfigCheck::DAILY_RUN_KEY]);
    }
}
$expect('a live key with APP_ENV=local is an admin ERROR, and so is a stale daily run with a live key',
    [$levels['stripe_key'] ?? null, $levels['daily'] ?? null], ['error', 'error']);
echo "\n";

// ── 5b. the daily run's invoice step (round 3, items 2 and 5) ───────────
echo "5b — the daily run: held rows re-alerted, one attempt per row, live rows issued once allowed, gone events\n";
$suffix7 = bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO users (fullname, email, password_hash) VALUES ('Daily test', ?, '')")->execute(["daily-test-$suffix7@example.com"]);
$uid7 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city, invoice_ico, main_user_id, subscription_end_date)
               VALUES ('Daily test s.r.o.', 'Test 1', '000 00', 'Test', '00000000', ?, '2027-01-01')")->execute([$uid7]);
$acc7 = (int) $pdo->lastInsertId();
$eventId7 = 'evt_test_gone_' . $suffix7;
try {
    $rowOf = static function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    };
    // A: held credit (its alert failed — no SMTP in this process).
    InvoiceIssuer::record($acc7, $ia = $invoice(1900, 900, [$line(1900, 'base')], ['livemode' => true]), true);
    $idA = (int) $pdo->query('SELECT id FROM invoices WHERE stripe_invoice_id = ' . $pdo->quote($ia['id']))->fetchColumn();
    // B: a live payment only prepared; C: a live payment skipped (no iDoklad credentials).
    $idB = (int) InvoiceIssuer::record($acc7, $invoice(1900, 1900, [$line(1900, 'base')], ['livemode' => true]), true);
    $withEnv(['IDOKLAD_ISSUE_MODE' => 'prepare'], static fn () => (new InvoiceIssuer())->issue($idB));
    $idC = (int) InvoiceIssuer::record($acc7, $invoice(2900, 2900, [$line(1900, 'base'), $line(1000, 'extra')], ['livemode' => true]), true);
    $withEnv(['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => null, 'IDOKLAD_CLIENT_SECRET' => null], static fn () => (new InvoiceIssuer())->issue($idC));
    $expect('set-up: A held without an alert, B prepared, C skipped, no attempt used',
        [[$rowOf($idA)['status'], $rowOf($idA)['operator_alerted_at']], [$rowOf($idB)['status'], (int) $rowOf($idB)['attempts']], [$rowOf($idC)['status'], (int) $rowOf($idC)['attempts']]],
        [['needs_review', null], ['prepared', 0], ['skipped', 0]]);

    // Sending switched on, iDoklad (the recorder) failing.
    $sendEnv = ['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => 'x', 'IDOKLAD_CLIENT_SECRET' => 'x'];
    [, $out] = $daily($sendEnv + ['IDOKLAD_RECORDER_FAIL' => '1'], ['--only=invoices', '--account=' . $acc7]);
    $expect('daily run 1 (iDoklad failing): A alerted now, B and C tried once each (not twice), error',
        [$rowOf($idA)['operator_alerted_at'] !== null, [$rowOf($idB)['status'], (int) $rowOf($idB)['attempts']], [$rowOf($idC)['status'], (int) $rowOf($idC)['attempts']]],
        [true, ['error', 1], ['error', 1]]);
    $expect('…the log says what really happened', [str_contains($out, "row=$idA held (credit) — operator alerted"), str_contains($out, "row=$idB LIVE payment prepared → error")], [true, true]);
    [, $out] = $daily($sendEnv, ['--only=invoices', '--account=' . $acc7]);
    $expect('daily run 2 (iDoklad answering): A not alerted again, B and C issued by the retry',
        [str_contains($out, "row=$idA"), [$rowOf($idB)['status'], (int) $rowOf($idB)['attempts']], [$rowOf($idC)['status'], (string) $rowOf($idC)['document_number'] !== '' ? 'numbered' : '']],
        [false, ['issued', 2], ['issued', 'numbered']]);

    // A webhook event Stripe no longer has: gone after the first re-run.
    $pdo->prepare("INSERT INTO stripe_webhook_events (event_id, event_type, status, attempts, error_message) VALUES (?, 'customer.subscription.updated', 'failed', 1, 'test')")
        ->execute([$eventId7]);
    [, $out] = $daily([], ['--only=events']);
    $s = $pdo->prepare('SELECT status, attempts FROM stripe_webhook_events WHERE event_id = ?');
    $s->execute([$eventId7]);
    $ev = $s->fetch();
    $gone = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key')['webhook_events_gone'] ?? [];
    $expect('an event Stripe answers 404 for → gone (terminal), the failure recorded, listed by the admin check',
        [$ev['status'] ?? null, (int) ($ev['attempts'] ?? 0), str_contains((string) ($gone['message'] ?? ''), $eventId7)], ['gone', 2, true]);
    [, $out] = $daily([], ['--only=events']);
    $expect('…not re-run by the next daily run (only the admin check line names it)', str_contains($out, "event $eventId7 ("), false);
} finally {
    $pdo->prepare('DELETE FROM stripe_webhook_events WHERE event_id = ?')->execute([$eventId7]);
    $pdo->prepare('DELETE FROM billing_operator_alerts WHERE alert_key = ?')->execute(["event_gone:$eventId7"]);
    $pdo->prepare('DELETE FROM invoices WHERE account_id = ?')->execute([$acc7]);
    $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$acc7]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid7]);
}
echo "\n";

// ── 6. over HTTP ────────────────────────────────────────────────────────
echo "6 — over HTTP\n";
$base = 'http://nginx';
$cookie = tempnam(sys_get_temp_dir(), 'billing-test-cookie');
$http = static function (string $method, string $path, ?array $body = null, array $headers = []) use ($base, $cookie): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $out = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($out, true)];
};

$suffix = bin2hex(random_bytes(3));
$created = ['users' => [], 'accounts' => []];
try {
    $pdo->prepare("INSERT INTO users (fullname, email, password_hash, vop_version, vop_accepted_at, privacy_version, privacy_accepted_at)
                   VALUES ('Main test', ?, '', ?, NOW(), ?, NOW())")
        ->execute(["billing-main-$suffix@example.com", \Firol\Legal\Terms::VOP_VERSION, \Firol\Legal\Terms::PRIVACY_VERSION]);
    $mainId = (int) $pdo->lastInsertId();
    $created['users'][] = $mainId;
    $pdo->prepare("INSERT INTO users (fullname, email, password_hash, vop_version, vop_accepted_at, privacy_version, privacy_accepted_at)
                   VALUES ('Member test', ?, ?, ?, NOW(), ?, NOW())")
        ->execute(["billing-member-$suffix@example.com", Password::hash("Pw-$suffix-12345"), \Firol\Legal\Terms::VOP_VERSION, \Firol\Legal\Terms::PRIVACY_VERSION]);
    $memberId = (int) $pdo->lastInsertId();
    $created['users'][] = $memberId;
    $pdo->prepare("INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city, invoice_ico, main_user_id, subscription_end_date)
                   VALUES ('Billing 403 test', 'Test 1', '000 00', 'Test', '00000000', ?, ?)")->execute([$mainId, date('Y-m-d', time() + 30 * 86400)]);
    $accId = (int) $pdo->lastInsertId();
    $created['accounts'][] = $accId;
    $pdo->prepare('UPDATE accounts SET stripe_customer_id = ? WHERE id = ?')->execute(['cus_billingtest_' . $suffix, $accId]);
    $pdo->prepare("INSERT INTO account_users (account_id, user_id, role, is_active) VALUES (?, ?, 'main', 1), (?, ?, 'technician', 1)")
        ->execute([$accId, $mainId, $accId, $memberId]);

    [$code, $login] = $http('POST', '/api/auth/login', ['email' => "billing-member-$suffix@example.com", 'password' => "Pw-$suffix-12345"]);
    $csrf = (string) ($login['csrfToken'] ?? '');
    $expect('member logs in', [$code, $csrf !== ''], [200, true]);
    $codes = [];
    foreach (['/api/billing/checkout', '/api/billing/cancel', '/api/billing/resume', '/api/billing/portal', '/api/billing/checkout-sync'] as $path) {
        [$code] = $http('POST', $path, ['billing_period' => 'monthly'], ["X-CSRF-Token: $csrf"]);
        $codes[$path] = $code;
    }
    $expect('member who is not the main user: 403 on every billing action', array_values(array_unique($codes)), [403]);
    [$code] = $http('GET', '/api/billing/invoices');
    $expect('member may still read the invoice list', $code, 200);

    // A failing webhook event is retried, not skipped.
    if (str_starts_with((string) ($_ENV['STRIPE_SECRET_KEY'] ?? ''), 'sk_test_') && (string) ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? '') !== '') {
        $eventId = 'evt_test_fail_' . $suffix;
        $event = [
            'id' => $eventId, 'object' => 'event', 'type' => 'customer.subscription.updated', 'livemode' => false,
            'api_version' => '2026-04-22.dahlia', 'created' => time(),
            'data' => ['object' => ['id' => 'sub_doesnotexist_' . $suffix, 'object' => 'subscription', 'customer' => 'cus_billingtest_' . $suffix]],
        ];
        $body = json_encode($event);
        $send = static function () use ($body, $base): int {
            $ts = time();
            $sig = hash_hmac('sha256', "$ts.$body", (string) $_ENV['STRIPE_WEBHOOK_SECRET']);
            $ch = curl_init($base . '/api/billing/webhook');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Stripe-Signature: t=$ts,v1=$sig"]]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code;
        };
        $c1 = $send();
        $c2 = $send();
        $s = $pdo->prepare('SELECT status, attempts FROM stripe_webhook_events WHERE event_id = ?');
        $s->execute([$eventId]);
        $expect('failing event: 500 twice, handled twice (not skipped as a duplicate)', [$c1, $c2, $s->fetch()], [500, 500, ['status' => 'failed', 'attempts' => 2]]);
        $pdo->prepare('DELETE FROM stripe_webhook_events WHERE event_id = ?')->execute([$eventId]);
    } else {
        echo "skip failing-event check (no sk_test_ key / webhook secret)\n";
    }
} finally {
    foreach ($created['accounts'] as $id) {
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
    }
    foreach ($created['users'] as $id) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    @unlink($cookie);
}

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures check(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
