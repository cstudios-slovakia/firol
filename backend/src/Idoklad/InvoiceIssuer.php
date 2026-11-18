<?php

declare(strict_types=1);

namespace Firol\Idoklad;

use Firol\Billing\SeatSync;
use Firol\Db;
use Firol\Stripe\StripeClient;
use Firol\Stripe\StripeShape;

/**
 * Turns a paid Stripe invoice into an iDoklad invoice, tracked in our
 * `invoices` table so the billing history can list it without asking
 * iDoklad.
 *
 * Two steps:
 *
 *  1. record() — called by the webhook, database only (plus a Stripe call when
 *     the invoice has more lines than the payload carries). It stores the
 *     Stripe invoice with all its lines and decides:
 *       - total 0 and nothing paid (trial start, net-zero change): no row, no
 *         invoice;
 *       - a credit (negative total, a netted line below zero, or part of the
 *         total paid from the customer balance) or lines that do not add up
 *         to the total after discounts: status `needs_review` — never issued
 *         automatically, shown in the admin panel with the Stripe numbers
 *         (how credits appear on a tax document is an open owner decision);
 *       - otherwise status `pending`.
 *  2. issue() — builds the iDoklad payload (invoiceLines(): proration pairs
 *     netted, amounts net of promotion-code discounts) and,
 *     when IssuePolicy allows it, sends it. The webhook runs it only after
 *     the response went back to Stripe (issueAfterResponse), so a slow
 *     iDoklad cannot make Stripe retry. bin/billing-daily.php retries
 *     `pending` / `error` rows up to MAX_AUTO_ATTEMPTS times, and the admin
 *     panel's „Vystaviť znova" runs it on demand.
 *
 * Statuses: pending → sending → issued | error | needs_review;
 * prepared (payload built and stored, not sent — see IssuePolicy);
 * skipped (iDoklad not configured). Rows from before 17. 11. 2026 may say
 * `draft`: those were normal invoices posted to iDoklad.
 *
 * An invoice POST that gets no definite answer (timeout, HTTP 5xx, or a 2xx
 * without an invoice Id) may have created the invoice in iDoklad, so that row
 * goes to `needs_review` (review_reason `uncertain_send`) instead of being
 * retried blindly. A row left in `sending` by a crash is treated the same way
 * by the daily command, and so is a row of the old code without a snapshot
 * (recoverSnapshot()).
 *
 * A `prepared` row of a LIVE Stripe invoice is an error state (a real payment
 * without a tax document): ConfigCheck reports it, the daily run alerts the
 * operator, and issues it once IssuePolicy allows sending.
 */
final class InvoiceIssuer
{
    public const MAX_AUTO_ATTEMPTS = 3;

    /** Minutes after which the daily command treats a `sending` row as stuck. */
    public const STUCK_SENDING_MINUTES = 60;

    /** @var list<int> */
    private static array $deferred = [];
    private static bool $shutdownRegistered = false;

    public function __construct(private ?IDokladApi $api = null)
    {
    }

