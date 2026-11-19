<?php

declare(strict_types=1);

namespace Firol\Idoklad;

use Firol\Auth\Admin;
use Firol\Db;
use Firol\Mail\Mailer;
use Firol\Mail\Templates\InvoiceOperatorAlertEmail;

/**
 * Tells the operator (every address in ADMIN_EMAIL) that a Stripe payment's
 * iDoklad invoice needs a person: it ended in `error` after the last
 * automatic attempt, a manual „Vystaviť znova" failed, iDoklad gave no
 * definite answer to the POST, the invoice is held for review (a mismatch, a
 * Stripe invoice that cannot be read), a live payment was only prepared /
 * skipped, or marking the invoice paid / sending it to the customer failed.
 *
 * Once per row AND state: the state a row was alerted for is stored with the
 * time (operator_alerted_at, operator_alerted_state — only when a mail really
 * went out, so the daily run repeats a failed one), and a row that moves to
 * another state needing a person (prepared → skipped, error → uncertain_send,
 * send error → send uncertain) is alerted again. A row of a Stripe TEST-mode
 * payment says so in the subject.
 */
final class InvoiceAlert
{
    /**
     * Alert about a row. $state defaults to the row's issue state
     * (stateOf()); the follow-up steps pass their own („send:error", …).
     */
    public static function notify(int $rowId, string $reason, ?string $state = null): bool
    {
        try {
            $row = self::load($rowId);
            if ($row === null) {
                return false;
            }
            $state ??= self::stateOf($row);
            $sent = false;
            foreach (Admin::envSeedEmails() as $to) {
                $sent = Mailer::send(InvoiceOperatorAlertEmail::build($to, $row, $reason, self::action($row, $state))) || $sent;
            }
            if ($sent) {
                Db::pdo()->prepare('UPDATE invoices SET operator_alerted_at = NOW(), operator_alerted_state = ? WHERE id = ?')
                    ->execute([$state, $rowId]);
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
     * Alert unless the operator was already alerted about this row in this
     * state. Returns 'sent', 'already' or 'failed'.
     */
    public static function notifyOnce(int $rowId, string $reason, ?string $state = null): string
    {
        $row = self::load($rowId);
        if ($row === null) {
            return 'failed';
        }
        $state ??= self::stateOf($row);
        if (self::alertedFor($row, $state)) {
            return 'already';
        }
        return self::notify($rowId, $reason, $state) ? 'sent' : 'failed';
    }

    /**
     * The issue state of a row as the alert memory stores it: status, and
     * the review reason for a held row („needs_review/uncertain_send").
     *
     * @param array<string, mixed> $row
     */
    public static function stateOf(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        $reason = (string) ($row['review_reason'] ?? '');
        return $reason !== '' ? "$status/$reason" : $status;
    }

    /** @param array<string, mixed> $row */
    public static function alertedFor(array $row, string $state): bool
    {
        return ($row['operator_alerted_at'] ?? null) !== null && (string) ($row['operator_alerted_state'] ?? '') === $state;
    }

    /** @return array<string, mixed>|null */
    private static function load(int $rowId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.id, i.account_id, i.stripe_invoice_id, i.status, i.review_reason, i.attempts,
                    i.stripe_total_cents, i.stripe_amount_paid_cents, i.stripe_tax_cents, i.stripe_livemode, i.currency,
                    i.operator_alerted_at, i.operator_alerted_state, i.document_number,
                    a.invoice_company_name
             FROM   invoices i
             JOIN   accounts a ON a.id = i.account_id
             WHERE  i.id = ?'
        );
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * What the operator can do about the row — only what can work in its
     * state (a prepared row cannot be issued while the mode is `prepare`, a
     * mismatch has no button).
     *
     * @param array<string, mixed> $row invoices row (status, review_reason)
     */
    public static function action(array $row, ?string $state = null): string
    {
        $status = (string) ($row['status'] ?? '');
        $reason = (string) ($row['review_reason'] ?? '');
        $state ??= self::stateOf($row);
        return match (true) {
            $state === 'send:uncertain'
                => 'iDoklad na odoslanie faktúry zákazníkovi neodpovedal jednoznačne. Over v iDoklade (história e-mailov dokladu), či e-mail odišiel; ak nie, pošli ju v administrácii (Účty → faktúry účtu) tlačidlom „Poslať znova".',
            str_starts_with($state, 'send:')
                => 'Faktúra je vystavená, ale zákazníkovi sa neodoslala. Skontroluj e-mail kontaktu v iDoklade a pošli ju v administrácii (Účty → faktúry účtu) tlačidlom „Poslať znova".',
            str_starts_with($state, 'paid:')
                => 'Faktúra je vystavená, ale v iDoklade nie je označená ako uhradená. Označ ju v iDoklade ručne (dátum úhrady = dátum platby v Stripe) alebo v administrácii „Poslať znova" zopakuje aj označenie.',
            $status === 'prepared' && IssuePolicy::mode() !== 'send'
                => 'Najprv nastav IDOKLAD_ISSUE_MODE=send — denná kontrola ju potom vystaví sama (alebo „Vystaviť" v administrácii, Účty → faktúry účtu).',
            $status === 'prepared'
                => 'Denná kontrola ju vystaví sama, alebo ju vystav v administrácii (Účty → faktúry účtu) tlačidlom „Vystaviť".',
            $status === 'skipped'
                => 'Najprv nastav prístup do iDokladu (IDOKLAD_CLIENT_ID / IDOKLAD_CLIENT_SECRET) — denná kontrola ju potom vystaví sama.',
            $status === 'needs_review' && $reason === 'uncertain_send'
                => 'Najprv over v iDoklade, či faktúra nevznikla; ak nie, vystav ju v administrácii (Účty → faktúry účtu) tlačidlom „Vystaviť znova".',
            $status === 'needs_review' && in_array($reason, ['mismatch', 'vat_mismatch'], true)
                => 'Automaticky sa nevystaví: sumy nesedia. Údaje zo Stripe sú v administrácii (Účty → faktúry účtu); faktúru vystav v iDoklade ručne.',
            $status === 'needs_review' && $reason === 'zero'
                => 'Nič sa nevystavuje. Ak ju stará verzia aplikácie vystavila v iDoklade, oprav ju tam.',
            $status === 'needs_review' && $reason === 'missing_snapshot'
                => 'Denná kontrola ju znova načíta zo Stripe. Najprv over v iDoklade, či faktúra už neexistuje.',
            $status === 'needs_review' && $reason === 'snapshot_unreadable'
                => 'Faktúru zo Stripe sa nepodarilo načítať ani opakovane — denná kontrola to už neskúša. Over platbu v Stripe a v iDoklade a vyrieš ju ručne.',
            $status === 'error' && (int) ($row['attempts'] ?? 0) >= InvoiceIssuer::MAX_AUTO_ATTEMPTS
                => 'Automatické pokusy sa minuli — treba zásah. Po odstránení príčiny ju vystav v administrácii (Účty → faktúry účtu) tlačidlom „Vystaviť znova".',
            default
                => 'V administrácii (Účty → faktúry účtu) ju po odstránení príčiny vystavíš tlačidlom „Vystaviť znova".',
        };
    }
}
