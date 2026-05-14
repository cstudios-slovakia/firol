<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Message;

/**
 * Sent when our iDoklad invoice issuance fails for a Stripe payment.
 * Stripe already charged the user — we just couldn't generate the SK
 * faktúra. The email reassures them and links to the Stripe-hosted
 * invoice PDF so they have *something* in hand until ops re-runs
 * the issuance from the admin panel.
 */
final class InvoiceFallbackEmail
{
    public static function build(string $to, string $accountName, int $amountCents, string $currency, ?string $hostedInvoiceUrl): Message
    {
        $amount   = number_format($amountCents / 100, 2, ',', ' ');
        $cur      = strtoupper($currency);
        $accEsc   = htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8');

        $hostedBlock = '';
        $hostedText  = '';
        if ($hostedInvoiceUrl !== null && $hostedInvoiceUrl !== '') {
            $hostedBlock = Layout::button($hostedInvoiceUrl, 'Zobraziť potvrdenie o platbe')
                         . Layout::linkFallback($hostedInvoiceUrl);
            $hostedText  = "\n\nPotvrdenie o platbe:\n" . $hostedInvoiceUrl;
        }

        $bodyHtml = <<<HTML
<p style="margin:0 0 16px 0;">Ahoj,</p>
<p style="margin:0 0 16px 0;">tvoja platba za predplatné Firol pre <strong>{$accEsc}</strong> vo výške <strong>{$amount}&nbsp;{$cur}</strong> bola úspešne prijatá.</p>
<p style="margin:0 0 16px 0;">Riadnu slovenskú faktúru ti pošleme dodatočne — pri jej automatickom vystavení nastal problém na strane účtovného systému a náš tím to už rieši. Tvoj prístup do aplikácie zostáva neprerušený.</p>
HTML
            . $hostedBlock
            . <<<HTML
<p style="margin:24px 0 0 0;font-size:13px;color:#7a8494;">V prípade otázok napíš na podporu — radi pomôžeme.</p>
HTML;

        $text = "Platba prijatá — Firol\n\n"
              . "Tvoja platba pre {$accountName} vo výške {$amount} {$cur} bola úspešne prijatá.\n"
              . "Slovenskú faktúru ti pošleme dodatočne — automatické vystavenie zlyhalo a tím to rieši."
              . $hostedText;

        return new Message(
            to:      $to,
            subject: 'Potvrdenie platby — Firol (faktúra príde dodatočne)',
            html:    Layout::render('Potvrdenie platby', 'Platba úspešne prijatá', $bodyHtml, "Platba {$amount} {$cur} prijatá, faktúra príde dodatočne."),
            text:    $text,
        );
    }
}
