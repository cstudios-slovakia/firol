<?php

declare(strict_types=1);

namespace Firol\Mail;

/**
 * Resend HTTP API client. Single static entry point (`Mailer::send`)
 * keeps the call sites in controllers compact.
 *
 * If RESEND_API_KEY is missing or the HTTP call fails, the mail is
 * logged via error_log() instead — so dev/staging keep working and a
 * Resend outage cannot break the underlying flow (password reset,
 * invite, invoice receipt). The caller is never made aware of the
 * failure; the return value is informational.
 *
 * Templates live in `Firol\Mail\Templates` and return a Message struct.
 */
final class Mailer
{
    public static function send(Message $msg): bool
    {
        $apiKey = (string) ($_ENV['RESEND_API_KEY'] ?? '');
        $from   = self::fromAddress();

        if ($apiKey === '') {
            error_log('[mail.skipped] no RESEND_API_KEY — to=' . $msg->to . ' subject=' . $msg->subject);
            // Also surface the plaintext body so devs can copy reset/invite links.
            error_log('[mail.skipped.body] ' . preg_replace('/\s+/', ' ', $msg->text));
            return false;
        }

        $payload = [
            'from'    => $from,
            'to'      => [$msg->to],
            'subject' => $msg->subject,
            'html'    => $msg->html,
            'text'    => $msg->text,
        ];
        if ($msg->replyTo !== null) {
            $payload['reply_to'] = $msg->replyTo;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return true;
        }

        error_log("[mail.failed] to={$msg->to} status={$status} err={$err} body=" . substr((string) $response, 0, 500));
        return false;
    }

    /**
     * Resolves the `From:` address. Resend requires a verified sender
     * domain in production; in dev we fall back to onboarding@resend.dev
     * so a missing MAIL_FROM env doesn't 422 every call.
     */
    private static function fromAddress(): string
    {
        $email = trim((string) ($_ENV['MAIL_FROM'] ?? ''));
        $name  = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'Firol'));

        if ($email === '') {
            $email = 'onboarding@resend.dev';
        }
        return $name !== '' ? "{$name} <{$email}>" : $email;
    }

    public static function appBaseUrl(): string
    {
        $url = trim((string) ($_ENV['APP_BASE_URL'] ?? ''));
        return $url !== '' ? rtrim($url, '/') : 'http://localhost:5173';
    }
}
