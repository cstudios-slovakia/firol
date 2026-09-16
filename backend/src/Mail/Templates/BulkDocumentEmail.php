<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Message;

/**
 * Several protocols in one e-mail — block 1 / chapter 9.1.
 *
 * A visit produces three or four protocols and the client wants one message,
 * not four. The same screen is reachable from the company history, where a
 * client asking for "everything from last year" is answered in one send.
 *
 * The body lists the protocol numbers so the recipient can check the
 * attachments against the list without opening each PDF.
 */
final class BulkDocumentEmail
{
    /**
     * @param list<array{filename: string, bytes: string, number: string}> $documents
     */
    public static function build(
        string $to,
        string $subject,
        string $brandName,
        array $documents,
        ?string $note = null,
    ): Message {
        $brandEsc = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');

        $listHtml = '';
        $listText = '';
        foreach ($documents as $doc) {
            $numEsc = htmlspecialchars($doc['number'], ENT_QUOTES, 'UTF-8');
            $listHtml .= '<li style="margin:0 0 4px 0;">' . $numEsc . '</li>';
            $listText .= '  - ' . $doc['number'] . "\n";
        }

        $noteBlock = '';
        $noteText  = '';
        if ($note !== null && trim($note) !== '') {
            $noteEsc   = nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8'));
            $noteBlock = <<<HTML
<p style="margin:0 0 12px 0;font-size:13px;font-weight:600;color:#5c6573;">Poznámka:</p>
<p style="margin:0 0 16px 0;padding:12px 14px;background:#f5f6f8;border-radius:10px;font-size:14px;line-height:1.55;color:#2c3440;">{$noteEsc}</p>
HTML;
            $noteText = "\n\nPoznámka:\n" . $note;
        }

        $count = count($documents);
        $word = $count === 1 ? 'protokol' : ($count < 5 ? 'protokoly' : 'protokolov');

        $bodyHtml = <<<HTML
<p style="margin:0 0 16px 0;">Dobrý deň,</p>
<p style="margin:0 0 16px 0;">v prílohe Vám zasielame {$count} {$word} vystavené spoločnosťou <strong>{$brandEsc}</strong>:</p>
<ul style="margin:0 0 16px 0;padding-left:20px;font-size:14px;color:#2c3440;">{$listHtml}</ul>
{$noteBlock}
<p style="margin:24px 0 0 0;font-size:13px;color:#7a8494;">Tento email bol odoslaný automaticky cez aplikáciu POapp.</p>
HTML;

        $text = $subject . "\n\n"
              . "V prílohe nájdete tieto protokoly:\n"
              . $listText
              . $noteText;

        $attachments = [];
        foreach ($documents as $doc) {
            $attachments[] = [
                'filename' => $doc['filename'],
                'content'  => base64_encode($doc['bytes']),
            ];
        }

        return new Message(
            to:      $to,
            subject: $subject,
            html:    Layout::render('Protokoly', $subject, $bodyHtml, $count . ' ' . $word . ' v prílohe.'),
            text:    $text,
            attachments: $attachments,
        );
    }
}
