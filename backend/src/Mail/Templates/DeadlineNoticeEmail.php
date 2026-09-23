<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Message;
use Firol\Support\ClientNotice;

/**
 * Automatic client notice of an upcoming termín — chapter 11.3.
 *
 * The same words as the manual „Oznámiť klientovi e-mailom" (see
 * {@see ClientNotice}), wrapped in the platform layout. Reply-To is the
 * technician responsible for the termín, so the client's answer reaches a
 * person rather than the noreply@ From.
 */
final class DeadlineNoticeEmail
{
    /** @param list<string> $types */
    public static function build(
        string $to,
        string $isoDate,
        array $types,
        string $senderName,
        ?string $senderPhone,
        ?string $replyTo,
    ): Message {
        $notice = ClientNotice::build($isoDate, $types, $senderName, $senderPhone);

        $paragraphs = preg_split('/\r\n\r\n/', $notice['body']) ?: [];
        $bodyHtml = '';
        foreach ($paragraphs as $p) {
            $bodyHtml .= '<p style="margin:0 0 16px 0;">'
                . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'))
                . '</p>';
        }

        return new Message(
            to:      $to,
            subject: $notice['subject'],
            html:    Layout::render(
                'Oznámenie termínu',
                'Oznámenie termínu kontroly',
                $bodyHtml,
                'Termín ' . ClientNotice::formatDate($isoDate),
            ),
            text:    $notice['body'],
            replyTo: $replyTo,
        );
    }
}
