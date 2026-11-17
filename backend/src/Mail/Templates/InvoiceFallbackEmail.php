<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Message;

/**
 * Sent when our iDoklad invoice issuance fails for a Stripe payment.
 * Stripe already charged the user — we just couldn't generate the SK
 * faktúra. Sent once, on the first failed attempt. It says what really
 * happens next: bin/billing-daily.php retries, the operator is alerted
 * when the retries run out (InvoiceAlert) and re-issues from the admin
 * panel („Vystaviť znova"), and the issued invoice shows in the app's
 * billing history — iDoklad does not e-mail it (an open owner decision).
 * Until then the Stripe-hosted receipt is linked.
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
<p style="margin:0 0 16px 0;">tvoja platba za predplatné POapp pre <strong>{$accEsc}</strong> vo výške <strong>{$amount}&nbsp;{$cur}</strong> bola úspešne prijatá.</p>
<p style="margin:0 0 16px 0;">Faktúru k platbe sa nám nepodarilo vystaviť automaticky — účtovný systém neodpovedal správne. Aplikácia to skúsi znova; ak sa to nepodarí, dostaneme upozornenie a faktúru vystavíme ručne. Keď bude vystavená, nájdeš ju v aplikácii v časti <strong>Predplatné a fakturácia → História platieb</strong>. Dovtedy ti ako doklad o platbe slúži potvrdenie zo Stripe. Tvoj prístup do aplikácie zostáva neprerušený.</p>
HTML
            . $hostedBlock
            . <<<HTML
<p style="margin:24px 0 0 0;font-size:13px;color:#7a8494;">V prípade otázok napíš na podporu — radi pomôžeme.</p>
HTML;

        $text = "Platba prijatá — POapp\n\n"
              . "Tvoja platba pre {$accountName} vo výške {$amount} {$cur} bola úspešne prijatá.\n"
              . "Faktúru sa nepodarilo vystaviť automaticky. Aplikácia to skúsi znova; ak sa to nepodarí, dostaneme upozornenie a vystavíme ju ručne.\n"
              . "Vystavenú faktúru nájdeš v aplikácii v časti Predplatné a fakturácia → História platieb. Dovtedy ti ako doklad slúži potvrdenie zo Stripe."
              . $hostedText;

        return new Message(
            to:      $to,
            subject: 'Potvrdenie platby — POapp (faktúra bude vystavená dodatočne)',
            html:    Layout::render('Potvrdenie platby', 'Platba úspešne prijatá', $bodyHtml, "Platba {$amount} {$cur} prijatá, faktúra príde dodatočne."),
            text:    $text,
        );
    }
}
