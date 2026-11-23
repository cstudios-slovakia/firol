<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Http\Url;
use Firol\Mail\Message;

/**
 * To the account's main user when a renewal payment failed (terms 5.3:
 * notice of a failed payment). Says the amount, the day the account becomes
 * read-only, and how to pay: „Zaplatiť" on the billing page opens the Stripe
 * customer portal (card change and paying the open invoice); Stripe's own
 * page of the invoice is linked as well. One e-mail per failed invoice
 * (Firol\Billing\PaymentGrace).
 */
final class PaymentFailedEmail
{
    public static function build(string $to, string $accountName, int $amountCents, string $currency, string $readOnlyFrom, ?string $hostedInvoiceUrl): Message
    {
        $amount  = number_format($amountCents / 100, 2, ',', ' ') . ' ' . strtoupper($currency === '' ? 'EUR' : $currency);
        $date    = date('j. n. Y', (int) strtotime($readOnlyFrom . ' 12:00:00'));
        $billing = Url::appBase() . '/billing';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $hostedHtml = '';
        $hostedText = '';
        if ($hostedInvoiceUrl !== null && $hostedInvoiceUrl !== '') {
            $hostedHtml = '<p style="margin:16px 0 0 0;font-size:13px;color:#7a8494;">Faktúru môžeš zaplatiť aj priamo na stránke Stripe: <a href="'
                . $e($hostedInvoiceUrl) . '">zaplatiť faktúru</a>.</p>';
            $hostedText = "\nFaktúru môžeš zaplatiť aj priamo na stránke Stripe:\n" . $hostedInvoiceUrl . "\n";
        }

        $bodyHtml = '<p style="margin:0 0 16px 0;">Ahoj,</p>'
            . '<p style="margin:0 0 16px 0;">platba za predplatné POapp pre <strong>' . $e($accountName) . '</strong> vo výške <strong>'
            . $e($amount) . '</strong> (s DPH) sa nepodarila — kartu sa nepodarilo zaťažiť.</p>'
            . '<p style="margin:0 0 16px 0;">Aplikáciu môžeš naďalej plne používať. Ak platba neprejde, od <strong>' . $e($date)
            . '</strong> bude účet len na čítanie (prezeranie a sťahovanie áno, nové záznamy a úpravy nie), kým ju nezaplatíš. Po zaplatení sa plný prístup obnoví hneď.</p>'
            . '<p style="margin:0 0 16px 0;">Zaplatiť alebo zmeniť kartu môžeš v aplikácii v časti <strong>Predplatné a fakturácia</strong> tlačidlom „Zaplatiť" (otvorí platobný portál Stripe).</p>'
            . Layout::button($billing, 'Zaplatiť')
            . Layout::linkFallback($billing)
            . $hostedHtml;

        $text = "Platba za predplatné POapp sa nepodarila\n\n"
            . "Platba za predplatné pre {$accountName} vo výške {$amount} (s DPH) sa nepodarila.\n"
            . "Aplikáciu môžeš naďalej plne používať. Ak platba neprejde, od {$date} bude účet len na čítanie, kým ju nezaplatíš. Po zaplatení sa plný prístup obnoví hneď.\n\n"
            . "Zaplatiť alebo zmeniť kartu: Predplatné a fakturácia → Zaplatiť (platobný portál Stripe)\n"
            . $billing . "\n"
            . $hostedText;

        return new Message(
            to:      $to,
            subject: 'Platba za predplatné POapp sa nepodarila',
            html:    Layout::render('Platba sa nepodarila', 'Platba za predplatné sa nepodarila', $bodyHtml, "Zaplať do {$date}, inak bude účet len na čítanie."),
            text:    $text,
        );
    }
}
