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
 *     result.
 *  1. Seats: for every account with a subscription that is not over, the
 *     subscription's status, period end and cancel flag are written from
 *     Stripe (repairs a state left stale by a lost event), then the wanted
 *     extra-technician quantity is pushed to Stripe (SeatSync::reconcile) and
 *     duplicate extra items merged. With BILLING_RECONCILE=off nothing is
 *     pushed: the differences are only listed. A subscription with an item
 *     nobody can vouch for is left for a person (listed as MANUAL).
 *  2. Invoices:
 *       - a row stuck in `sending` (a crash during the iDoklad POST) goes to
 *         `needs_review` (`uncertain_send`) and the operator is alerted —
 *         it is never re-sent blindly;
 *       - a row without the Stripe invoice snapshot (left by the code before
 *         17. 11. 2026, or a rebuild that failed) is rebuilt from Stripe and
 *         held for a person to check iDoklad first;
 *       - a `prepared` row of a LIVE Stripe invoice (a real payment without a
 *         tax document) is issued when IssuePolicy now allows sending, else
 *         the operator is alerted once;
 *       - `pending` rows older than 10 minutes and `error` rows below
 *         InvoiceIssuer::MAX_AUTO_ATTEMPTS are issued again (IssuePolicy
 *         still decides whether anything is really sent). The last failed
 *         attempt alerts the operator.
 *  3. Stamps system_settings.billing_daily_last_run (the admin check warns
 *     when it is older than 36 hours).
 *
 * With a LIVE Stripe key the run refuses unless APP_ENV=production or
 * --allow-live is given: on a developer machine with a copy of production
 * data it would push the local roster to real customers.
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
$isProduction = strtolower(trim((string) ($_ENV['APP_ENV'] ?? ''))) === 'production';
if (StripeClient::isConfigured() && !StripeClient::isTestKey() && !$isProduction && !isset($options['allow-live'])) {
    fwrite(STDERR, "Refusing: STRIPE_SECRET_KEY is a LIVE key and APP_ENV is not 'production'. This run would push the "
        . "local roster to real customers. Pass --allow-live if that is really meant.\n");
    exit(2);
}
$step = static fn (string $name): bool => $only === '' || $only === $name;

$pdo = Db::pdo();
$failures = 0;
$log = static function (string $line): void {
    echo date('Y-m-d H:i:s'), ' ', $line, "\n";
};

$log('billing-daily start' . ($dryRun ? ' (dry run)' : '') . (SeatSync::pushEnabled() ? '' : ' — BILLING_RECONCILE=off, seats report only'));
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
        if ($dryRun) {
            $log("event $id ({$ev['event_type']}) {$ev['status']} after {$ev['attempts']} attempt(s) — would re-run: {$ev['error_message']}");
            continue;
        }
        $result = BillingController::reprocessEvent($id);
        $log("event $id ({$ev['event_type']}) {$ev['status']} → $result");
        if (str_starts_with($result, 'failed')) {
            $failures++;
        }
        if ($ev['operator_alerted_at'] === null) {
            $sent = OperatorAlert::send(
                'udalosť zo Stripe sa nespracovala',
                'Webhook zo Stripe sa pri doručení nespracoval. Denná kontrola ho načítala zo Stripe a spustila znova.',
                [
                    "Udalosť: $id ({$ev['event_type']})",
                    "Stav pred dennou kontrolou: {$ev['status']}, pokusov: {$ev['attempts']}",
                    'Chyba: ' . ((string) $ev['error_message'] !== '' ? $ev['error_message'] : '—'),
                    'Výsledok opakovania: ' . ($result === 'done' ? 'spracovaná' : $result),
                ],
            );
            if ($sent) {
                $pdo->prepare('UPDATE stripe_webhook_events SET operator_alerted_at = NOW() WHERE event_id = ?')->execute([$id]);
            }
        }
    }
    $log(sprintf('events: %d re-run', count($events)));
}

