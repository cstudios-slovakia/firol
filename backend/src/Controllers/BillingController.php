<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Billing\SeatSync;
use Firol\Billing\SubscriptionStatus;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Idoklad\InvoiceIssuer;
use Firol\Stripe\StripeClient;
use Firol\Stripe\StripeShape;
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
 *  - The main user clicks "Aktivovať predplatné" → POST /api/billing/checkout
 *    → a Stripe Checkout Session in subscription mode with the chosen Price
 *    (plus the extra-technician line when the team is already above the
 *    included count). The card is collected by Stripe, not us.
 *  - `customer.subscription.*` webhooks (and checkout-sync right after the
 *    redirect back) re-read the subscription from Stripe and store
 *    `subscription_end_date` (= current_period_end), `stripe_subscription_id`,
 *    `stripe_status` and the billing period, then let SeatSync push the
 *    wanted extra-technician quantity to Stripe.
 *  - "Spravovať kartu" / "Zaplatiť" hit /api/billing/portal → a Stripe-hosted
 *    Customer Portal session (card, invoices, paying an open invoice).
 *
 * Only the account's main user (or an app admin) may start a checkout,
 * cancel, resume, open the portal or sync after checkout.
 */
final class BillingController
{
    /** Allowed billing periods — must match the env-configured Stripe Prices. */
    private const PERIODS = ['monthly', 'yearly'];

    /** Minutes after which an event still marked `processing` is taken over. */
    private const STALE_PROCESSING_MINUTES = 10;

    private const MAIN_USER_ONLY = 'Predplatné môže spravovať iba hlavný používateľ účtu.';

