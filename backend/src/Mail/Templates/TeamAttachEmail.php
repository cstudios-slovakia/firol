<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Mailer;
use Firol\Mail\Message;

/**
 * Sent when an already-registered user is attached to a new account.
 * Unlike InviteEmail there is no password-reset token — they already
 * have a working login. The CTA just takes them to the app, where the
 * account switcher will show the new tenant.
 */
final class TeamAttachEmail
{
    public static function build(string $to, string $inviterName, string $accountName): Message
    {
        $url = Mailer::appBaseUrl() . '/login';

        $inviterEsc = htmlspecialchars($inviterName, ENT_QUOTES, 'UTF-8');
        $accountEsc = htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8');

        $bodyHtml = <<<HTML
<p style="margin:0 0 16px 0;">Ahoj,</p>
<p style="margin:0 0 16px 0;"><strong>{$inviterEsc}</strong> ťa pridal/a do firmy <strong>{$accountEsc}</strong> v aplikácii Firol.</p>
<p style="margin:0 0 16px 0;">Tvoj účet už existuje — stačí sa prihlásiť pôvodným heslom a v prepínači účtov vpravo hore uvidíš nový tím.</p>
HTML
            . Layout::button($url, 'Prihlásiť sa')
            . Layout::linkFallback($url);

        $text = "Pridanie do tímu — {$accountName}\n\n"
              . "{$inviterName} ťa pridal/a do firmy {$accountName}.\n"
              . "Tvoj účet už existuje, stačí sa prihlásiť:\n"
              . $url;

        return new Message(
            to:      $to,
            subject: "Pridanie do tímu — {$accountName}",
            html:    Layout::render('Tím', "Si v tíme {$accountName}", $bodyHtml, "{$inviterName} ťa pridal/a do Firol."),
            text:    $text,
        );
    }
}
