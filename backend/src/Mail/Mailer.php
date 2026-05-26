<?php

declare(strict_types=1);

namespace Firol\Mail;

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
            $mail->Encoding   = 'base64';
            $mail->Timeout    = 15;

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
            $mail->addAddress($msg->to);
            if ($msg->replyTo !== null && $msg->replyTo !== '') {
                $mail->addReplyTo($msg->replyTo);
            }

            $mail->Subject = $msg->subject;
            $mail->isHTML(true);
            $mail->Body    = $msg->html;
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
        $name  = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'Firol'));

        if ($email === '') {
            $email = 'no-reply@localhost';
        }
        return [$email, $name];
    }

    public static function appBaseUrl(): string
    {
        return \Firol\Http\Url::appBase();
    }
}