    /**
     * Record a paid Stripe invoice. Returns the local row id when the invoice
     * is to be issued, null otherwise (already recorded, nothing to invoice,
     * or held for review).
     *
     * @param array<string, mixed> $stripeInvoice the Invoice object (any API version)
     */
    public static function record(int $accountId, array $stripeInvoice, ?bool $livemode = null): ?int
    {
        $stripeInvoiceId = (string) ($stripeInvoice['id'] ?? '');
        if ($stripeInvoiceId === '') {
            return null;
        }
        if (self::rowFor($stripeInvoiceId) !== null) {
            return null;
        }

        $stripeInvoice = self::withAllLines($stripeInvoice);
        $livemode ??= isset($stripeInvoice['livemode']) ? (bool) $stripeInvoice['livemode'] : null;
        $decision = self::classify($stripeInvoice);

        if ($decision['decision'] === 'skip') {
            error_log("[idoklad.record] $stripeInvoiceId not invoiced: " . $decision['reason']);
            return null;
        }

        $total    = (int) ($stripeInvoice['total'] ?? 0);
        $paid     = (int) ($stripeInvoice['amount_paid'] ?? 0);
        $currency = strtoupper((string) ($stripeInvoice['currency'] ?? 'eur'));
        $status   = $decision['decision'] === 'review' ? 'needs_review' : 'pending';
        $pdfUrl   = isset($stripeInvoice['invoice_pdf']) ? (string) $stripeInvoice['invoice_pdf'] : null;

        try {
            Db::pdo()->prepare(
                'INSERT INTO invoices
                     (account_id, stripe_invoice_id, stripe_invoice_pdf_url,
                      amount_cents, stripe_total_cents, stripe_amount_paid_cents, stripe_livemode,
                      currency, status, review_reason, error_message, stripe_invoice_json, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $accountId, $stripeInvoiceId, $pdfUrl,
                max(0, $paid), $total, $paid, $livemode === null ? null : ($livemode ? 1 : 0),
                $currency, $status, $decision['decision'] === 'review' ? $decision['reason'] : null,
                $decision['decision'] === 'review' ? $decision['detail'] : null,
                json_encode($stripeInvoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return null; // recorded by a concurrent delivery
            }
            throw $e;
        }
        $rowId = (int) Db::pdo()->lastInsertId();

        if ($status === 'needs_review') {
            error_log("[idoklad.record] $stripeInvoiceId held for review: " . $decision['detail']);
            return null;
        }
        return $rowId;
    }

    /**
     * What to do with a Stripe invoice.
     *
     * Decided on invoiceLines(), not on Stripe's raw lines: a quantity change
     * of an existing item is two proration lines („Unused time on N ×",
     * negative, and „Remaining time on N+1 ×", positive) that are one charge,
     * and a promotion code lowers the lines by their discount_amounts. Held
     * for review only for a real credit — a netted group or the total below
     * zero, or a total paid partly from the customer's balance — and when the
     * netted lines do not add up to the total (tax, or a discount that could
     * not be placed). How credits appear on a tax document is an open owner
     * decision.
     *
     * @param array<string, mixed> $stripeInvoice with all lines
     * @return array{decision: 'skip'|'review'|'issue', reason: string, detail: string}
     */
    public static function classify(array $stripeInvoice): array
    {
        $total = (int) ($stripeInvoice['total'] ?? 0);
        $paid  = (int) ($stripeInvoice['amount_paid'] ?? 0);
        $numbers = sprintf('total %s, paid %s', self::eur($total), self::eur($paid));

        if ($total === 0 && $paid === 0) {
            return ['decision' => 'skip', 'reason' => 'zero', 'detail' => "nothing to invoice ($numbers)"];
        }
        if ($total < 0) {
            return ['decision' => 'review', 'reason' => 'credit', 'detail' => "negative total — credit ($numbers)"];
        }
        if ($paid < $total) {
            return ['decision' => 'review', 'reason' => 'credit', 'detail' => "partly paid from the customer's credit balance ($numbers)"];
        }
        if ($paid > $total) {
            return ['decision' => 'review', 'reason' => 'mismatch', 'detail' => "paid more than the total ($numbers)"];
        }
        $sum = 0;
        foreach (self::invoiceLines($stripeInvoice) as $line) {
            if ($line['amount'] < 0 && $line['kind'] !== 'discount') {
                return ['decision' => 'review', 'reason' => 'credit', 'detail' => 'a credit of ' . self::eur($line['amount']) . ' — ' . $line['name'] . " ($numbers)"];
            }
            $sum += $line['amount'];
        }
        if ($sum !== $total) {
            return ['decision' => 'review', 'reason' => 'mismatch', 'detail' => 'the lines add up to ' . self::eur($sum) . " after discounts — tax or a discount that could not be placed ($numbers)"];
        }
        return ['decision' => 'issue', 'reason' => '', 'detail' => ''];
    }

    /**
     * The lines of a Stripe invoice as they go onto the iDoklad invoice, in
     * Stripe's order:
     *
     *  - every amount net of its discounts (the line's discount_amounts);
     *  - the proration lines of one change — same subscription, same kind
     *    (extra technician / plan), same proration period — netted into one
     *    line „…, pomerná časť (+1 technik) (period)". A change that nets to
     *    0 (duplicate extra items merged) gives no line;
     *  - a discount of the invoice that no line carries → one „Zľava" line;
     *  - lines of 0 dropped.
     *
     * `delta` is the change in technicians of a netted extra-technician
     * proration (quantity of the positive lines minus that of the negative
     * ones).
     *
     * @param array<string, mixed> $stripeInvoice with all lines
     * @return list<array{name: string, amount: int, qty: int, kind: 'extra'|'base'|'discount', proration: bool, discounted: bool, period: ?array{0: int, 1: int}, delta: int}>
     */
    public static function invoiceLines(array $stripeInvoice): array
    {
        $out    = [];
        $groups = [];
        $placed = 0;
        foreach ((array) ($stripeInvoice['lines']['data'] ?? []) as $line) {
            $line     = (array) $line;
            $amount   = (int) ($line['amount'] ?? 0);
            $discount = self::lineDiscount($line);
            $placed  += $discount;
            $kind     = self::isExtraLine($line) ? 'extra' : 'base';
            $period   = StripeShape::linePeriod($line);
            $qty      = max(0, (int) ($line['quantity'] ?? 1));

            if (StripeShape::lineIsProration($line)) {
                $key = implode('|', [
                    StripeShape::lineSubscriptionId($line) ?? '', $kind,
                    $period[0] ?? '', $period[1] ?? '',
                ]);
                if (!isset($groups[$key])) {
                    $groups[$key] = count($out);
                    $out[] = ['name' => '', 'amount' => 0, 'qty' => 1, 'kind' => $kind, 'proration' => true,
                              'discounted' => false, 'period' => $period, 'delta' => 0];
                }
                $g = $groups[$key];
                $out[$g]['amount'] += $amount - $discount;
                $out[$g]['discounted'] = $out[$g]['discounted'] || $discount !== 0;
                if ($amount !== 0) {
                    $out[$g]['delta'] += ($amount < 0 ? -1 : 1) * $qty;
                }
                continue;
            }
            $out[] = ['name' => '', 'amount' => $amount - $discount, 'qty' => max(1, $qty), 'kind' => $kind,
                      'proration' => false, 'discounted' => $discount !== 0, 'period' => $period, 'delta' => 0];
        }

        $invoiceDiscount = 0;
        foreach ((array) ($stripeInvoice['total_discount_amounts'] ?? []) as $d) {
            $invoiceDiscount += (int) ((array) $d)['amount'];
        }
        if ($invoiceDiscount > $placed) {
            $out[] = ['name' => '', 'amount' => -($invoiceDiscount - $placed), 'qty' => 1, 'kind' => 'discount',
                      'proration' => false, 'discounted' => false, 'period' => null, 'delta' => 0];
        }

        $result = [];
        foreach ($out as $l) {
            if ($l['amount'] === 0) {
                continue;
            }
            $l['name'] = self::lineName($l);
            $result[] = $l;
        }
        return $result;
    }

    /**
     * @param array{kind: string, proration: bool, discounted: bool, period: ?array{0: int, 1: int}, delta: int} $l
     */
    private static function lineName(array $l): string
    {
        if ($l['kind'] === 'discount') {
            return 'Zľava';
        }
        $name = $l['kind'] === 'extra' ? 'POapp — extra technik' : 'POapp — predplatné';
        if ($l['proration']) {
            $name .= ', pomerná časť';
            if ($l['kind'] === 'extra' && $l['delta'] !== 0) {
                $n = abs($l['delta']);
                $name .= ' (' . ($l['delta'] > 0 ? '+' : '−') . $n . ' ' . self::technicianWord($n) . ')';
            }
        }
        if ($l['discounted']) {
            $name .= ', po zľave';
        }
        return $name . self::periodText($l['period']);
    }

    /** „technik" in the form that goes with a count (1 technik, 2 technici, 5 technikov). */
    private static function technicianWord(int $n): string
    {
        return match (true) {
            $n === 1          => 'technik',
            $n >= 2 && $n <= 4 => 'technici',
            default           => 'technikov',
        };
    }

    /**
     * Sum of a line's discount_amounts in cents (the same field in the pinned
     * API version and in 2026-04-22.dahlia).
     *
     * @param array<string, mixed> $line
     */
    private static function lineDiscount(array $line): int
    {
        $sum = 0;
        foreach ((array) ($line['discount_amounts'] ?? []) as $d) {
            $sum += (int) (((array) $d)['amount'] ?? 0);
        }
        return $sum;
    }

    /**
     * Issue (or prepare) the invoice of one `invoices` row. Returns the row's
     * status afterwards.
     *
     * Automatic runs take `pending` rows and `error` rows below
     * MAX_AUTO_ATTEMPTS. A manual run (admin „Vystaviť znova") also takes an
     * `error` row past the cap, a `prepared` or `skipped` row, and a
     * `needs_review` row whose reason is `uncertain_send` — the admin has
     * checked in iDoklad that the invoice does not exist.
     */
    public function issue(int $rowId, bool $manual = false): string
    {
        $row = self::row($rowId);
        if ($row === null) {
            return 'missing';
        }
        if (!self::canIssue($row, $manual)) {
            return (string) $row['status'];
        }
        $claimable = $manual
            ? ['pending', 'error', 'prepared', 'skipped', 'needs_review']
            : ['pending', 'error'];
        $stripeInvoice = json_decode((string) ($row['stripe_invoice_json'] ?? ''), true);
        if (!is_array($stripeInvoice)) {
            // A row written before 17. 11. 2026 (no snapshot). The old code
            // may have posted it to iDoklad already — never sent from here.
            return self::recoverSnapshot($rowId);
        }

        $accountId = (int) $row['account_id'];
        $livemode  = $row['stripe_livemode'] === null ? null : (int) $row['stripe_livemode'] === 1;
        $payload   = self::buildPayload($accountId, $stripeInvoice);
        $policy    = $this->api !== null
            ? ['send' => true, 'reason' => ''] // an injected API is a test recorder
            : IssuePolicy::decide($livemode);

        if (!$policy['send']) {
            self::claim($rowId, $claimable, 'prepared', $policy['reason'], $payload);
            return 'prepared';
        }
        if ($this->api === null && !IDokladClient::isConfigured()) {
            self::claim($rowId, $claimable, 'skipped', 'iDoklad not configured', $payload);
            return 'skipped';
        }
        if (!self::claim($rowId, $claimable, 'sending', null, $payload)) {
            $current = self::row($rowId);
            return (string) ($current['status'] ?? 'missing');
        }
        $attempts = (int) $row['attempts'] + 1;

        $posting = false;
        try {
            $api       = $this->api ?? IDokladClient::fromEnv();
            $contactId = self::ensureContact($api, $accountId);
            $full      = self::mergeTemplate($api->get('IssuedInvoices/Default'), $payload, $contactId);
            self::storePayload($rowId, $full);

            $posting = true;
            $created = $api->post('IssuedInvoices', $full);
            $posting = false;

            if ((int) ($created['Id'] ?? 0) <= 0) {
                // A 2xx answer without an invoice id: iDoklad may or may not
                // have created it. Same as no answer — a person checks.
                $msg = 'iDoklad answered the invoice POST without an invoice Id. Check in iDoklad whether the invoice exists before „Vystaviť znova".';
                self::setStatus($rowId, 'needs_review', 'uncertain_send', $msg);
                InvoiceAlert::notify($rowId, $msg);
                return 'needs_review';
            }

            $publicUrl = self::publicUrl($created);
            // The POST response sometimes omits PublicHtmlUrl — read the
            // detail once to recover it. Non-fatal: the UI falls back to the
            // Stripe receipt.
            if ($publicUrl === null && isset($created['Id'])) {
                try {
                    $publicUrl = self::publicUrl($api->get('IssuedInvoices/' . (int) $created['Id']));
                } catch (\Throwable $e) {
                    error_log('[idoklad.refetch-url] ' . $e->getMessage());
                }
            }

            Db::pdo()->prepare(
                'UPDATE invoices
                 SET    idoklad_invoice_id = ?, document_number = ?, idoklad_public_url = ?,
                        status = ?, review_reason = NULL, error_message = NULL
                 WHERE  id = ?'
            )->execute([
                isset($created['Id']) ? (int) $created['Id'] : null,
                (string) ($created['DocumentNumber'] ?? ''),
                $publicUrl,
                'issued',
                $rowId,
            ]);
            return 'issued';
        } catch (\Throwable $e) {
            error_log('[idoklad.issue] row=' . $rowId . ' ' . $e->getMessage());
            if ($posting && $e instanceof IDokladTransportException) {
                $msg = 'iDoklad did not answer the invoice POST (' . $e->getMessage() . '). Check in iDoklad whether the invoice exists before „Vystaviť znova".';
                self::setStatus($rowId, 'needs_review', 'uncertain_send', $msg);
                InvoiceAlert::notify($rowId, $msg);
                return 'needs_review';
            }
            self::setStatus($rowId, 'error', null, $e->getMessage());
            if ($attempts === 1 && (int) $row['amount_cents'] > 0) {
                self::sendFallbackEmail($accountId, $stripeInvoice);
            }
            if ($manual || $attempts >= self::MAX_AUTO_ATTEMPTS) {
                InvoiceAlert::notify($rowId, $e->getMessage());
            }
            return 'error';
        }
    }

    /**
     * Issue the row once the response has gone back to the caller (Stripe):
     * under PHP-FPM fastcgi_finish_request() closes the request first; on the
     * CLI it simply runs at shutdown.
     */
    public static function issueAfterResponse(int $rowId): void
    {
        self::$deferred[] = $rowId;
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            foreach (self::$deferred as $id) {
                try {
                    (new self())->issue($id);
                } catch (\Throwable $e) {
                    error_log('[idoklad.deferred] row=' . $id . ' ' . $e->getMessage());
                }
            }
            self::$deferred = [];
        });
    }