// ── 1. Seats ────────────────────────────────────────────────────────────
if ($step('seats')) {
    $sql = "SELECT id FROM accounts
            WHERE  stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''
              AND  (stripe_status IS NULL OR stripe_status NOT IN ('canceled', 'incomplete_expired'))";
    $params = [];
    if ($onlyAccount !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlyAccount;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY id');
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $changed = 0;
    $manual  = 0;
    $seatFailures = 0;
    foreach ($ids as $accountId) {
        try {
            $state = BillingController::syncSubscriptionState($accountId, $dryRun);
            if ($state !== null) {
                $log(sprintf('subscription account=%d %s%s', $accountId, $dryRun ? 'would write ' : '', $state));
            }
            $r = SeatSync::reconcile($accountId, $dryRun);
            if ($r['action'] === 'manual') {
                $manual++;
                $log(sprintf('seats account=%d MANUAL (wanted=%d stripe=%d): %s', $accountId, $r['wanted'], $r['before'], implode('; ', $r['notes'])));
            } elseif ($r['action'] !== 'none') {
                $changed++;
                $log(sprintf('seats account=%d wanted=%d stripe=%d %s: %s', $accountId, $r['wanted'], $r['before'], $r['action'], implode('; ', $r['notes'])));
            } elseif ($r['notes'] !== []) {
                $log(sprintf('seats account=%d ok (wanted=%d): %s', $accountId, $r['wanted'], implode('; ', $r['notes'])));
            }
        } catch (\Throwable $e) {
            $seatFailures++;
            $log("seats account=$accountId ERROR " . $e->getMessage());
        }
    }
    $failures += $seatFailures;
    $log(sprintf('seats: %d subscription(s) checked, %d %s, %d left for a person, %d error(s)', count($ids), $changed,
        $dryRun || !SeatSync::pushEnabled() ? 'would change' : 'corrected', $manual, $seatFailures));
}

// ── 2. Invoices ─────────────────────────────────────────────────────────
if ($step('invoices')) {
    $accountFilter = $onlyAccount !== null ? ' AND account_id = ' . (int) $onlyAccount : '';

    $stuck = $pdo->query(
        "SELECT id FROM invoices
         WHERE  status = 'sending'
           AND  last_attempt_at < NOW() - INTERVAL " . InvoiceIssuer::STUCK_SENDING_MINUTES . " MINUTE" . $accountFilter
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stuck as $id) {
        $id = (int) $id;
        $msg = 'The iDoklad POST was interrupted (row left in „sending"). Check in iDoklad whether the invoice exists before „Vystaviť znova".';
        if ($dryRun) {
            $log("invoice row=$id stuck in sending — would hold for review");
            continue;
        }
        $pdo->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'uncertain_send', error_message = ? WHERE id = ? AND status = 'sending'")
            ->execute([$msg, $id]);
        InvoiceAlert::notify($id, $msg);
        $log("invoice row=$id stuck in sending — held for review, operator alerted");
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
        if ($dryRun) {
            $log("invoice row=$id {$row['status']} without the Stripe snapshot — would rebuild it and hold it for review");
            continue;
        }
        $status = InvoiceIssuer::recoverSnapshot($id);
        $log("invoice row=$id {$row['status']} without the Stripe snapshot → $status");
    }

    // Real payments whose invoice was only prepared.
    $preparedLive = $pdo->query(
        "SELECT id, operator_alerted_at FROM invoices
         WHERE  status = 'prepared' AND stripe_livemode = 1" . $accountFilter . '
         ORDER  BY id'
    )->fetchAll();
    $policy = IssuePolicy::decide(true);
    $issuer = new InvoiceIssuer();
    foreach ($preparedLive as $row) {
        $id = (int) $row['id'];
        if ($dryRun) {
            $log("invoice row=$id LIVE payment only prepared — " . ($policy['send'] ? 'would issue' : 'would alert the operator'));
            continue;
        }
        if ($policy['send']) {
            $status = $issuer->issue($id, true);
            $log("invoice row=$id LIVE payment only prepared → $status");
            if ($status === 'error') {
                $failures++;
            }
            continue;
        }
        $failures++;
        $log("invoice row=$id ERROR: LIVE payment only prepared — " . $policy['reason']);
        if ($row['operator_alerted_at'] === null) {
            InvoiceAlert::notify($id, 'Ostrá platba dostala len pripravenú faktúru, do iDokladu sa nič neodoslalo (' . $policy['reason']
                . '). Nastav IDOKLAD_ISSUE_MODE=send — denná kontrola ju potom vystaví — alebo ju vystav v administrácii tlačidlom „Vystaviť".');
        }
    }

    $retry = $pdo->prepare(
        "SELECT id, status, attempts FROM invoices
         WHERE  ((status = 'pending' AND created_at < NOW() - INTERVAL 10 MINUTE)
                 OR (status = 'error' AND attempts < ?))
           AND  stripe_invoice_json IS NOT NULL AND stripe_invoice_json <> ''" . $accountFilter . '
         ORDER  BY id'
    );
    $retry->execute([InvoiceIssuer::MAX_AUTO_ATTEMPTS]);
    $rows = $retry->fetchAll();
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
    $log(sprintf('invoices: %d stuck, %d without snapshot, %d live prepared, %d retried',
        count($stuck), count($noSnapshot), count($preparedLive), count($rows)));
}

if (!$dryRun) {
    $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([ConfigCheck::DAILY_RUN_KEY, date('Y-m-d H:i:s')]);
}
$log("billing-daily done, $failures failure(s)");
exit($failures > 0 ? 1 : 0);
