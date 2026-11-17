<?php

declare(strict_types=1);

namespace Firol\Idoklad;

use Firol\Auth\Admin;
use Firol\Db;
use Firol\Mail\Mailer;
use Firol\Mail\Templates\InvoiceOperatorAlertEmail;

/**
 * Tells the operator (every address in ADMIN_EMAIL) that an invoice needs a
 * person: it ended in `error` after the last automatic attempt, a manual
 * „Vystaviť znova" failed, or iDoklad gave no definite answer to the POST.
 * Remembers when it alerted, so the daily command does not repeat itself.
 */
final class InvoiceAlert
{
    public static function notify(int $rowId, string $reason): void
    {
        try {
            $stmt = Db::pdo()->prepare(
                'SELECT i.id, i.account_id, i.stripe_invoice_id, i.status, i.attempts,
                        i.stripe_total_cents, i.stripe_amount_paid_cents, i.currency,
                        a.invoice_company_name
                 FROM   invoices i
                 JOIN   accounts a ON a.id = i.account_id
                 WHERE  i.id = ?'
            );
            $stmt->execute([$rowId]);
            $row = $stmt->fetch();
            if (!$row) {
                return;
            }
            $sent = false;
            foreach (Admin::envSeedEmails() as $to) {
                $sent = Mailer::send(InvoiceOperatorAlertEmail::build($to, $row, $reason)) || $sent;
            }
            if ($sent) {
                Db::pdo()->prepare('UPDATE invoices SET operator_alerted_at = NOW() WHERE id = ?')->execute([$rowId]);
            } else {
                error_log("[idoklad.alert] row=$rowId not mailed (no ADMIN_EMAIL or mail failed): $reason");
            }
        } catch (\Throwable $e) {
            error_log("[idoklad.alert] row=$rowId " . $e->getMessage());
        }
    }
}
