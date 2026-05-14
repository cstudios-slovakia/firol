<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Mailer;
use Firol\Mail\Message;

final class PasswordResetEmail
{
    public static function build(string $to, string $token): Message
    {
        $url = Mailer::appBaseUrl() . '/password-reset/confirm?token=' . urlencode($token);

        $bodyHtml = <<<HTML
<p style="margin:0 0 16px 0;">Ahoj,</p>
<p style="margin:0 0 16px 0;">prijali sme žiadosť o obnovenie hesla k tvojmu Firol účtu. Klikni na tlačidlo nižšie a nastav si nové heslo. Odkaz platí <strong>1 hodinu</strong>.</p>
HTML
            . Layout::button($url, 'Nastaviť nové heslo')
            . Layout::linkFallback($url)
            . <<<HTML
<p style="margin:24px 0 0 0;font-size:13px;color:#7a8494;">Ak si o obnovu hesla nežiadal/a, tento email pokojne ignoruj — tvoje heslo zostáva nezmenené.</p>
HTML;

        $text = "Obnova hesla — Firol\n\n"
              . "Klikni na odkaz a nastav si nové heslo (platí 1 hodinu):\n"
              . $url . "\n\n"
              . "Ak si o obnovu nežiadal/a, ignoruj tento email.";

        return new Message(
            to:      $to,
            subject: 'Obnova hesla — Firol',
            html:    Layout::render('Obnova hesla', 'Nastav si nové heslo', $bodyHtml, 'Odkaz na obnovu hesla platí 1 hodinu.'),
            text:    $text,
        );
    }
}
