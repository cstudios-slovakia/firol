<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Auth\Admin;
use Firol\Controllers\BillingController;
use Firol\Db;
use Firol\Idoklad\IDokladClient;
use Firol\Idoklad\IssuePolicy;
use Firol\Stripe\StripeClient;

/**
 * Billing configuration check shown in the admin panel („Kontrola
 * fakturácie") and printed by bin/billing-daily.php. Reads env and the
 * database; asks Stripe only for the extra-technician product and the
 * products of the base prices (GET requests). Never calls iDoklad.
 *
 * Everything that means a real payment without a tax document, a technician
 * nobody is charged for, or a nightly backstop that does not run is an
 * ERROR, named by account and amount, so it cannot hide in a yellow count.
 */
final class ConfigCheck
{
    /** system_settings key the daily command stamps after each full run. */
    public const DAILY_RUN_KEY = 'billing_daily_last_run';

    /**
     * system_settings key the daily command stamps when its live-key guard
     * refused the steps that write to Stripe (value: time — reason); removed
     * by the next run that is not refused.
     */
    public const DAILY_REFUSED_KEY = 'billing_daily_refused';

    /** Minutes after which a webhook event still `processing` is reported. */
    public const STALE_EVENT_MINUTES = 10;

    /** Days the reconcile switch may stay off before it is an error. */
    public const SWITCH_OFF_MAX_DAYS = 3;

    /** Days a `gone` webhook event stays listed. */
    public const GONE_EVENT_LIST_DAYS = 60;

    /** How many accounts / rows a message names before „a ďalšie N". */
    private const NAMED = 5;

    /**
     * @return list<array{key: string, level: 'ok'|'warn'|'error', message: string}>
     */
    public static function run(): array
    {
        $out = [];
        $prod = strtolower(trim((string) ($_ENV['APP_ENV'] ?? ''))) === 'production';
        $add = static function (string $key, string $level, string $message) use (&$out): void {
            $out[] = ['key' => $key, 'level' => $level, 'message' => $message];
        };

        $key = (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '');
        $liveKey = $key !== '' && !StripeClient::isTestKey();
        if ($key === '') {
            $add('stripe_key', 'error', 'STRIPE_SECRET_KEY nie je nastavený — predplatné nefunguje.');
        } elseif (!$liveKey) {
            $add('stripe_key', $prod ? 'error' : 'ok', $prod
                ? 'Produkcia používa testovací kľúč Stripe (sk_test) — nikto nič nezaplatí.'
                : 'Stripe v testovacom režime (sk_test).');
        } else {
            $add('stripe_key', $prod ? 'ok' : 'error', $prod
                ? 'Stripe v ostrom režime.'
                : 'OSTRÝ kľúč Stripe, ale APP_ENV=' . ($_ENV['APP_ENV'] ?? '') . ' (nie „production"): denná kontrola nezapisuje do Stripe '
                  . '(počty technikov, opakovanie udalostí) a pri predvolenom režime sa faktúry len pripravia. V produkcii nastav APP_ENV=production.');
        }

        foreach (['STRIPE_WEBHOOK_SECRET', 'STRIPE_PRICE_MONTHLY', 'STRIPE_PRICE_YEARLY'] as $name) {
            if ((string) ($_ENV[$name] ?? '') === '') {
                $add(strtolower($name), 'error', "$name nie je nastavený.");
            }
        }

        $productId = StripeClient::extraTechnicianProductId();
        if ($productId === '') {
            $add('stripe_product_extra', 'error',
                'STRIPE_PRODUCT_EXTRA_TECHNICIAN nie je nastavený — extra technika nemožno pridať do existujúceho predplatného '
                . '(zákazník za neho neplatí); Checkout použije náhradný produkt. Založ v Stripe produkt „POapp — extra technik" a jeho ID (prod_…) daj do .env.');
        } elseif ($key !== '') {
            $add(...self::checkExtraProduct($productId));
        } else {
            $add('stripe_product_extra', 'warn', 'Produkt pre extra technika je nastavený, bez kľúča Stripe sa nedá overiť.');
        }

        $switch = SeatSync::reconcileSwitch();
        $since = SeatSync::switchOffSince(); // stamps the first time off, clears when on
        if ($switch === 'invalid') {
            $add('billing_reconcile', 'error',
                'BILLING_RECONCILE=' . ($_ENV['BILLING_RECONCILE'] ?? '') . ' nie je platná hodnota (len „on" alebo „off") — berie sa ako VYPNUTÉ: '
                . 'počet extra technikov sa do Stripe neposiela. Oprav hodnotu v .env.');
        }
        if ($switch !== 'on') {
            $long = $since !== null && strtotime($since) < time() - self::SWITCH_OFF_MAX_DAYS * 86400;
            if ($switch === 'off') {
                $add('billing_reconcile_off', $long ? 'error' : 'warn',
                    'BILLING_RECONCILE=off' . ($since !== null ? " od $since" : '') . ' — počet extra technikov sa do Stripe neposiela (webhooky len zapisujú stav, denná kontrola len hlási). '
                    . ($long ? 'Vypnuté dlhšie ako ' . self::SWITCH_OFF_MAX_DAYS . ' dni: noví technici sa neúčtujú. ' : '')
                    . 'Po kontrole výpisu bin/billing-seat-report.php ho zapni.');
            } elseif ($long) {
                $add('billing_reconcile_off', 'error', "Synchronizácia technikov je vypnutá od $since (neplatná hodnota BILLING_RECONCILE).");
            }
        }

        $mode = IssuePolicy::mode();
        if ($liveKey && $mode !== 'send') {
            $add('idoklad_live_mode', 'error',
                'Ostrý kľúč Stripe, ale IDOKLAD_ISSUE_MODE nie je „send" (APP_ENV=' . ($_ENV['APP_ENV'] ?? '') . ') — '
                . 'skutočné platby nedostanú faktúru. V produkcii nastav IDOKLAD_ISSUE_MODE=send.');
        }
        if (!IDokladClient::isConfigured()) {
            $add('idoklad', $prod || $liveKey ? 'error' : 'ok', $prod || $liveKey
                ? 'iDoklad nie je nastavený (IDOKLAD_CLIENT_ID / IDOKLAD_CLIENT_SECRET) — platby nedostanú faktúru (stav „skipped"). Po nastavení ich denná kontrola vystaví.'
                : 'iDoklad nie je nastavený — faktúry sa nevystavujú (stav „skipped").');
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
            $add('admin_email', $prod || $liveKey ? 'error' : 'warn', 'ADMIN_EMAIL je prázdny — upozornenia na platby bez faktúry nemá kto dostať.');
        }

        try {
            self::databaseChecks($add, $prod, $liveKey);
        } catch (\Throwable $e) {
            $add('db', 'error', 'Databázu sa nepodarilo prečítať: ' . $e->getMessage());
        }

        return $out;
    }