    /**
     * Our part of the iDoklad invoice: header fields and one item per Stripe
     * line. VAT rate, price type and numbering come from iDoklad's default
     * template at send time (how VAT is carried is an open owner decision).
     *
     * @param array<string, mixed> $stripeInvoice
     * @return array<string, mixed>
     */
    public static function buildPayload(int $accountId, array $stripeInvoice): array
    {
        $currency = strtoupper((string) ($stripeInvoice['currency'] ?? 'eur'));
        $paidAt   = $stripeInvoice['status_transitions']['paid_at'] ?? null;
        $paidDate = is_int($paidAt) && $paidAt > 0 ? date('Y-m-d', $paidAt) : date('Y-m-d');
        $id       = (string) ($stripeInvoice['id'] ?? '');

        $items = [];
        foreach (self::invoiceLines($stripeInvoice) as $line) {
            $amount = $line['amount'];
            $qty    = $line['qty'];
            if (!$line['proration'] && $qty > 1 && $amount % $qty === 0) {
                $items[] = ['Name' => $line['name'], 'Amount' => $qty, 'UnitPrice' => round($amount / $qty / 100, 2), 'Unit' => 'ks'];
            } else {
                $items[] = ['Name' => $line['name'], 'Amount' => 1, 'UnitPrice' => round($amount / 100, 2), 'Unit' => 'ks'];
            }
        }

        return [
            'PartnerId'      => null, // the iDoklad contact of the account, filled in at send time
            'CurrencyId'     => self::currencyId($currency),
            'DateOfIssue'    => date('Y-m-d'),
            'DateOfTaxing'   => $paidDate,
            'DateOfMaturity' => $paidDate,
            'Description'    => self::deriveDescription($stripeInvoice),
            'Note'           => "Stripe invoice $id",
            'Items'          => $items,
        ];
    }

