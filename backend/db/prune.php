<?php

declare(strict_types=1);

/**
 * Nightly retention job. Run with:
 *
 *     php /var/www/backend/db/prune.php
 *
 * Enforces the 12-month log retention published in the privacy policy
 * (Zásady ochrany osobných údajov, čl. 4.1 — change request 3.1). Without this
 * the error log and the audit trail would grow without bound, and the
 * statement in the policy would not be true.
 *
 * What it touches:
 *   - audit_log            — rows older than RETENTION_DAYS are deleted.
 *   - storage/php_errors.log — rotated to php_errors-YYYY-MM.log on the first
 *                            of each month; archives older than RETENTION_DAYS
 *                            are deleted.
 *   - storage/ratelimit    — stale buckets left behind by abandoned sessions.
 *
 * Deliberately NOT touched: generated PDFs, photos, signatures and any other
 * user content. Those follow the subscription lifecycle (VOP čl. 6.3) and are
 * removed through the account/data purge paths, never on a timer.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Db;
use Firol\Storage\Storage;

const RETENTION_DAYS = 365;

$cutoff = (new DateTimeImmutable('-' . RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s');

// ── Audit trail ─────────────────────────────────────────────────────────────

try {
    $stmt = Db::pdo()->prepare('DELETE FROM audit_log WHERE created_at < ?');
    $stmt->execute([$cutoff]);
    printf("audit_log: deleted %d row(s) older than %s\n", $stmt->rowCount(), $cutoff);
} catch (Throwable $e) {
    fwrite(STDERR, '[prune] audit_log: ' . $e->getMessage() . "\n");
}

// ── Error log rotation ──────────────────────────────────────────────────────

$storage = Storage::root();
$live    = $storage . '/php_errors.log';

// Rotate once a month so an archive covers a whole calendar month and its
// mtime is a fair proxy for "when these entries stopped being written".
if (date('d') === '01' && is_file($live) && filesize($live) > 0) {
    $archive = sprintf('%s/php_errors-%s.log', $storage, (new DateTimeImmutable('-1 day'))->format('Y-m'));
    if (!is_file($archive) && @rename($live, $archive)) {
        printf("php_errors.log: rotated to %s\n", basename($archive));
    }
}

$removed = 0;
foreach (glob($storage . '/php_errors-*.log') ?: [] as $archive) {
    if (filemtime($archive) < strtotime('-' . RETENTION_DAYS . ' days')) {
        if (@unlink($archive)) {
            $removed++;
        }
    }
}
printf("php_errors archives: removed %d\n", $removed);

// ── Rate-limit buckets ──────────────────────────────────────────────────────

// These are short-lived by design; anything a day old is dead weight.
$rateDir = $storage . '/ratelimit';
$stale   = 0;
if (is_dir($rateDir)) {
    foreach (glob($rateDir . '/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < strtotime('-1 day')) {
            if (@unlink($file)) {
                $stale++;
            }
        }
    }
}
printf("ratelimit: removed %d stale bucket(s)\n", $stale);

echo "Done.\n";