    /** @param callable(string, string, string): void $add */
    private static function databaseChecks(callable $add, bool $prod, bool $liveKey): void
    {
        $pdo = Db::pdo();

        // Paid Stripe invoices without a tax document, waiting for a person.
        $held = $pdo->query(
            "SELECT i.id, i.account_id, a.invoice_company_name, i.review_reason, i.stripe_amount_paid_cents, i.amount_cents
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status = 'needs_review'
             ORDER  BY i.id"
        )->fetchAll();
        if ($held !== []) {
            $add('invoices_held', 'error', 'Zaplatené faktúry bez daňového dokladu (čakajú na kontrolu): ' . count($held) . ' — '
                . self::names($held, static fn (array $r): string => sprintf('%s (#%d) %s, %s', (string) $r['invoice_company_name'], (int) $r['account_id'],
                    self::eur((int) ($r['stripe_amount_paid_cents'] ?? $r['amount_cents'])), self::reasonLabel((string) $r['review_reason'])))
                . '. Účty → faktúry účtu.');
        }

        $failed = $pdo->query(
            "SELECT i.id, i.account_id, a.invoice_company_name, i.status, i.attempts, i.stripe_amount_paid_cents, i.amount_cents
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status IN ('error', 'sending') ORDER BY i.id"
        )->fetchAll();
        if ($failed !== []) {
            $add('invoices_failed', 'warn', 'Faktúry, ktoré zlyhali alebo sa nedokončili: ' . count($failed) . ' — '
                . self::names($failed, static fn (array $r): string => sprintf('%s (#%d) %s, %s po %d pokusoch', (string) $r['invoice_company_name'], (int) $r['account_id'],
                    self::eur((int) ($r['stripe_amount_paid_cents'] ?? $r['amount_cents'])), (string) $r['status'], (int) $r['attempts']))
                . '. Denná kontrola ich skúsi znova.');
        }

        // A real payment whose invoice was only prepared, never sent.
        $preparedLive = $pdo->query(
            "SELECT i.account_id, a.invoice_company_name, i.stripe_amount_paid_cents, i.amount_cents
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status = 'prepared' AND i.stripe_livemode = 1 ORDER BY i.id"
        )->fetchAll();
        if ($preparedLive !== []) {
            $add('invoices_prepared_live', 'error',
                'Ostré platby bez faktúry: ' . count($preparedLive) . ' (stav „pripravená, neodoslaná“) — '
                . self::names($preparedLive, static fn (array $r): string => sprintf('%s (#%d) %s', (string) $r['invoice_company_name'], (int) $r['account_id'], self::eur((int) ($r['stripe_amount_paid_cents'] ?? $r['amount_cents']))))
                . '. Najprv nastav IDOKLAD_ISSUE_MODE=send — denná kontrola ich potom vystaví sama.');
        }

        // A real payment skipped because iDoklad had no credentials.
        $skippedLive = $pdo->query(
            "SELECT i.account_id, a.invoice_company_name, i.stripe_amount_paid_cents, i.amount_cents
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status = 'skipped' AND i.stripe_livemode = 1 ORDER BY i.id"
        )->fetchAll();
        if ($skippedLive !== []) {
            $add('invoices_skipped_live', 'error',
                'Ostré platby bez faktúry, lebo iDoklad nebol nastavený: ' . count($skippedLive) . ' — '
                . self::names($skippedLive, static fn (array $r): string => sprintf('%s (#%d) %s', (string) $r['invoice_company_name'], (int) $r['account_id'], self::eur((int) ($r['stripe_amount_paid_cents'] ?? $r['amount_cents']))))
                . '. Nastav IDOKLAD_CLIENT_ID / IDOKLAD_CLIENT_SECRET — denná kontrola ich potom vystaví.');
        }

        // Accounts the seat reconcile leaves for a person, or that failed.
        $seats = $pdo->query(
            "SELECT id, invoice_company_name, seat_sync_state, seat_sync_note, extra_technicians, stripe_extra_quantity
             FROM   accounts
             WHERE  seat_sync_state IN ('manual', 'error')
               AND  stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''
               AND  (stripe_status IS NULL OR stripe_status NOT IN ('canceled', 'incomplete_expired'))
             ORDER  BY id"
        )->fetchAll();
        if ($seats !== []) {
            $manual = array_values(array_filter($seats, static fn (array $r): bool => $r['seat_sync_state'] === 'manual'));
            $errors = array_values(array_filter($seats, static fn (array $r): bool => $r['seat_sync_state'] === 'error'));
            $add('seats', 'error', 'Technici sa do Stripe nesynchronizujú — na ručné riešenie: ' . count($manual) . ', s chybou: ' . count($errors) . '. '
                . self::names($seats, static fn (array $r): string => sprintf('%s (#%d) %s, chce %d, v Stripe %s: %s', (string) $r['invoice_company_name'], (int) $r['id'],
                    $r['seat_sync_state'] === 'manual' ? 'ručne' : 'chyba', (int) $r['extra_technicians'],
                    $r['stripe_extra_quantity'] === null ? '?' : (string) (int) $r['stripe_extra_quantity'], mb_substr((string) $r['seat_sync_note'], 0, 160)))
                . '. Pozri bin/billing-seat-report.php.');
        }

        // Customers with more than one live subscription (the daily run).
        $multi = $pdo->query(
            "SELECT id, invoice_company_name, stripe_subscription_id, stripe_status, stripe_other_subscriptions
             FROM   accounts WHERE stripe_other_subscriptions IS NOT NULL AND stripe_other_subscriptions <> '' ORDER BY id"
        )->fetchAll();
        if ($multi !== []) {
            $add('subscriptions_multiple', 'error', 'Zákazníci s viac ako jedným živým predplatným v Stripe: ' . count($multi) . ' — '
                . self::names($multi, static function (array $r): string {
                    $others = json_decode((string) $r['stripe_other_subscriptions'], true) ?: [];
                    return sprintf('%s (#%d): používa sa %s (%s), ďalšie %s', (string) $r['invoice_company_name'], (int) $r['id'],
                        (string) $r['stripe_subscription_id'], (string) $r['stripe_status'],
                        implode(', ', array_map(static fn (array $o): string => $o['id'] . ' (' . $o['status'] . ', '
                            . ((int) $o['extra_qty'] > 0 ? 'nesie ' . (int) $o['extra_qty'] . ' extra technik(ov)' : 'bez extra technika') . ')', $others)));
                })
                . '. Aplikácia staré predplatné nezruší — rozhodni v Stripe.');
        }

        $events = $pdo->query(
            "SELECT SUM(status = 'failed') AS failed,
                    SUM(status = 'processing' AND updated_at < NOW() - INTERVAL " . self::STALE_EVENT_MINUTES . " MINUTE) AS stuck
             FROM   stripe_webhook_events"
        )->fetch();
        $failedEv = (int) ($events['failed'] ?? 0);
        $stuck    = (int) ($events['stuck'] ?? 0);
        if ($failedEv + $stuck > 0) {
            $add('webhook_events', 'warn',
                "Udalosti zo Stripe, ktoré sa nespracovali: $failedEv zlyhaných, $stuck zaseknutých. Denná kontrola ich spustí znova a upozorní správcu.");
        }
        $gone = $pdo->query(
            "SELECT event_id, event_type, error_message FROM stripe_webhook_events
             WHERE  status = 'gone' AND updated_at > NOW() - INTERVAL " . self::GONE_EVENT_LIST_DAYS . ' DAY ORDER BY received_at'
        )->fetchAll();
        if ($gone !== []) {
            $add('webhook_events_gone', 'warn', 'Udalosti zo Stripe, ktoré sa už nespracujú (Stripe ich nevráti alebo zlyhávajú viac ako '
                . BillingController::EVENT_GIVE_UP_DAYS . ' dní): ' . count($gone) . ' — '
                . self::names($gone, static fn (array $r): string => $r['event_id'] . ' (' . $r['event_type'] . ')')
                . '. Skontroluj dotknuté účty ručne.');
        }

        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([self::DAILY_REFUSED_KEY]);
        $refused = $stmt->fetchColumn();
        if ($refused !== false) {
            $add('daily_refused', 'error', 'Denná kontrola odmietla zapisovať do Stripe (' . $refused . '). '
                . 'Faktúry a upozornenia bežia, počty technikov a opakovanie udalostí nie. V produkcii nastav APP_ENV=production.');
        }

        $stmt->execute([self::DAILY_RUN_KEY]);
        $last = $stmt->fetchColumn();
        if ($last === false || strtotime((string) $last) < time() - 36 * 3600) {
            $add('daily', $prod || $liveKey ? 'error' : 'warn', 'Denná kontrola fakturácie (bin/billing-daily.php) '
                . ($last === false ? 'ešte celá nebežala.' : 'naposledy celá bežala ' . $last . '.'));
        } else {
            $add('daily', 'ok', 'Denná kontrola fakturácie naposledy bežala ' . $last . '.');
        }
    }

