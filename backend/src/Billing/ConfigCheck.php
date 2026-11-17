<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Auth\Admin;
use Firol\Db;
use Firol\Idoklad\IDokladClient;
use Firol\Idoklad\IssuePolicy;
use Firol\Stripe\StripeClient;

/**
 * Billing configuration check shown in the admin panel („Kontrola
 * fakturácie") and printed by bin/billing-daily.php. Reads env and the
 * database only — never calls Stripe or iDoklad.
 */
final class ConfigCheck
{
    /** system_settings key the daily command stamps after each run. */
    public const DAILY_RUN_KEY = 'billing_daily_last_run';

    /**
     * @return list<array{key: string, level: 'ok'|'warn'|'error', message: string}>
     */
    public static function run(): array
    {
        $out = [];
        $prod = strtolower((string) ($_ENV['APP_ENV'] ?? '')) === 'production';
        $add = static function (string $key, string $level, string $message) use (&$out): void {
            $out[] = ['key' => $key, 'level' => $level, 'message' => $message];
        };

        $key = (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        if ($key === '') {
            $add('stripe_key', 'error', 'STRIPE_SECRET_KEY nie je nastavený — predplatné nefunguje.');
        } elseif (StripeClient::isTestKey()) {
            $add('stripe_key', $prod ? 'error' : 'ok', $prod
                ? 'Produkcia používa testovací kľúč Stripe (sk_test) — nikto nič nezaplatí.'
                : 'Stripe v testovacom režime (sk_test).');
        } else {
            $add('stripe_key', $prod ? 'ok' : 'warn', $prod
                ? 'Stripe v ostrom režime.'
                : 'Mimo produkcie je nastavený OSTRÝ kľúč Stripe.');
        }

        foreach (['STRIPE_WEBHOOK_SECRET', 'STRIPE_PRICE_MONTHLY', 'STRIPE_PRICE_YEARLY'] as $name) {
            if ((string) ($_ENV[$name] ?? '') === '') {
                $add(strtolower($name), 'error', "$name nie je nastavený.");
            }
        }

        if (StripeClient::extraTechnicianProductId() === '') {
            $add('stripe_product_extra', 'error',
                'STRIPE_PRODUCT_EXTRA_TECHNICIAN nie je nastavený — extra technikov nemožno pridať do predplatného ani do Checkoutu, '
                . 'zákazník za nich neplatí. Založ v Stripe produkt „POapp — extra technik" a jeho ID (prod_…) daj do .env.');
        } else {
            $add('stripe_product_extra', 'ok', 'Produkt pre extra technika je nastavený.');
        }

        $mode = IssuePolicy::mode();
        if (!IDokladClient::isConfigured()) {
            $add('idoklad', $prod ? 'warn' : 'ok', 'iDoklad nie je nastavený — faktúry sa nevystavujú (stav „skipped").');
        } elseif ($mode === 'send') {
            $add('idoklad', $prod ? 'ok' : 'warn', $prod
                ? 'iDoklad: faktúry sa vystavujú.'
                : 'iDoklad: IDOKLAD_ISSUE_MODE=send mimo produkcie — faktúry sa naozaj vystavujú.');
        } else {
            $add('idoklad', $prod ? 'error' : 'ok', $prod
                ? 'iDoklad: IDOKLAD_ISSUE_MODE=prepare v produkcii — faktúry sa len pripravia, nevystavia.'
                : 'iDoklad: faktúry sa len pripravia (payload sa uloží), neodosielajú sa.');
        }
        if (IssuePolicy::allowTestEvents()) {
            $add('idoklad_test_events', $prod ? 'error' : 'warn', 'IDOKLAD_ALLOW_TEST_EVENTS je zapnuté — aj testovacie platby zo Stripe sa môžu vystaviť v iDoklade.');
        }
        if (array_key_exists('IDOKLAD_DRAFT_MODE', $_ENV)) {
            $add('idoklad_draft_mode', 'warn', 'IDOKLAD_DRAFT_MODE sa už nepoužíva (nikdy nevytváral koncepty). Odstráň ho; ochranu dáva IDOKLAD_ISSUE_MODE.');
        }

        if (Admin::envSeedEmails() === []) {
            $add('admin_email', 'warn', 'ADMIN_EMAIL je prázdny — upozornenia na nevystavené faktúry nemá kto dostať.');
        }

        try {
            $pdo = Db::pdo();
            $counts = $pdo->query(
                "SELECT status, COUNT(*) AS n FROM invoices
                 WHERE status IN ('needs_review', 'error', 'sending') GROUP BY status"
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
            $review = (int) ($counts['needs_review'] ?? 0);
            $error  = (int) ($counts['error'] ?? 0) + (int) ($counts['sending'] ?? 0);
            if ($review + $error > 0) {
                $add('invoices', 'warn', "Faktúry na kontrolu: $review, chybné alebo nedokončené: $error (Účty → faktúry účtu).");
            }

            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $stmt->execute([self::DAILY_RUN_KEY]);
            $last = $stmt->fetchColumn();
            if ($last === false || strtotime((string) $last) < time() - 36 * 3600) {
                $add('daily', $prod ? 'error' : 'warn', 'Denná kontrola fakturácie (bin/billing-daily.php) '
                    . ($last === false ? 'ešte nebežala.' : 'naposledy bežala ' . $last . '.'));
            } else {
                $add('daily', 'ok', 'Denná kontrola fakturácie naposledy bežala ' . $last . '.');
            }
        } catch (\Throwable $e) {
            $add('db', 'error', 'Databázu sa nepodarilo prečítať: ' . $e->getMessage());
        }

        return $out;
    }
}
