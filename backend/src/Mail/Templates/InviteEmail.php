<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Mailer;
use Firol\Mail\Message;

final class InviteEmail
{
    public static function build(string $to, string $inviterName, string $accountName, string $token): Message
    {
        $url = Mailer::appBaseUrl() . '/invite/accept?token=' . urlencode($token);

        $inviterEsc = htmlspecialchars($inviterName, ENT_QUOTES, 'UTF-8');
        $accountEsc = htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8');

        $bodyHtml = <<<HTML
<p style="margin:0 0 16px 0;">Ahoj,</p>
<p style="margin:0 0 16px 0;"><strong>{$inviterEsc}</strong> ťa pozval/a do firmy <strong>{$accountEsc}</strong> v aplikácii Firol — SaaS pre revízie požiarnej ochrany.</p>
<p style="margin:0 0 16px 0;">Klikni na tlačidlo nižšie a pozvánku potvrď. Pokiaľ v Firole ešte nemáš účet, rovno si nastavíš heslo. Pozvánka platí <strong>7 dní</strong>.</p>
HTML
            . Layout::button($url, 'Prijať pozvánku')
            . Layout::linkFallback($url)
            . <<<HTML
<p style="margin:24px 0 0 0;font-size:13px;color:#7a8494;">Ak si túto pozvánku nečakal/a, môžeš tento email ignorovať — bez tvojho potvrdenia ťa do tímu nikto nepridá.</p>
HTML;

        $text = "Pozvánka do Firol — {$accountName}\n\n"
              . "{$inviterName} ťa pozval/a do firmy {$accountName}.\n"
              . "Potvrď pozvánku (odkaz platí 7 dní):\n"
              . $url;

        return new Message(
            to:      $to,
            subject: "Pozvánka do Firol — {$accountName}",
            html:    Layout::render('Pozvánka', "Pridaj sa do {$accountName}", $bodyHtml, "{$inviterName} ťa pozval/a do Firol."),
            text:    $text,
        );
    }
}
