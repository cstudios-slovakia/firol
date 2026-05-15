<?php

declare(strict_types=1);

namespace Firol\Mail\Templates;

use Firol\Mail\Message;

/**
 * Email used when a technician forwards a generated protocol PDF to a
 * customer. The PDF is sent as an attachment; the body is a short cover
 * note naming the protocol and the issuing company.
 */
final class DocumentEmail
{
    public static function build(
        string $to,
        string $documentNumber,
        string $brandName,
        string $pdfFilename,
        string $pdfBytes,
        ?string $note = null,
    ): Message {
        $numEsc   = htmlspecialchars($documentNumber, ENT_QUOTES, 'UTF-8');
        $brandEsc = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');

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

        $bodyHtml = <<<HTML
<p style="margin:0 0 16px 0;">Dobrý deň,</p>
<p style="margin:0 0 16px 0;">v prílohe Vám zasielame PDF protokol <strong>{$numEsc}</strong> vystavený spoločnosťou <strong>{$brandEsc}</strong>.</p>
{$noteBlock}
<p style="margin:24px 0 0 0;font-size:13px;color:#7a8494;">Tento email bol odoslaný automaticky cez aplikáciu Firol.</p>
HTML;

        $text = "Protokol {$documentNumber} — {$brandName}\n\n"
              . "V prílohe nájdete PDF protokol {$documentNumber}."
              . $noteText;

        return new Message(
            to:      $to,
            subject: 'Protokol ' . $documentNumber . ' — ' . $brandName,
            html:    Layout::render('Protokol', 'Protokol ' . $documentNumber, $bodyHtml, 'PDF protokol ' . $documentNumber . ' v prílohe.'),
            text:    $text,
            attachments: [
                [
                    'filename' => $pdfFilename,
                    'content'  => base64_encode($pdfBytes),
                ],
            ],
        );
    }
}
