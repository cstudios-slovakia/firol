<?php

declare(strict_types=1);

/**
 * Daily billing run (cron, once a day):
 *
 *     php backend/bin/billing-daily.php [--dry-run] [--only=seats|invoices] [--account=ID]
 *
 *  1. Seats: for every account with a subscription that is not over, push the
 *     wanted extra-technician quantity to Stripe (SeatSync::reconcile) and
 *     merge duplicate extra items. Each correction is logged.
 *  2. Invoices:
 *       - a row stuck in `sending` (a crash during the iDoklad POST) goes to
 *         `needs_review` (`uncertain_send`) and the operator is alerted —
 *         it is never re-sent blindly;
 *       - `pending` rows older than 10 minutes and `error` rows below
 *         InvoiceIssuer::MAX_AUTO_ATTEMPTS are issued again (IssuePolicy
 *         still decides whether anything is really sent). The last failed
 *         attempt alerts the operator.
 *  3. Stamps system_settings.billing_daily_last_run (the admin check warns
 *     when it is older than 36 hours).
 *
 * --dry-run reads Stripe and lists what would change; it changes nothing in
 * Stripe, iDoklad or the database. Exit code 1 when anything failed.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Billing\ConfigCheck;
use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Idoklad\InvoiceAlert;
use Firol\Idoklad\InvoiceIssuer;

$options = getopt('', ['dry-run', 'only:', 'account:']);
$dryRun  = isset($options['dry-run']);
$only    = isset($options['only']) ? (string) $options['only'] : '';
if ($only !== '' && $only !== 'seats' && $only !== 'invoices') {
    fwrite(STDERR, "--only must be 'seats' or 'invoices'.\n");
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

$pdo = Db::pdo();
$failures = 0;
$log = static function (string $line): void {
    echo date('Y-m-d H:i:s'), ' ', $line, "\n";
};

$log('billing-daily start' . ($dryRun ? ' (dry run)' : ''));
foreach (ConfigCheck::run() as $check) {
    if ($check['level'] !== 'ok') {
        $log(strtoupper($check['level']) . ' config ' . $check['key'] . ': ' . $check['message']);
    }
}

// ── 1. Seats ────────────────────────────────────────────────────────────
if ($only === '' || $only === 'seats') {
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
    foreach ($ids as $accountId) {
        try {
            $r = SeatSync::reconcile($accountId, $dryRun);
            if ($r['action'] !== 'none') {
                $changed++;
                $log(sprintf('seats account=%d wanted=%d stripe=%d %s: %s', $accountId, $r['wanted'], $r['before'], $r['action'], implode('; ', $r['notes'])));
            } elseif ($r['notes'] !== []) {
                $log(sprintf('seats account=%d ok (wanted=%d): %s', $accountId, $r['wanted'], implode('; ', $r['notes'])));
            }
        } catch (\Throwable $e) {
            $failures++;
            $log("seats account=$accountId ERROR " . $e->getMessage());
        }
    }
    $log(sprintf('seats: %d subscription(s) checked, %d %s, %d error(s)', count($ids), $changed, $dryRun ? 'would change' : 'corrected', $failures));
}

// ── 2. Invoices ─────────────────────────────────────────────────────────
if ($only === '' || $only === 'invoices') {
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

    $retry = $pdo->prepare(
        "SELECT id, status, attempts FROM invoices
         WHERE  ((status = 'pending' AND created_at < NOW() - INTERVAL 10 MINUTE)
                 OR (status = 'error' AND attempts < ?))" . $accountFilter . '
         ORDER  BY id'
    );
    $retry->execute([InvoiceIssuer::MAX_AUTO_ATTEMPTS]);
    $rows = $retry->fetchAll();
    $issuer = new InvoiceIssuer();
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
    $log(sprintf('invoices: %d stuck, %d retried', count($stuck), count($rows)));
}

if (!$dryRun) {
    $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([ConfigCheck::DAILY_RUN_KEY, date('Y-m-d H:i:s')]);
}
$log("billing-daily done, $failures failure(s)");
exit($failures > 0 ? 1 : 0);
