<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Auth\Admin;
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
}
