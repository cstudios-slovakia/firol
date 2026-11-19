<?php

declare(strict_types=1);

namespace Firol\Billing;

use Firol\Auth\Admin;
use Firol\Controllers\BillingController;
use Firol\Db;
use Firol\Idoklad\IDokladClient;
use Firol\Idoklad\InvoiceIssuer;
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

    /** Hours after which a missing daily run is alerted from the webhook. */
    public const DAILY_STALE_HOURS = 36;

    /** OperatorAlert key of the webhook's „daily run missing" alert. */
    public const DAILY_STALE_ALERT = 'daily_stale';

    /**
     * Checks the daily run does not e-mail as ConfigCheck errors: it cannot
     * report its own absence (the webhook does, alertIfDailyStale()), and a
     * refused run is alerted on its own.
     */
    public const NOT_MAILED = ['daily', 'daily_refused'];

    /**
     * @return list<array{key: string, level: 'ok'|'warn'|'error', message: string, dismiss?: list<array{id: string, label: string}>}>
     */
    public static function run(): array
    {
        $out = [];
        $prod = strtolower(trim((string) ($_ENV['APP_ENV'] ?? ''))) === 'production';
        $add = static function (string $key, string $level, string $message, ?array $dismiss = null) use (&$out): void {
            $out[] = ['key' => $key, 'level' => $level, 'message' => $message] + ($dismiss !== null ? ['dismiss' => $dismiss] : []);
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

        // VAT: prices are net, Stripe adds VAT_RATE_PERCENT with the
        // exclusive tax rate STRIPE_TAX_RATE_ID.
        if (!Vat::percentIsSet()) {
            $add('vat_rate', 'warn', 'VAT_RATE_PERCENT nie je nastavený — počíta sa s ' . Vat::label() . '. Nastav ho v .env.');
        }
        $taxRateId = Vat::stripeTaxRateId();
        if ($taxRateId === '') {
            $add('stripe_tax_rate', 'error', 'STRIPE_TAX_RATE_ID nie je nastavený — Checkout je zablokovaný a predplatné by sa účtovalo bez DPH. '
                . 'Založ v Stripe daňovú sadzbu (Tax rates: DPH ' . Vat::label() . ', exclusive, SK) a jej ID (txr_…) daj do .env.');
        } elseif ($key !== '') {
            $add(...self::checkTaxRate($taxRateId));
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

    /** @param callable(string, string, string, ?array): void $add */
    private static function databaseChecks(callable $add, bool $prod, bool $liveKey): void
    {
        $pdo = Db::pdo();
        // A row of a Stripe TEST-mode payment is a warning in production (so
        // a test payment left on the server does not keep the check red);
        // anywhere else it counts like a live one.
        $isTest = static fn (array $r): bool => $r['stripe_livemode'] !== null && (int) $r['stripe_livemode'] === 0;
        $split = static function (array $rows) use ($prod, $isTest): array {
            if (!$prod) {
                return [$rows, []];
            }
            return [array_values(array_filter($rows, static fn (array $r): bool => !$isTest($r))),
                    array_values(array_filter($rows, $isTest))];
        };
        $label = static fn (array $r): string => sprintf('%s (#%d) %s%s', (string) $r['invoice_company_name'], (int) $r['account_id'],
            self::eur((int) ($r['stripe_amount_paid_cents'] ?? $r['amount_cents'])),
            $r['stripe_livemode'] !== null && (int) $r['stripe_livemode'] === 0 ? ' [test]' : '');

        // Paid Stripe invoices without a tax document, waiting for a person.
        $held = $pdo->query(
            "SELECT i.id, i.account_id, a.invoice_company_name, i.review_reason, i.stripe_amount_paid_cents, i.amount_cents, i.stripe_livemode
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status = 'needs_review'
             ORDER  BY i.id"
        )->fetchAll();
        [$heldLive, $heldTest] = $split($held);
        foreach ([['invoices_held', 'error', $heldLive], ['invoices_held_test', 'warn', $heldTest]] as [$k, $level, $rows]) {
            if ($rows !== []) {
                $add($k, $level, ($k === 'invoices_held_test' ? 'Testovacie platby' : 'Zaplatené faktúry') . ' bez daňového dokladu (čakajú na kontrolu): ' . count($rows) . ' — '
                    . self::names($rows, static fn (array $r): string => $label($r) . ', ' . self::reasonLabel((string) $r['review_reason']))
                    . '. Účty → faktúry účtu.');
            }
        }

        // Failed issues: below the cap the daily run tries again (warning);
        // a live row at the cap is no longer retried and needs a person.
        $failed = $pdo->query(
            "SELECT i.id, i.account_id, a.invoice_company_name, i.status, i.attempts, i.stripe_amount_paid_cents, i.amount_cents, i.stripe_livemode
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status IN ('error', 'sending') ORDER BY i.id"
        )->fetchAll();
        $atCap = static fn (array $r): bool => $r['status'] === 'error' && (int) $r['attempts'] >= InvoiceIssuer::MAX_AUTO_ATTEMPTS;
        $retried = array_values(array_filter($failed, static fn (array $r): bool => !$atCap($r)));
        $stopped = array_values(array_filter($failed, $atCap));
        $stoppedLive = array_values(array_filter($stopped, static fn (array $r): bool => (int) $r['stripe_livemode'] === 1));
        $stoppedOther = array_values(array_filter($stopped, static fn (array $r): bool => (int) $r['stripe_livemode'] !== 1));
        $failLabel = static fn (array $r): string => $label($r) . sprintf(', %s po %d pokusoch', (string) $r['status'], (int) $r['attempts']);
        if ($retried !== []) {
            $add('invoices_failed', 'warn', 'Faktúry, ktoré zlyhali alebo sa nedokončili: ' . count($retried) . ' — '
                . self::names($retried, $failLabel) . '. Denná kontrola ich skúsi znova.');
        }
        if ($stoppedLive !== []) {
            $add('invoices_failed_stopped', 'error', 'Ostré platby bez faktúry po ' . InvoiceIssuer::MAX_AUTO_ATTEMPTS . ' neúspešných pokusoch — treba zásah: ' . count($stoppedLive) . ' — '
                . self::names($stoppedLive, $failLabel) . '. Denná kontrola ich už neskúša; po odstránení príčiny „Vystaviť znova" (Účty → faktúry účtu).');
        }
        if ($stoppedOther !== []) {
            $add('invoices_failed_stopped_test', 'warn', 'Testovacie platby bez faktúry po ' . InvoiceIssuer::MAX_AUTO_ATTEMPTS . ' pokusoch (už sa neskúšajú): ' . count($stoppedOther) . ' — '
                . self::names($stoppedOther, $failLabel) . '.');
        }

        // Issued invoices whose marking as paid or e-mail to the customer
        // failed for good, or is uncertain.
        $follow = $pdo->query(
            "SELECT i.id, i.account_id, a.invoice_company_name, i.stripe_amount_paid_cents, i.amount_cents, i.stripe_livemode, i.document_number,
                    i.send_state, i.send_attempts, i.paid_mark_state, i.paid_mark_attempts
             FROM   invoices i JOIN accounts a ON a.id = i.account_id
             WHERE  i.status = 'issued'
               AND  (i.send_state = 'uncertain'
                     OR (i.send_state = 'error' AND i.send_attempts >= " . InvoiceIssuer::MAX_AUTO_ATTEMPTS . ")
                     OR (i.paid_mark_state = 'error' AND i.paid_mark_attempts >= " . InvoiceIssuer::MAX_AUTO_ATTEMPTS . '))
             ORDER  BY i.id'
        )->fetchAll();
        if ($follow !== []) {
            $add('invoices_followup', 'error', 'Vystavené faktúry, ktoré sa zákazníkovi neodoslali alebo sa neoznačili ako uhradené — treba zásah: ' . count($follow) . ' — '
                . self::names($follow, static fn (array $r): string => $label($r) . ' ' . (string) $r['document_number'] . ': '
                    . implode(', ', array_filter([
                        $r['send_state'] === 'uncertain' ? 'odoslanie neisté' : ($r['send_state'] === 'error' ? 'neodoslaná' : ''),
                        $r['paid_mark_state'] === 'error' ? 'neoznačená ako uhradená' : '',
                    ])))
                . '. Účty → faktúry účtu → „Poslať znova".');
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

        // Live subscriptions without the VAT tax rate (as the last read of
        // the subscription saw them): Stripe charges them without VAT.
        $taxRateId = Vat::stripeTaxRateId();
        if ($taxRateId !== '') {
            $noTax = $pdo->prepare(
                "SELECT id, invoice_company_name, stripe_subscription_id, stripe_tax_rate_ids FROM accounts
                 WHERE  stripe_status IN ('active', 'trialing', 'past_due', 'unpaid', 'paused')
                   AND  stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''
                   AND  (stripe_tax_rate_ids IS NULL OR stripe_tax_rate_ids <> ?)
                 ORDER  BY id"
            );
            $noTax->execute([$taxRateId]);
            $noTax = $noTax->fetchAll();
            if ($noTax !== []) {
                $add('subscriptions_no_vat', 'error', 'Živé predplatné bez daňovej sadzby DPH (STRIPE_TAX_RATE_ID): ' . count($noTax) . ' — '
                    . self::names($noTax, static fn (array $r): string => sprintf('%s (#%d) %s%s', (string) $r['invoice_company_name'], (int) $r['id'],
                        (string) $r['stripe_subscription_id'], (string) $r['stripe_tax_rate_ids'] !== '' ? ' (má ' . $r['stripe_tax_rate_ids'] . ')' : ''))
                    . '. Denná kontrola sadzbu doplní (pri BILLING_RECONCILE=on); dovtedy by sa účtovalo bez DPH.');
            }
        }

        // A recorded subscription that is over while the customer has a live
        // one (stored by a daily run that could not take it over).
        $ended = $pdo->query(
            "SELECT id, invoice_company_name, stripe_subscription_id, stripe_status, stripe_other_subscriptions
             FROM   accounts WHERE stripe_other_subscriptions IS NOT NULL AND stripe_other_subscriptions <> ''
               AND  stripe_status IN ('canceled', 'incomplete_expired') ORDER BY id"
        )->fetchAll();
        if ($ended !== []) {
            $add('subscription_ended_live', 'error', 'V databáze skončené predplatné, zákazník má v Stripe živé: ' . count($ended) . ' — '
                . self::names($ended, static function (array $r): string {
                    $others = json_decode((string) $r['stripe_other_subscriptions'], true) ?: [];
                    return sprintf('%s (#%d): %s (%s), živé %s', (string) $r['invoice_company_name'], (int) $r['id'], (string) $r['stripe_subscription_id'], (string) $r['stripe_status'],
                        implode(', ', array_map(static fn (array $o): string => $o['id'] . ' (' . $o['status'] . ')', $others)));
                })
                . '. Denná kontrola ho prevezme, keď smie zapisovať; jeho technici sa dovtedy neúčtujú.');
        }

        // Customers with more than one live subscription (the daily run).
        $multi = $pdo->query(
            "SELECT id, invoice_company_name, stripe_subscription_id, stripe_status, stripe_other_subscriptions
             FROM   accounts WHERE stripe_other_subscriptions IS NOT NULL AND stripe_other_subscriptions <> ''
               AND  (stripe_status IS NULL OR stripe_status NOT IN ('canceled', 'incomplete_expired')) ORDER BY id"
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
        // An invoice.* event that is gone may be a payment without a tax
        // document: an error until an admin dismisses it (after checking
        // the account). Other gone events are listed for a while.
        $goneInvoice = $pdo->query(
            "SELECT event_id, event_type, error_message FROM stripe_webhook_events
             WHERE  status = 'gone' AND event_type LIKE 'invoice.%' AND dismissed_at IS NULL ORDER BY received_at"
        )->fetchAll();
        if ($goneInvoice !== []) {
            $add('webhook_events_gone_invoice', 'error', 'Udalosti faktúr zo Stripe, ktoré sa už nespracujú: ' . count($goneInvoice) . ' — '
                . self::names($goneInvoice, static fn (array $r): string => $r['event_id'] . ' (' . $r['event_type'] . ')')
                . '. Platba mohla ostať bez faktúry: over ju v Stripe a v iDoklade, potom udalosť zruš („Vyriešené").',
                array_map(static fn (array $r): array => ['id' => (string) $r['event_id'], 'label' => $r['event_id'] . ' (' . $r['event_type'] . ')'], $goneInvoice));
        }
        $gone = $pdo->query(
            "SELECT event_id, event_type, error_message FROM stripe_webhook_events
             WHERE  status = 'gone' AND event_type NOT LIKE 'invoice.%'
               AND  updated_at > NOW() - INTERVAL " . self::GONE_EVENT_LIST_DAYS . ' DAY ORDER BY received_at'
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
        if ($last === false || strtotime((string) $last) < time() - self::DAILY_STALE_HOURS * 3600) {
            $add('daily', $prod || $liveKey ? 'error' : 'warn', 'Denná kontrola fakturácie (bin/billing-daily.php) '
                . ($last === false ? 'ešte celá nebežala.' : 'naposledy celá bežala ' . $last . '.'));
        } else {
            $add('daily', 'ok', 'Denná kontrola fakturácie naposledy bežala ' . $last . '.');
        }
    }

    /**
     * The webhook path: tell the operator once when the daily run has not
     * run for DAILY_STALE_HOURS (a missing cron cannot e-mail itself) — where
     * the `daily` check is an error (production, or a live Stripe key). The
     * daily run forgets the alert after each full run. Returns the
     * OperatorAlert result, or null when nothing was due.
     */
    public static function alertIfDailyStale(): ?string
    {
        $prod = strtolower(trim((string) ($_ENV['APP_ENV'] ?? ''))) === 'production';
        $liveKey = StripeClient::isConfigured() && !StripeClient::isTestKey();
        if (!$prod && !$liveKey) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([self::DAILY_RUN_KEY]);
        $last = $stmt->fetchColumn();
        if ($last !== false && strtotime((string) $last) >= time() - self::DAILY_STALE_HOURS * 3600) {
            return null;
        }
        return OperatorAlert::once(self::DAILY_STALE_ALERT, 'denná kontrola fakturácie nebeží',
            'Denná kontrola fakturácie (bin/billing-daily.php) ' . ($last === false ? 'ešte celá nebežala' : 'naposledy celá bežala ' . $last)
            . '. Bez nej sa neopakujú zlyhané faktúry, udalosti zo Stripe ani počty technikov. Skontroluj cron na serveri.',
            ['Server: ' . (gethostname() ?: '?'), 'Cron: php backend/bin/billing-daily.php (README, Production deploy checklist)']);
    }

    /** Slovak label of an invoices.review_reason. */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'credit'              => 'dobropis / kredit (pravidlo pred 19. 11. 2026 — denná kontrola ju vystaví)',
            'mismatch'            => 'nesúhlasia sumy',
            'vat_mismatch'        => 'nesedí DPH alebo súčet v iDoklade',
            'no_vat'              => 'Stripe účtoval bez DPH',
            'uncertain_send'      => 'iDoklad neodpovedal jednoznačne',
            'missing_snapshot'    => 'chýba faktúra zo Stripe',
            'snapshot_unreadable' => 'faktúru zo Stripe nemožno načítať',
            'zero'                => '0 €',
            'discount'            => 'zľava',
            'credit_carried'      => 'nič sa neplatilo, kredit prechádza ďalej',
            'credit_covered'      => 'uhradené z kreditu',
            default               => $reason !== '' ? $reason : 'na kontrolu',
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
     * Reads STRIPE_TAX_RATE_ID from Stripe: it must exist, be active,
     * exclusive (Stripe adds it to the net price) and have the percentage of
     * VAT_RATE_PERCENT.
     *
     * @return array{0: string, 1: 'ok'|'warn'|'error', 2: string}
     */
    private static function checkTaxRate(string $taxRateId): array
    {
        try {
            $rate = StripeClient::get()->taxRates->retrieve($taxRateId);
        } catch (\Throwable $e) {
            return ['stripe_tax_rate', 'error', "Daňová sadzba STRIPE_TAX_RATE_ID ($taxRateId) sa v Stripe nenašla: " . $e->getMessage()];
        }
        $problems = [];
        if (!$rate->active) {
            $problems[] = 'je archivovaná';
        }
        if ($rate->inclusive) {
            $problems[] = 'je „inclusive" (DPH by bola v cene, nie navrch)';
        }
        if (abs((float) $rate->percentage - Vat::percent()) > 0.0001) {
            $problems[] = 'má ' . Vat::label((float) $rate->percentage) . ', VAT_RATE_PERCENT je ' . Vat::label();
        }
        if ($problems !== []) {
            return ['stripe_tax_rate', 'error', "Daňová sadzba STRIPE_TAX_RATE_ID ($taxRateId) " . implode(', ', $problems) . '.'];
        }
        return ['stripe_tax_rate', 'ok', 'DPH: daňová sadzba „' . (string) $rate->display_name . '" ' . Vat::label() . ', pripočítava sa k cene.'];
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
