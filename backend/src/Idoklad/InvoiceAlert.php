<?php

declare(strict_types=1);

namespace Firol\Idoklad;

use Firol\Auth\Admin;
use Firol\Db;
use Firol\Mail\Mailer;
use Firol\Mail\Templates\InvoiceOperatorAlertEmail;

/**
 * Tells the operator (every address in ADMIN_EMAIL) that a Stripe payment has
 * no iDoklad invoice and needs a person: it ended in `error` after the last
 * automatic attempt, a manual „Vystaviť znova" failed, iDoklad gave no
 * definite answer to the POST, the invoice is held for review (a credit, a
 * mismatch), or a live payment was only prepared / skipped.
 *
 * Remembers when it alerted (operator_alerted_at) only when a mail really
 * went out, so the daily run repeats a failed one. Returns whether it did.
 */
final class InvoiceAlert
{
    public static function notify(int $rowId, string $reason): bool
    {
        try {
            $stmt = Db::pdo()->prepare(
                'SELECT i.id, i.account_id, i.stripe_invoice_id, i.status, i.review_reason, i.attempts,
                        i.stripe_total_cents, i.stripe_amount_paid_cents, i.currency,
                        a.invoice_company_name
                 FROM   invoices i
                 JOIN   accounts a ON a.id = i.account_id
                 WHERE  i.id = ?'
            );
            $stmt->execute([$rowId]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }
            $sent = false;
            foreach (Admin::envSeedEmails() as $to) {
                $sent = Mailer::send(InvoiceOperatorAlertEmail::build($to, $row, $reason, self::action($row))) || $sent;
            }
            if ($sent) {
                Db::pdo()->prepare('UPDATE invoices SET operator_alerted_at = NOW() WHERE id = ?')->execute([$rowId]);
            } else {
                error_log("[idoklad.alert] row=$rowId not mailed (no ADMIN_EMAIL or mail failed): $reason");
            }
            return $sent;
        } catch (\Throwable $e) {
            error_log("[idoklad.alert] row=$rowId " . $e->getMessage());
            return false;
        }
    }

    /**
     * What the operator can do about the row — only what can work in its
     * state (a prepared row cannot be issued while the mode is `prepare`, a
     * credit has no button until the owner decides how credits are invoiced).
     *
     * @param array<string, mixed> $row invoices row (status, review_reason)
     */
    public static function action(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        $reason = (string) ($row['review_reason'] ?? '');
        return match (true) {
            $status === 'prepared' && IssuePolicy::mode() !== 'send'
                => 'Najprv nastav IDOKLAD_ISSUE_MODE=send — denná kontrola ju potom vystaví sama (alebo „Vystaviť" v administrácii, Účty → faktúry účtu).',
            $status === 'prepared'
                => 'Denná kontrola ju vystaví sama, alebo ju vystav v administrácii (Účty → faktúry účtu) tlačidlom „Vystaviť".',
            $status === 'skipped'
                => 'Najprv nastav prístup do iDokladu (IDOKLAD_CLIENT_ID / IDOKLAD_CLIENT_SECRET) — denná kontrola ju potom vystaví sama.',
            $status === 'needs_review' && $reason === 'uncertain_send'
                => 'Najprv over v iDoklade, či faktúra nevznikla; ak nie, vystav ju v administrácii (Účty → faktúry účtu) tlačidlom „Vystaviť znova".',
            $status === 'needs_review' && in_array($reason, ['credit', 'mismatch', 'zero'], true)
                => 'Automaticky sa nevystaví: ako sa dobropisy a nezhody objavia na daňovom doklade, rozhoduje majiteľ. Údaje zo Stripe sú v administrácii (Účty → faktúry účtu).',
            $status === 'needs_review' && $reason === 'missing_snapshot'
                => 'Denná kontrola ju znova načíta zo Stripe. Najprv over v iDoklade, či faktúra už neexistuje.',
            default
                => 'V administrácii (Účty → faktúry účtu) ju po odstránení príčiny vystavíš tlačidlom „Vystaviť znova".',
        };
    }
}
