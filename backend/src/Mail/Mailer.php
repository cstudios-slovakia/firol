<?php

declare(strict_types=1);

namespace Firol\Mail;

use Firol\Mail\Templates\Layout;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP transport via PHPMailer. Single static entry point (`Mailer::send`)
 * keeps the call sites in controllers compact.
 *
 * If SMTP is not configured or the delivery fails, the mail is logged via
 * error_log() instead — so dev/staging keep working and an SMTP outage
 * cannot break the underlying flow (password reset, invite, invoice
 * receipt). The caller is never made aware of the failure; the return
 * value is informational.
 *
 * Templates live in `Firol\Mail\Templates` and return a Message struct.
 */
final class Mailer
{
    public static function send(Message $msg): bool
    {
        $host = trim((string) ($_ENV['SMTP_HOST'] ?? ''));
        $user = (string) ($_ENV['SMTP_USERNAME'] ?? '');
        $pass = (string) ($_ENV['SMTP_PASSWORD'] ?? '');
        $port = (int) ($_ENV['SMTP_PORT'] ?? 587);
        $enc  = strtolower(trim((string) ($_ENV['SMTP_ENCRYPTION'] ?? 'tls')));

        if ($host === '') {
            error_log('[mail.skipped] no SMTP_HOST — to=' . $msg->to . ' subject=' . $msg->subject);
            // Also surface the plaintext body so devs can copy reset/invite links.
            error_log('[mail.skipped.body] ' . preg_replace('/\s+/', ' ', $msg->text));
            return false;
        }

        [$fromEmail, $fromName] = self::fromAddress();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;

            // Deliverability, aimed at Outlook/Hotmail's filter specifically —
            // Gmail and Websupport already inbox these messages, Outlook junks
            // them. SPF, DKIM and DMARC all verify, so what is left is the
            // cheap signals:
            //
            // - quoted-printable, not base64: a body whose every byte is
            //   base64 reads as something hiding from a content scanner.
            // - Message-ID is derived from Hostname; left alone PHPMailer uses
            //   $_SERVER['SERVER_NAME'], which is absent on CLI/cron sends and
            //   degrades to `localhost.localdomain` — a Message-ID whose domain
            //   is unrelated to the From domain is a junk signal.
            // - X-Mailer only advertises the library and its version.
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->Hostname = self::senderDomain($fromEmail);
            $mail->XMailer  = ' ';

            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
            }

            if ($enc === 'ssl' || $enc === 'smtps') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls' || $enc === 'starttls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($fromEmail, $fromName);
            // Envelope sender, so Return-Path matches From and SPF is aligned
            // for DMARC rather than merely passing on the hosting domain.
            $mail->Sender = $fromEmail;
            $mail->addAddress($msg->to);
            if ($msg->replyTo !== null && $msg->replyTo !== '') {
                $mail->addReplyTo($msg->replyTo);
            }

            $mail->Subject = $msg->subject;
            $mail->isHTML(true);
            $mail->Body    = self::embedLogo($mail, $msg->html);
            $mail->AltBody = $msg->text;

            foreach ($msg->attachments as $att) {
                $bytes = base64_decode($att['content'], true);
                if ($bytes === false) {
                    continue;
                }
                $mail->addStringAttachment($bytes, $att['filename']);
            }

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log("[mail.failed] to={$msg->to} err=" . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log('[mail.failed] to=' . $msg->to . ' ' . $e::class . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Attaches the POapp logo as an inline (Content-ID) part so the header
     * renders even in clients that block remote images as trackers —
     * BlueMail does, and showed a broken placeholder instead of the logo.
     *
     * If the asset is not readable we rewrite the reference to the hosted
     * URL, so a missing file degrades to the old behaviour rather than to
     * a dead `cid:`.
     */
    private static function embedLogo(PHPMailer $mail, string $html): string
    {
        $cid = Layout::LOGO_CID;
        if (!str_contains($html, "cid:{$cid}")) {
            return $html;
        }

        $file  = Layout::logoFile();
        $bytes = is_readable($file) ? (string) file_get_contents($file) : '';
        if ($bytes === '') {
            error_log("[mail.logo] unreadable asset {$file} — falling back to the hosted URL");
            return str_replace("cid:{$cid}", Layout::logoUrl(), $html);
        }

        $mail->addStringEmbeddedImage(
            $bytes,
            $cid,
            'poapp-logo.png',
            PHPMailer::ENCODING_BASE64,
            'image/png',
        );

        return $html;
    }

    /**
     * Resolves the `From:` address as a (email, name) pair for PHPMailer.
     * In dev (no MAIL_FROM) we fall back to no-reply@localhost so the
     * library doesn't reject an empty sender; production must set
     * MAIL_FROM to a domain authorized by the SMTP server.
     *
     * @return array{0: string, 1: string}
     */
    private static function fromAddress(): array
    {
        $email = trim((string) ($_ENV['MAIL_FROM'] ?? ''));
        $name  = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'POapp'));

        if ($email === '') {
            $email = 'no-reply@localhost';
        }
        return [$email, $name];
    }

    /** Domain part of the From address, used for HELO and the Message-ID. */
    private static function senderDomain(string $fromEmail): string
    {
        $at = strrpos($fromEmail, '@');
        $domain = $at === false ? '' : substr($fromEmail, $at + 1);

        return $domain !== '' ? $domain : 'localhost.localdomain';
    }

    public static function appBaseUrl(): string
    {
        return \Firol\Http\Url::appBase();
    }
}