    /**
     * „POapp — predplatné (period)", the period taken from the first line
     * billed by the subscription itself — not from a proration, which covers
     * only the rest of a period.
     *
     * @param array<string, mixed> $stripeInvoice
     */
    public static function deriveDescription(array $stripeInvoice): string
    {
        foreach ((array) ($stripeInvoice['lines']['data'] ?? []) as $line) {
            if (StripeShape::lineIsSubscription($line) && !StripeShape::lineIsProration($line)) {
                return 'POapp — predplatné' . self::periodText(StripeShape::linePeriod($line));
            }
        }
        return 'POapp — predplatné';
    }

    /**
     * The invoice with every line: a webhook payload carries at most the
     * first page (`has_more`), so the rest is read from Stripe.
     *
     * @param array<string, mixed> $stripeInvoice
     * @return array<string, mixed>
     */
    public static function withAllLines(array $stripeInvoice): array
    {
        if (empty($stripeInvoice['lines']['has_more'])) {
            return $stripeInvoice;
        }
        $lines = [];
        foreach (StripeClient::get()->invoices->allLines((string) $stripeInvoice['id'], ['limit' => 100])->autoPagingIterator() as $line) {
            $lines[] = StripeShape::arr($line);
        }
        $stripeInvoice['lines']['data']     = $lines;
        $stripeInvoice['lines']['has_more'] = false;
        return $stripeInvoice;
    }

