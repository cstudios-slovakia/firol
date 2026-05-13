<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Idoklad\InvoiceIssuer;
use Firol\Stripe\StripeClient;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe-backed subscription management.
 *
 * Flow:
 *  - Registration creates a Stripe Customer (no card, no subscription yet)
 *    and stores stripe_customer_id on the account. accounts.subscription_end_date
 *    is set to today + trial_days from system_settings — a *local* trial that
 *    doesn't cost anything until the user opts in to a real subscription.
 *  - When the trial is running out (or after it expires), the user clicks
 *    "Zaplatiť" → POST /api/billing/checkout → we create a Stripe Checkout
 *    Session in subscription mode with the chosen Price and redirect them
 *    to Stripe's hosted page. The card is collected by Stripe, not us.
 *  - When the subscription becomes active or renews, the
 *    `customer.subscription.created/updated` webhook lands here and we
 *    sync `subscription_end_date` (= Stripe's `current_period_end`),
 *    `stripe_subscription_id`, `stripe_status`. That moment also lifts
 *    the 6a read-only gate.
 *  - "Spravovať predplatné" hits /api/billing/portal → a Stripe-hosted
 *    Customer Portal session where the user can change card, see
 *    invoices, switch plan, or cancel. Cancellations come back as
 *    `customer.subscription.deleted`.
 */
final class BillingController
{
    /** Allowed billing periods — must match the env-configured Stripe Prices. */
    private const PERIODS = ['monthly', 'yearly'];

    /**
     * POST /api/billing/checkout
     * Body: { billing_period?: 'monthly'|'yearly' }
     *
     * Returns the Stripe Checkout URL — the frontend redirects there.
     */
    public static function checkout(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $billingPeriod = $req->jsonString('billing_period') ?? 'monthly';
        if (!in_array($billingPeriod, self::PERIODS, true)) {
            Response::error('billing_period must be monthly or yearly', 422);
        }

        $account = self::loadAccountFull($accountId);

        // Billing details (address + IČO) are required before any Stripe session
        // can be created — iDoklad needs them to issue a proper Slovak invoice.
        if (
            empty(trim($account['invoice_street']      ?? '')) ||
            empty(trim($account['invoice_postal_code'] ?? '')) ||
            empty(trim($account['invoice_city']        ?? '')) ||
            empty(trim($account['invoice_ico']         ?? ''))
        ) {
            Response::error(
                'Pred aktiváciou predplatného vyplň fakturačné údaje (adresa a IČO) v nastaveniach.',
                422
            );
        }

        $customerId = self::ensureCustomer($account);

        $base    = StripeClient::appBaseUrl();
        $success = $base . '/settings?checkout=success';
        $cancel  = $base . '/settings?checkout=cancel';

        // Spec: subscription must respect any remaining trial. If the
        // user pays today but their local trial ends in 10 days, Stripe
        // should not bill until then — pass `trial_end` so Stripe runs
        // its own trial that aligns with ours. Skip when there's no
        // remaining trial (e.g. they already lapsed).
        $subscriptionData = [
            'metadata' => [
                'firol_account_id' => (string) $accountId,
                'billing_period'   => $billingPeriod,
            ],
        ];
        $trialEnd = self::trialEndUnix($account['subscription_end_date'] ?? null);
        if ($trialEnd !== null) {
            $subscriptionData['trial_end'] = $trialEnd;
        }

        try {
            $session = StripeClient::get()->checkout->sessions->create([
                'mode'        => 'subscription',
                'customer'    => $customerId,
                'line_items'  => [[
                    'price'    => StripeClient::priceFor($billingPeriod),
                    'quantity' => 1,
                ]],
                'success_url' => $success . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $cancel,
                'allow_promotion_codes' => true,
                // Force Slovak — without this Stripe auto-detects from
                // Accept-Language. The "X dni/dní" wording on their page
                // is Stripe's own translation; we can't override it
                // beyond picking 'en' or 'auto'.
                'locale'      => 'sk',
                'subscription_data' => $subscriptionData,
                'metadata' => [
                    'firol_account_id' => (string) $accountId,
                    'billing_period'   => $billingPeriod,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.checkout] ' . $e->getMessage());
            Response::error('Stripe Checkout sa nepodaril spustiť', 502);
        }

        // Persist the chosen period eagerly so the UI reflects the user's
        // intent even before the webhook fires.
        Db::pdo()->prepare(
            'UPDATE accounts SET billing_period = ? WHERE id = ?'
        )->execute([$billingPeriod, $accountId]);

        Response::json([
            'url' => $session->url,
            'id'  => $session->id,
        ]);
    }

    /**
     * GET /api/billing/invoices — list invoices we've recorded for the
     * active account (Stripe payments; idoklad numbers when iDoklad
     * issued them).
     */
    public static function invoices(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $stmt = Db::pdo()->prepare(
            'SELECT id, stripe_invoice_id, idoklad_invoice_id, document_number,
                    amount_cents, currency, status, issued_at
             FROM   invoices
             WHERE  account_id = ?
             ORDER  BY issued_at DESC, id DESC
             LIMIT  100'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();

        $items = array_map(static fn(array $r) => [
            'id'                 => (int) $r['id'],
            'stripe_invoice_id'  => (string) $r['stripe_invoice_id'],
            'idoklad_invoice_id' => $r['idoklad_invoice_id'] !== null ? (int) $r['idoklad_invoice_id'] : null,
            'document_number'    => $r['document_number'] ?: null,
            'amount_cents'       => (int) $r['amount_cents'],
            'currency'           => (string) $r['currency'],
            'status'             => (string) $r['status'],
            'issued_at'          => $r['issued_at'],
        ], $rows);

        Response::json(['items' => $items]);
    }

    /**
     * POST /api/billing/portal
     * Stripe-hosted Customer Portal — change card, see invoices, cancel.
     */
    public static function portal(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $account = self::loadAccount($accountId);
        $customerId = self::ensureCustomer($account);

        try {
            $session = StripeClient::get()->billingPortal->sessions->create([
                'customer'    => $customerId,
                'return_url'  => StripeClient::appBaseUrl() . '/settings',
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.portal] ' . $e->getMessage());
            Response::error('Stripe Portal sa nepodarilo otvoriť', 502);
        }

        Response::json(['url' => $session->url]);
    }

    /**
     * POST /api/billing/webhook
     *
     * No CSRF, no session — Stripe authenticates itself via the
     * Stripe-Signature header. The body must be read RAW (not decoded)
     * so the HMAC matches.
     */
    public static function webhook(Request $req): void
    {
        $payload  = (string) file_get_contents('php://input');
        $sigHeader = $req->header('stripe-signature') ?? '';
        $secret   = StripeClient::webhookSecret();

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            error_log('[billing.webhook] bad signature: ' . $e->getMessage());
            Response::error('Invalid signature', 400);
        } catch (\Throwable $e) {
            error_log('[billing.webhook] parse error: ' . $e->getMessage());
            Response::error('Bad request', 400);
        }

        $type = (string) $event->type;
        $obj  = $event->data->object;

        switch ($type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                self::handleSubscriptionUpsert($obj);
                break;

            case 'customer.subscription.deleted':
                self::handleSubscriptionDeleted($obj);
                break;

            case 'invoice.payment_succeeded':
                error_log("[billing.webhook] payment ok customer={$obj->customer} amount={$obj->amount_paid}");
                $accId = self::findAccountByCustomer((string) $obj->customer);
                if ($accId !== null) {
                    (new InvoiceIssuer())->issueForStripePayment($accId, $obj->toArray());
                }
                break;

            case 'invoice.payment_failed':
                error_log("[billing.webhook] payment FAILED customer={$obj->customer} amount={$obj->amount_due}");
                break;

            default:
                // Other events are noise for us — ack so Stripe doesn't retry.
                break;
        }

        Response::noContent();
    }

    private static function handleSubscriptionUpsert(\Stripe\StripeObject $sub): void
    {
        $customerId = (string) $sub->customer;
        if ($customerId === '') {
            return;
        }
        $accountId = self::findAccountByCustomer($customerId);
        if ($accountId === null) {
            error_log("[billing.webhook] unknown customer $customerId");
            return;
        }

        $items = $sub->items->data ?? [];

        // Stripe API ≥ 2025-04 moved `current_period_end` from the
        // Subscription root onto each Subscription Item. Try root first
        // for backwards-compatibility, then fall back to the first item.
        $periodEnd = null;
        if (isset($sub->current_period_end) && is_int($sub->current_period_end)) {
            $periodEnd = $sub->current_period_end;
        } elseif (!empty($items) && isset($items[0]->current_period_end) && is_int($items[0]->current_period_end)) {
            $periodEnd = $items[0]->current_period_end;
        }

        $endDate = $periodEnd !== null ? date('Y-m-d', $periodEnd) : null;

        // Subscription on trial without a paid period yet — fall back to
        // the trial_end the Stripe object carries.
        if ($endDate === null && isset($sub->trial_end) && is_int($sub->trial_end)) {
            $endDate = date('Y-m-d', $sub->trial_end);
        }

        $status = (string) ($sub->status ?? '');
        $period = self::inferBillingPeriod($items);

        $pdo = Db::pdo();
        if ($endDate !== null) {
            $pdo->prepare(
                'UPDATE accounts
                 SET    stripe_subscription_id = ?,
                        stripe_status          = ?,
                        billing_period         = COALESCE(?, billing_period),
                        subscription_end_date  = ?
                 WHERE  id = ?'
            )->execute([(string) $sub->id, $status, $period, $endDate, $accountId]);
        } else {
            $pdo->prepare(
                'UPDATE accounts
                 SET    stripe_subscription_id = ?,
                        stripe_status          = ?,
                        billing_period         = COALESCE(?, billing_period)
                 WHERE  id = ?'
            )->execute([(string) $sub->id, $status, $period, $accountId]);
        }

        error_log("[billing.webhook] account=$accountId subscription={$sub->id} status=$status end=$endDate");
    }

    private static function handleSubscriptionDeleted(\Stripe\StripeObject $sub): void
    {
        $customerId = (string) $sub->customer;
        $accountId  = self::findAccountByCustomer($customerId);
        if ($accountId === null) {
            return;
        }
        // Don't yank `subscription_end_date` backwards — let the user keep
        // access until the period they already paid for ends. Stripe sets
        // status='canceled' on the final delete event.
        Db::pdo()->prepare(
            'UPDATE accounts SET stripe_status = ? WHERE id = ?'
        )->execute([(string) ($sub->status ?? 'canceled'), $accountId]);
    }

    /** @return array<string, mixed> */
    private static function loadAccount(int $accountId): array
    {
        return self::loadAccountFull($accountId);
    }

    /**
     * Same as loadAccount but also returns subscription_end_date so
     * callers can compute a trial alignment. Kept separate to leave
     * loadAccount's leaner signature available to callers that don't
     * care about the trial.
     *
     * @return array<string, mixed>
     */
    private static function loadAccountFull(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, invoice_company_name, stripe_customer_id, subscription_end_date,
                    invoice_street, invoice_postal_code, invoice_city, invoice_ico
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Account not found', 404);
        }
        return $row;
    }

    /**
     * Convert a stored YYYY-MM-DD trial-end date into a Unix timestamp
     * Stripe will accept for `subscription_data.trial_end`. Returns
     * null when the trial has already lapsed (or the date is missing)
     * so the subscription starts billing immediately.
     *
     * Stripe requires trial_end to be at least 48 h in the future; if
     * the remaining trial is shorter than that we skip the trial too
     * rather than 400 from Checkout.
     */
    private static function trialEndUnix(?string $endDate): ?int
    {
        if ($endDate === null || $endDate === '') {
            return null;
        }
        // Trial expires at end-of-day local time — use 23:59:59 to give
        // the user the full last day.
        $ts = strtotime($endDate . ' 23:59:59');
        if ($ts === false) {
            return null;
        }
        $minimum = time() + 48 * 3600;
        return $ts > $minimum ? $ts : null;
    }

    /**
     * Create (and persist) a Stripe Customer if the account doesn't have
     * one yet — keeps the registration path simple and recovers
     * gracefully if Stripe was misconfigured at signup time.
     *
     * @param array<string, mixed> $account
     */
    private static function ensureCustomer(array $account): string
    {
        $existing = (string) ($account['stripe_customer_id'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        // Pull the main user's email so the Stripe Customer record is
        // identifiable in the dashboard.
        $userStmt = Db::pdo()->prepare(
            'SELECT u.fullname, u.email
             FROM   accounts a
             JOIN   users    u ON u.id = a.main_user_id
             WHERE  a.id = ?'
        );
        $userStmt->execute([$account['id']]);
        $user = $userStmt->fetch() ?: [];

        try {
            $customer = StripeClient::get()->customers->create([
                'email'    => $user['email']    ?? null,
                'name'     => $account['invoice_company_name'] ?? null,
                'metadata' => [
                    'firol_account_id' => (string) $account['id'],
                    'firol_user_name'  => $user['fullname'] ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.ensureCustomer] ' . $e->getMessage());
            Response::error('Nepodarilo sa vytvoriť Stripe Customer', 502);
        }

        Db::pdo()->prepare(
            'UPDATE accounts SET stripe_customer_id = ? WHERE id = ?'
        )->execute([$customer->id, $account['id']]);

        return $customer->id;
    }

    private static function findAccountByCustomer(string $customerId): ?int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM accounts WHERE stripe_customer_id = ?'
        );
        $stmt->execute([$customerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Map Stripe Price IDs back to our coarse monthly/yearly bucket. If
     * the price doesn't match either env-configured ID we leave it
     * untouched so an admin-side price tweak doesn't wipe the column.
     *
     * @param array<int, \Stripe\StripeObject> $items
     */
    private static function inferBillingPeriod(array $items): ?string
    {
        foreach ($items as $item) {
            $priceId = (string) ($item->price->id ?? '');
            if ($priceId !== '' && $priceId === ($_ENV['STRIPE_PRICE_MONTHLY'] ?? null)) {
                return 'monthly';
            }
            if ($priceId !== '' && $priceId === ($_ENV['STRIPE_PRICE_YEARLY'] ?? null)) {
                return 'yearly';
            }
        }
        return null;
    }
}