    /**
     * POST /api/billing/checkout
     * Body: { billing_period?: 'monthly'|'yearly' }
     *
     * Returns the Stripe Checkout URL — the frontend redirects there.
     */
    public static function checkout(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::requireMainUser(self::MAIN_USER_ONLY);

        $billingPeriod = $req->jsonString('billing_period') ?? 'monthly';
        if (!in_array($billingPeriod, self::PERIODS, true)) {
            Response::error('Neplatné fakturačné obdobie (očakáva sa „monthly“ alebo „yearly“).', 422);
        }

        // One checkout at a time per account: two clicks (or two tabs) must
        // not open two sessions that could both complete.
        $lock = Db::pdo()->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute(['firol_checkout_' . $accountId]);
        if ((int) $lock->fetchColumn() !== 1) {
            Response::error('Platba sa práve pripravuje. Skús to o chvíľu znova.', 409);
        }

        $account = self::loadAccountFull($accountId);

        // Never stack a second subscription on a live one — also not on one
        // whose payment failed: that one is paid through the portal.
        $existingStatus = (string) ($account['stripe_status'] ?? '');
        self::refuseLiveSubscription($existingStatus);

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

        // The DB may be behind Stripe (a webhook not delivered yet, a session
        // completed in another tab): ask Stripe for a live subscription of the
        // customer before opening a new session.
        $live = self::findLiveSubscription($customerId);
        if ($live !== null) {
            self::handleSubscriptionUpsert($live);
            self::refuseLiveSubscription((string) $live->status);
        }
        self::expireOpenCheckoutSessions($customerId);

        // Push the latest billing details onto the Stripe Customer so any
        // Stripe-side document (and the fallback receipt we surface when
        // iDoklad is unavailable) carries the company address and tax IDs.
        self::syncCustomerBillingDetails($customerId, $account);

        // Land back on /billing — that's where the checkout success handler
        // lives (calls /api/billing/checkout-sync to pull the fresh
        // subscription state from Stripe before the webhook arrives).
        $base    = StripeClient::appBaseUrl();
        $success = $base . '/billing?checkout=success';
        $cancel  = $base . '/billing?checkout=cancel';

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

        // The wanted extra quantity is computed fresh, so a technician
        // invited a moment ago is on the first invoice.
        $extra = SeatSync::wantedExtra($accountId, (int) $account['included_technicians']);
        try {
            $lineItems = self::checkoutLineItems($billingPeriod, $extra);
        } catch (\RuntimeException $e) {
            error_log('[billing.checkout] ' . $e->getMessage());
            Response::error('Platobná brána nemá na serveri nastavený produkt pre extra technika (STRIPE_PRODUCT_EXTRA_TECHNICIAN).', 500);
        }

        try {
            $session = StripeClient::get()->checkout->sessions->create([
                'mode'        => 'subscription',
                'customer'    => $customerId,
                'line_items'  => $lineItems,
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

        // billing_period is NOT stored here: it is taken from the
        // subscription once it exists (webhook / checkout-sync), so an
        // abandoned checkout leaves the account as it was.

        Response::json([
            'url' => $session->url,
            'id'  => $session->id,
        ]);
    }

    /**
     * Line items of a new subscription: the base Price, plus the extra
     * technicians beyond the included count under the fixed extra product.
     *
     * @return list<array<string, mixed>>
     * @throws \RuntimeException when extras are wanted and the product is not configured
     */
    public static function checkoutLineItems(string $billingPeriod, int $extra): array
    {
        $lineItems = [[
            'price'    => StripeClient::priceFor($billingPeriod),
            'quantity' => 1,
        ]];
        if ($extra > 0) {
            $lineItems[] = [
                'price_data' => SeatSync::extraPriceData($billingPeriod),
                'quantity'   => $extra,
            ];
        }
        return $lineItems;
    }

    private static function refuseLiveSubscription(string $status): void
    {
        if ($status === 'past_due' || $status === 'unpaid') {
            Response::error(
                'Predplatné má nezaplatenú platbu. Zaplať ju cez „Zaplatiť“ (Stripe portál) — nové predplatné netreba.',
                409,
                ['code' => 'subscription_unpaid'],
            );
        }
        if (SubscriptionStatus::isLive($status)) {
            Response::error(
                'Účet už má aktívne predplatné. Spravuj ho cez Stripe portál.',
                409,
                ['code' => 'subscription_live'],
            );
        }
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
                    idoklad_public_url, stripe_invoice_pdf_url,
                    amount_cents, currency, status, issued_at
             FROM   invoices
             WHERE  account_id = ?
             ORDER  BY issued_at DESC, id DESC
             LIMIT  100'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();

        $items = array_map(static function (array $r): array {
            // Prefer the iDoklad-issued Slovak invoice; fall back to the
            // Stripe-hosted PDF receipt when iDoklad is off or errored.
            $pdfUrl = $r['idoklad_public_url'] ?: ($r['stripe_invoice_pdf_url'] ?: null);
            return [
                'id'                 => (int) $r['id'],
                'stripe_invoice_id'  => (string) $r['stripe_invoice_id'],
                'idoklad_invoice_id' => $r['idoklad_invoice_id'] !== null ? (int) $r['idoklad_invoice_id'] : null,
                'document_number'    => $r['document_number'] ?: null,
                'amount_cents'       => (int) $r['amount_cents'],
                'currency'           => (string) $r['currency'],
                'status'             => (string) $r['status'],
                'issued_at'          => $r['issued_at'],
                'pdf_url'            => $pdfUrl,
            ];
        }, $rows);

        Response::json(['items' => $items]);
    }

    /**
     * POST /api/billing/cancel
     *
     * Schedules the active subscription for cancellation at period end.
     * The user keeps full access until `subscription_end_date`; Stripe
     * fires `customer.subscription.updated` with cancel_at_period_end=1
     * which we mirror into the account row.
     */
    public static function cancel(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::requireMainUser(self::MAIN_USER_ONLY);

        $stmt = Db::pdo()->prepare(
            'SELECT stripe_subscription_id, stripe_status
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch() ?: [];

        $subId = (string) ($row['stripe_subscription_id'] ?? '');
        if ($subId === '') {
            Response::error('Nemáš aktívne predplatné na zrušenie.', 422);
        }
        $status = (string) ($row['stripe_status'] ?? '');
        if (!in_array($status, ['active', 'trialing', 'past_due'], true)) {
            Response::error('Predplatné nie je v stave, ktorý je možné zrušiť.', 422);
        }

        try {
            StripeClient::get()->subscriptions->update($subId, [
                'cancel_at_period_end' => true,
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.cancel] ' . $e->getMessage());
            Response::error('Zrušenie predplatného zlyhalo.', 502);
        }

        // Persist eagerly — webhook will reconfirm asynchronously.
        Db::pdo()->prepare(
            'UPDATE accounts SET stripe_cancel_at_period_end = 1 WHERE id = ?'
        )->execute([$accountId]);

        Response::json(['ok' => true]);
    }

    /**
     * POST /api/billing/resume
     *
     * Reverses a scheduled cancel-at-period-end (only meaningful while
     * the period hasn't ended yet — afterwards the subscription is
     * fully canceled and the user has to re-checkout).
     */
    public static function resume(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::requireMainUser(self::MAIN_USER_ONLY);

        $stmt = Db::pdo()->prepare(
            'SELECT stripe_subscription_id FROM accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $subId = (string) ($stmt->fetchColumn() ?: '');
        if ($subId === '') {
            Response::error('Nemáš aktívne predplatné.', 422);
        }

        try {
            StripeClient::get()->subscriptions->update($subId, [
                'cancel_at_period_end' => false,
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.resume] ' . $e->getMessage());
            Response::error('Obnovenie predplatného zlyhalo.', 502);
        }

        Db::pdo()->prepare(
            'UPDATE accounts SET stripe_cancel_at_period_end = 0 WHERE id = ?'
        )->execute([$accountId]);

        Response::json(['ok' => true]);
    }

    /**
     * POST /api/billing/portal
     * Stripe-hosted Customer Portal — change card, see invoices, pay an open
     * invoice of a past_due / unpaid subscription.
     */
    public static function portal(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::requireMainUser(self::MAIN_USER_ONLY);

        $account = self::loadAccount($accountId);
        $customerId = self::ensureCustomer($account);

        try {
            $session = StripeClient::get()->billingPortal->sessions->create([
                'customer'    => $customerId,
                'return_url'  => StripeClient::appBaseUrl() . '/billing',
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.portal] ' . $e->getMessage());
            Response::error('Stripe Portal sa nepodarilo otvoriť', 502);
        }

        Response::json(['url' => $session->url]);
    }

    /**
     * POST /api/billing/checkout-sync
     *
     * Called by the frontend immediately after Stripe redirects back with
     * ?checkout=success. We don't trust the session_id alone — we query
     * Stripe for the customer's most recent subscription directly, so even
     * if the session is unknown or the webhook is delayed we get the real
     * state every time. Also pushes the wanted extra quantity (a technician
     * invited while Checkout was open).
     *
     * Non-fatal — if Stripe is unreachable we just return 204 and the
     * webhook will catch up later anyway.
     */
    public static function checkoutSync(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::requireMainUser(self::MAIN_USER_ONLY);

        $stmt = Db::pdo()->prepare(
            'SELECT stripe_customer_id FROM accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $customerId = (string) ($stmt->fetchColumn() ?: '');
        if ($customerId === '') {
            Response::noContent();
        }

        $sub = self::fetchLatestSubscription($customerId);
        if ($sub !== null) {
            try {
                self::handleSubscriptionUpsert($sub);
            } catch (\Throwable $e) {
                error_log('[billing.checkoutSync] ' . $e->getMessage());
            }
        }

        Response::noContent();
    }

    /**
     * Query Stripe for the most recently created subscription belonging to
     * the given customer, across any status (including trialing/active).
     * Returns null on error or when the customer has no subscriptions.
     */
    private static function fetchLatestSubscription(string $customerId): ?\Stripe\Subscription
    {
        try {
            $list = StripeClient::get()->subscriptions->all([
                'customer' => $customerId,
                'status'   => 'all',
                'limit'    => 10,
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.fetchLatestSubscription] ' . $e->getMessage());
            return null;
        }

        $best = null;
        $bestRank = -1;
        // Prefer active/trialing over canceled/incomplete so a stale canceled
        // sub doesn't shadow a freshly purchased one.
        $rank = [
            'active'             => 5,
            'trialing'           => 4,
            'past_due'           => 3,
            'unpaid'             => 2,
            'incomplete'         => 1,
            'incomplete_expired' => 0,
            'canceled'           => 0,
            'paused'             => 0,
        ];
        foreach ($list->data as $s) {
            $r = $rank[$s->status] ?? -1;
            if ($r > $bestRank || ($r === $bestRank && (int) $s->created > (int) ($best->created ?? 0))) {
                $best = $s;
                $bestRank = $r;
            }
        }
        return $best;
    }

    /**
     * A subscription of the customer that still bills, or null. Throws when
     * Stripe cannot be asked — checkout must not guess.
     */
    private static function findLiveSubscription(string $customerId): ?\Stripe\Subscription
    {
        try {
            $list = StripeClient::get()->subscriptions->all([
                'customer' => $customerId,
                'status'   => 'all',
                'limit'    => 20,
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.findLiveSubscription] ' . $e->getMessage());
            Response::error('Stripe sa nepodarilo overiť. Skús to o chvíľu znova.', 502);
        }
        foreach ($list->data as $s) {
            if (SubscriptionStatus::isLive((string) $s->status)) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Expire the customer's still-open Checkout sessions, so an older tab
     * cannot complete a second subscription after this one. Best effort.
     */
    private static function expireOpenCheckoutSessions(string $customerId): void
    {
        try {
            $stripe = StripeClient::get();
            $open = $stripe->checkout->sessions->all([
                'customer' => $customerId,
                'status'   => 'open',
                'limit'    => 20,
            ]);
            foreach ($open->data as $session) {
                $stripe->checkout->sessions->expire((string) $session->id);
            }
        } catch (\Throwable $e) {
            error_log('[billing.expireOpenCheckoutSessions] ' . $e->getMessage());
        }
    }

    /**
     * POST /api/billing/webhook
     *
     * No CSRF, no session — Stripe authenticates itself via the
     * Stripe-Signature header. The body must be read RAW (not decoded)
     * so the HMAC matches.
     *
     * Each event is marked `done` only after it was handled. A failure is
     * stored as `failed` and answered with 500, so Stripe's retry handles it
     * again instead of being skipped as a duplicate.
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
            Response::error('Neplatný podpis.', 400);
        } catch (\Throwable $e) {
            error_log('[billing.webhook] parse error: ' . $e->getMessage());
            Response::error('Neplatná požiadavka.', 400);
        }

        $type    = (string) $event->type;
        $eventId = (string) ($event->id ?? '');

        $claim = self::claimEvent($eventId, $type);
        if ($claim === 'done') {
            error_log("[billing.webhook] duplicate event $eventId — skipping");
            Response::noContent();
        }
        if ($claim === 'busy') {
            Response::error('Udalosť sa práve spracúva.', 409);
        }

        try {
            self::dispatchEvent($event);
        } catch (\Throwable $e) {
            error_log("[billing.webhook] event $eventId ($type) failed: " . $e->getMessage());
            self::finishEvent($eventId, 'failed', $e->getMessage());
            Response::error('Spracovanie udalosti zlyhalo.', 500);
        }

        self::finishEvent($eventId, 'done', null);
        Response::noContent();
    }

    /** Handle one verified Stripe event. Throws to have Stripe retry it. */
    public static function dispatchEvent(\Stripe\Event $event): void
    {
        $type     = (string) $event->type;
        $obj      = $event->data->object;
        $livemode = isset($event->livemode) ? (bool) $event->livemode : null;

        switch ($type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.paused':
            case 'customer.subscription.resumed':
                self::handleSubscriptionUpsert($obj);
                break;

            case 'customer.subscription.deleted':
                self::handleSubscriptionDeleted($obj);
                break;

            case 'checkout.session.completed':
                $subId = $obj->subscription ?? null;
                $subId = is_object($subId) ? (string) ($subId->id ?? '') : (string) $subId;
                if ($subId !== '') {
                    self::handleSubscriptionUpsert(\Stripe\Subscription::constructFrom(['id' => $subId]));
                }
                break;

            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                // Both fire for every paid invoice (0 € ones too); record()
                // is idempotent on the Stripe invoice id.
                $accId = self::findAccountByCustomer((string) $obj->customer);
                if ($accId === null) {
                    error_log("[billing.webhook] invoice {$obj->id}: unknown customer {$obj->customer}");
                    break;
                }
                $rowId = InvoiceIssuer::record($accId, $obj->toArray(), $livemode);
                if ($rowId !== null) {
                    InvoiceIssuer::issueAfterResponse($rowId);
                }
                break;

            case 'invoice.finalized':
                // Stripe finalises the invoice before charging — the
                // invoice_pdf URL becomes stable here. A local row may not
                // exist yet; record() stores the URL then.
                $pdfUrl = (string) ($obj->invoice_pdf ?? '');
                $stripeInvId = (string) ($obj->id ?? '');
                if ($pdfUrl !== '' && $stripeInvId !== '') {
                    Db::pdo()->prepare(
                        'UPDATE invoices SET stripe_invoice_pdf_url = ? WHERE stripe_invoice_id = ?'
                    )->execute([$pdfUrl, $stripeInvId]);
                }
                break;

            case 'invoice.payment_failed':
                // Access rules and the customer notice for a failed renewal
                // are an open owner decision (docs/development-roadmap.md).
                error_log("[billing.webhook] payment FAILED customer={$obj->customer} amount={$obj->amount_due}");
                break;

            default:
                // Other events are noise for us — ack so Stripe doesn't retry.
                break;
        }
    }

    /**
     * Record the event as being processed. Returns 'process' (go on), 'done'
     * (already handled — skip) or 'busy' (another delivery is handling it
     * right now).
     */
    private static function claimEvent(string $eventId, string $type): string
    {
        if ($eventId === '') {
            return 'process';
        }
        $pdo = Db::pdo();
        try {
            $pdo->prepare(
                "INSERT INTO stripe_webhook_events (event_id, event_type, status, attempts)
                 VALUES (?, ?, 'processing', 1)"
            )->execute([$eventId, $type]);
            return 'process';
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
        $take = $pdo->prepare(
            "UPDATE stripe_webhook_events
             SET    status = 'processing', attempts = attempts + 1, error_message = NULL
             WHERE  event_id = ?
               AND  (status = 'failed'
                     OR (status = 'processing' AND updated_at < NOW() - INTERVAL " . self::STALE_PROCESSING_MINUTES . ' MINUTE))'
        );
        $take->execute([$eventId]);
        if ($take->rowCount() === 1) {
            return 'process';
        }
        $stmt = $pdo->prepare('SELECT status FROM stripe_webhook_events WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return (string) $stmt->fetchColumn() === 'done' ? 'done' : 'busy';
    }

    private static function finishEvent(string $eventId, string $status, ?string $error): void
    {
        if ($eventId === '') {
            return;
        }
        Db::pdo()->prepare(
            'UPDATE stripe_webhook_events
             SET    status = ?, error_message = ?, processed_at = IF(? = \'done\', NOW(), processed_at)
             WHERE  event_id = ?'
        )->execute([$status, $error !== null ? mb_substr($error, 0, 2000) : null, $status, $eventId]);
    }

    /**
     * Store the state of a subscription on its account and push the wanted
     * extra quantity.
     *
     * The subscription is read again from Stripe (unless the caller just did),
     * so an old or out-of-order event cannot write stale data, and the shape
     * is the pinned API version whatever the event's version. Events of a
     * subscription that is not the account's current one are ignored — see
     * isCurrentSubscription().
     */
    public static function handleSubscriptionUpsert(\Stripe\StripeObject $sub, bool $refetch = true): void
    {
        $subId = (string) ($sub->id ?? '');
        if ($subId === '') {
            return;
        }
        if ($refetch) {
            $sub = StripeClient::get()->subscriptions->retrieve($subId, SeatSync::RETRIEVE_EXPAND);
        }
        $data = StripeShape::arr($sub);

        $customerId = is_array($data['customer'] ?? null) ? (string) ($data['customer']['id'] ?? '') : (string) ($data['customer'] ?? '');
        if ($customerId === '') {
            return;
        }
        $account = self::findAccountRowByCustomer($customerId);
        if ($account === null) {
            error_log("[billing.webhook] unknown customer $customerId");
            return;
        }
        $accountId = (int) $account['id'];
        $status    = (string) ($data['status'] ?? '');

        if (!self::isCurrentSubscription($account, $subId, $status)) {
            error_log(sprintf(
                '[billing.webhook] account=%d ignores subscription %s (%s): current is %s (%s)%s',
                $accountId, $subId, $status,
                (string) $account['stripe_subscription_id'], (string) $account['stripe_status'],
                SubscriptionStatus::isLive($status) && SubscriptionStatus::isLive((string) $account['stripe_status'])
                    ? ' — SECOND LIVE SUBSCRIPTION, check the customer in Stripe' : '',
            ));
            return;
        }

        $items = (array) ($data['items']['data'] ?? []);
        $split = SeatSync::classifyItems($items, (string) ($account['stripe_extra_subscription_item_id'] ?? ''));
        $interval = $split['base'] !== null ? StripeShape::itemInterval($split['base']) : null;
        $period = match ($interval) {
            'year'  => 'yearly',
            'month' => 'monthly',
            default => null,
        };

        $periodEnd = StripeShape::subscriptionPeriodEnd($data);
        $endDate = $periodEnd !== null ? date('Y-m-d', $periodEnd) : null;
        // Subscription on trial without a paid period yet — fall back to
        // the trial_end the Stripe object carries.
        if ($endDate === null && isset($data['trial_end']) && is_int($data['trial_end'])) {
            $endDate = date('Y-m-d', $data['trial_end']);
        }
        $cancelFlag = !empty($data['cancel_at_period_end']) ? 1 : 0;

        Db::pdo()->prepare(
            'UPDATE accounts
             SET    stripe_subscription_id      = ?,
                    stripe_status               = ?,
                    billing_period              = COALESCE(?, billing_period),
                    stripe_cancel_at_period_end = ?,
                    subscription_end_date       = COALESCE(?, subscription_end_date)
             WHERE  id = ?'
        )->execute([$subId, $status, $period, $cancelFlag, $endDate, $accountId]);

        error_log("[billing.webhook] account=$accountId subscription=$subId status=$status end=$endDate");

        // Stripe's quantity is never copied into the DB — the DB's wanted
        // quantity is pushed to Stripe instead (repairs a failed SeatSync
        // call and a technician invited while Checkout was open).
        SeatSync::recompute($accountId);
    }

    public static function handleSubscriptionDeleted(\Stripe\StripeObject $sub): void
    {
        $subId = (string) ($sub->id ?? '');
        if ($subId === '') {
            return;
        }
        $data = StripeShape::arr(StripeClient::get()->subscriptions->retrieve($subId));
        $customerId = is_array($data['customer'] ?? null) ? (string) ($data['customer']['id'] ?? '') : (string) ($data['customer'] ?? '');
        $account = self::findAccountRowByCustomer($customerId);
        if ($account === null) {
            return;
        }
        $current = (string) ($account['stripe_subscription_id'] ?? '');
        if ($current !== '' && $current !== $subId) {
            error_log("[billing.webhook] account={$account['id']} ignores deletion of old subscription $subId (current $current)");
            return;
        }
        // Don't yank `subscription_end_date` backwards — let the user keep
        // access until the period they already paid for ends. Stripe sets
        // status='canceled' on the final delete event.
        Db::pdo()->prepare(
            'UPDATE accounts
             SET    stripe_subscription_id      = ?,
                    stripe_status               = ?,
                    stripe_cancel_at_period_end = 0
             WHERE  id = ?'
        )->execute([$subId, (string) ($data['status'] ?? 'canceled'), (int) $account['id']]);
    }

    /**
     * Whether an event of subscription $subId may change the account.
     *
     * Yes when the account has no subscription yet, when it is the account's
     * current subscription, or when the current one is over (canceled /
     * expired) and this one is not — a new subscription after the old one
     * ended. An event of any other subscription (an old one, or a second one
     * started in parallel) is ignored.
     *
     * @param array<string, mixed> $account
     */
    private static function isCurrentSubscription(array $account, string $subId, string $status): bool
    {
        $current = (string) ($account['stripe_subscription_id'] ?? '');
        if ($current === '' || $current === $subId) {
            return true;
        }
        $currentStatus = (string) ($account['stripe_status'] ?? '');
        return ($currentStatus === '' || SubscriptionStatus::isTerminal($currentStatus))
            && !SubscriptionStatus::isTerminal($status);
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
                    stripe_status,
                    invoice_street, invoice_postal_code, invoice_city, invoice_country,
                    invoice_ico, invoice_dic, invoice_ic_dph,
                    included_technicians, extra_technicians
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Účet sa nenašiel.', 404);
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

    /**
     * Mirror the account's invoice_* fields onto the Stripe Customer so the
     * hosted invoice / receipt — which we fall back to when iDoklad can't
     * issue the legal SK invoice — shows the company address and tax IDs.
     *
     * Stripe has no native slot for the Slovak IČO, so it goes into
     * customer metadata (visible in the dashboard / via API) alongside the
     * DIČ. The EU VAT number (IČ DPH) is registered as a real `eu_vat`
     * tax ID when present so it renders on the document.
     *
     * Entirely best-effort: any Stripe error here must never block checkout.
     *
     * @param array<string, mixed> $account
     */
    private static function syncCustomerBillingDetails(string $customerId, array $account): void
    {
        $ico   = trim((string) ($account['invoice_ico']    ?? ''));
        $dic   = trim((string) ($account['invoice_dic']    ?? ''));
        $icDph = trim((string) ($account['invoice_ic_dph'] ?? ''));

        try {
            StripeClient::get()->customers->update($customerId, [
                'name'    => (string) ($account['invoice_company_name'] ?? '') ?: null,
                'address' => [
                    'line1'       => (string) ($account['invoice_street']      ?? '') ?: null,
                    'postal_code' => (string) ($account['invoice_postal_code'] ?? '') ?: null,
                    'city'        => (string) ($account['invoice_city']        ?? '') ?: null,
                    'country'     => self::countryCode((string) ($account['invoice_country'] ?? 'Slovensko')),
                ],
                'metadata' => [
                    'firol_ico' => $ico,
                    'firol_dic' => $dic,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[billing.syncCustomerBillingDetails] ' . $e->getMessage());
        }

        // Register the EU VAT number as a tax ID, but only once — Stripe
        // would otherwise accumulate duplicates on every checkout.
        if ($icDph === '') {
            return;
        }
        try {
            $stripe   = StripeClient::get();
            $existing = $stripe->customers->allTaxIds($customerId, ['limit' => 20]);
            foreach ($existing->data as $tid) {
                if (strcasecmp((string) ($tid->value ?? ''), $icDph) === 0) {
                    return; // already present
                }
            }
            $stripe->customers->createTaxId($customerId, [
                'type'  => 'eu_vat',
                'value' => $icDph,
            ]);
        } catch (\Throwable $e) {
            // Invalid/foreign VAT format etc. — non-fatal, just log.
            error_log('[billing.syncCustomerTaxId] ' . $e->getMessage());
        }
    }

    /** Map a free-text country name onto an ISO 3166-1 alpha-2 code for Stripe. */
    private static function countryCode(string $name): string
    {
        $n = strtolower(trim($name));
        if ($n === '' || str_contains($n, 'sloven')) return 'SK';
        if (str_contains($n, 'česk') || str_contains($n, 'czech') || str_contains($n, 'cesk')) return 'CZ';
        return 'SK';
    }

    private static function findAccountByCustomer(string $customerId): ?int
    {
        $row = self::findAccountRowByCustomer($customerId);
        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<string, mixed>|null */
    private static function findAccountRowByCustomer(string $customerId): ?array
    {
        if ($customerId === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT id, stripe_subscription_id, stripe_status, stripe_extra_subscription_item_id
             FROM   accounts WHERE stripe_customer_id = ?'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
