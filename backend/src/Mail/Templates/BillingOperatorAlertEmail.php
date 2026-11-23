<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Http\Url;
use Firol\Mail\Message;

/**
 * Operator alert about billing that is not an invoice row (see
 * Firol\Billing\OperatorAlert): a Stripe webhook event that failed and was
 * re-run by the daily command. Goes to the ADMIN_EMAIL addresses.
 */
final class BillingOperatorAlertEmail
{
    /** @param list<string> $lines facts, one per line */
    public static function build(string $to, string $subject, string $intro, array $lines): Message
    {
        $adminUrl = Url::appBase() . '/admin';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $bodyHtml = '<p style="margin:0 0 16px 0;">' . $e($intro) . '</p>'
            . '<ul style="margin:0 0 16px 0;padding-left:18px;font-size:14px;">'
            . implode('', array_map(static fn (string $l): string => '<li>' . $e($l) . '</li>', $lines))
            . '</ul>'
            . '<p style="margin:0 0 16px 0;">Stav fakturácie ukazuje administrácia → „Kontrola fakturácie".</p>'
            . Layout::button($adminUrl, 'Otvoriť administráciu');

        $text = $intro . "\n\n- " . implode("\n- ", $lines) . "\n\n"
            . "Stav fakturácie ukazuje administrácia → „Kontrola fakturácie\".\n" . $adminUrl;

        return new Message(
            to:      $to,
            subject: 'POapp — ' . $subject,
            html:    Layout::render($subject, $subject, $bodyHtml, $intro),
            text:    $text,
        );
    }
}
