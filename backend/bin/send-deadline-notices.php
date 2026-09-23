<?php

declare(strict_types=1);

/**
 * Automatic client notice of upcoming termíny — chapter 11.3. Run daily:
 *
 *     php /var/www/backend/bin/send-deadline-notices.php [--dry-run] [--today=YYYY-MM-DD]
 *
 * For every account that switched the notice on (it is OFF after install),
 * e-mails the client about each termín falling within the chosen number of
 * days (7 / 14 / 30):
 *
 *   - only when the company has a contact e-mail (`companies.contact_email`);
 *   - one message per prevádzka and day, listing every control due there —
 *     the same grouping and the same words as the manual button
 *     („Oznámiť klientovi e-mailom", lib/clientNoticeEmail.ts);
 *   - the day announced is the planned visit date when one is set, otherwise
 *     the deadline itself — again as the manual notice does;
 *   - never twice: each deadline is claimed in `deadline_notices` (unique per
 *     defining úkon) BEFORE the mail goes out, so two overlapping runs cannot
 *     both send it. A failed send releases the claim and is retried the next
 *     day; a run killed between claim and send loses that one notice rather
 *     than risking a duplicate.
 *
 * The window is "from today up to N days ahead", not "exactly N days ahead",
 * so a day the cron did not run is caught up the next day instead of being
 * skipped. Accounts whose subscription has ended send nothing.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Auth\Admin;
use Firol\Db;
use Firol\Mail\Mailer;
use Firol\Mail\ReplyTo;
use Firol\Mail\Templates\DeadlineNoticeEmail;
use Firol\Support\Deadlines;

$options = getopt('', ['dry-run', 'today:']);
$dryRun  = isset($options['dry-run']);
$today   = isset($options['today']) && is_string($options['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['today'])
    ? $options['today']
    : date('Y-m-d');

$pdo = Db::pdo();

$accounts = $pdo->query(
    'SELECT id, client_notice_days, subscription_end_date, main_user_id
     FROM   accounts WHERE client_notice_auto = 1'
)->fetchAll(PDO::FETCH_ASSOC);

$claim = $pdo->prepare(
    'INSERT IGNORE INTO deadline_notices (account_id, inspection_id, notice_date, recipient)
     VALUES (?, ?, ?, ?)'
);
$release = $pdo->prepare('DELETE FROM deadline_notices WHERE inspection_id = ? AND account_id = ?');
$senderStmt = $pdo->prepare('SELECT fullname, phone FROM users WHERE id = ?');

$sent = 0;
$failed = 0;

foreach ($accounts as $account) {
    $accountId = (int) $account['id'];
    $days = (int) $account['client_notice_days'];
    $until = (new DateTimeImmutable($today))->modify('+' . $days . ' days')->format('Y-m-d');

    $expired = (string) $account['subscription_end_date'] < $today
        && !Admin::isAdmin((int) $account['main_user_id']);
    if ($expired) {
        printf("account %d: subscription ended, skipped\n", $accountId);
        continue;
    }

    // Group the open, not yet announced deadlines of the window by
    // prevádzka + announced day.
    $groups = [];
    foreach (Deadlines::compute($accountId, false, $today) as $d) {
        if ($d['company_email'] === null || $d['notice_sent_at'] !== null) {
            continue;
        }
        $day = $d['planned_date'] ?? $d['due_date'];
        if ($day < $today || $day > $until) {
            continue;
        }
        $groups[$d['facility_id'] . '@' . $day][] = $d;
    }

    foreach ($groups as $group) {
        $first = $group[0];
        $day = $first['planned_date'] ?? $first['due_date'];
        $to = (string) $first['company_email'];

        if ($dryRun) {
            printf(
                "[dry-run] account %d: %s — %s, %s → %s (%s)\n",
                $accountId,
                $first['company_name'],
                $first['facility_name'],
                $day,
                $to,
                implode(', ', array_map(static fn (array $d): string => $d['type'], $group)),
            );
            continue;
        }

        // Claim first; send only what this run actually claimed.
        $claimed = [];
        foreach ($group as $d) {
            $claim->execute([$accountId, $d['inspection_id'], $day, $to]);
            if ($claim->rowCount() === 1) {
                $claimed[] = $d;
            }
        }
        if ($claimed === []) {
            continue;
        }

        // Signed by the technician responsible for the termín (the one who
        // performed the úkon defining it); replies go to them, falling back
        // to the main user when they are no longer on the team.
        $technicianId = $claimed[0]['technician']['id'] ?? (int) $account['main_user_id'];
        $senderStmt->execute([$technicianId]);
        $sender = $senderStmt->fetch(PDO::FETCH_ASSOC) ?: ['fullname' => '', 'phone' => null];

        $ok = Mailer::send(DeadlineNoticeEmail::build(
            $to,
            $day,
            array_map(static fn (array $d): string => $d['type'], $claimed),
            (string) $sender['fullname'],
            $sender['phone'] !== null ? (string) $sender['phone'] : null,
            ReplyTo::forSender($accountId, (int) $technicianId),
        ));

        if ($ok) {
            $sent++;
            printf(
                "account %d: notice for %s — %s on %s sent to %s\n",
                $accountId,
                $first['company_name'],
                $first['facility_name'],
                $day,
                $to,
            );
        } else {
            $failed++;
            foreach ($claimed as $d) {
                $release->execute([$d['inspection_id'], $accountId]);
            }
            fwrite(STDERR, sprintf(
                "[deadline-notices] account %d: sending to %s failed, will retry\n",
                $accountId,
                $to,
            ));
        }
    }
}

printf("Done. %d notice(s) sent, %d failed.\n", $sent, $failed);