    /** @param array<string, mixed> $row */
    private static function canIssue(array $row, bool $manual): bool
    {
        $status = (string) $row['status'];
        if ($manual) {
            return self::manualIssueAllowed($row);
        }
        if ($status === 'pending') {
            return true;
        }
        return $status === 'error' && (int) $row['attempts'] < self::MAX_AUTO_ATTEMPTS;
    }

    /**
     * Whether the admin's „Vystaviť" / „Vystaviť znova" can work for a row:
     * a pending, failed, prepared or skipped row, or one held because
     * iDoklad gave no definite answer (after the admin checked in iDoklad).
     * Not a credit held for review, and not a row without the Stripe invoice
     * snapshot — the daily run rebuilds that from Stripe first.
     *
     * @param array<string, mixed> $row invoices row (status, review_reason, stripe_invoice_json)
     */
    public static function manualIssueAllowed(array $row): bool
    {
        if (array_key_exists('stripe_invoice_json', $row) && (string) ($row['stripe_invoice_json'] ?? '') === '') {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        return in_array($status, ['pending', 'error', 'prepared', 'skipped'], true)
            || ($status === 'needs_review' && (string) ($row['review_reason'] ?? '') === 'uncertain_send');
    }

    /**
     * A row without the Stripe invoice snapshot — written by the code before
     * 17. 11. 2026 (left `pending` / `error`), or one whose rebuild failed
     * (`needs_review` / `missing_snapshot`). Reads the invoice again from
     * Stripe by its id and stores it. The row is never sent from here: the
     * old code may have posted it to iDoklad before it stopped, so it goes
     * to `needs_review` / `uncertain_send` and a person checks iDoklad first
     * (a credit or a 0 € invoice gets its own reason). When Stripe cannot be
     * read the row stays `missing_snapshot` and the daily run tries again.
     *
     * Returns the row's status afterwards.
     */
    public static function recoverSnapshot(int $rowId): string
    {
        $row = self::row($rowId);
        if ($row === null) {
            return 'missing';
        }
        $stripeId = (string) $row['stripe_invoice_id'];
        try {
            if (!StripeClient::isConfigured()) {
                throw new \RuntimeException('Stripe is not configured');
            }
            $inv = self::withAllLines(StripeShape::arr(StripeClient::get()->invoices->retrieve($stripeId)));
        } catch (\Throwable $e) {
            error_log("[idoklad.recover] row=$rowId $stripeId " . $e->getMessage());
            self::setStatus($rowId, 'needs_review', 'missing_snapshot',
                'Faktúru zo Stripe sa nepodarilo načítať (' . mb_substr($e->getMessage(), 0, 300) . '); denná kontrola to skúsi znova. '
                . 'Riadok je zo staršej verzie aplikácie — najprv skontroluj v iDoklade, či faktúra už neexistuje.');
            return 'needs_review';
        }

        Db::pdo()->prepare(
            'UPDATE invoices
             SET    stripe_invoice_json = ?, stripe_total_cents = ?, stripe_amount_paid_cents = ?, stripe_livemode = ?
             WHERE  id = ?'
        )->execute([
            json_encode($inv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int) ($inv['total'] ?? 0), (int) ($inv['amount_paid'] ?? 0),
            isset($inv['livemode']) ? ((bool) $inv['livemode'] ? 1 : 0) : null,
            $rowId,
        ]);

        $decision = self::classify($inv);
        if ($decision['decision'] === 'review') {
            self::setStatus($rowId, 'needs_review', $decision['reason'], $decision['detail']);
            return 'needs_review';
        }
        if ($decision['decision'] === 'skip') {
            self::setStatus($rowId, 'needs_review', 'zero', 'Faktúra zo Stripe je na 0 € — nič sa nevystavuje. Ak ju stará verzia aplikácie vystavila v iDoklade, treba ju tam opraviť.');
            return 'needs_review';
        }
        $msg = 'Nedokončená faktúra zo staršej verzie aplikácie (stav „' . $row['status'] . '"). Mohla už v iDoklade vzniknúť — '
            . 'najprv skontroluj v iDoklade, či faktúra už neexistuje, až potom „Vystaviť znova".';
        self::setStatus($rowId, 'needs_review', 'uncertain_send', $msg);
        InvoiceAlert::notify($rowId, $msg);
        return 'needs_review';
    }

    /**
     * Move the row to $to if it is still in one of $from. Stores the payload
     * and counts the attempt. False when another run took the row first.
     *
     * @param list<string> $from
     * @param array<string, mixed> $payload
     */
    private static function claim(int $rowId, array $from, string $to, ?string $message, array $payload): bool
    {
        $in = implode(',', array_fill(0, count($from), '?'));
        $stmt = Db::pdo()->prepare(
            "UPDATE invoices
             SET    status = ?, error_message = ?, review_reason = NULL, idoklad_payload_json = ?,
                    attempts = attempts + 1, last_attempt_at = NOW()
             WHERE  id = ? AND status IN ($in)"
        );
        $stmt->execute([$to, $message, self::json($payload), $rowId, ...$from]);
        return $stmt->rowCount() === 1;
    }

    private static function setStatus(int $rowId, string $status, ?string $reason, ?string $message): void
    {
        Db::pdo()->prepare(
            'UPDATE invoices SET status = ?, review_reason = ?, error_message = ? WHERE id = ?'
        )->execute([$status, $reason, $message, $rowId]);
    }

    /** @param array<string, mixed> $payload */
    private static function storePayload(int $rowId, array $payload): void
    {
        Db::pdo()->prepare('UPDATE invoices SET idoklad_payload_json = ? WHERE id = ?')
            ->execute([self::json($payload), $rowId]);
    }

    /**
     * iDoklad's POST /IssuedInvoices is strict about required metadata
     * (DocumentSerialNumber, NumericSequenceId, item discount and tax flags);
     * /IssuedInvoices/Default returns a fully populated template and only
     * our fields are overwritten.
     *
     * @param array<string, mixed> $tpl
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function mergeTemplate(array $tpl, array $payload, int $contactId): array
    {
        $itemTpl = $tpl['Items'][0] ?? [
            'DiscountPercentage' => 0,
            'IsTaxMovement'      => true,
            'PriceType'          => 1,
            'VatRateType'        => 0,
            'Unit'               => 'ks',
        ];
        unset($itemTpl['Id']);
        $items = array_map(static fn (array $item): array => array_merge($itemTpl, $item), (array) $payload['Items']);

        $full = array_merge($tpl, $payload, ['PartnerId' => $contactId, 'Items' => $items]);
        // Server-managed / read-only keys that POST rejects.
        foreach (['Id', 'DateOfPayment', 'DateOfLastReminder', 'Attachments', 'Phases', 'PaymentStatus'] as $k) {
            unset($full[$k]);
        }
        return $full;
    }

    /** @param array<string, mixed> $line */
    private static function isExtraLine(array $line): bool
    {
        $productId = StripeClient::extraTechnicianProductId();
        if ($productId !== '' && StripeShape::lineProductId($line) === $productId) {
            return true;
        }
        if ((string) ($line['metadata']['firol_line_kind'] ?? '') === SeatSync::LINE_KIND) {
            return true;
        }
        // A legacy item (inline product of an old Checkout, named „POapp — …"
        // or, before 28. 7. 2026, „Firol — …"): Stripe puts the product name
        // into the line description („1 × POapp — extra technik (at …)",
        // „Remaining time on Firol — extra technik after …").
        $description = (string) ($line['description'] ?? '');
        foreach (SeatSync::LEGACY_PRODUCT_NAMES as $name) {
            if (str_contains($description, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * „ (01.12.–17.12.2026)" within one year, „ (17.12.2026 – 17.01.2027)"
     * across a year end.
     *
     * @param array{0: int, 1: int}|null $period
     */
    private static function periodText(?array $period): string
    {
        if ($period === null) {
            return '';
        }
        if (date('Y', $period[0]) === date('Y', $period[1])) {
            return ' (' . date('d.m.', $period[0]) . '–' . date('d.m.Y', $period[1]) . ')';
        }
        return ' (' . date('d.m.Y', $period[0]) . ' – ' . date('d.m.Y', $period[1]) . ')';
    }

    /** @param array<string, mixed> $created */
    private static function publicUrl(array $created): ?string
    {
        $url = $created['PublicHtmlUrl'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * On iDoklad failure the customer still gets a payment confirmation —
     * Stripe already charged them. Sent once, on the first failed attempt.
     *
     * @param array<string, mixed> $stripeInvoice
     */
    private static function sendFallbackEmail(int $accountId, array $stripeInvoice): void
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.email, a.invoice_company_name
             FROM   accounts a
             JOIN   users    u ON u.id = a.main_user_id
             WHERE  a.id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['email'])) {
            return;
        }

        $hosted = isset($stripeInvoice['hosted_invoice_url']) ? (string) $stripeInvoice['hosted_invoice_url'] : null;

        \Firol\Mail\Mailer::send(
            \Firol\Mail\Templates\InvoiceFallbackEmail::build(
                (string) $row['email'],
                (string) ($row['invoice_company_name'] ?? 'tvoju firmu'),
                (int) ($stripeInvoice['amount_paid'] ?? 0),
                (string) ($stripeInvoice['currency'] ?? 'eur'),
                $hosted,
            )
        );
    }

    /** @return array<string, mixed>|null */
    private static function rowFor(string $stripeInvoiceId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT id, status FROM invoices WHERE stripe_invoice_id = ?');
        $stmt->execute([$stripeInvoiceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function row(int $rowId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$rowId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Reuse the existing iDoklad Contact for this account, or create one
     * from `accounts.invoice_*` fields on first invoice. Persists the id
     * so subsequent invoices skip the lookup.
     */
    private static function ensureContact(IDokladApi $api, int $accountId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT idoklad_contact_id, invoice_company_name, invoice_street,
                    invoice_postal_code, invoice_city, invoice_country,
                    invoice_ico, invoice_dic, invoice_ic_dph
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException("Account $accountId not found");
        }
        $existing = $row['idoklad_contact_id'] ?? null;
        if ($existing !== null) {
            return (int) $existing;
        }

        $payload = [
            'CompanyName'     => $row['invoice_company_name'] ?? 'Klient POapp',
            'Street'          => $row['invoice_street']       ?? '',
            'PostalCode'      => $row['invoice_postal_code']  ?? '',
            'City'            => $row['invoice_city']         ?? '',
            'CountryId'       => self::countryId((string) ($row['invoice_country'] ?? 'Slovensko')),
            'IdentificationNumber' => $row['invoice_ico']     ?? '',
            'VatIdentificationNumber' => $row['invoice_ic_dph'] ?? '',
            'VatIdentificationNumberSk' => $row['invoice_dic'] ?? '',
        ];
        $created = $api->post('Contacts', $payload);
        $contactId = isset($created['Id']) ? (int) $created['Id'] : 0;
        if ($contactId === 0) {
            throw new \RuntimeException('iDoklad contact creation returned no Id');
        }

        Db::pdo()->prepare(
            'UPDATE accounts SET idoklad_contact_id = ? WHERE id = ?'
        )->execute([$contactId, $accountId]);

        return $contactId;
    }

    /** iDoklad CurrencyId enum: 1=CZK, 2=EUR, 3=USD … (live list via /Currencies). */
    private static function currencyId(string $currency): int
    {
        return match (strtoupper($currency)) {
            'EUR' => 2,
            'CZK' => 1,
            'USD' => 3,
            default => 2,
        };
    }

    /** iDoklad CountryId enum: 1=CZ, 2=SK, … (full list via /Countries). */
    private static function countryId(string $name): int
    {
        $n = strtolower($name);
        if (str_contains($n, 'sloven')) return 2;
        if (str_contains($n, 'česk') || str_contains($n, 'czech')) return 1;
        return 2; // default SK for our use case
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
