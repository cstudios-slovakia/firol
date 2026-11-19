<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Db;
use Firol\Mail\Mailer;
use Firol\Mail\Templates\PaymentFailedEmail;
use Firol\Stripe\StripeShape;

/**
 * A renewal whose payment failed (owner's decision of 18. 11. 2026): the
 * account stays fully usable for PAYMENT_GRACE_DAYS (default 14) from the
 * failed renewal, then it is read-only until it is paid.
 *
 * Stripe moves current_period_end a whole period ahead even when the renewal
 * payment fails (status past_due), so the paid-until date alone gave a free
 * period. Each failed invoice is recorded once (billing_payment_failures) —
 * from the invoice.payment_failed webhook, or from the subscription's latest
 * invoice when the app reads a past_due / unpaid subscription (webhook,
 * checkout-sync, daily run), so a lost event is caught. While the
 * subscription is past_due / unpaid and a failure is open,
 * subscription_end_date is capped at the end of the grace period
 * (graceEnd()) — the same mechanism as an ended subscription: from the next
 * day the server answers every write with 402 and the app shows read-only.
 * A payment resolves the failure; the next read of the subscription
 * (invoice.paid / customer.subscription.updated webhook, the daily run)
 * writes the real period end again, so access comes back at once.
 *
 * The main user gets one e-mail per failed invoice (amount, the date the
 * account becomes read-only, the link to pay); the banner is shown to every
 * member while a failure is open (AuthController /me: payment_failed).
 *
 * Trials, cancelled subscriptions and accounts without one are not affected:
 * only a past_due / unpaid subscription is capped.
 */
final class PaymentGrace
{
    public const DEFAULT_DAYS = 14;

    /** Statuses of a subscription whose renewal is unpaid. */
    public const UNPAID_STATUSES = ['past_due', 'unpaid'];

    /** PAYMENT_GRACE_DAYS (env), else 14. */
    public static function days(): int
    {
        $raw = trim((string) ($_ENV['PAYMENT_GRACE_DAYS'] ?? ''));
        return ctype_digit($raw) ? (int) $raw : self::DEFAULT_DAYS;
    }

