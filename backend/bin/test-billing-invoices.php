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
 *      bin/billing-daily.php refuses a live key outside production.
 *   6. Over HTTP (nginx → PHP-FPM): a team member who is not the main user
 *      gets 403 from every billing action; a webhook event that fails is
 *      handled again on Stripe's retry instead of being skipped (needs an
 *      sk_test_ key — Stripe answers 404 for the made-up subscription).
 *
 *     docker compose exec php php bin/test-billing-invoices.php
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
$expect('captured renewal with credit lines → needs review (credit)', InvoiceIssuer::classify($inv)['reason'], 'credit');
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
    $expect('send mode + livemode=false: prepared, never sent', [$st, str_contains((string) $row($id2)['error_message'], 'test-mode')], ['prepared', true]);

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
// bin/billing-daily.php with a live key outside production.
$daily = static function (array $env, array $args = []): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/billing-daily.php', '--dry-run', '--only=seats', '--account=999999', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $err];
};
[$code, $err] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local']);
$expect('billing-daily with a live key and APP_ENV=local refuses', [$code, str_contains($err, 'LIVE key')], [2, true]);
[$code] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--allow-live']);
$expect('…--allow-live lets it run (dry run, a missing account, nothing read)', $code, 0);
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
