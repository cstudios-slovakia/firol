<?php

declare(strict_types=1);

/**
 * Daily billing run (cron, once a day — installed by .github/workflows/deploy.yml):
 *
 *     php backend/bin/billing-daily.php [--dry-run] [--only=events|seats|invoices] [--account=ID] [--allow-live]
 *
 *  0. Webhook events: every Stripe event stored as `failed`, or still
 *     `processing` after 10 minutes, is read again from Stripe by its id and
 *     handled; the operator (ADMIN_EMAIL) is told once per event, with the
 *     result. An event Stripe no longer returns (or one failing for more than
 *     30 days) becomes `gone` — not re-run again, listed by ConfigCheck,
 *     alerted once.
 *  1. Seats: for every account with a subscription that is not over, the
 *     customer's subscriptions are read from Stripe: a paid subscription
 *     takes over an unpaid recorded one (alerted once), the current one's
 *     status, period end and cancel flag are written, and other live
 *     subscriptions are stored for ConfigCheck and alerted once. Then the
 *     wanted extra-technician quantity is pushed to Stripe
 *     (SeatSync::reconcile) and duplicate extra items merged. With
 *     BILLING_RECONCILE=off nothing is pushed: the differences are only
 *     listed. An account left for a person (MANUAL) or failing (ERROR) is
 *     stored on the account and alerted once per state.
 *  2. Invoices:
 *       - a row stuck in `sending` (a crash during the iDoklad POST) goes to
 *         `needs_review` (`uncertain_send`) and the operator is alerted —
 *         it is never re-sent blindly;
 *       - a row without the Stripe invoice snapshot (left by the code before
 *         17. 11. 2026, or a rebuild that failed) is rebuilt from Stripe and
 *         held for a person to check iDoklad first;
 *       - every `needs_review` row whose alert did not go out
 *         (operator_alerted_at NULL — a failed mail, an old row) is alerted;
 *       - a `prepared` row of a LIVE Stripe invoice (a real payment without a
 *         tax document) is issued when IssuePolicy now allows sending, else
 *         the operator is alerted once; a `skipped` live row (iDoklad had no
 *         credentials) the same once iDoklad is configured. Claimed only from
 *         `prepared` / `skipped`, so a row an admin moved meanwhile is never
 *         posted;
 *       - `pending` rows older than 10 minutes and `error` rows below
 *         InvoiceIssuer::MAX_AUTO_ATTEMPTS are issued again (IssuePolicy
 *         still decides whether anything is really sent), except a row this
 *         run already handled. The last failed attempt alerts the operator.
 *  3. A full run (no --dry-run, --only or --account, not refused) stamps
 *     system_settings.billing_daily_last_run (the admin check reports it
 *     when older than 36 hours).
 *
 * With a LIVE Stripe key and APP_ENV other than production (and no
 * --allow-live) the steps that WRITE TO STRIPE are refused — the event re-run
 * and the seat push (seats are only reported): on a developer machine with a
 * copy of production data they would push the local roster to real
 * customers. The checks and the invoice step still run. The refusal is
 * stamped in system_settings (billing_daily_refused, an error in
 * ConfigCheck), alerted once, and the run exits with 2.
 *
 * --dry-run reads Stripe and lists what would change; it changes nothing in
 * Stripe, iDoklad or the database. Exit code 1 when anything failed.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Billing\ConfigCheck;
use Firol\Billing\OperatorAlert;
use Firol\Billing\SeatSync;
use Firol\Controllers\BillingController;
use Firol\Db;
use Firol\Idoklad\IDokladClient;
use Firol\Idoklad\InvoiceAlert;
use Firol\Idoklad\InvoiceIssuer;
use Firol\Idoklad\IssuePolicy;
use Firol\Stripe\StripeClient;

$options = getopt('', ['dry-run', 'only:', 'account:', 'allow-live']);
$dryRun  = isset($options['dry-run']);
$only    = isset($options['only']) ? (string) $options['only'] : '';
if ($only !== '' && !in_array($only, ['events', 'seats', 'invoices'], true)) {
    fwrite(STDERR, "--only must be 'events', 'seats' or 'invoices'.\n");
    exit(2);
}
$onlyAccount = null;
if (isset($options['account'])) {
    if (!is_string($options['account']) || !ctype_digit($options['account'])) {
        fwrite(STDERR, "--account must be a numeric account id.\n");
        exit(2);
    }
    $onlyAccount = (int) $options['account'];
}
$partial = $dryRun || $only !== '' || $onlyAccount !== null;
$step = static fn (string $name): bool => $only === '' || $only === $name;

$pdo = Db::pdo();
$failures = 0;
$log = static function (string $line): void {
    echo date('Y-m-d H:i:s'), ' ', $line, "\n";
};
$setting = static function (string $key, ?string $value) use ($pdo): void {
    if ($value === null) {
        $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, mb_substr($value, 0, 500)]);
};

// The live-key guard: refuse only what writes to Stripe.
$isProduction = strtolower(trim((string) ($_ENV['APP_ENV'] ?? ''))) === 'production';
$refused = StripeClient::isConfigured() && !StripeClient::isTestKey() && !$isProduction && !isset($options['allow-live']);
if ($refused) {
    $reason = 'OSTRÝ kľúč Stripe a APP_ENV=' . ($_ENV['APP_ENV'] ?? '') . ' (nie „production")';
    fwrite(STDERR, "Refusing to write to Stripe: STRIPE_SECRET_KEY is a LIVE key and APP_ENV is not 'production'. "
        . "Event re-runs and seat pushes are skipped (seats are only reported); the checks and the invoice step run. "
        . "Set APP_ENV=production, or pass --allow-live if this machine really is meant to change live customers.\n");
    if (!$dryRun) {
        $setting(ConfigCheck::DAILY_REFUSED_KEY, date('Y-m-d H:i:s') . ' — ' . $reason);
        $sent = OperatorAlert::once('daily_refused', 'denná kontrola fakturácie nezapisuje do Stripe',
            'Denná kontrola beží s ostrým kľúčom Stripe mimo produkcie, preto nezapisuje do Stripe: neopakuje nespracované udalosti a neposiela počty technikov. '
            . 'Faktúry a upozornenia bežia. Ak je to produkčný server, nastav APP_ENV=production.',
            ["Dôvod: $reason", 'Server: ' . (gethostname() ?: '?')]);
        $log("REFUSED to write to Stripe — $reason; operator alert: $sent");
    }
} elseif (!$dryRun) {
    $setting(ConfigCheck::DAILY_REFUSED_KEY, null);
    OperatorAlert::forget('daily_refused');
}

$log('billing-daily start' . ($dryRun ? ' (dry run)' : '') . ($refused ? ' — Stripe writes REFUSED (live key outside production)' : '')
    . (SeatSync::pushEnabled() ? '' : ' — BILLING_RECONCILE ' . SeatSync::reconcileSwitch() . ', seats report only'));
foreach (ConfigCheck::run() as $check) {
    if ($check['level'] !== 'ok') {
        $log(strtoupper($check['level']) . ' config ' . $check['key'] . ': ' . $check['message']);
    }
}

// ── 0. Webhook events that failed ───────────────────────────────────────
if ($step('events') && $onlyAccount === null && StripeClient::isConfigured()) {
    $events = $pdo->query(
        "SELECT event_id, event_type, status, attempts, error_message, operator_alerted_at
         FROM   stripe_webhook_events
         WHERE  status = 'failed'
            OR  (status = 'processing' AND updated_at < NOW() - INTERVAL " . ConfigCheck::STALE_EVENT_MINUTES . ' MINUTE)
         ORDER  BY received_at'
    )->fetchAll();
    foreach ($events as $ev) {
        $id = (string) $ev['event_id'];
        if ($dryRun || $refused) {
            $log("event $id ({$ev['event_type']}) {$ev['status']} after {$ev['attempts']} attempt(s) — "
                . ($refused ? 'NOT re-run (Stripe writes refused)' : 'would re-run') . ": {$ev['error_message']}");
            continue;
        }
        $result = BillingController::reprocessEvent($id);
        $log("event $id ({$ev['event_type']}) {$ev['status']} → $result");
        if (str_starts_with($result, 'failed') || str_starts_with($result, 'gone')) {
            $failures++;
        }
        $lines = [
            "Udalosť: $id ({$ev['event_type']})",
            "Stav pred dennou kontrolou: {$ev['status']}, pokusov: {$ev['attempts']}",
            'Chyba: ' . ((string) $ev['error_message'] !== '' ? $ev['error_message'] : '—'),
            'Výsledok opakovania: ' . ($result === 'done' ? 'spracovaná' : $result),
        ];
        if ($ev['operator_alerted_at'] === null) {
            $sent = OperatorAlert::send(
                'udalosť zo Stripe sa nespracovala',
                'Webhook zo Stripe sa pri doručení nespracoval. Denná kontrola ho načítala zo Stripe a spustila znova.',
                $lines,
            );
            if ($sent) {
                $pdo->prepare('UPDATE stripe_webhook_events SET operator_alerted_at = NOW() WHERE event_id = ?')->execute([$id]);
            }
            $log("event $id operator " . ($sent ? 'alerted' : 'NOT alerted (mail failed or no ADMIN_EMAIL; next run tries again)'));
        }
        if (str_starts_with($result, 'gone')) {
            $sent = OperatorAlert::once("event_gone:$id", 'udalosť zo Stripe sa už nespracuje',
                'Denná kontrola udalosť ďalej neopakuje (Stripe ju už nevráti alebo zlyháva viac ako ' . BillingController::EVENT_GIVE_UP_DAYS . ' dní). Skontroluj dotknutý účet ručne.',
                $lines);
            $log("event $id gone — operator alert: $sent");
        }
    }
    $log(sprintf('events: %d re-run', ($dryRun || $refused) ? 0 : count($events)));
}

// ── 1. Seats ────────────────────────────────────────────────────────────
if ($step('seats')) {
    $seatDry = $dryRun || $refused;
    $sql = "SELECT id, invoice_company_name FROM accounts
            WHERE  stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''
              AND  (stripe_status IS NULL OR stripe_status NOT IN ('canceled', 'incomplete_expired'))";
    $params = [];
    if ($onlyAccount !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlyAccount;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY id');
    $stmt->execute($params);
    $accountRows = $stmt->fetchAll();
    $changed = 0;
    $manual  = 0;
    $seatFailures = 0;
    $alertState = static function (int $accountId, string $name, string $state, string $note) use ($log, $seatDry): void {
        if ($seatDry) {
            return;
        }
        if (!in_array($state, ['manual', 'error'], true)) {
            OperatorAlert::forget("seats:$accountId:");
            return;
        }
        $sent = OperatorAlert::once("seats:$accountId:$state",
            $state === 'manual' ? 'technici účtu sa nesynchronizujú — treba rozhodnúť' : 'synchronizácia technikov účtu zlyháva',
            $state === 'manual'
                ? 'Denná kontrola nechala predplatné účtu na človeka: do Stripe sa nič neposiela, kým to niekto nevyrieši. Extra technici sa medzitým neúčtujú.'
                : 'Synchronizácia počtu technikov so Stripe zlyhala. Denná kontrola to skúsi znova zajtra.',
            ["Účet: $name (#$accountId)", 'Poznámka: ' . mb_substr($note, 0, 600), 'Výpis: php backend/bin/billing-seat-report.php --account=' . $accountId]);
        $log("seats account=$accountId $state — operator alert: $sent");
    };
    foreach ($accountRows as $acc) {
        $accountId = (int) $acc['id'];
        $name = (string) $acc['invoice_company_name'];
        try {
            $state = BillingController::syncSubscriptionState($accountId, $seatDry);
            if ($state !== null) {
                $log(sprintf('subscription account=%d %s%s', $accountId, $seatDry ? 'would write ' : '', $state));
            }
            if (!$seatDry) {
                $others = $pdo->prepare('SELECT stripe_subscription_id, stripe_status, stripe_other_subscriptions FROM accounts WHERE id = ?');
                $others->execute([$accountId]);
                $o = $others->fetch();
                $list = json_decode((string) ($o['stripe_other_subscriptions'] ?? ''), true) ?: [];
                if ($list === []) {
                    OperatorAlert::forget("subs:$accountId:");
                } else {
                    $ids = array_merge([(string) $o['stripe_subscription_id']], array_column($list, 'id'));
                    sort($ids);
                    $sent = OperatorAlert::once('subs:' . $accountId . ':' . implode(',', $ids), 'zákazník má viac živých predplatných',
                        'Zákazník má v Stripe viac ako jedno predplatné, ktoré sa účtuje. Aplikácia používa jedno z nich; ostatné nezruší ani nezmení — rozhodni v Stripe.',
                        array_merge(["Účet: $name (#$accountId)", "Používa sa: {$o['stripe_subscription_id']} ({$o['stripe_status']})"],
                            array_map(static fn (array $x): string => "Ďalšie: {$x['id']} ({$x['status']}), "
                                . ((int) $x['extra_qty'] > 0 ? "nesie {$x['extra_qty']} extra technik(ov)" : 'bez extra technika'), $list)));
                    $log("subscription account=$accountId has " . (count($list) + 1) . " live subscriptions — operator alert: $sent");
                }
            }
            $r = SeatSync::reconcile($accountId, $seatDry);
            if ($r['action'] === 'manual') {
                $manual++;
                $log(sprintf('seats account=%d MANUAL (wanted=%d stripe=%d): %s', $accountId, $r['wanted'], $r['before'], implode('; ', $r['notes'])));
                $alertState($accountId, $name, 'manual', implode('; ', $r['notes']));
            } else {
                if ($r['action'] !== 'none') {
                    $changed++;
                    $log(sprintf('seats account=%d wanted=%d stripe=%d %s: %s', $accountId, $r['wanted'], $r['before'], $r['action'], implode('; ', $r['notes'])));
                } elseif ($r['notes'] !== []) {
                    $log(sprintf('seats account=%d ok (wanted=%d): %s', $accountId, $r['wanted'], implode('; ', $r['notes'])));
                }
                $alertState($accountId, $name, $r['action'] === 'would_change' ? 'would_change' : 'ok', '');
            }
        } catch (\Throwable $e) {
            $seatFailures++;
            $log("seats account=$accountId ERROR " . $e->getMessage());
            if (!$seatDry) {
                SeatSync::recordState($accountId, 'error', $e->getMessage());
            }
            $alertState($accountId, $name, 'error', $e->getMessage());
        }
    }
    $failures += $seatFailures;
    $log(sprintf('seats: %d subscription(s) checked, %d %s, %d left for a person, %d error(s)', count($accountRows), $changed,
        $seatDry || !SeatSync::pushEnabled() ? 'would change' : 'corrected', $manual, $seatFailures));
}

// ── 2. Invoices ─────────────────────────────────────────────────────────
if ($step('invoices')) {
    $accountFilter = $onlyAccount !== null ? ' AND account_id = ' . (int) $onlyAccount : '';
    $handled = []; // rows this run already worked on: the retry loop skips them
    $alertLog = static fn (bool $sent): string => $sent ? 'operator alerted' : 'operator NOT alerted (mail failed or no ADMIN_EMAIL; next run tries again)';

    $stuck = $pdo->query(
        "SELECT id FROM invoices
         WHERE  status = 'sending'
           AND  last_attempt_at < NOW() - INTERVAL " . InvoiceIssuer::STUCK_SENDING_MINUTES . " MINUTE" . $accountFilter
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stuck as $id) {
        $id = (int) $id;
        $handled[$id] = true;
        $msg = 'Odosielanie faktúry do iDokladu sa prerušilo (riadok ostal v stave „sending"). Pred „Vystaviť znova" over v iDoklade, či faktúra nevznikla.';
        if ($dryRun) {
            $log("invoice row=$id stuck in sending — would hold for review");
            continue;
        }
        $pdo->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'uncertain_send', error_message = ? WHERE id = ? AND status = 'sending'")
            ->execute([$msg, $id]);
        $log("invoice row=$id stuck in sending — held for review, " . $alertLog(InvoiceAlert::notify($id, $msg)));
    }

    // Rows without the Stripe invoice snapshot: the old code's leftovers.
    $noSnapshot = $pdo->query(
        "SELECT id, status FROM invoices
         WHERE  (stripe_invoice_json IS NULL OR stripe_invoice_json = '')
           AND  ((status IN ('pending', 'error') AND created_at < NOW() - INTERVAL 10 MINUTE)
                 OR (status = 'needs_review' AND review_reason = 'missing_snapshot'))" . $accountFilter . '
         ORDER  BY id'
    )->fetchAll();
    foreach ($noSnapshot as $row) {
        $id = (int) $row['id'];
        $handled[$id] = true;
        if ($dryRun) {
            $log("invoice row=$id {$row['status']} without the Stripe snapshot — would rebuild it and hold it for review");
            continue;
        }
        $status = InvoiceIssuer::recoverSnapshot($id);
        $log("invoice row=$id {$row['status']} without the Stripe snapshot → $status");
    }

    // Held rows whose alert never went out (a failed mail, a row of an
    // older version): one alert each.
    $unalerted = $pdo->query(
        "SELECT id, review_reason, error_message FROM invoices
         WHERE  status = 'needs_review' AND operator_alerted_at IS NULL" . $accountFilter . '
         ORDER  BY id'
    )->fetchAll();
    $unalertedCount = 0;
    foreach ($unalerted as $row) {
        $id = (int) $row['id'];
        if (isset($handled[$id])) {
            continue;
        }
        $unalertedCount++;
        if ($dryRun) {
            $log("invoice row=$id held ({$row['review_reason']}) without an operator alert — would alert");
            continue;
        }
        $sent = InvoiceAlert::notify($id, (string) ($row['error_message'] ?: 'Faktúra čaká na kontrolu.'));
        $log("invoice row=$id held ({$row['review_reason']}) — " . $alertLog($sent));
    }

    // Real payments whose invoice was only prepared, or skipped because
    // iDoklad had no credentials. Claimed only from that status.
    $policy = IssuePolicy::decide(true);
    $issuer = new InvoiceIssuer();
    $liveLeft = $pdo->query(
        "SELECT id, status, operator_alerted_at FROM invoices
         WHERE  status IN ('prepared', 'skipped') AND stripe_livemode = 1" . $accountFilter . '
         ORDER  BY id'
    )->fetchAll();
    foreach ($liveLeft as $row) {
        $id = (int) $row['id'];
        $handled[$id] = true;
        $from = (string) $row['status'];
        $canSend = $policy['send'] && ($from === 'prepared' || IDokladClient::isConfigured());
        if ($dryRun) {
            $log("invoice row=$id LIVE payment $from — " . ($canSend ? 'would issue' : 'would alert the operator'));
            continue;
        }
        if ($canSend) {
            $status = $issuer->issue($id, true, [$from]);
            $log("invoice row=$id LIVE payment $from → $status");
            if (!in_array($status, ['issued'], true)) {
                $failures++;
            }
            continue;
        }
        $failures++;
        $why = $from === 'skipped' ? 'iDoklad nie je nastavený' : $policy['reason'];
        $line = "invoice row=$id ERROR: LIVE payment $from — $why";
        if ($row['operator_alerted_at'] === null) {
            $line .= ', ' . $alertLog(InvoiceAlert::notify($id, 'Ostrá platba nemá faktúru v iDoklade (' . $why . ').'));
        }
        $log($line);
    }

    $retry = $pdo->prepare(
        "SELECT id, status, attempts FROM invoices
         WHERE  ((status = 'pending' AND created_at < NOW() - INTERVAL 10 MINUTE)
                 OR (status = 'error' AND attempts < ?))
           AND  stripe_invoice_json IS NOT NULL AND stripe_invoice_json <> ''" . $accountFilter . '
         ORDER  BY id'
    );
    $retry->execute([InvoiceIssuer::MAX_AUTO_ATTEMPTS]);
    $rows = array_values(array_filter($retry->fetchAll(), static fn (array $r): bool => !isset($handled[(int) $r['id']])));
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if ($dryRun) {
            $log("invoice row=$id {$row['status']} (attempts {$row['attempts']}) — would issue");
            continue;
        }
        try {
            $status = $issuer->issue($id);
            $log("invoice row=$id {$row['status']} → $status");
            if ($status === 'error') {
                $failures++;
            }
        } catch (\Throwable $e) {
            $failures++;
            $log("invoice row=$id ERROR " . $e->getMessage());
        }
    }
    $log(sprintf('invoices: %d stuck, %d without snapshot, %d held without alert, %d live prepared/skipped, %d retried',
        count($stuck), count($noSnapshot), $unalertedCount, count($liveLeft), count($rows)));
}

if (!$partial && !$refused) {
    $setting(ConfigCheck::DAILY_RUN_KEY, date('Y-m-d H:i:s'));
} else {
    $log('last-run stamp not written (' . implode(', ', array_filter([
        $dryRun ? 'dry run' : '', $only !== '' ? "--only=$only" : '', $onlyAccount !== null ? "--account=$onlyAccount" : '', $refused ? 'Stripe writes refused' : '',
    ])) . ')');
}
$log("billing-daily done, $failures failure(s)");
exit($refused ? 2 : ($failures > 0 ? 1 : 0));
