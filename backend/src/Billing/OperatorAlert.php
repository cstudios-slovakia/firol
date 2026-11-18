<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Auth\Admin;
use Firol\Db;
use Firol\Mail\Mailer;
use Firol\Mail\Templates\BillingOperatorAlertEmail;

/**
 * E-mails the operator (every address in ADMIN_EMAIL) about a billing problem
 * that is not tied to one invoice row — those go through
 * Firol\Idoklad\InvoiceAlert. Returns whether at least one mail went out.
 */
final class OperatorAlert
{
    /** @param list<string> $lines */
    public static function send(string $subject, string $intro, array $lines): bool
    {
        $sent = false;
        try {
            foreach (Admin::envSeedEmails() as $to) {
                $sent = Mailer::send(BillingOperatorAlertEmail::build($to, $subject, $intro, $lines)) || $sent;
            }
        } catch (\Throwable $e) {
            error_log('[billing.alert] ' . $e->getMessage());
        }
        if (!$sent) {
            error_log("[billing.alert] not mailed (no ADMIN_EMAIL or mail failed): $subject — " . implode('; ', $lines));
        }
        return $sent;
    }

    /**
     * Send the alert only if the one with this key was not mailed yet
     * (table billing_operator_alerts). The key is stored only after a mail
     * went out, so a failed send is tried again by the next run.
     *
     * Returns 'sent', 'already' (mailed before) or 'failed'.
     *
     * @param list<string> $lines
     */
    public static function once(string $key, string $subject, string $intro, array $lines): string
    {
        if (self::wasSent($key)) {
            return 'already';
        }
        if (!self::send($subject, $intro, $lines)) {
            return 'failed';
        }
        try {
            Db::pdo()->prepare('INSERT IGNORE INTO billing_operator_alerts (alert_key) VALUES (?)')->execute([self::key($key)]);
        } catch (\Throwable $e) {
            error_log('[billing.alert] could not remember ' . $key . ': ' . $e->getMessage());
        }
        return 'sent';
    }

    public static function wasSent(string $key): bool
    {
        try {
            $stmt = Db::pdo()->prepare('SELECT 1 FROM billing_operator_alerts WHERE alert_key = ?');
            $stmt->execute([self::key($key)]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            error_log('[billing.alert] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Forget every alert whose key starts with $prefix, so the same problem
     * alerts again when it comes back (an account that was repaired, a daily
     * run that is no longer refused).
     */
    public static function forget(string $prefix): void
    {
        try {
            Db::pdo()->prepare('DELETE FROM billing_operator_alerts WHERE alert_key LIKE ?')
                ->execute([addcslashes(self::key($prefix), '%_\\') . '%']);
        } catch (\Throwable $e) {
            error_log('[billing.alert] ' . $e->getMessage());
        }
    }

    private static function key(string $key): string
    {
        return mb_substr($key, 0, 191);
    }
}