    /**
     * Record a failed invoice (once per Stripe invoice). Returns true when it
     * is new. The grace period counts from the invoice's creation — the
     * renewal moment (a test clock's time on a test clock).
     *
     * @param array<string, mixed> $invoice
     */
    public static function recordFailure(int $accountId, array $invoice): bool
    {
        $id = (string) ($invoice['id'] ?? '');
        if ($id === '') {
            return false;
        }
        $created = (int) ($invoice['created'] ?? time());
        $stmt = Db::pdo()->prepare(
            'INSERT IGNORE INTO billing_payment_failures
                 (stripe_invoice_id, account_id, stripe_subscription_id, amount_due_cents, currency, hosted_invoice_url, livemode, failed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $accountId, StripeShape::invoiceSubscriptionId($invoice),
            (int) ($invoice['amount_due'] ?? 0), strtoupper((string) ($invoice['currency'] ?? 'eur')),
            isset($invoice['hosted_invoice_url']) ? (string) $invoice['hosted_invoice_url'] : null,
            isset($invoice['livemode']) ? ((bool) $invoice['livemode'] ? 1 : 0) : null,
            date('Y-m-d H:i:s', $created),
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * From a past_due / unpaid subscription read with its latest invoice
     * expanded (SeatSync::RETRIEVE_EXPAND): record that invoice when it is
     * open and Stripe already tried to charge it.
     *
     * @param array<string, mixed> $sub
     */
    public static function recordFromSubscription(int $accountId, array $sub): void
    {
        $inv = $sub['latest_invoice'] ?? null;
        if (!is_array($inv) || (string) ($inv['status'] ?? '') !== 'open' || (int) ($inv['attempt_count'] ?? 0) < 1) {
            return;
        }
        if (self::recordFailure($accountId, $inv)) {
            self::notifyAfterResponse();
        }
    }

    /** A failed invoice was paid (invoice.paid). */
    public static function resolveInvoice(string $stripeInvoiceId): void
    {
        Db::pdo()->prepare('UPDATE billing_payment_failures SET resolved_at = NOW() WHERE stripe_invoice_id = ? AND resolved_at IS NULL')
            ->execute([$stripeInvoiceId]);
    }

    /** The account's subscription is paid up again: every open failure is over. */
    public static function resolveAccount(int $accountId): void
    {
        Db::pdo()->prepare('UPDATE billing_payment_failures SET resolved_at = NOW() WHERE account_id = ? AND resolved_at IS NULL')
            ->execute([$accountId]);
    }

    /**
     * The account's open failure the grace period counts from: the earliest
     * one not resolved, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function openFailure(int $accountId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM billing_payment_failures WHERE account_id = ? AND resolved_at IS NULL ORDER BY failed_at ASC LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * The last day the account is fully usable (Y-m-d): the failure plus
     * PAYMENT_GRACE_DAYS. From the next day it is read-only.
     *
     * @param array<string, mixed> $failure billing_payment_failures row
     */
    public static function graceEnd(array $failure): string
    {
        return date('Y-m-d', (int) strtotime((string) $failure['failed_at']) + self::days() * 86400);
    }

    /** The first read-only day (Y-m-d). */
    public static function readOnlyFrom(array $failure): string
    {
        return date('Y-m-d', (int) strtotime(self::graceEnd($failure) . ' 12:00:00') + 86400);
    }

    /**
     * The paid-until date to store for a subscription: its period end, capped
     * at the end of the grace period while it is past_due / unpaid with an
     * open failure. Resolves the account's failures when it is paid
     * (active / trialing). Records the latest invoice as a failure when it
     * is open and was tried.
     *
     * @param array<string, mixed> $sub
     */
    public static function endDateFor(int $accountId, string $status, array $sub, ?string $periodEndDate): ?string
    {
        if (in_array($status, SeatSync::PAID_STATUSES, true)) {
            self::resolveAccount($accountId);
            return $periodEndDate;
        }
        if (!in_array($status, self::UNPAID_STATUSES, true)) {
            return $periodEndDate;
        }
        self::recordFromSubscription($accountId, $sub);
        $failure = self::openFailure($accountId);
        if ($failure === null) {
            return $periodEndDate;
        }
        $grace = self::graceEnd($failure);
        return $periodEndDate === null ? $grace : min($periodEndDate, $grace);
    }

    /**
     * What the screens show while a failure is open on a past_due / unpaid
     * subscription: amount, when it failed, the first read-only day.
     *
     * @return array{amount_cents: int, currency: string, failed_at: string, read_only_from: string}|null
     */
    public static function bannerFor(int $accountId, ?string $status): ?array
    {
        if (!in_array((string) $status, self::UNPAID_STATUSES, true)) {
            return null;
        }
        $f = self::openFailure($accountId);
        if ($f === null) {
            return null;
        }
        return [
            'amount_cents'   => (int) $f['amount_due_cents'],
            'currency'       => (string) $f['currency'],
            'failed_at'      => substr((string) $f['failed_at'], 0, 10),
            'read_only_from' => self::readOnlyFrom($f),
        ];
    }

    /** Send the e-mails of new failures after the response (webhook). */
    public static function notifyAfterResponse(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::sendPendingEmails();
            } catch (\Throwable $e) {
                error_log('[billing.payment-failed] ' . $e->getMessage());
            }
        });
    }

    /**
     * E-mail the main user about every open failure not e-mailed yet (one
     * e-mail per failed invoice; a failed send is repeated by the next run —
     * the webhook's or the daily one). Returns how many went out.
     */
    public static function sendPendingEmails(?int $accountId = null): int
    {
        $sql = 'SELECT f.*, u.email, a.invoice_company_name, a.stripe_status
                FROM   billing_payment_failures f
                JOIN   accounts a ON a.id = f.account_id
                JOIN   users u ON u.id = a.main_user_id
                WHERE  f.resolved_at IS NULL AND f.emailed_at IS NULL';
        $params = [];
        if ($accountId !== null) {
            $sql .= ' AND f.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql . ' ORDER BY f.failed_at');
        $stmt->execute($params);
        $sent = 0;
        foreach ($stmt->fetchAll() as $f) {
            if ((string) $f['email'] === '') {
                continue;
            }
            $ok = Mailer::send(PaymentFailedEmail::build(
                (string) $f['email'],
                (string) ($f['invoice_company_name'] ?? ''),
                (int) $f['amount_due_cents'],
                (string) $f['currency'],
                self::readOnlyFrom($f),
                isset($f['hosted_invoice_url']) ? (string) $f['hosted_invoice_url'] : null,
            ));
            if ($ok) {
                Db::pdo()->prepare('UPDATE billing_payment_failures SET emailed_at = NOW() WHERE stripe_invoice_id = ? AND emailed_at IS NULL')
                    ->execute([(string) $f['stripe_invoice_id']]);
                $sent++;
            } else {
                error_log('[billing.payment-failed] e-mail for ' . $f['stripe_invoice_id'] . ' not sent; the next run tries again');
            }
        }
        return $sent;
    }
}
