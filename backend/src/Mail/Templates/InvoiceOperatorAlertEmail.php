<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Http\Url;
use Firol\Mail\Message;

/**
 * Operator alert: an iDoklad invoice for a Stripe payment needs a person
 * (see Firol\Idoklad\InvoiceAlert). Goes to the ADMIN_EMAIL addresses.
 */
final class InvoiceOperatorAlertEmail
{
    /** @param array<string, mixed> $row invoices row joined with the account name */
    public static function build(string $to, array $row, string $reason): Message
    {
        $company  = (string) ($row['invoice_company_name'] ?? '');
        $stripeId = (string) ($row['stripe_invoice_id'] ?? '');
        $status   = (string) ($row['status'] ?? '');
        $attempts = (int) ($row['attempts'] ?? 0);
        $cur      = strtoupper((string) ($row['currency'] ?? 'EUR'));
        $total    = $row['stripe_total_cents'] !== null ? number_format((int) $row['stripe_total_cents'] / 100, 2, ',', ' ') . " $cur" : '—';
        $paid     = $row['stripe_amount_paid_cents'] !== null ? number_format((int) $row['stripe_amount_paid_cents'] / 100, 2, ',', ' ') . " $cur" : '—';
        $adminUrl = Url::appBase() . '/admin';

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $bodyHtml = '<p style="margin:0 0 16px 0;">Faktúru v iDoklade pre platbu cez Stripe sa nepodarilo vystaviť automaticky.</p>'
            . '<table style="border-collapse:collapse;font-size:14px;margin:0 0 16px 0;">'
            . '<tr><td style="padding:2px 12px 2px 0;color:#7a8494;">Účet</td><td>' . $e($company) . ' (#' . (int) $row['account_id'] . ')</td></tr>'
            . '<tr><td style="padding:2px 12px 2px 0;color:#7a8494;">Stripe faktúra</td><td>' . $e($stripeId) . '</td></tr>'
            . '<tr><td style="padding:2px 12px 2px 0;color:#7a8494;">Spolu / zaplatené</td><td>' . $e($total) . ' / ' . $e($paid) . '</td></tr>'
            . '<tr><td style="padding:2px 12px 2px 0;color:#7a8494;">Stav</td><td>' . $e($status) . ', pokusov: ' . $attempts . '</td></tr>'
            . '</table>'
            . '<p style="margin:0 0 16px 0;"><strong>Dôvod:</strong> ' . $e($reason) . '</p>'
            . '<p style="margin:0 0 16px 0;">V administrácii (Účty → faktúry účtu) ju po odstránení príčiny vystavíš tlačidlom „Vystaviť znova".</p>'
            . Layout::button($adminUrl, 'Otvoriť administráciu');

        $text = "Faktúru v iDoklade pre platbu cez Stripe sa nepodarilo vystaviť automaticky.\n\n"
            . "Účet: $company (#" . (int) $row['account_id'] . ")\n"
            . "Stripe faktúra: $stripeId\n"
            . "Spolu / zaplatené: $total / $paid\n"
            . "Stav: $status, pokusov: $attempts\n"
            . "Dôvod: $reason\n\n"
            . "V administrácii (Účty → faktúry účtu) ju po odstránení príčiny vystavíš tlačidlom „Vystaviť znova\".\n"
            . $adminUrl;

        return new Message(
            to:      $to,
            subject: 'POapp — faktúru sa nepodarilo vystaviť (' . $company . ')',
            html:    Layout::render('Faktúra nevystavená', 'Faktúra potrebuje zásah', $bodyHtml, 'Faktúru pre ' . $company . ' sa nepodarilo vystaviť.'),
            text:    $text,
        );
    }
}
