<?php

declare(strict_types=1);

/**
 * Billing checks that need neither iDoklad nor a Stripe test clock (fix lists
 * of 17.–19. 11. 2026: docs/prompts/billing-fixes-2026-11*.md and
 * billing-decisions-2026-11.md):
 *
 *   1. IssuePolicy — what is sent and what is only prepared; a test-mode
 *      Stripe invoice is never sent. The policy matrix (APP_ENV ×
 *      IDOKLAD_ISSUE_MODE × Stripe key) with what the admin check says.
 *   2. Captured webhook payloads in API version 2026-04-22.dahlia
 *      (bin/fixtures/stripe-dahlia): fields read whatever the version, the
 *      tax in both shapes; the capture of 17. 11. (no VAT) is held as no_vat.
 *   3. Which Stripe invoices become an iDoklad invoice, with VAT (23 %,
 *      Stripe's per-line rounding): net unit prices with the standard rate,
 *      the document equal to the amount paid; credits (decisions part 3) —
 *      a removal as a negative line, a part paid from the balance as
 *      „Kredit z predchádzajúceho obdobia", nothing paid → no_document; the
 *      count printed only when its sign matches the amount, „po zľave" only on
 *      discounted lines; a netted line whose VAT rounds differently priced
 *      with VAT; isOurSubscription, the clamped proration date, the next
 *      charge of a manual account, the VAT helper.
 *   4. InvoiceIssuer end to end with a RECORDING STUB in place of iDoklad: the
 *      payload, Recount before the POST (a difference is held), marking paid
 *      and the e-mail to the customer (decisions part 4: retries to the cap,
 *      uncertain never retried, „Poslať znova", claimed atomically, the
 *      contact's e-mail, no e-mail, test-mode never e-mailed, ErrorCode 110),
 *      a failure and the retry cap, an uncertain POST, a 2xx without an Id,
 *      old credit rows issued now, snapshots that cannot be read (terminal
 *      after 3 tries or a 404), the alert memory per row and state, the
 *      admin check for test and live rows, the grace period of a failed
 *      renewal (decisions part 2: capped paid-until date, one e-mail,
 *      resolved by a payment, a second failure's own 14 days).
 *   5. bin/reprice-yearly-extra-seats.php refuses a bad option;
 *      bin/billing-daily.php with a live key outside production refuses the
 *      Stripe writes and only prepares invoices (item 8), stamps and alerts
 *      the refusal once; ConfigCheck errors mailed once per check and
 *      forgotten when fixed (item 3); the webhook's alert for a stale daily
 *      run; partial runs do not stamp the last run. The touched settings and
 *      alert memory are restored.
 *  5b. The daily run's invoice step as a child process: a held row alerted
 *      once, one attempt per row per run, live rows and old credit rows
 *      issued once allowed, failed e-mails retried, gone events (only this
 *      test's, --event; an invoice.* one an error until dismissed).
 *   6. Over HTTP (nginx → PHP-FPM): a team member who is not the main user
 *      gets 403 from every billing action; a webhook event that fails is
 *      handled again on Stripe's retry instead of being skipped (needs an
 *      sk_test_ key — Stripe answers 404 for the made-up subscription).
 *
 *     docker compose exec php php bin/test-billing-invoices.php   (refuses anything but an sk_test_ key)
 *
 * iDoklad is never called: bin/lib/idoklad-recorder.php is loaded before the
 * autoloader and IS Firol\Idoklad\IDokladClient in this process (checked),
 * and the credentials are removed as well. SMTP is blank in this process and
 * in every child (the recorder blanks it before Dotenv runs, and the child
 * checks it); where a check counts mail, Mailer writes it to a capture file.
 * Parts 1–4 run in one transaction that is rolled back; parts 5b and 6 create
 * throwaway rows and delete them. Local / staging only.
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
// One example operator address: alert counts do not depend on the real
// ADMIN_EMAIL, and no captured mail names a real address.
$_ENV['ADMIN_EMAIL'] = 'ops-test@example.com';
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
    $keyChanged = array_key_exists('STRIPE_SECRET_KEY', $env);
    if ($keyChanged) {
        \Firol\Stripe\StripeClient::swap(null); // built from the key in force
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
        if ($keyChanged) {
            \Firol\Stripe\StripeClient::swap(null); // not the fake key's client afterwards
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

// Test helpers of the decisions round (19. 11. 2026): every message this
// process mails is captured to a file when the capture is on (Mailer::
// $captureFile), so an alert can be counted without SMTP.
$mailFile = tempnam(sys_get_temp_dir(), 'billing-mail');
$mails = static function () use ($mailFile): array {
    $out = [];
    foreach (file($mailFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true);
    }
    return $out;
};
$mailsReset = static function () use ($mailFile): void { file_put_contents($mailFile, ''); };
$captureOn  = static function () use ($mailFile): void { \Firol\Mail\Mailer::$captureFile = $mailFile; };
$captureOff = static function (): void { \Firol\Mail\Mailer::$captureFile = null; };

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
$itemStarts = array_map(static fn ($i) => $i['current_period_start'], $sub['items']['data']);
$expect('period start read from the items', StripeShape::subscriptionPeriodStart($sub), max($itemStarts));
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
// The capture is from before VAT (17. 11. 2026): Stripe added none, so the
// invoice is held as „no VAT" now — the lines are still netted as in round 3.
$expect('captured renewal of 17. 11. (no VAT on it): held as no_vat, the lines still netted into one (+1 technik) line', [
    InvoiceIssuer::classify($inv)['decision'] . '/' . InvoiceIssuer::classify($inv)['reason'],
    $withEnv(['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => $extraProduct], static fn () => array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($inv))[0]),
], ['review/no_vat', ['POapp — extra technik, pomerná časť (+1 technik) (17.11.–17.12.2026)', 1166]]);
// Dahlia carries the tax per line in `taxes` and on the invoice in
// `total_taxes` (`tax` is gone); the pinned version in `tax_amounts` / `tax`.
$expect('tax read in both shapes', [
    StripeShape::lineTax(['taxes' => [['amount' => 437], ['amount' => 1]]]), StripeShape::lineTax(['tax_amounts' => [['amount' => -246]]]),
    StripeShape::invoiceTax(['total_taxes' => [['amount' => 1164]]]), StripeShape::invoiceTax(['tax' => 667, 'total_taxes' => []]),
], [438, -246, 1164, 667]);
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
echo "3 — which Stripe invoices are invoiced, and the payload (VAT 23 %, credits)\n";
$period = ['start' => strtotime('2026-12-17'), 'end' => strtotime('2027-01-17')];
// A Stripe line as the pinned API renders it: net amount, its discount, and
// Stripe's VAT of the line (23 % of amount − discount, rounded per line —
// what the test clock of 19. 11. 2026 showed). $tax overrides the VAT.
$line = static fn (int $amount, string $desc, bool $proration = false, int $qty = 1, ?array $per = null, int $discount = 0, string $item = 'si_x', ?int $tax = null) => [
    'amount' => $amount, 'description' => $desc, 'quantity' => $qty, 'proration' => $proration,
    'type' => $proration ? 'invoiceitem' : 'subscription', 'period' => $per ?? $period,
    'subscription' => 'sub_test', 'subscription_item' => $item,
    'price' => ['id' => 'price_x', 'product' => $desc === 'extra' ? 'prod_extra_test' : 'prod_base_test'],
    'discount_amounts' => $discount > 0 ? [['amount' => $discount, 'discount' => 'di_test']] : [],
    'tax_amounts' => [['amount' => $tax ?? (int) round(($amount - $discount) * 0.23), 'inclusive' => false, 'tax_rate' => 'txr_test', 'taxable_amount' => $amount - $discount]],
];
// An invoice; total null = lines + VAT − an unplaced discount (Stripe's total),
// paid null = the total.
$invoice = static function (?int $total, ?int $paid, array $lines, array $more = []): array {
    $tax = 0;
    $sum = 0;
    foreach ($lines as $l) {
        $t = StripeShape::lineTax($l);
        $tax += $t;
        $d = 0;
        foreach ($l['discount_amounts'] ?? [] as $da) {
            $d += $da['amount'];
        }
        $sum += $l['amount'] - $d + $t;
    }
    $total ??= $sum;
    return array_merge([
        'id' => 'in_test_' . bin2hex(random_bytes(4)), 'object' => 'invoice', 'currency' => 'eur', 'livemode' => false,
        'total' => $total, 'amount_paid' => $paid ?? $total, 'tax' => $tax, 'lines' => ['data' => $lines, 'has_more' => false],
        'status_transitions' => ['paid_at' => strtotime('2026-12-17 10:00')],
    ], $more);
};
$c = static fn (array $i) => InvoiceIssuer::classify($i)['decision'] . (InvoiceIssuer::classify($i)['reason'] !== '' ? '/' . InvoiceIssuer::classify($i)['reason'] : '');
$xEnv = ['STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test'];
$items = static fn (array $i) => array_map(static fn ($x) => [$x['Name'], $x['Amount'], $x['UnitPrice'], $x['PriceType'], $x['VatRateType']], InvoiceIssuer::payloadItems($i));
$docTotal = static fn (array $i) => InvoiceIssuer::documentTotalCents(InvoiceIssuer::payloadItems($i));

$expect('0 € trial-start invoice → no invoice, no row', $c($invoice(0, 0, [$line(0, 'base', false, 1, null, 0, 'si_x', 0)])), 'skip/zero');
$plan = $invoice(null, null, [$line(1900, 'base')]);
$expect('19 € + 23 % VAT (23,37 €) paid → issue', [$plan['total'], $c($plan)], [2337, 'issue']);
$expect('…payload: net unit price 19,00, „price without VAT" (PriceType 1), the standard rate (VatRateType 1 — 2 is the zero rate)',
    $items($plan), [['POapp — predplatné (17.12.2026 – 17.01.2027)', 1, 19.0, 1, 1]]);
$expect('…the document (base + VAT) is the amount paid', $docTotal($plan), 2337);
$expect('Stripe charged no VAT (a subscription without the tax rate) → held, no_vat', $c($invoice(null, null, [$line(1900, 'base', false, 1, null, 0, 'si_x', 0)])), 'review/no_vat');
$expect('a line whose VAT is not 23 % (20 % rate) → held, vat_mismatch', $c($invoice(null, null, [$line(1900, 'base', false, 1, null, 0, 'si_x', 380)])), 'review/vat_mismatch');
$expect('lines + VAT ≠ total (a discount nobody carries is not the case) → held, mismatch', $c($invoice(2000, 2000, [$line(1900, 'base')])), 'review/mismatch');
$expect('paid more than the total → held, mismatch', $c($invoice(null, 9999, [$line(1900, 'base')])), 'review/mismatch');

// Part 3 of the decisions: credits.
$expect('total below 0 (a removal credit larger than the invoice), nothing paid → no document, credit carried',
    $c($invoice(null, 0, [$line(-1000, 'extra', true, 2), $line(500, 'extra', true, 1)], ['ending_balance' => -615])), 'no_document/credit_carried');
$expect('…the reason names the credit left on the customer', str_contains(InvoiceIssuer::classify($invoice(null, 0, [$line(-1000, 'extra', true, 2)], ['ending_balance' => -1230]))['detail'], 'kredit zákazníka v Stripe 12,30 €'), true);
$expect('a positive total covered entirely by the customer\'s credit (paid 0) → no document, credit covered',
    $c($invoice(null, 0, [$line(1900, 'base')], ['starting_balance' => -3000, 'ending_balance' => -663])), 'no_document/credit_covered');
$partly = $invoice(null, 1337, [$line(1900, 'base')], ['starting_balance' => -1000, 'ending_balance' => 0]);
$expect('paid partly from the customer\'s credit (23,37 € total, 13,37 € paid) → issue', $c($partly), 'issue');
$expect('…with one negative item „Kredit z predchádzajúceho obdobia" for the difference (priced with VAT, standard rate)',
    $items($partly), [['POapp — predplatné (17.12.2026 – 17.01.2027)', 1, 19.0, 1, 1], ['Kredit z predchádzajúceho obdobia', 1, -10.0, 0, 1]]);
$expect('…the document equals the amount paid', $docTotal($partly), 1337);

// Proration pairs of one change are one charge (round 2), netted over the
// whole invoice per subscription and kind (round 3).
$change = ['start' => strtotime('2026-12-01'), 'end' => strtotime('2026-12-17')];
$qtyUp = $invoice(null, null, [
    $line(-534, 'extra', true, 1, $change), $line(1068, 'extra', true, 2, $change),
    $line(1900, 'base'), $line(2000, 'extra', false, 2),
]);
$expect('quantity 1→2 (Unused 1× / Remaining 2×) → issue', $withEnv($xEnv, static fn () => $c($qtyUp)), 'issue');
$expect('…netted into one line „(+1 technik)"', $withEnv($xEnv, static fn () => array_map(static fn ($l) => [$l['name'], $l['amount'], $l['tax']], InvoiceIssuer::invoiceLines($qtyUp))), [
    ['POapp — extra technik, pomerná časť (+1 technik) (01.12.–17.12.2026)', 534, 123],
    ['POapp — predplatné (17.12.2026 – 17.01.2027)', 1900, 437],
    ['POapp — extra technik (17.12.2026 – 17.01.2027)', 2000, 460],
]);
$expect('…payload: 2 extra technicians as 2 × 10 € net', $withEnv($xEnv, static fn () => array_map(static fn ($i) => [$i[1], $i[2], $i[3]], $items($qtyUp))), [[1, 5.34, 1], [1, 19.0, 1], [2, 10.0, 1]]);
$expect('…document = amount paid', $withEnv($xEnv, static fn () => [$docTotal($qtyUp), $qtyUp['total']]), [$qtyUp['total'], $qtyUp['total']]);
$merge = $invoice(null, null, [
    $line(-534, 'extra', true, 1, $change, 0, 'si_dup'), $line(-534, 'extra', true, 1, $change, 0, 'si_keep'), $line(1068, 'extra', true, 2, $change, 0, 'si_keep'),
    $line(1900, 'base'), $line(2000, 'extra', false, 2, null, 0, 'si_keep'),
]);
$expect('duplicate items merged (net 0) → no proration line, issue', $withEnv($xEnv, static fn () => [$c($merge), count(InvoiceIssuer::invoiceLines($merge))]), ['issue', 2]);

// Part 3: a removal is a negative line on the same invoice.
$removal = $invoice(null, null, [
    $line(-1068, 'extra', true, 2, $change), $line(534, 'extra', true, 1, $change),
    $line(1900, 'base'), $line(1000, 'extra'),
]);
$expect('a removal (2→1 nets to −5,34 €) → issued with the negative line', $withEnv($xEnv, static fn () => $c($removal)), 'issue');
$expect('…„POapp — extra technik, pomerná časť (−1 technik) (period)", negative amount, same VAT rate',
    $withEnv($xEnv, static fn () => $items($removal)[0]), ['POapp — extra technik, pomerná časť (−1 technik) (01.12.–17.12.2026)', 1, -5.34, 1, 1]);
$expect('…the document equals the amount paid', $withEnv($xEnv, static fn () => [$docTotal($removal), $removal['amount_paid']]), [$removal['amount_paid'], $removal['amount_paid']]);
$expect('3 technicians added at once → „(+3 technici)"', $withEnv($xEnv, static fn () => InvoiceIssuer::invoiceLines($invoice(null, null, [$line(-534, 'extra', true, 1, $change), $line(2136, 'extra', true, 4, $change)]))[0]['name']),
    'POapp — extra technik, pomerná časť (+3 technici) (01.12.–17.12.2026)');

$at = static fn (string $start) => ['start' => strtotime($start), 'end' => strtotime('2026-12-17')];
$mergeApart = $invoice(null, null, [
    $line(-700, 'extra', true, 1, $at('2026-12-01 10:00:00'), 0, 'si_dup'),
    $line(-700, 'extra', true, 1, $at('2026-12-01 10:00:03'), 0, 'si_keep'),
    $line(1400, 'extra', true, 2, $at('2026-12-01 10:00:03'), 0, 'si_keep'),
    $line(1900, 'base'), $line(2000, 'extra', false, 2, null, 0, 'si_keep'),
]);
$expect('merge with the two calls 3 s apart → issue, no proration line',
    $withEnv($xEnv, static fn () => [$c($mergeApart), count(InvoiceIssuer::invoiceLines($mergeApart))]), ['issue', 2]);
$mergeLate = $invoice(null, null, [
    $line(867, 'extra', true, 1, $at('2026-12-01 10:00'), 0, 'si_dup'),
    $line(-865, 'extra', true, 1, $at('2026-12-01 11:00'), 0, 'si_dup'),
    $line(1900, 'base'), $line(1000, 'extra'),
]);
$expect('merge an hour after the duplicate appeared (+8,67 / −8,65) → issue, +0,02 € worded without a count (the counts net to 0)',
    $withEnv($xEnv, static fn () => [$c($mergeLate), array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($mergeLate))[0]]),
    ['issue', ['POapp — extra technik, pomerná časť (01.12.–17.12.2026)', 2]]);
$addRemovePlus = $invoice(null, null, [
    $line(1000, 'extra', true, 1, $at('2026-11-17')), $line(-500, 'extra', true, 1, $at('2026-12-02')), $line(1900, 'base'),
]);
$expect('add then remove in one period, positive net (+5,00 €) → issue, one line without a count',
    $withEnv($xEnv, static fn () => [$c($addRemovePlus), array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($addRemovePlus))[0]]),
    ['issue', ['POapp — extra technik, pomerná časť (17.11.–17.12.2026)', 500]]);
$addRemoveMinus = $invoice(null, null, [
    $line(333, 'extra', true, 1, $at('2026-12-07')),
    $line(-1000, 'extra', true, 2, $at('2026-12-02')), $line(500, 'extra', true, 1, $at('2026-12-02')),
    $line(1900, 'base'), $line(1000, 'extra'),
]);
$expect('add and remove in one period, negative net (−1,67 €) → issued with a negative line (no count: the counts net to 0)',
    $withEnv($xEnv, static fn () => [$c($addRemoveMinus), $items($addRemoveMinus)[0][0], $items($addRemoveMinus)[0][2]]),
    ['issue', 'POapp — extra technik, pomerná časť (02.12.–17.12.2026)', -1.67]);
// Item 6: a count that contradicts the amount is not printed.
$dearer = $invoice(null, null, [
    $line(-500, 'extra', true, 2, $at('2026-12-02')), $line(2177, 'extra', true, 1, $at('2026-12-02')), $line(1900, 'base'),
]);
$expect('a netted line „−1 technik" on +16,77 € is printed without the count',
    $withEnv($xEnv, static fn () => array_map(static fn ($l) => [$l['name'], $l['amount'], $l['delta']], InvoiceIssuer::invoiceLines($dearer))[0]),
    ['POapp — extra technik, pomerná časť (02.12.–17.12.2026)', 1677, -1]);

// VAT rounding: Stripe rounds each of its lines; a netted line may then differ
// from 23 % of its net by a cent. That line goes with its gross price, so the
// document still equals the amount paid.
$cent = $invoice(null, null, [$line(7, 'extra', true, 1, $at('2026-12-02')), $line(7, 'extra', true, 1, $at('2026-12-03')), $line(1900, 'base')]);
$expect('two proration lines of 0,07 € (VAT 0,02 each, 0,03 on 0,14 €) → issue; that line priced with VAT (0,18 €), the document = paid',
    $withEnv($xEnv, static fn () => [$c($cent), $items($cent)[0][2], $items($cent)[0][3], $docTotal($cent), $cent['amount_paid']]),
    ['issue', 0.18, 0, $cent['amount_paid'], $cent['amount_paid']]);

// The plan is never an extra line (round 3).
$guard = $line(1900, 'x');
$guard['description'] = '1 × POapp — extra technik (at €19.00 / month)';
$guard['price'] = ['id' => 'price_base_guard', 'product' => 'prod_whatever'];
$expect('a base-price line with a legacy name in its description is the plan',
    $withEnv(['STRIPE_PRICE_MONTHLY' => 'price_base_guard'], static fn () => InvoiceIssuer::invoiceLines($invoice(null, null, [$guard]))[0]['kind']), 'base');

// Texts of discounted lines; item 6: „po zľave" only on a discounted line.
$three = $invoice(null, null, [$line(1900, 'base'), $line(3000, 'extra', false, 3, null, 250)], ['total_discount_amounts' => [['amount' => 250, 'discount' => 'di_test']]]);
$expect('a discounted line that does not divide by the quantity → 1 ks with the count in the name',
    $withEnv($xEnv, static fn () => array_slice($items($three)[1], 0, 3)),
    ['POapp — 3 × extra technik, po zľave (17.12.2026 – 17.01.2027)', 1, 27.5]);
$proOff = $line(427, 'extra', true, 1, $change);
$proOff['description'] = 'Remaining time on Extra technician (test) (with 20.0% off) after 30 Nov 2026';
$proFlag = $line(534, 'extra', true, 1, $change, 0, 'si_y');
$proFlag['subscription'] = 'sub_other';
$proFlag['discountable'] = true;
$disc = $invoice(null, null, [$proOff, $proFlag, $line(1900, 'base', false, 1, null, 380), $line(1000, 'extra', false, 1, null, 200)],
    ['total_discount_amounts' => [['amount' => 580, 'discount' => 'di_test']]]);
$expect('„po zľave": a proration Stripe priced „with 20.0% off" yes, a line that is only discountable no, the plan with a discount amount yes',
    $withEnv($xEnv, static fn () => array_column(InvoiceIssuer::invoiceLines($disc), 'name')),
    ['POapp — extra technik, pomerná časť (+1 technik), po zľave (01.12.–17.12.2026)', 'POapp — extra technik, pomerná časť (+1 technik) (01.12.–17.12.2026)',
     'POapp — predplatné, po zľave (17.12.2026 – 17.01.2027)', 'POapp — extra technik, po zľave (17.12.2026 – 17.01.2027)']);
$pct = $invoice(null, null, [$line(1900, 'base', false, 1, null, 380), $line(1000, 'extra', false, 1, null, 200)],
    ['total_discount_amounts' => [['amount' => 580, 'discount' => 'di_test']]]);
$expect('20 % coupon (VAT on the discounted amount, as Stripe): 23,20 € net + 5,34 € VAT = 28,54 € → issue, document = paid',
    $withEnv($xEnv, static fn () => [$pct['total'], $c($pct), $docTotal($pct)]), [2854, 'issue', 2854]);
$fixed = $invoice(null, null, [$line(1900, 'base'), $line(1000, 'extra')], ['total_discount_amounts' => [['amount' => 500, 'discount' => 'di_test']]]);
$fixed['total'] -= 500;
$fixed['amount_paid'] = $fixed['total'];
$expect('a discount carried by no line → one „Zľava" item priced with VAT (Stripe put no VAT on it), the document = paid → issue',
    $withEnv($xEnv, static fn () => [$c($fixed), array_map(static fn ($l) => [$l['name'], $l['amount']], InvoiceIssuer::invoiceLines($fixed))[2] ?? null, $items($fixed)[2][3], $docTotal($fixed) === $fixed['amount_paid']]),
    ['issue', ['Zľava', -500], 0, true]);

$payload = $withEnv($xEnv, static fn () => InvoiceIssuer::buildPayload(1, $invoice(null, null, [
    $line(667, 'extra', true, 1, ['start' => strtotime('2026-12-07'), 'end' => strtotime('2026-12-17')]),
    $line(1900, 'base'),
    $line(1000, 'extra'),
])));
$expect('payload: one item per line, net unit prices', array_map(static fn ($i) => [$i['Name'], $i['Amount'], $i['UnitPrice']], $payload['Items']), [
    ['POapp — extra technik, pomerná časť (+1 technik) (07.12.–17.12.2026)', 1, 6.67],
    ['POapp — predplatné (17.12.2026 – 17.01.2027)', 1, 19.0],
    ['POapp — extra technik (17.12.2026 – 17.01.2027)', 1, 10.0],
]);
$expect('payload: header period from the subscription line', $payload['Description'], 'POapp — predplatné (17.12.2026 – 17.01.2027)');
$expect('payload: taxing date = payment date', $payload['DateOfTaxing'], '2026-12-17');
foreach (SeatSync::LEGACY_PRODUCT_NAMES as $legacyName) {
    $legacy = $line(1000, 'x');
    $legacy['description'] = '1 × ' . $legacyName . ' (at €10.00 / month)';
    $expect("legacy extra line „{$legacyName}\" named as extra technician", InvoiceIssuer::buildPayload(1, $invoice(null, null, [$legacy]))['Items'][0]['Name'], 'POapp — extra technik (17.12.2026 – 17.01.2027)');
}

// classifyItems after a base price change (round 3).
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
$expect('…without a remembered plan item: no base, both unknown', $cls([$other, $oldPlan]), [null, [], ['si_other', 'si_plan']]);
$expect('one unrecognised item and nothing else: it is the plan (as before)', $cls([$oldPlan]), ['si_plan', [], []]);

// Part 0, item 1: a subscription of another product is never the account's.
$ours = static fn (array $items, string $remembered = '') => $withEnv(
    ['STRIPE_PRICE_MONTHLY' => 'price_plan_new', 'STRIPE_PRICE_YEARLY' => 'price_plan_new_y', 'STRIPE_PRODUCT_EXTRA_TECHNICIAN' => 'prod_extra_test'],
    static fn () => SeatSync::isOurSubscription(['items' => ['data' => $items]], $remembered));
$expect('isOurSubscription: a configured base price yes; one item of another product no (no lone-item fallback); the remembered plan item yes; an extra item alone no',
    [$ours([$item('si_a', 'price_plan_new', 'prod_x')]), $ours([$other]), $ours([$oldPlan], 'si_plan'), $ours([$item('si_e', 'price_e', 'prod_extra_test')])],
    [true, false, true, false]);

// Part 0, item 5: proration_date clamped into the subscription's period.
$subP = ['current_period_start' => 1000, 'current_period_end' => 2000];
$expect('proration date: now inside the period stays; before the start → start (a clock behind Stripe); at / after the end → end − 1 (right after a renewal)',
    [SeatSync::prorationDate($subP, 1500), SeatSync::prorationDate($subP, 990), SeatSync::prorationDate($subP, 2000), SeatSync::prorationDate($subP, 2500)],
    [1500, 1000, 1999, 1999]);

// The reconcile switch (round 3).
$sw = static fn (?string $v) => $withEnv(['BILLING_RECONCILE' => $v], static fn () => [SeatSync::reconcileSwitch(), SeatSync::pushEnabled()]);
$expect('BILLING_RECONCILE unset / on / off / OFF / disabled / false',
    [$sw(null), $sw('on'), $sw('off'), $sw('OFF'), $sw('disabled'), $sw('false')],
    [['on', true], ['on', true], ['off', false], ['off', false], ['invalid', false], ['invalid', false]]);
$expect('…an invalid value is an admin error', $withEnv(['BILLING_RECONCILE' => 'disabled'], static fn () => array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key')['billing_reconcile'] ?? null), 'error');

// „Ďalšia fakturácia" (net; the screens add VAT).
$acct = ['stripe_subscription_id' => 'sub_x', 'stripe_base_unit_amount_cents' => 1900, 'extra_technicians' => 2,
         'stripe_extra_quantity' => 1, 'stripe_extra_subscription_item_id' => 'si_x', 'stripe_extra_unit_amount_cents' => 1000, 'seat_sync_state' => 'ok'];
$expect('next charge: switch on → wanted count (19 + 2 × 10)', $withEnv(['BILLING_RECONCILE' => null], static fn () => SeatSync::nextChargeCents($acct)), 3900);
$expect('…switch off → Stripe\'s count (19 + 1 × 10)', $withEnv(['BILLING_RECONCILE' => 'off'], static fn () => SeatSync::nextChargeCents($acct)), 2900);
$expect('…item 9: an account left for a person or failing shows no figure', [SeatSync::nextChargeCents(['seat_sync_state' => 'manual'] + $acct), SeatSync::nextChargeCents(['seat_sync_state' => 'error'] + $acct)], [null, null]);
$expect('…no plan item recognised → no next charge', SeatSync::nextChargeCents(['stripe_base_unit_amount_cents' => null] + $acct), null);
$expect('VAT helper: 23 % of 27,00 € = 6,21 €, of −10,71 € = −2,46 €; VAT_RATE_PERCENT read from env, 23 when unset',
    [\Firol\Billing\Vat::of(2700), \Firol\Billing\Vat::of(-1071), $withEnv(['VAT_RATE_PERCENT' => '20'], static fn () => \Firol\Billing\Vat::of(2700)), $withEnv(['VAT_RATE_PERCENT' => null], static fn () => \Firol\Billing\Vat::percent())],
    [621, -246, 540, 23.0]);
echo "\n";

// ── 4. InvoiceIssuer with a recording stub ──────────────────────────────
echo "4 — InvoiceIssuer end to end, iDoklad replaced by a recorder\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $mainEmail = 'invoice-test-' . bin2hex(random_bytes(3)) . '@example.com';
    $pdo->prepare("INSERT INTO users (fullname, email, password_hash) VALUES ('Invoice test', ?, '')")->execute([$mainEmail]);
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
    $i1 = $invoice(null, null, [$line(1900, 'base'), $line(1000, 'extra')]);
    $id1 = InvoiceIssuer::record($acc, $i1, false);
    $expect('record: paid invoice → pending row, Stripe\'s VAT stored', $id1 !== null ? [$row($id1)['status'], (int) $row($id1)['stripe_tax_cents']] : null, ['pending', 667]);
    $expect('record: the same invoice again → nothing new', InvoiceIssuer::record($acc, $i1, false), null);
    $st = (new InvoiceIssuer())->issue($id1);
    $r = $row($id1);
    $expect('prepare mode: status prepared, payload stored, nothing sent', [$st, $r['idoklad_invoice_id'], json_decode((string) $r['idoklad_payload_json'], true)['Note'] ?? null], ['prepared', null, 'Stripe invoice ' . $i1['id']]);

    $i2 = $invoice(null, null, [$line(1900, 'base')]);
    $id2 = InvoiceIssuer::record($acc, $i2, false);
    $st = $withEnv(['IDOKLAD_ISSUE_MODE' => 'send'], static fn () => (new InvoiceIssuer())->issue($id2));
    $expect('send mode + livemode=false: prepared, never sent', [$st, str_contains((string) $row($id2)['error_message'], 'testovacia platba')], ['prepared', true]);
    $expect('preparing a row is not an attempt', [(int) $row($id1)['attempts'], (int) $row($id2)['attempts']], [0, 0]);

    // The send path, recorded: Recount, POST, mark paid, e-mail.
    $rec = new IDokladClient();
    $i3 = $invoice(null, null, [
        $line(667, 'extra', true, 1, ['start' => strtotime('2026-12-07'), 'end' => strtotime('2026-12-17')]),
        $line(1900, 'base'),
        $line(1000, 'extra'),
    ], ['livemode' => true]);
    $id3 = InvoiceIssuer::record($acc, $i3, true);
    $st = $withEnv($xEnv, static fn () => (new InvoiceIssuer($rec))->issue($id3));
    $sent = $rec->lastInvoicePost();
    $expect('recorder: issued, number stored', [$st, $row($id3)['document_number']], ['issued', 'FV-TEST-1']);
    $expect('recorder: calls — contact (with the main user\'s e-mail), template, Recount, POST, FullyPay with the Stripe payment date, the e-mail to the partner',
        array_map(static fn ($c) => $c[0] . ' ' . $c[1], $rec->calls),
        ['POST Contacts', 'GET IssuedInvoices/Default', 'POST IssuedInvoices/Recount', 'POST IssuedInvoices', 'PUT IssuedDocumentPayments/FullyPay/9001?dateOfPayment=2026-12-17', 'POST Mails/IssuedInvoice/Send']);
    $expect('…the contact carries the main user\'s e-mail', $rec->calls[0][2]['Email'] ?? null, $mainEmail);
    $expect('…the mail request: DocumentId, SendToPartner only, PDF, Slovak', $rec->calls[5][2],
        ['DocumentId' => 9001, 'SendToPartner' => true, 'SendToSelf' => false, 'SendToAccountant' => false, 'Method' => 0, 'ReportLanguage' => 2]);
    $expect('…the row: marked paid and sent, to the main user', [$row($id3)['paid_mark_state'], $row($id3)['send_state'], $row($id3)['sent_to']], ['done', 'sent', $mainEmail]);
    $expect('recorded payload: partner, template numbering kept, read-only keys dropped',
        [$sent['PartnerId'] ?? null, $sent['DocumentSerialNumber'] ?? null, array_key_exists('Id', $sent ?? []), array_key_exists('PaymentStatus', $sent ?? [])],
        [555, 42, false, false]);
    $expect('recorded payload: our VAT fields win over the template\'s (template VatRateType 2 = zero rate)', array_map(static fn ($i) => [$i['VatRateType'], $i['PriceType'], array_key_exists('Id', $i)], $sent['Items']), [[1, 1, false], [1, 1, false], [1, 1, false]]);
    $expect('recorded payload: base + VAT = Stripe\'s amount paid (35,67 € net + 8,20 € VAT = 43,87 €)', [InvoiceIssuer::documentTotalCents($sent['Items']), $i3['amount_paid']], [4387, 4387]);
    echo "     · recorded iDoklad payload (35,67 € + VAT):\n";
    foreach (explode("\n", (string) json_encode(['Items' => $sent['Items'], 'DateOfTaxing' => $sent['DateOfTaxing'], 'Description' => $sent['Description']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) as $l) {
        echo "       $l\n";
    }

    // Part 3, recorded: a removal and a part paid from the credit.
    $recR = new IDokladClient();
    $idR = InvoiceIssuer::record($acc, $removalLive = $invoice(null, null, [
        $line(-1068, 'extra', true, 2, $change), $line(534, 'extra', true, 1, $change), $line(1900, 'base'), $line(1000, 'extra'),
    ], ['livemode' => true]), true);
    $withEnv($xEnv, static fn () => (new InvoiceIssuer($recR))->issue($idR));
    $pR = $recR->lastInvoicePost();
    $expect('recorded removal: issued with the negative line, the document = amount paid', [$row($idR)['status'], $pR['Items'][0]['Name'], $pR['Items'][0]['UnitPrice'], InvoiceIssuer::documentTotalCents($pR['Items']), (int) $removalLive['amount_paid']],
        ['issued', 'POapp — extra technik, pomerná časť (−1 technik) (01.12.–17.12.2026)', -5.34, (int) $removalLive['amount_paid'], (int) $removalLive['amount_paid']]);
    $recC = new IDokladClient();
    $idC2 = InvoiceIssuer::record($acc, $invoice(null, 1337, [$line(1900, 'base')], ['livemode' => true, 'starting_balance' => -1000, 'ending_balance' => 0]), true);
    (new InvoiceIssuer($recC))->issue($idC2);
    $pC = $recC->lastInvoicePost();
    $expect('recorded part paid from credit: „Kredit z predchádzajúceho obdobia" −10,00 € (with VAT), the document = 13,37 € paid',
        [$row($idC2)['status'], end($pC['Items'])['Name'], end($pC['Items'])['UnitPrice'], end($pC['Items'])['PriceType'], InvoiceIssuer::documentTotalCents($pC['Items'])],
        ['issued', 'Kredit z predchádzajúceho obdobia', -10.0, 0, 1337]);
    $idNo = InvoiceIssuer::record($acc, $invoice(null, 0, [$line(-1000, 'extra', true, 2)], ['livemode' => true, 'ending_balance' => -1230]), true);
    $s = $pdo->prepare("SELECT status, review_reason, operator_alerted_at FROM invoices WHERE account_id = ? AND status = 'no_document'");
    $s->execute([$acc]);
    $expect('total below 0: a visible no_document row (credit carried), no alert, nothing to issue', [$idNo, $s->fetch()], [null, ['status' => 'no_document', 'review_reason' => 'credit_carried', 'operator_alerted_at' => null]]);

    // A row held as a credit before 19. 11. 2026 is issued now.
    $recOld = new IDokladClient();
    $idOld = InvoiceIssuer::record($acc, $invoice(null, null, [$line(-1068, 'extra', true, 2, $change), $line(534, 'extra', true, 1, $change), $line(1900, 'base')], ['livemode' => true]), true);
    $pdo->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'credit' WHERE id = ?")->execute([$idOld]);
    $expect('a row held as a credit (old rule): „Vystaviť znova" offered, the daily claim from needs_review issues it with the negative line',
        [InvoiceIssuer::manualIssueAllowed($row($idOld)), $withEnv($xEnv, static fn () => (new InvoiceIssuer($recOld))->issue($idOld, true, ['needs_review'])), $recOld->lastInvoicePost()['Items'][0]['UnitPrice'] ?? null],
        [true, 'issued', -5.34]);

    // iDoklad's Recount differs from the amount paid: never posted.
    $recV = new IDokladClient();
    $recV->recountAnswer = ['Prices' => ['TotalWithVat' => 23.38]];
    $idV = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $expect('Recount 23,38 € for 23,37 € paid → needs_review / vat_mismatch, no POST, „Vystaviť znova" offered (checked again)',
        [(new InvoiceIssuer($recV))->issue($idV), $row($idV)['review_reason'], $recV->invoicePosts(), InvoiceIssuer::manualIssueAllowed($row($idV))], ['needs_review', 'vat_mismatch', 0, true]);
    $recV->recountAnswer = ['Data' => []];
    $expect('…a Recount without a total is held the same way', [(new InvoiceIssuer($recV))->issue($idV, true, ['needs_review']), $recV->invoicePosts()], ['needs_review', 0]);

    // Part 4: the follow-up steps fail on their own; never issue again.
    $recS = new IDokladClient();
    $recS->failSend = new RuntimeException('iDoklad POST Mails/IssuedInvoice/Send failed: HTTP 400');
    $idS = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuerS = new InvoiceIssuer($recS);
    $expect('the e-mail fails (HTTP 400): the invoice stays issued and marked paid, send error, 1 attempt', [$issuerS->issue($idS), $row($idS)['paid_mark_state'], $row($idS)['send_state'], (int) $row($idS)['send_attempts']], ['issued', 'done', 'error', 1]);
    $issuerS->followUps($idS);
    $issuerS->followUps($idS);
    $issuerS->followUps($idS);
    $expect('…retried automatically up to the cap (3 attempts), then left; never issued again (1 invoice POST)',
        [(int) $row($idS)['send_attempts'], $row($idS)['send_state'], $recS->invoicePosts(), $recS->count('POST', 'Mails/')], [3, 'error', 1, 3]);
    $expect('…„Poslať znova" is offered', InvoiceIssuer::manualFollowUpAllowed($row($idS)), true);
    $recS->failSend = null;
    $expect('…„Poslať znova" (manual, past the cap) sends it', [$issuerS->followUps($idS, true)['send'], $recS->invoicePosts()], ['sent', 1]);

    $recU = new IDokladClient();
    $recU->failSend = new IDokladTransportException('iDoklad transport error: Operation timed out');
    $idU = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuerU = new InvoiceIssuer($recU);
    $issuerU->issue($idU);
    $expect('the e-mail times out after the request left → uncertain', $row($idU)['send_state'], 'uncertain');
    $issuerU->followUps($idU);
    $expect('…never retried automatically (still 1 mail request)', [$recU->count('POST', 'Mails/'), $row($idU)['send_state']], [1, 'uncertain']);
    $recU->failSend = null;
    $expect('…„Poslať znova" after a person checked iDoklad sends it', [$issuerU->followUps($idU, true)['send'], $recU->count('POST', 'Mails/')], ['sent', 2]);

    $recP = new IDokladClient();
    $recP->failMarkPaid = new RuntimeException('iDoklad PUT failed: HTTP 400 — {"ErrorCode":110,"Message":"Document has already been paid"}', 110);
    $idP = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    (new InvoiceIssuer($recP))->issue($idP);
    $expect('marking paid answered „already paid" (ErrorCode 110) counts as done', $row($idP)['paid_mark_state'], 'done');
    $recP2 = new IDokladClient();
    $recP2->failMarkPaid = new RuntimeException('iDoklad PUT failed: HTTP 400 — validation');
    $idP2 = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    (new InvoiceIssuer($recP2))->issue($idP2);
    $expect('marking paid fails: error, the invoice still issued and still e-mailed', [$row($idP2)['status'], $row($idP2)['paid_mark_state'], $row($idP2)['send_state']], ['issued', 'error', 'sent']);

    // Claimed atomically: a send already in flight is not started again.
    $recA = new IDokladClient();
    $idA = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    (new InvoiceIssuer($recA))->issue($idA);
    $pdo->prepare("UPDATE invoices SET send_state = 'sending' WHERE id = ?")->execute([$idA]);
    $before = $recA->count('POST', 'Mails/');
    (new InvoiceIssuer($recA))->followUps($idA, true);
    $expect('a send in flight (`sending`) is never claimed again', $recA->count('POST', 'Mails/'), $before);

    // The contact's e-mail follows the main user's.
    $newEmail = 'invoice-new-' . bin2hex(random_bytes(3)) . '@example.com';
    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $uid]);
    $recE = new IDokladClient();
    $idE = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    (new InvoiceIssuer($recE))->issue($idE);
    $expect('the main user\'s e-mail changed: PATCH Contacts with it before the send', [$recE->calls[4][0] ?? null, $recE->calls[4][1] ?? null, $recE->calls[4][2]['Email'] ?? null, $row($idE)['sent_to']], ['PATCH', 'Contacts', $newEmail, $newEmail]);
    $pdo->prepare("UPDATE users SET email = '' WHERE id = ?")->execute([$uid]);
    $recN = new IDokladClient();
    $idN = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    (new InvoiceIssuer($recN))->issue($idN);
    $expect('no e-mail on the main user: send error, not retried automatically, no mail request', [$row($idN)['send_state'], (int) $row($idN)['send_attempts'], $recN->count('POST', 'Mails/')], ['error', 3, 0]);
    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$mainEmail, $uid]);

    // A test-mode payment is never e-mailed (also when test events are issued).
    IDokladClient::$shared = new IDokladClient();
    $idT = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')]), false);
    $st = $withEnv(['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_ALLOW_TEST_EVENTS' => 'true', 'IDOKLAD_CLIENT_ID' => 'x', 'IDOKLAD_CLIENT_SECRET' => 'x'], static fn () => (new InvoiceIssuer())->issue($idT));
    $expect('a Stripe test-mode payment issued with IDOKLAD_ALLOW_TEST_EVENTS: marked paid, never e-mailed', [$st, $row($idT)['paid_mark_state'], $row($idT)['send_state'], IDokladClient::$shared->count('POST', 'Mails/')], ['issued', 'done', null, 0]);
    $withEnv(['IDOKLAD_ISSUE_MODE' => 'prepare'], static fn () => (new InvoiceIssuer())->followUps($id3));
    $expect('in prepare mode the follow-ups do nothing (nothing is sent or marked unless sending is on)', IDokladClient::$shared->count('PUT', 'IssuedDocumentPayments/'), 1);

    // A failing iDoklad: error, retried up to the cap, then left for a person.
    $rec4 = new IDokladClient();
    $rec4->failInvoicePost = new RuntimeException('iDoklad POST IssuedInvoices failed: HTTP 400 — validation');
    $id4 = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuer4 = new InvoiceIssuer($rec4);
    $statuses = [];
    for ($k = 0; $k < 4; $k++) {
        $statuses[] = $issuer4->issue($id4);
    }
    $expect('failing iDoklad: error, 3 attempts, the 4th automatic run does nothing', [$statuses, (int) $row($id4)['attempts']], [['error', 'error', 'error', 'error'], 3]);
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key');
    $expect('…a live row at the cap is an admin ERROR worded „treba zásah" (not „skúsi znova")', [$checks['invoices_failed_stopped']['level'] ?? null, str_contains((string) ($checks['invoices_failed_stopped']['message'] ?? ''), 'treba zásah')], ['error', true]);
    $rec4->failInvoicePost = null;
    $expect('manual „Vystaviť znova" past the cap issues it', [$issuer4->issue($id4, true), (int) $row($id4)['attempts']], ['issued', 4]);

    $rec5 = new IDokladClient();
    $rec5->failInvoicePost = new IDokladTransportException('iDoklad transport error: Operation timed out');
    $id5 = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuer5 = new InvoiceIssuer($rec5);
    $expect('timeout on the POST: needs_review / uncertain_send', [$issuer5->issue($id5), $row($id5)['review_reason']], ['needs_review', 'uncertain_send']);
    $posts = $rec5->invoicePosts();
    $issuer5->issue($id5);
    $expect('…an automatic run does not post again', $rec5->invoicePosts(), $posts);

    $rec6 = new IDokladClient();
    $rec6->invoicePostAnswer = ['DocumentNumber' => ''];
    $id6 = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $issuer6 = new InvoiceIssuer($rec6);
    $expect('2xx without Id: needs_review / uncertain_send, not issued, no follow-up', [$issuer6->issue($id6), $row($id6)['review_reason'], $row($id6)['idoklad_invoice_id'], $rec6->count('PUT', ''), $rec6->count('POST', 'Mails/')], ['needs_review', 'uncertain_send', null, 0, 0]);
    $expect('…the admin can „Vystaviť znova" after checking iDoklad', InvoiceIssuer::manualIssueAllowed($row($id6)), true);

    // Rows the old code left without a snapshot (item 4: alerted, then terminal).
    $pdo->prepare("INSERT INTO invoices (account_id, stripe_invoice_id, amount_cents, currency, status, issued_at)
                   VALUES (?, ?, 1900, 'EUR', 'pending', NOW())")->execute([$acc, 'in_legacy_doesnotexist_' . bin2hex(random_bytes(3))]);
    $idL = (int) $pdo->lastInsertId();
    $expect('legacy pending row without snapshot: no „Vystaviť" button', InvoiceIssuer::manualIssueAllowed($row($idL)), false);
    $recL = new IDokladClient();
    $st1 = $withEnv(['STRIPE_SECRET_KEY' => ''], static fn () => (new InvoiceIssuer($recL))->issue($idL));
    $st2 = $withEnv(['STRIPE_SECRET_KEY' => ''], static fn () => InvoiceIssuer::recoverSnapshot($idL));
    $expect('Stripe cannot be read: missing_snapshot (tried again), never sent', [$st1, $row($idL)['review_reason'], (int) $row($idL)['snapshot_read_failures'], $recL->invoicePosts()], ['needs_review', 'missing_snapshot', 2, 0]);
    $withEnv(['STRIPE_SECRET_KEY' => ''], static fn () => InvoiceIssuer::recoverSnapshot($idL));
    $expect('…the 3rd failed read → snapshot_unreadable (terminal, no longer fetched by the daily run)', [$row($idL)['review_reason'], InvoiceIssuer::manualIssueAllowed($row($idL))], ['snapshot_unreadable', false]);
    $pdo->prepare("INSERT INTO invoices (account_id, stripe_invoice_id, amount_cents, currency, status, issued_at)
                   VALUES (?, ?, 1900, 'EUR', 'pending', NOW())")->execute([$acc, 'in_legacy_doesnotexist_' . bin2hex(random_bytes(3))]);
    $idL2 = (int) $pdo->lastInsertId();
    InvoiceIssuer::recoverSnapshot($idL2);
    $expect('…Stripe answers 404 (it has no such invoice): terminal at once', $row($idL2)['review_reason'], 'snapshot_unreadable');

    // Item 4: the alert memory is per row AND state.
    $captureOn();
    $mailsReset();
    $idM = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $withEnv(['APP_ENV' => 'prod', 'IDOKLAD_ISSUE_MODE' => null], static fn () => (new InvoiceIssuer())->issue($idM));
    $a1 = $row($idM)['operator_alerted_state'];
    $again = \Firol\Idoklad\InvoiceAlert::notifyOnce($idM, 'x');
    $withEnv(['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => null, 'IDOKLAD_CLIENT_SECRET' => null], static fn () => (new InvoiceIssuer())->issue($idM, true, ['prepared']));
    $expect('a live row prepared: alerted once (state prepared), not again in the same state; prepared → skipped is alerted again',
        [$a1, $again, $row($idM)['status'], $row($idM)['operator_alerted_state'], count($mails())], ['prepared', 'already', 'skipped', 'skipped', 2]);
    $mailsReset();
    $idQ = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base', false, 1, null, 0, 'si_x', 0)]), false);
    InvoiceIssuer::runDeferred();
    InvoiceIssuer::runDeferred();
    $expect('a held row alerted from the webhook\'s deferred job once only, the mail tagged [TEST] for a test-mode payment',
        [count($mails()), str_starts_with((string) ($mails()[0]['subject'] ?? ''), '[TEST] '), $row((int) $pdo->query('SELECT MAX(id) FROM invoices WHERE account_id = ' . $acc)->fetchColumn())['operator_alerted_state']],
        [1, true, 'needs_review/no_vat']);
    $captureOff();

    // ConfigCheck: test rows warn in production, live rows are errors.
    $levels = $withEnv(['APP_ENV' => 'production'], static fn () => array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key'));
    $expect('production: a held live row is an error, a held test row a warning', [$levels['invoices_held'] ?? null, $levels['invoices_held_test'] ?? null], ['error', 'warn']);
    $levels = array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key');
    $expect('follow-ups failed past the cap / uncertain: admin error', $levels['invoices_followup'] ?? null, 'error');

    // The daily claim only from the status seen (round 3).
    $rec10 = new IDokladClient();
    $id10 = InvoiceIssuer::record($acc, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $pdo->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'uncertain_send' WHERE id = ?")->execute([$id10]);
    $expect('daily claim only from „prepared": a row moved to uncertain_send is not posted',
        [(new InvoiceIssuer($rec10))->issue($id10, true, ['prepared']), $rec10->invoicePosts()], ['needs_review', 0]);

    // Part 2: a failed renewal — recorded once, the paid-until date capped.
    $pdo->prepare("UPDATE accounts SET stripe_subscription_id = 'sub_grace', stripe_status = 'past_due' WHERE id = ?")->execute([$acc]);
    $failedAt = strtotime('2026-12-17 10:00:00');
    $failedInv = ['id' => 'in_failed_' . bin2hex(random_bytes(3)), 'created' => $failedAt, 'amount_due' => 3321, 'currency' => 'eur', 'livemode' => true,
                  'status' => 'open', 'attempt_count' => 1, 'hosted_invoice_url' => 'https://example.com/pay', 'subscription' => 'sub_grace'];
    $expect('a failed invoice is recorded once', [\Firol\Billing\PaymentGrace::recordFailure($acc, $failedInv), \Firol\Billing\PaymentGrace::recordFailure($acc, $failedInv)], [true, false]);
    $expect('past_due: the period end Stripe moved ahead (17. 1.) is capped at the failure + 14 days (31. 12.)',
        \Firol\Billing\PaymentGrace::endDateFor($acc, 'past_due', [], '2027-01-17'), '2026-12-31');
    $expect('…the banner: amount with VAT, read-only from 1. 1. 2027', \Firol\Billing\PaymentGrace::bannerFor($acc, 'past_due'),
        ['amount_cents' => 3321, 'currency' => 'EUR', 'failed_at' => '2026-12-17', 'read_only_from' => '2027-01-01']);
    $expect('…PAYMENT_GRACE_DAYS=7 → 24. 12.', $withEnv(['PAYMENT_GRACE_DAYS' => '7'], static fn () => \Firol\Billing\PaymentGrace::endDateFor($acc, 'past_due', [], '2027-01-17')), '2026-12-24');
    $captureOn();
    $mailsReset();
    $sentNow = \Firol\Billing\PaymentGrace::sendPendingEmails($acc);
    $sentAgain = \Firol\Billing\PaymentGrace::sendPendingEmails($acc);
    $m = $mails()[0] ?? [];
    $expect('…one e-mail to the main user (amount, date, the link to pay), not a second one',
        [$sentNow, $sentAgain, $m['to'] ?? null, str_contains((string) ($m['text'] ?? ''), '33,21 EUR (s DPH)'), str_contains((string) ($m['text'] ?? ''), 'od 1. 1. 2027'), str_contains((string) ($m['text'] ?? ''), '/billing')],
        [1, 0, $mainEmail, true, true, true]);
    $captureOff();
    $expect('paid (active): the failure is resolved, the real period end comes back, no banner',
        [\Firol\Billing\PaymentGrace::endDateFor($acc, 'active', [], '2027-01-17'), \Firol\Billing\PaymentGrace::bannerFor($acc, 'past_due')], ['2027-01-17', null]);
    $second = ['id' => 'in_failed2_' . bin2hex(random_bytes(3)), 'created' => strtotime('2027-02-17 10:00:00')] + $failedInv;
    \Firol\Billing\PaymentGrace::recordFailure($acc, $second);
    $expect('a second failure in a later period counts its own 14 days', \Firol\Billing\PaymentGrace::endDateFor($acc, 'past_due', [], '2027-03-17'), '2027-03-03');
    $expect('trial, cancelled: never capped', [\Firol\Billing\PaymentGrace::endDateFor($acc, 'trialing', [], '2027-03-17'), \Firol\Billing\PaymentGrace::endDateFor($acc, 'canceled', [], '2027-03-17')], ['2027-03-17', '2027-03-17']);
    $expect('a failure read from the subscription\'s latest invoice (a lost event) is recorded too',
        [\Firol\Billing\PaymentGrace::endDateFor($acc, 'unpaid', ['latest_invoice' => ['id' => 'in_lost_' . bin2hex(random_bytes(3)), 'status' => 'open', 'attempt_count' => 2, 'created' => strtotime('2027-02-10'), 'amount_due' => 100]], '2027-03-17')],
        ['2027-02-24']);
} finally {
    $pdo->rollBack();
    $captureOff();
}
echo "\n";

// ── 5. reprice script options, the daily run's refusal ──────────────────
echo "5 — bin/reprice-yearly-extra-seats.php refuses bad options; the daily run with a live key outside production\n";
$runScript = static function (array $args): int {
    $p = proc_open([PHP_BINARY, __DIR__ . '/reprice-yearly-extra-seats.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($p);
};
$expect('misspelled --acount=5 refused', $runScript(['--offline', '--acount=5']), 2);
$expect('non-numeric --account=abc refused', $runScript(['--offline', '--account=abc']), 2);
$expect('--offline --account=999999 runs (dry, database only)', $runScript(['--offline', '--account=999999']), 0);
// bin/billing-daily.php as a child process, with the iDoklad recorder
// prepended (auto_prepend_file — it IS the iDoklad client there too). Every
// variable a check wants empty is listed in FIROL_TEST_BLANK: proc_open()
// drops variables with an empty value, so the child would otherwise load the
// real ones from backend/.env. SMTP is always blank in the child; with
// $capture its mail goes to the capture file.
$daily = static function (array $env, array $args, bool $capture = false) use ($mailFile): array {
    $env += ['IDOKLAD_CLIENT_ID' => '', 'IDOKLAD_CLIENT_SECRET' => '', 'IDOKLAD_ISSUE_MODE' => 'prepare', 'IDOKLAD_ALLOW_TEST_EVENTS' => '',
             'ADMIN_EMAIL' => 'ops-test@example.com'];
    $blank = array_keys(array_filter($env, static fn ($v) => $v === ''));
    $env = array_filter($env, static fn ($v) => $v !== '');
    $env['FIROL_TEST_BLANK'] = implode(',', $blank);
    if ($capture) {
        $env['FIROL_TEST_MAIL_CAPTURE'] = $mailFile;
    }
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
$settingSet = static function (string $key, ?string $value) use ($pdo): void {
    if ($value === null) {
        $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);
    } else {
        $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    }
};
// The settings and alert memory these checks touch are restored at the end.
$saved = [
    'refused' => $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY),
    'last'    => $settingOf(\Firol\Billing\ConfigCheck::DAILY_RUN_KEY),
    'alerts'  => $pdo->query("SELECT alert_key, sent_at FROM billing_operator_alerts WHERE alert_key = 'daily_refused' OR alert_key LIKE 'config:%'")->fetchAll(),
];
try {
    [$code, , $err] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--dry-run', '--only=seats', '--account=999999']);
    $expect('item 10: the child loads no iDoklad credentials or SMTP from .env (checked in the child)', [str_contains($err, '[recorder-env] ok'), str_contains($err, 'LEAK')], [true, false]);
    $expect('billing-daily with a live key and APP_ENV=local: Stripe writes refused, exit 2 (dry run: nothing stamped)',
        [$code, str_contains($err, 'LIVE key'), $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY)], [2, true, $saved['refused']]);
    $mailsReset();
    [$code, $out] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local', 'IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => 'x', 'IDOKLAD_CLIENT_SECRET' => 'x'],
        ['--only=invoices', '--account=999999'], true);
    $refusedStamp = $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY);
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key');
    $expect('…a real run: the invoice step still runs — item 8: only preparing, also with IDOKLAD_ISSUE_MODE=send — the refusal is stamped and is an admin ERROR',
        [$code, str_contains($out, 'invoices:'), str_contains($out, 'invoices only prepared'), $refusedStamp !== null, $checks['daily_refused'] ?? null], [2, true, true, true, 'error']);
    $expect('…one operator alert for it (mailed by the child, remembered)', \Firol\Billing\OperatorAlert::wasSent('daily_refused'), true);
    $configMails = count(array_filter($mails(), static fn ($m) => str_contains((string) $m['subject'], 'chyba v kontrole fakturácie')));
    $expect('item 3: every ConfigCheck ERROR mailed once per check (the live key with APP_ENV=local among them)',
        [$configMails > 0, \Firol\Billing\OperatorAlert::wasSent('config:stripe_key:')], [true, true]);
    $mailsReset();
    [$code, $out] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--only=invoices', '--account=999999'], true);
    $expect('…a second refused run alerts nothing again (refusal and config errors: already)',
        [$code, str_contains($out, 'operator alert: already'), count(array_filter($mails(), static fn ($m) => str_contains((string) $m['subject'], 'chyba v kontrole fakturácie') && str_contains((string) $m['subject'], 'stripe_key')))],
        [2, true, 0]);
    [$code] = $daily(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], ['--dry-run', '--only=seats', '--account=999999', '--allow-live']);
    $expect('…--allow-live lets it run (dry run, a missing account, nothing read)', $code, 0);
    [$code] = $daily([], ['--only=invoices', '--account=999999']);
    $expect('…a run that is not refused removes the stamp and the alert memory; the config error that is gone is forgotten',
        [$code, $settingOf(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY), \Firol\Billing\OperatorAlert::wasSent('daily_refused'), \Firol\Billing\OperatorAlert::wasSent('config:stripe_key:')], [0, null, false, false]);
    $expect('partial runs (--dry-run, --only, --account, refused) never stamp the last run', $settingOf(\Firol\Billing\ConfigCheck::DAILY_RUN_KEY), $saved['last']);
    $settingSet(\Firol\Billing\ConfigCheck::DAILY_RUN_KEY, '2000-01-01 00:00:00');
    $levels = $withEnv(['STRIPE_SECRET_KEY' => 'sk_live_fake', 'APP_ENV' => 'local'], static fn () => array_column(\Firol\Billing\ConfigCheck::run(), 'level', 'key'));
    $expect('a live key with APP_ENV=local is an admin ERROR, and so is a stale daily run with a live key', [$levels['stripe_key'] ?? null, $levels['daily'] ?? null], ['error', 'error']);
    // Item 3: the webhook path alerts once when the daily run is stale.
    $captureOn();
    $mailsReset();
    $pdo->prepare('DELETE FROM billing_operator_alerts WHERE alert_key = ?')->execute([\Firol\Billing\ConfigCheck::DAILY_STALE_ALERT]);
    $r1 = $withEnv(['APP_ENV' => 'production'], static fn () => \Firol\Billing\ConfigCheck::alertIfDailyStale());
    $r2 = $withEnv(['APP_ENV' => 'production'], static fn () => \Firol\Billing\ConfigCheck::alertIfDailyStale());
    $r3 = \Firol\Billing\ConfigCheck::alertIfDailyStale();
    $expect('the webhook path: a daily run older than 36 h alerts once in production; not outside production with a test key', [$r1, $r2, $r3, count($mails())], ['sent', 'already', null, 1]);
    $captureOff();
} finally {
    $settingSet(\Firol\Billing\ConfigCheck::DAILY_RUN_KEY, $saved['last']);
    $settingSet(\Firol\Billing\ConfigCheck::DAILY_REFUSED_KEY, $saved['refused']);
    $pdo->exec("DELETE FROM billing_operator_alerts WHERE alert_key IN ('daily_refused', '" . \Firol\Billing\ConfigCheck::DAILY_STALE_ALERT . "') OR alert_key LIKE 'config:%'");
    foreach ($saved['alerts'] as $a) {
        $pdo->prepare('INSERT IGNORE INTO billing_operator_alerts (alert_key, sent_at) VALUES (?, ?)')->execute([$a['alert_key'], $a['sent_at']]);
    }
}
echo "\n";

// ── 5b. the daily run's invoice step ────────────────────────────────────
echo "5b — the daily run: held rows alerted once per state, one attempt per row, live rows issued once allowed, follow-ups, gone events\n";
$suffix7 = bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO users (fullname, email, password_hash) VALUES ('Daily test', ?, '')")->execute(["daily-test-$suffix7@example.com"]);
$uid7 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO accounts (invoice_company_name, invoice_street, invoice_postal_code, invoice_city, invoice_ico, main_user_id, subscription_end_date)
               VALUES ('Daily test s.r.o.', 'Test 1', '000 00', 'Test', '00000000', ?, '2027-01-01')")->execute([$uid7]);
$acc7 = (int) $pdo->lastInsertId();
$eventId7 = 'evt_test_gone_' . $suffix7;
$eventId8 = 'evt_test_gone_inv_' . $suffix7;
try {
    $rowOf = static function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    };
    // A: held (no VAT) — its alert failed (no SMTP in this process).
    InvoiceIssuer::record($acc7, $ia = $invoice(null, null, [$line(1900, 'base', false, 1, null, 0, 'si_x', 0)], ['livemode' => true]), true);
    InvoiceIssuer::runDeferred();
    $idA = (int) $pdo->query('SELECT id FROM invoices WHERE stripe_invoice_id = ' . $pdo->quote($ia['id']))->fetchColumn();
    // B: a live payment only prepared; C: a live payment skipped (no iDoklad credentials).
    $idB = (int) InvoiceIssuer::record($acc7, $invoice(null, null, [$line(1900, 'base')], ['livemode' => true]), true);
    $withEnv(['IDOKLAD_ISSUE_MODE' => 'prepare'], static fn () => (new InvoiceIssuer())->issue($idB));
    $idC = (int) InvoiceIssuer::record($acc7, $invoice(null, null, [$line(1900, 'base'), $line(1000, 'extra')], ['livemode' => true]), true);
    $withEnv(['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => null, 'IDOKLAD_CLIENT_SECRET' => null], static fn () => (new InvoiceIssuer())->issue($idC));
    // D: a row held as a credit under the old rule.
    $idD = (int) InvoiceIssuer::record($acc7, $invoice(null, null, [$line(-1068, 'extra', true, 2, $change), $line(534, 'extra', true, 1, $change), $line(1900, 'base')], ['livemode' => true]), true);
    $pdo->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'credit' WHERE id = ?")->execute([$idD]);
    $expect('set-up: A held without an alert, B prepared, C skipped, D an old credit, no attempt used',
        [[$rowOf($idA)['status'], $rowOf($idA)['operator_alerted_at']], [$rowOf($idB)['status'], (int) $rowOf($idB)['attempts']], [$rowOf($idC)['status'], (int) $rowOf($idC)['attempts']], $rowOf($idD)['review_reason']],
        [['needs_review', null], ['prepared', 0], ['skipped', 0], 'credit']);

    $sendEnv = ['IDOKLAD_ISSUE_MODE' => 'send', 'IDOKLAD_CLIENT_ID' => 'x', 'IDOKLAD_CLIENT_SECRET' => 'x'];
    [, $out] = $daily($sendEnv + ['IDOKLAD_RECORDER_FAIL' => '1'], ['--only=invoices', '--account=' . $acc7], true);
    $expect('daily run 1 (iDoklad failing): A alerted now, B and C tried once each (not twice), error; D tried once',
        [$rowOf($idA)['operator_alerted_state'], [$rowOf($idB)['status'], (int) $rowOf($idB)['attempts']], [$rowOf($idC)['status'], (int) $rowOf($idC)['attempts']], [$rowOf($idD)['status'], (int) $rowOf($idD)['attempts']]],
        ['needs_review/no_vat', ['error', 1], ['error', 1], ['error', 1]]);
    $expect('…the log says what really happened', [str_contains($out, "row=$idA needs_review/no_vat — operator alerted"), str_contains($out, "row=$idB LIVE payment prepared → error")], [true, true]);
    [, $out] = $daily($sendEnv + ['IDOKLAD_RECORDER_FAIL_SEND' => '1'], ['--only=invoices', '--account=' . $acc7], true);
    $expect('daily run 2 (iDoklad answering, its e-mail failing): A not alerted again, B, C and D issued by the retry, marked paid, e-mail error',
        [str_contains($out, "row=$idA "), [$rowOf($idB)['status'], (int) $rowOf($idB)['attempts']], [$rowOf($idC)['status'], $rowOf($idC)['paid_mark_state'], $rowOf($idC)['send_state']], $rowOf($idD)['status']],
        [false, ['issued', 2], ['issued', 'done', 'error'], 'issued']);
    [, $out] = $daily($sendEnv, ['--only=invoices', '--account=' . $acc7], true);
    $expect('daily run 3: the failed e-mails retried and sent; nothing issued again', [$rowOf($idC)['send_state'], $rowOf($idB)['send_state'], (int) $rowOf($idB)['attempts']], ['sent', 'sent', 2]);

    // Gone events: limited to this test's events (--event); an invoice.* one
    // is an error until dismissed.
    $pdo->prepare("INSERT INTO stripe_webhook_events (event_id, event_type, status, attempts, error_message) VALUES (?, 'customer.subscription.updated', 'failed', 1, 'test'), (?, 'invoice.paid', 'failed', 1, 'test')")
        ->execute([$eventId7, $eventId8]);
    $daily([], ['--only=events', '--event=' . $eventId7]);
    $daily([], ['--only=events', '--event=' . $eventId8]);
    $s = $pdo->prepare('SELECT status, attempts FROM stripe_webhook_events WHERE event_id = ?');
    $s->execute([$eventId7]);
    $ev = $s->fetch();
    $checks = array_column(\Firol\Billing\ConfigCheck::run(), null, 'key');
    $expect('an event Stripe answers 404 for → gone, recorded, listed; item 10: only this test\'s events re-run (--event)',
        [$ev['status'] ?? null, (int) ($ev['attempts'] ?? 0), str_contains((string) ($checks['webhook_events_gone']['message'] ?? ''), $eventId7)], ['gone', 2, true]);
    $expect('a gone invoice.paid event is an admin ERROR with a „Vyriešené" item', [$checks['webhook_events_gone_invoice']['level'] ?? null, in_array($eventId8, array_column($checks['webhook_events_gone_invoice']['dismiss'] ?? [], 'id'), true)], ['error', true]);
    $pdo->prepare('UPDATE stripe_webhook_events SET dismissed_at = NOW() WHERE event_id = ?')->execute([$eventId8]);
    $expect('…dismissed: no longer an error', isset(array_column(\Firol\Billing\ConfigCheck::run(), null, 'key')['webhook_events_gone_invoice']), false);
    [, $out] = $daily([], ['--only=events', '--event=' . $eventId7]);
    $expect('…not re-run by the next daily run', str_contains($out, "event $eventId7 ("), false);
} finally {
    $pdo->prepare('DELETE FROM stripe_webhook_events WHERE event_id IN (?, ?)')->execute([$eventId7, $eventId8]);
    $pdo->prepare('DELETE FROM billing_operator_alerts WHERE alert_key IN (?, ?)')->execute(["event_gone:$eventId7", "event_gone:$eventId8"]);
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

@unlink($mailFile);
echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures check(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