    /** Slovak label of an invoices.review_reason. */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'credit'           => 'dobropis / kredit',
            'mismatch'         => 'nesúhlasia sumy',
            'uncertain_send'   => 'iDoklad neodpovedal jednoznačne',
            'missing_snapshot' => 'chýba faktúra zo Stripe',
            'zero'             => '0 €',
            'discount'         => 'zľava',
            default            => $reason !== '' ? $reason : 'na kontrolu',
        };
    }

    /**
     * „a, b, c a ďalšie 4" — the first NAMED rows.
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): string $label
     */
    private static function names(array $rows, callable $label): string
    {
        $named = array_map($label, array_slice($rows, 0, self::NAMED));
        $more = count($rows) - count($named);
        return implode('; ', $named) . ($more > 0 ? " a ďalšie $more" : '');
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /**
     * Reads STRIPE_PRODUCT_EXTRA_TECHNICIAN from Stripe: it must exist, be
     * active and must not be the product of STRIPE_PRICE_MONTHLY / _YEARLY
     * (the plan's own item would then be taken for the extra item).
     *
     * @return array{0: string, 1: 'ok'|'warn'|'error', 2: string}
     */
    private static function checkExtraProduct(string $productId): array
    {
        $baseProducts = SeatSync::baseProductIds();
        if ($baseProducts === null) {
            return ['stripe_product_extra', 'warn', 'Produkty základných cien (STRIPE_PRICE_MONTHLY / _YEARLY) sa zo Stripe nepodarilo načítať — produkt pre extra technika sa nedá overiť.'];
        }
        if (in_array($productId, $baseProducts, true)) {
            return ['stripe_product_extra', 'error',
                'STRIPE_PRODUCT_EXTRA_TECHNICIAN je produkt základného predplatného (cena STRIPE_PRICE_MONTHLY / _YEARLY). '
                . 'Synchronizácia technikov sa preto zastavila. Nastav ID samostatného produktu „POapp — extra technik".'];
        }
        try {
            $product = StripeClient::get()->products->retrieve($productId);
        } catch (\Throwable $e) {
            return ['stripe_product_extra', 'error', "Produkt STRIPE_PRODUCT_EXTRA_TECHNICIAN ($productId) sa v Stripe nenašiel: " . $e->getMessage()];
        }
        if (!$product->active) {
            return ['stripe_product_extra', 'error', "Produkt STRIPE_PRODUCT_EXTRA_TECHNICIAN ($productId) je v Stripe archivovaný — nové položky extra technika nevzniknú."];
        }
        return ['stripe_product_extra', 'ok', 'Produkt pre extra technika: „' . $product->name . '".'];
    }
}
