<?php

declare(strict_types=1);

namespace Firol\Idoklad;

use Firol\Billing\SeatSync;
use Firol\Billing\Vat;
use Firol\Db;
use Firol\Stripe\StripeClient;
use Firol\Stripe\StripeShape;

/**
 * Turns a paid Stripe invoice into an iDoklad invoice, tracked in our
 * `invoices` table so the billing history can list it without asking
 * iDoklad.
 *
 * Steps:
 *
 *  1. record() — called by the webhook, database only (plus a Stripe call when
 *     the invoice has more lines than the payload carries). It stores the
 *     Stripe invoice with all its lines and decides (classify()):
 *       - total 0 and every line 0 (trial start): no row, no invoice;
 *       - nothing paid — a total of 0 or below (a removal credit larger
 *         than the invoice: Stripe carries the rest on the customer's
 *         balance), or a total covered entirely by that balance: status
 *         `no_document` (reason credit_carried / credit_covered), a
 *         visible state, not an error — no tax document for 0 €;
 *       - lines that do not add up to the total, a total without VAT, or a
 *         line whose VAT is not the configured rate: `needs_review`
 *         (mismatch / no_vat / vat_mismatch) — never issued
 *         automatically, alerted;
 *       - otherwise `pending`.
 *  2. issue() — builds the iDoklad payload and, when IssuePolicy allows it,
 *     sends it: one item per netted Stripe line with its NET unit price and
 *     the standard VAT rate, a removal of a technician as a negative item, a
 *     part paid from the customer's Stripe balance as one negative item
 *     „Kredit z predchádzajúceho obdobia" — so the document's base + VAT is
 *     the amount paid, to the cent. Before the POST iDoklad's own Recount of
 *     the document is compared with the amount paid; a difference holds the
 *     row (`needs_review` / vat_mismatch) and alerts — never sent. The
 *     webhook runs it only after the response went back to Stripe
 *     (issueAfterResponse). bin/billing-daily.php retries `pending` /
 *     `error` rows up to MAX_AUTO_ATTEMPTS times, and the admin panel's
 *     „Vystaviť znova" runs it on demand.
 *  3. followUps() — right after the invoice is issued: mark it paid in
 *     iDoklad (payment date = Stripe's payment date) and ask iDoklad to
 *     e-mail it to the customer (iDoklad's normal invoice e-mail with the
 *     PDF, to the contact's e-mail = the main user's e-mail). Two steps with
 *     their own state on the row (paid_mark_*, send_*); a failure never
 *     touches the issued state and never issues again. The daily run retries
 *     a failed step up to MAX_AUTO_ATTEMPTS, then the operator is alerted
 *     once; the admin's „Poslať znova" runs them on demand. The send is
 *     claimed atomically; an uncertain answer (no definite reply after the
 *     request left) is held for a person (`uncertain`), never retried.
 *     Nothing is marked or sent unless the invoice itself was issued in
 *     `send` mode, and a Stripe test-mode payment is never e-mailed.
 *
 * Statuses: pending → sending → issued | error | needs_review;
 * prepared (payload built and stored, not sent — see IssuePolicy);
 * skipped (iDoklad not configured); no_document. Rows from before
 * 17. 11. 2026 may say `draft`: those were normal invoices posted to iDoklad.
 * A row held as needs_review / credit before 19. 11. 2026 is decided again by
 * the current rules when issued (the daily run does it).
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

    /** Failed reads of a row's Stripe invoice before it is given up. */
    public const SNAPSHOT_READ_TRIES = 3;

    /** iDoklad item VatRateType „Basic" (1) — the standard rate; 2 is the zero rate. */
    public const VAT_RATE_TYPE_BASIC = 1;
    /** iDoklad item PriceType: 0 = price with VAT, 1 = price without VAT. */
    public const PRICE_WITH_VAT = 0;
    public const PRICE_WITHOUT_VAT = 1;

    /** Name of the item for the part paid from the customer's Stripe balance. */
    public const CREDIT_LINE_NAME = 'Kredit z predchádzajúceho obdobia';

    /** @var list<\Closure(): mixed> jobs run after the response (issue, alert) */
    private static array $deferred = [];
    private static bool $shutdownRegistered = false;

    public function __construct(private ?IDokladApi $api = null)
    {
    }

    /**
     * Record a paid Stripe invoice. Returns the local row id when the invoice
     * is to be issued, null otherwise (already recorded, nothing to invoice,
     * no document, or held for review).
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
            error_log("[idoklad.record] $stripeInvoiceId not invoiced: " . $decision['detail']);
            return null;
        }

        $total    = (int) ($stripeInvoice['total'] ?? 0);
        $paid     = (int) ($stripeInvoice['amount_paid'] ?? 0);
        $currency = strtoupper((string) ($stripeInvoice['currency'] ?? 'eur'));
        $status   = match ($decision['decision']) {
            'review'      => 'needs_review',
            'no_document' => 'no_document',
            default       => 'pending',
        };
        $pdfUrl   = isset($stripeInvoice['invoice_pdf']) ? (string) $stripeInvoice['invoice_pdf'] : null;
        $held     = $status !== 'pending';

        try {
            Db::pdo()->prepare(
                'INSERT INTO invoices
                     (account_id, stripe_invoice_id, stripe_invoice_pdf_url,
                      amount_cents, stripe_total_cents, stripe_amount_paid_cents, stripe_tax_cents, stripe_livemode,
                      currency, status, review_reason, error_message, stripe_invoice_json, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $accountId, $stripeInvoiceId, $pdfUrl,
                max(0, $paid), $total, $paid, StripeShape::invoiceTax($stripeInvoice), $livemode === null ? null : ($livemode ? 1 : 0),
                $currency, $status, $held ? $decision['reason'] : null, $held ? $decision['detail'] : null,
                json_encode($stripeInvoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return null; // recorded by a concurrent delivery
            }
            throw $e;
        }
        $rowId = (int) Db::pdo()->lastInsertId();

        if ($status === 'no_document') {
            error_log("[idoklad.record] $stripeInvoiceId no document: " . $decision['detail']);
            return null;
        }
        if ($status === 'needs_review') {
            // A paid invoice without a tax document: the operator hears of it
            // (after the response went back to Stripe; once per row and
            // state — the daily run repeats it only when the mail failed).
            error_log("[idoklad.record] $stripeInvoiceId held for review: " . $decision['detail']);
            self::alertAfterResponse($rowId, 'Faktúra čaká na kontrolu: ' . $decision['detail']);
            return null;
        }
        return $rowId;
    }

    /**
     * What to do with a Stripe invoice.
     *
     * Decided on invoiceLines() (proration lines netted, amounts net of
     * discounts, each with Stripe's VAT):
     *
     *  - skip: total 0 and nothing on it (trial start);
     *  - no_document: nothing paid — a total of 0 or below with credit lines
     *    (credit_carried: the rest stays on the customer's Stripe balance and
     *    is used by the next invoice), or a positive total paid entirely
     *    from that balance (credit_covered);
     *  - review: paid more than the total, lines + VAT that do not add up to
     *    the total (mismatch), a total without VAT (no_vat — the
     *    subscription had no tax rate), or a line whose VAT is not the
     *    configured rate (vat_mismatch);
     *  - issue: everything else — also a negative line (a technician removed
     *    mid-period) and a total paid partly from the balance (a negative
     *    „Kredit z predchádzajúceho obdobia" line makes the document equal
     *    the amount paid).
     *
     * @param array<string, mixed> $stripeInvoice with all lines
     * @return array{decision: 'skip'|'review'|'issue'|'no_document', reason: string, detail: string}
     */
    public static function classify(array $stripeInvoice): array
    {
        $total = (int) ($stripeInvoice['total'] ?? 0);
        $paid  = (int) ($stripeInvoice['amount_paid'] ?? 0);
        $tax   = StripeShape::invoiceTax($stripeInvoice);
        $numbers = sprintf('spolu %s s DPH, z toho DPH %s, zaplatené %s', self::eur($total), self::eur($tax), self::eur($paid));
        $lines = self::invoiceLines($stripeInvoice);
        $carried = self::balanceCarried($stripeInvoice);

        if ($total === 0 && $paid === 0 && $lines === []) {
            return ['decision' => 'skip', 'reason' => 'zero', 'detail' => "nie je čo fakturovať ($numbers)"];
        }
        if ($total <= 0 && $paid === 0) {
            return ['decision' => 'no_document', 'reason' => 'credit_carried',
                    'detail' => 'nič sa neplatilo, dobropis prechádza na ďalšie obdobie'
                        . ($carried !== null ? ' (kredit zákazníka v Stripe ' . self::eur($carried) . ')' : '') . " ($numbers)"];
        }
        if ($paid > $total) {
            return ['decision' => 'review', 'reason' => 'mismatch', 'detail' => "zaplatené viac, než je súčet ($numbers)"];
        }
        if ($paid === 0) {
            return ['decision' => 'no_document', 'reason' => 'credit_covered',
                    'detail' => 'celá suma uhradená z kreditu zákazníka v Stripe, nič sa neplatilo'
                        . ($carried !== null ? ' (zostatok kreditu ' . self::eur($carried) . ')' : '') . " ($numbers)"];
        }

        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line['amount'] + $line['tax'];
        }
        if ($sum !== $total) {
            return ['decision' => 'review', 'reason' => 'mismatch', 'detail' => 'položky s DPH po zľavách dávajú spolu ' . self::eur($sum) . " — zľava alebo daň, ktorú sa nepodarilo priradiť ($numbers)"];
        }
        if ($tax === 0) {
            return ['decision' => 'review', 'reason' => 'no_vat', 'detail' => "Stripe účtoval bez DPH (predplatné nemá daňovú sadzbu STRIPE_TAX_RATE_ID) ($numbers)"];
        }
        foreach ($lines as $line) {
            // Stripe rounds the VAT of each of its lines; a netted line of n
            // Stripe lines may differ from its own rounding by up to n/2 cent.
            if ($line['kind'] !== 'discount' && abs(Vat::of($line['amount']) - $line['tax']) > max(1, intdiv($line['members'] + 1, 2))) {
                return ['decision' => 'review', 'reason' => 'vat_mismatch', 'detail' => 'DPH riadku „' . $line['name'] . '" je ' . self::eur($line['tax'])
                    . ' zo základu ' . self::eur($line['amount']) . ', nie ' . Vat::label() . " ($numbers)"];
            }
        }
        $document = self::documentTotalCents(self::payloadItems($stripeInvoice));
        if ($document !== $paid) {
            return ['decision' => 'review', 'reason' => 'vat_mismatch', 'detail' => 'doklad by mal spolu ' . self::eur($document) . " ($numbers)"];
        }
        return ['decision' => 'issue', 'reason' => '', 'detail' => ''];
    }

    /**
     * The customer's credit left on the Stripe balance after the invoice, as
     * a positive amount in cents (ending_balance is negative for a credit), or
     * null when the invoice does not say.
     *
     * @param array<string, mixed> $stripeInvoice
     */
    private static function balanceCarried(array $stripeInvoice): ?int
    {
        $end = $stripeInvoice['ending_balance'] ?? null;
        return is_int($end) ? max(0, -$end) : null;
    }

    /**
     * The lines of a Stripe invoice as they go onto the iDoklad invoice, in
     * Stripe's order:
     *
     *  - every amount net of its discounts (the line's discount_amounts) and
     *    of VAT, with Stripe's VAT of the line (`tax`);
     *  - ALL proration lines of one subscription and kind (extra technician
     *    / plan) netted into one line over the whole invoice — „…, pomerná
     *    časť (+1 technik) (period)", the period from the earliest start to
     *    the latest end. Not per proration period: two Stripe calls of one
     *    change made seconds apart (a duplicate merge: delete + update) get
     *    different period starts, and an addition later undone in the same
     *    period is one charge for the days in between. A net of 0 gives no
     *    line. The count is printed only when its sign matches the amount's
     *    (a removal and an addition of a dearer technician can net to
     *    „−1 technik" on a positive amount); a negative net is a credit line
     *    of the document (a technician removed mid-period);
     *  - „po zľave" only on a line that is discounted: it carries a
     *    discount amount, or (a proration of a discounted price — Stripe
     *    prices it net of the coupon and puts no discount amount on it) its
     *    description says „with … off";
     *  - a discount of the invoice that no line carries → one „Zľava" line;
     *  - lines of 0 dropped.
     *
     * `delta` is the change in technicians of a netted extra-technician
     * proration (quantity of the positive lines minus that of the negative
     * ones); `members` how many Stripe lines it nets.
     *
     * @param array<string, mixed> $stripeInvoice with all lines
     * @return list<array{name: string, amount: int, tax: int, qty: int, kind: 'extra'|'base'|'discount', proration: bool, discounted: bool, period: ?array{0: int, 1: int}, delta: int, members: int}>
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
            $tax      = StripeShape::lineTax($line);
            $placed  += $discount;
            $kind     = self::isExtraLine($line) ? 'extra' : 'base';
            $period   = StripeShape::linePeriod($line);
            $qty      = max(0, (int) ($line['quantity'] ?? 1));
            $discounted = $discount !== 0 || self::descriptionSaysDiscounted($line);

            if (StripeShape::lineIsProration($line)) {
                $key = (StripeShape::lineSubscriptionId($line) ?? '') . '|' . $kind;
                if (!isset($groups[$key])) {
                    $groups[$key] = count($out);
                    $out[] = ['name' => '', 'amount' => 0, 'tax' => 0, 'qty' => 1, 'kind' => $kind, 'proration' => true,
                              'discounted' => false, 'period' => $period, 'delta' => 0, 'members' => 0];
                }
                $g = $groups[$key];
                $out[$g]['amount']  += $amount - $discount;
                $out[$g]['tax']     += $tax;
                $out[$g]['members'] += 1;
                $out[$g]['discounted'] = $out[$g]['discounted'] || $discounted;
                if ($period !== null) {
                    $p = $out[$g]['period'];
                    $out[$g]['period'] = $p === null ? $period : [min($p[0], $period[0]), max($p[1], $period[1])];
                }
                if ($amount !== 0) {
                    $out[$g]['delta'] += ($amount < 0 ? -1 : 1) * $qty;
                }
                continue;
            }
            $out[] = ['name' => '', 'amount' => $amount - $discount, 'tax' => $tax, 'qty' => max(1, $qty), 'kind' => $kind,
                      'proration' => false, 'discounted' => $discounted, 'period' => $period, 'delta' => 0, 'members' => 1];
        }

        $invoiceDiscount = self::invoiceDiscount($stripeInvoice);
        if ($invoiceDiscount > $placed) {
            $out[] = ['name' => '', 'amount' => -($invoiceDiscount - $placed), 'tax' => 0, 'qty' => 1, 'kind' => 'discount',
                      'proration' => false, 'discounted' => false, 'period' => null, 'delta' => 0, 'members' => 1];
        }

        $result = [];
        foreach ($out as $l) {
            if ($l['amount'] === 0 && $l['tax'] === 0) {
                continue;
            }
            $l['name'] = self::lineName($l);
            $result[] = $l;
        }
        return $result;
    }

    /**
     * A proration of a discounted price: Stripe words it „… (with 20.0% off)
     * after …" / „(with €3.00 off)" and prices it net of the coupon.
     *
     * @param array<string, mixed> $line
     */
    private static function descriptionSaysDiscounted(array $line): bool
    {
        return (bool) preg_match('/\(with [^)]* off\)/i', (string) ($line['description'] ?? ''));
    }

    /**
     * @param array{kind: string, proration: bool, discounted: bool, period: ?array{0: int, 1: int}, delta: int, amount: int} $l
     */
    private static function lineName(array $l): string
    {
        if ($l['kind'] === 'discount') {
            return 'Zľava';
        }
        $name = $l['kind'] === 'extra' ? 'POapp — extra technik' : 'POapp — predplatné';
        if ($l['proration']) {
            $name .= ', pomerná časť';
            // The count only when it says the same as the amount: a positive
            // delta on a positive amount, a negative one on a credit.
            if ($l['kind'] === 'extra' && $l['delta'] !== 0 && ($l['delta'] > 0) === ($l['amount'] > 0)) {
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
     * The invoice's whole discount in cents (total_discount_amounts).
     *
     * @param array<string, mixed> $stripeInvoice
     */
    private static function invoiceDiscount(array $stripeInvoice): int
    {
        $sum = 0;
        foreach ((array) ($stripeInvoice['total_discount_amounts'] ?? []) as $d) {
            $sum += (int) (((array) $d)['amount'] ?? 0);
        }
        return $sum;
    }

    /**
     * The iDoklad items of a Stripe invoice: one per invoiceLines() line, plus
     * the credit item when part of the total was paid from the customer's
     * Stripe balance.
     *
     * Each line goes with its NET unit price (PriceType 1) and the standard
     * VAT rate (VatRateType 1 „Basic"; 2 would be the zero rate), unless
     * the configured rate applied to the line's net amount does not give
     * Stripe's VAT of that line to the cent (a netted line whose Stripe
     * lines were rounded one by one): that line goes with its gross price
     * (PriceType 0, net + Stripe's VAT), so iDoklad's total stays Stripe's.
     * A quantity above 1 keeps its unit price when the net amount divides by
     * it; otherwise the line goes as 1 ks with the count in the name.
     *
     * The credit item „Kredit z predchádzajúceho obdobia" is the difference
     * between the total and the amount paid, as a negative gross price at the
     * standard rate (the credit came from earlier invoices' VAT-bearing
     * lines), so base + VAT of the document is the amount paid.
     *
     * @param array<string, mixed> $stripeInvoice
     * @return list<array{Name: string, Amount: int, UnitPrice: float, Unit: string, PriceType: int, VatRateType: int}>
     */
    public static function payloadItems(array $stripeInvoice): array
    {
        $items = [];
        $item = static fn (string $name, int $qty, int $unitCents, int $priceType): array => [
            'Name' => $name, 'Amount' => $qty, 'UnitPrice' => round($unitCents / 100, 2), 'Unit' => 'ks',
            'PriceType' => $priceType, 'VatRateType' => self::VAT_RATE_TYPE_BASIC,
        ];
        foreach (self::invoiceLines($stripeInvoice) as $line) {
            $amount = $line['amount'];
            $qty    = $line['qty'];
            $netExact = Vat::of($amount) === $line['tax'];
            if (!$netExact) {
                $name = !$line['proration'] && $qty > 1 ? preg_replace('/^POapp — /u', 'POapp — ' . $qty . ' × ', $line['name'], 1) : $line['name'];
                $items[] = $item((string) $name, 1, $amount + $line['tax'], self::PRICE_WITH_VAT);
            } elseif (!$line['proration'] && $qty > 1 && $amount % $qty === 0 && Vat::of(intdiv($amount, $qty)) * $qty === $line['tax']) {
                $items[] = $item($line['name'], $qty, intdiv($amount, $qty), self::PRICE_WITHOUT_VAT);
            } elseif (!$line['proration'] && $qty > 1) {
                // A discounted amount that does not divide by the quantity (or
                // whose VAT per unit would round differently) goes on as 1 ks:
                // the count moves into the name.
                $name = preg_replace('/^POapp — /u', 'POapp — ' . $qty . ' × ', $line['name'], 1);
                $items[] = $item((string) $name, 1, $amount, self::PRICE_WITHOUT_VAT);
            } else {
                $items[] = $item($line['name'], 1, $amount, self::PRICE_WITHOUT_VAT);
            }
        }
        $total = (int) ($stripeInvoice['total'] ?? 0);
        $paid  = (int) ($stripeInvoice['amount_paid'] ?? 0);
        if ($paid > 0 && $paid < $total) {
            $items[] = $item(self::CREDIT_LINE_NAME, 1, -($total - $paid), self::PRICE_WITH_VAT);
        }
        return $items;
    }

    /**
     * The total with VAT of a document of these items in cents, counted per
     * item: an item without VAT gets the configured rate on Amount ×
     * UnitPrice, rounded half away from zero (Stripe's rounding of a line);
     * an item with VAT counts as it is. iDoklad's own Recount is compared
     * with the amount paid before every POST (the reference does not say how
     * iDoklad rounds).
     *
     * @param list<array<string, mixed>> $items
     */
    public static function documentTotalCents(array $items): int
    {
        $cents = 0;
        foreach ($items as $i) {
            $line = (int) round((float) $i['Amount'] * (float) $i['UnitPrice'] * 100);
            $cents += (int) $i['PriceType'] === self::PRICE_WITH_VAT ? $line : Vat::gross($line);
        }
        return $cents;
    }

    /**
     * Issue (or prepare) the invoice of one `invoices` row. Returns the row's
     * status afterwards.
     *
     * Automatic runs take `pending` rows and `error` rows below
     * MAX_AUTO_ATTEMPTS. A manual run (admin „Vystaviť znova", or the daily
     * run with $onlyFrom) also takes an `error` row past the cap, a
     * `prepared` or `skipped` row, and a `needs_review` row whose reason is
     * `uncertain_send` (the admin has checked in iDoklad that the invoice
     * does not exist), `vat_mismatch` (iDoklad's Recount is checked again
     * before the POST) or `credit` (held under the rule before 19. 11. 2026,
     * decided again now).
     *
     * $onlyFrom limits a run to rows still in one of those statuses (the
     * daily run and the admin pass the status they saw, so a row moved to
     * another state in between is never posted).
     *
     * Only a real send counts as an attempt: preparing a row (IssuePolicy
     * says „prepare") or skipping it (iDoklad not configured) does not use up
     * one of the MAX_AUTO_ATTEMPTS. A LIVE row that is only prepared or
     * skipped alerts the operator once.
     *
     * @param list<string>|null $onlyFrom
     */
    public function issue(int $rowId, bool $manual = false, ?array $onlyFrom = null): string
    {
        $row = self::row($rowId);
        if ($row === null) {
            return 'missing';
        }
        if ($onlyFrom !== null && !in_array((string) $row['status'], $onlyFrom, true)) {
            return (string) $row['status'];
        }
        if (!self::canIssue($row, $manual)) {
            return (string) $row['status'];
        }
        $claimable = $manual
            ? ['pending', 'error', 'prepared', 'skipped', 'needs_review']
            : ['pending', 'error'];
        if ($onlyFrom !== null) {
            $claimable = array_values(array_intersect($claimable, $onlyFrom));
        }
        $stripeInvoice = json_decode((string) ($row['stripe_invoice_json'] ?? ''), true);
        if (!is_array($stripeInvoice)) {
            // A row written before 17. 11. 2026 (no snapshot). The old code
            // may have posted it to iDoklad already — never sent from here.
            return self::recoverSnapshot($rowId);
        }

        // A row held as a credit under the rule before 19. 11. 2026: the
        // current rules decide it (issued with a negative line, no document,
        // or still held for a mismatch).
        if ((string) $row['status'] === 'needs_review' && (string) $row['review_reason'] === 'credit') {
            $d = self::classify($stripeInvoice);
            if ($d['decision'] !== 'issue') {
                $to = $d['decision'] === 'no_document' ? 'no_document' : 'needs_review';
                $reason = $d['decision'] === 'skip' ? 'zero' : $d['reason'];
                Db::pdo()->prepare("UPDATE invoices SET status = ?, review_reason = ?, error_message = ? WHERE id = ? AND status = 'needs_review' AND review_reason = 'credit'")
                    ->execute([$to, $reason, $d['detail'], $rowId]);
                if ($to === 'needs_review' && $reason !== 'zero') {
                    InvoiceAlert::notifyOnce($rowId, 'Faktúra čaká na kontrolu: ' . $d['detail']);
                }
                return $to;
            }
        }

        $accountId = (int) $row['account_id'];
        $livemode  = $row['stripe_livemode'] === null ? null : (int) $row['stripe_livemode'] === 1;
        $payload   = self::buildPayload($accountId, $stripeInvoice);
        $policy    = $this->api !== null
            ? ['send' => true, 'reason' => ''] // an injected API is a test recorder
            : IssuePolicy::decide($livemode);

        if (!$policy['send']) {
            if (self::claim($rowId, $claimable, 'prepared', $policy['reason'], $payload, false) && $livemode === true) {
                InvoiceAlert::notifyOnce($rowId, 'Ostrá platba dostala len pripravenú faktúru, do iDokladu sa nič neodoslalo (' . $policy['reason'] . ').');
            }
            return 'prepared';
        }
        if ($this->api === null && !IDokladClient::isConfigured()) {
            $msg = 'iDoklad nie je nastavený (IDOKLAD_CLIENT_ID / IDOKLAD_CLIENT_SECRET) — faktúra sa neodoslala.';
            if (self::claim($rowId, $claimable, 'skipped', $msg, $payload, false) && $livemode === true) {
                InvoiceAlert::notifyOnce($rowId, 'Ostrá platba nemá faktúru: ' . $msg);
            }
            return 'skipped';
        }
        if (!self::claim($rowId, $claimable, 'sending', null, $payload)) {
            $current = self::row($rowId);
            return (string) ($current['status'] ?? 'missing');
        }
        $attempts = (int) $row['attempts'] + 1;
        $paid     = (int) ($stripeInvoice['amount_paid'] ?? 0);

        $posting = false;
        try {
            $api       = $this->api ?? IDokladClient::fromEnv();
            $contactId = self::ensureContact($api, $accountId);
            $full      = self::mergeTemplate($api->get('IssuedInvoices/Default'), $payload, $contactId);
            self::storePayload($rowId, $full);

            // iDoklad counts the document itself before anything is created:
            // a total that is not the amount paid is never sent.
            $recount = $api->post('IssuedInvoices/Recount', $full);
            $withVat = $recount['Prices']['TotalWithVat'] ?? null;
            if (!is_numeric($withVat) || (int) round((float) $withVat * 100) !== $paid) {
                $msg = is_numeric($withVat)
                    ? 'iDoklad by vystavil doklad na ' . self::eur((int) round((float) $withVat * 100)) . ' s DPH, zaplatené bolo ' . self::eur($paid)
                      . ' — faktúra sa neodoslala. Skontroluj sadzbu DPH a zaokrúhľovanie v iDoklade; po oprave „Vystaviť znova".'
                    : 'iDoklad nevrátil súčet dokladu (IssuedInvoices/Recount bez Prices.TotalWithVat) — faktúra sa neodoslala.';
                self::setStatus($rowId, 'needs_review', 'vat_mismatch', $msg);
                InvoiceAlert::notifyOnce($rowId, $msg);
                return 'needs_review';
            }

            $posting = true;
            $created = $api->post('IssuedInvoices', $full);
            $posting = false;

            if ((int) ($created['Id'] ?? 0) <= 0) {
                // A 2xx answer without an invoice id: iDoklad may or may not
                // have created it. Same as no answer — a person checks.
                $msg = 'iDoklad odpovedal na odoslanie faktúry bez jej Id. Pred „Vystaviť znova" over v iDoklade, či faktúra nevznikla.';
                self::setStatus($rowId, 'needs_review', 'uncertain_send', $msg);
                InvoiceAlert::notify($rowId, $msg);
                return 'needs_review';
            }

            $publicUrl = self::publicUrl($created);
            // The POST response sometimes omits PublicHtmlUrl — read the
            // detail once to recover it. Non-fatal: the UI falls back to the
            // Stripe receipt.
            if ($publicUrl === null) {
                try {
                    $publicUrl = self::publicUrl($api->get('IssuedInvoices/' . (int) $created['Id']));
                } catch (\Throwable $e) {
                    error_log('[idoklad.refetch-url] ' . $e->getMessage());
                }
            }

            // Issued. The two follow-up steps start as pending: marking it
            // paid, and (a live payment only) e-mailing it to the customer.
            Db::pdo()->prepare(
                'UPDATE invoices
                 SET    idoklad_invoice_id = ?, document_number = ?, idoklad_public_url = ?,
                        status = ?, review_reason = NULL, error_message = NULL,
                        paid_mark_state = \'pending\', send_state = ?
                 WHERE  id = ?'
            )->execute([
                (int) $created['Id'],
                (string) ($created['DocumentNumber'] ?? ''),
                $publicUrl,
                'issued',
                $livemode === true || $this->api !== null ? 'pending' : null,
                $rowId,
            ]);
        } catch (\Throwable $e) {
            error_log('[idoklad.issue] row=' . $rowId . ' ' . $e->getMessage());
            if ($posting && $e instanceof IDokladTransportException) {
                $msg = 'iDoklad na odoslanie faktúry neodpovedal (' . $e->getMessage() . '). Pred „Vystaviť znova" over v iDoklade, či faktúra nevznikla.';
                self::setStatus($rowId, 'needs_review', 'uncertain_send', $msg);
                InvoiceAlert::notify($rowId, $msg);
                return 'needs_review';
            }
            $msg = 'Chyba pri vystavení v iDoklade: ' . $e->getMessage();
            self::setStatus($rowId, 'error', null, $msg);
            if ($attempts === 1 && (int) $row['amount_cents'] > 0) {
                self::sendFallbackEmail($accountId, $stripeInvoice);
            }
            if ($manual || $attempts >= self::MAX_AUTO_ATTEMPTS) {
                InvoiceAlert::notifyOnce($rowId, $msg);
            }
            return 'error';
        }

        $this->followUps($rowId);
        return 'issued';
    }

    /**
     * The two steps after an invoice is issued: mark it paid in iDoklad, then
     * ask iDoklad to e-mail it to the customer. Each step runs only while its
     * state allows it (pending, or error below the cap; a manual run also
     * takes error past the cap and — for the send — uncertain, after a person
     * checked iDoklad). A failure is recorded on the row and never changes
     * the issued state. Returns the two states afterwards.
     *
     * Runs only when the invoice was issued in `send` mode (IssuePolicy, or
     * an injected test API); the e-mail only for a live Stripe payment.
     *
     * @return array{paid: ?string, send: ?string}
     */
    public function followUps(int $rowId, bool $manual = false): array
    {
        $row = self::row($rowId);
        if ($row === null || (string) $row['status'] !== 'issued' || (int) ($row['idoklad_invoice_id'] ?? 0) <= 0) {
            return ['paid' => $row['paid_mark_state'] ?? null, 'send' => $row['send_state'] ?? null];
        }
        $livemode = $row['stripe_livemode'] === null ? null : (int) $row['stripe_livemode'] === 1;
        if ($this->api === null && (!IssuePolicy::decide($livemode)['send'] || !IDokladClient::isConfigured())) {
            return ['paid' => $row['paid_mark_state'], 'send' => $row['send_state']];
        }
        try {
            $api = $this->api ?? IDokladClient::fromEnv();
        } catch (\Throwable $e) {
            error_log('[idoklad.followups] row=' . $rowId . ' ' . $e->getMessage());
            return ['paid' => $row['paid_mark_state'], 'send' => $row['send_state']];
        }
        $paid = self::markPaid($api, $row, $manual);
        $send = ($livemode === true || $this->api !== null) ? self::sendToCustomer($api, self::row($rowId) ?? $row, $manual) : $row['send_state'];
        return ['paid' => $paid, 'send' => $send];
    }

    /**
     * PUT IssuedDocumentPayments/FullyPay/{id}?dateOfPayment=… — „Metoda
     * provede plnou úhradu faktury" (iDoklad API v3). The payment date is
     * Stripe's (status_transitions.paid_at). ErrorCode 110 („Document has
     * already been paid") counts as done.
     *
     * @param array<string, mixed> $row
     */
    private static function markPaid(IDokladApi $api, array $row, bool $manual): ?string
    {
        $rowId = (int) $row['id'];
        $from = ['pending', 'error'];
        $in = implode(',', array_fill(0, count($from), '?'));
        $cap = $manual ? '' : ' AND paid_mark_attempts < ' . self::MAX_AUTO_ATTEMPTS;
        $claim = Db::pdo()->prepare(
            "UPDATE invoices SET paid_mark_state = 'marking', paid_mark_attempts = paid_mark_attempts + 1, last_attempt_at = NOW()
             WHERE id = ? AND paid_mark_state IN ($in)$cap"
        );
        $claim->execute([$rowId, ...$from]);
        if ($claim->rowCount() !== 1) {
            return (string) (self::row($rowId)['paid_mark_state'] ?? '') ?: null;
        }
        $attempts = (int) $row['paid_mark_attempts'] + 1;
        $date = self::paidDate(json_decode((string) $row['stripe_invoice_json'], true) ?: []);
        try {
            $api->put('IssuedDocumentPayments/FullyPay/' . (int) $row['idoklad_invoice_id'] . '?dateOfPayment=' . $date);
            $note = null;
        } catch (\Throwable $e) {
            if ($e->getCode() === 110 || str_contains($e->getMessage(), 'already been paid') || str_contains($e->getMessage(), '"ErrorCode":110')) {
                $note = 'iDoklad: doklad už bol uhradený (ErrorCode 110).';
            } else {
                $msg = 'Označenie úhrady v iDoklade zlyhalo: ' . mb_substr($e->getMessage(), 0, 500);
                Db::pdo()->prepare("UPDATE invoices SET paid_mark_state = 'error', paid_mark_error = ? WHERE id = ?")->execute([$msg, $rowId]);
                if ($manual || $attempts >= self::MAX_AUTO_ATTEMPTS) {
                    InvoiceAlert::notifyOnce($rowId, $msg, 'paid:error');
                }
                return 'error';
            }
        }
        Db::pdo()->prepare("UPDATE invoices SET paid_mark_state = 'done', paid_marked_at = NOW(), paid_mark_error = ? WHERE id = ?")->execute([$note, $rowId]);
        return 'done';
    }

    /**
     * POST Mails/IssuedInvoice/Send with SendToPartner — „Send to the partner
     * (purchaser/supplier) specified on the document" (iDoklad API v3):
     * iDoklad's own invoice e-mail with the PDF, to the e-mail of the contact
     * the invoice was issued to. That contact's e-mail is set to the main
     * user's e-mail first (PATCH Contacts) when it differs from the one the
     * app last stored on it. Answers {Sent: […], NotSent: […]}: sent only when
     * nothing is in NotSent.
     *
     * Claimed atomically (pending / error → sending), so two runs never send
     * twice. An answer that may have sent the e-mail without saying so (a
     * transport error after the request left) is `uncertain`: held for a
     * person, never retried automatically. No e-mail on the main user: error,
     * alerted once, not retried automatically.
     *
     * @param array<string, mixed> $row
     */
    private static function sendToCustomer(IDokladApi $api, array $row, bool $manual): ?string
    {
        $rowId = (int) $row['id'];
        $state = $row['send_state'] ?? null;
        if ($state === null || in_array($state, ['sent', 'sending'], true)) {
            return $state;
        }
        $recipient = self::recipientEmail((int) $row['account_id']);
        if ($recipient === '') {
            $msg = 'Zákazník nemá e-mail (hlavný používateľ účtu) — faktúra sa mu neodoslala.';
            Db::pdo()->prepare("UPDATE invoices SET send_state = 'error', send_error = ?, send_attempts = GREATEST(send_attempts, ?) WHERE id = ? AND send_state IN ('pending', 'error')")
                ->execute([$msg, self::MAX_AUTO_ATTEMPTS, $rowId]);
            InvoiceAlert::notifyOnce($rowId, $msg, 'send:no_email');
            return 'error';
        }
        $from = $manual ? ['pending', 'error', 'uncertain'] : ['pending', 'error'];
        $in = implode(',', array_fill(0, count($from), '?'));
        $cap = $manual ? '' : ' AND send_attempts < ' . self::MAX_AUTO_ATTEMPTS;
        $claim = Db::pdo()->prepare(
            "UPDATE invoices SET send_state = 'sending', send_attempts = send_attempts + 1, sent_to = ?, last_attempt_at = NOW()
             WHERE id = ? AND send_state IN ($in)$cap"
        );
        $claim->execute([$recipient, $rowId, ...$from]);
        if ($claim->rowCount() !== 1) {
            return (string) (self::row($rowId)['send_state'] ?? '') ?: null;
        }
        $attempts = (int) $row['send_attempts'] + 1;
        $fail = static function (string $msg) use ($rowId, $manual, $attempts): string {
            Db::pdo()->prepare("UPDATE invoices SET send_state = 'error', send_error = ? WHERE id = ?")->execute([$msg, $rowId]);
            if ($manual || $attempts >= self::MAX_AUTO_ATTEMPTS) {
                InvoiceAlert::notifyOnce($rowId, $msg, 'send:error');
            }
            return 'error';
        };

        $payload = json_decode((string) ($row['idoklad_payload_json'] ?? ''), true);
        $contactId = (int) (is_array($payload) ? ($payload['PartnerId'] ?? 0) : 0);
        try {
            self::syncContactEmail($api, (int) $row['account_id'], $contactId, $recipient);
        } catch (\Throwable $e) {
            return $fail('E-mail kontaktu v iDoklade sa nepodarilo nastaviť: ' . mb_substr($e->getMessage(), 0, 400));
        }

        try {
            $answer = $api->post('Mails/IssuedInvoice/Send', [
                'DocumentId'       => (int) $row['idoklad_invoice_id'],
                'SendToPartner'    => true,
                'SendToSelf'       => false,
                'SendToAccountant' => false,
                'Method'           => 0, // Pdf: the regular PDF attachment
                'ReportLanguage'   => 2, // Sk
            ]);
        } catch (IDokladTransportException $e) {
            $msg = 'iDoklad na odoslanie faktúry zákazníkovi neodpovedal (' . mb_substr($e->getMessage(), 0, 300) . ') — e-mail mohol odísť. Over v iDoklade, až potom „Poslať znova".';
            Db::pdo()->prepare("UPDATE invoices SET send_state = 'uncertain', send_error = ? WHERE id = ?")->execute([$msg, $rowId]);
            InvoiceAlert::notifyOnce($rowId, $msg, 'send:uncertain');
            return 'uncertain';
        } catch (\Throwable $e) {
            return $fail('Odoslanie faktúry zákazníkovi zlyhalo: ' . mb_substr($e->getMessage(), 0, 500));
        }
        $sent = array_values(array_filter((array) ($answer['Sent'] ?? []), 'is_string'));
        $notSent = array_values(array_filter((array) ($answer['NotSent'] ?? []), 'is_string'));
        if ($sent === [] || $notSent !== []) {
            return $fail('iDoklad e-mail neodoslal' . ($notSent !== [] ? ' (NotSent: ' . implode(', ', $notSent) . ')' : ' (prázdny zoznam Sent)')
                . '. Skontroluj e-mail kontaktu v iDoklade.');
        }
        Db::pdo()->prepare("UPDATE invoices SET send_state = 'sent', sent_at = NOW(), send_error = NULL WHERE id = ?")->execute([$rowId]);
        return 'sent';
    }

    /**
     * The address the invoice e-mail goes to: the account's main user's
     * e-mail (users.email — the login of the person who manages billing;
     * the account has no separate billing e-mail). '' when there is none.
     */
    public static function recipientEmail(int $accountId): string
    {
        $stmt = Db::pdo()->prepare('SELECT u.email FROM accounts a JOIN users u ON u.id = a.main_user_id WHERE a.id = ?');
        $stmt->execute([$accountId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    /**
     * Put $email on the iDoklad contact the invoice was issued to, unless the
     * app already stored exactly that e-mail on the account's contact
     * (accounts.idoklad_contact_email). PATCH /v3/Contacts with Id in the body
     * („Update contact. Partial update is also possible.").
     */
    private static function syncContactEmail(IDokladApi $api, int $accountId, int $contactId, string $email): void
    {
        if ($contactId <= 0) {
            throw new \RuntimeException('faktúra nemá kontakt (PartnerId)');
        }
        $stmt = Db::pdo()->prepare('SELECT idoklad_contact_id, idoklad_contact_email FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $acc = $stmt->fetch() ?: [];
        if ((int) ($acc['idoklad_contact_id'] ?? 0) === $contactId && strcasecmp((string) ($acc['idoklad_contact_email'] ?? ''), $email) === 0) {
            return;
        }
        $api->patch('Contacts', ['Id' => $contactId, 'Email' => $email]);
        if ((int) ($acc['idoklad_contact_id'] ?? 0) === $contactId) {
            Db::pdo()->prepare('UPDATE accounts SET idoklad_contact_email = ? WHERE id = ?')->execute([$email, $accountId]);
        }
    }

    /**
     * Issue the row once the response has gone back to the caller (Stripe):
     * under PHP-FPM fastcgi_finish_request() closes the request first; on the
     * CLI it simply runs at shutdown.
     */
    public static function issueAfterResponse(int $rowId): void
    {
        self::$deferred[] = static fn () => (new self())->issue($rowId);
        self::registerShutdown();
    }

    /**
     * Alert the operator about a row once the response has gone back to the
     * caller (a held invoice recorded by the webhook: no SMTP wait while
     * Stripe waits for the answer). Once per row and state: when the daily
     * run (an event re-run) alerted the same state meanwhile, nothing more
     * is sent.
     */
    public static function alertAfterResponse(int $rowId, string $reason): void
    {
        self::$deferred[] = static fn () => InvoiceAlert::notifyOnce($rowId, $reason);
        self::registerShutdown();
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::runDeferred();
        });
    }

    /**
     * Run the jobs queued for after the response now (the daily run calls it
     * after an event re-run, so its own alert loop sees what they did).
     */
    public static function runDeferred(): void
    {
        $jobs = self::$deferred;
        self::$deferred = [];
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (\Throwable $e) {
                error_log('[idoklad.deferred] ' . $e->getMessage());
            }
        }
    }

    /**
     * Our part of the iDoklad invoice: header fields and the items
     * (payloadItems(): net unit prices, the standard VAT rate). Numbering and
     * the remaining item flags come from iDoklad's default template at send
     * time.
     *
     * @param array<string, mixed> $stripeInvoice
     * @return array<string, mixed>
     */
    public static function buildPayload(int $accountId, array $stripeInvoice): array
    {
        $currency = strtoupper((string) ($stripeInvoice['currency'] ?? 'eur'));
        $paidDate = self::paidDate($stripeInvoice);
        $id       = (string) ($stripeInvoice['id'] ?? '');

        return [
            'PartnerId'      => null, // the iDoklad contact of the account, filled in at send time
            'CurrencyId'     => self::currencyId($currency),
            'DateOfIssue'    => date('Y-m-d'),
            'DateOfTaxing'   => $paidDate,
            'DateOfMaturity' => $paidDate,
            'Description'    => self::deriveDescription($stripeInvoice),
            'Note'           => "Stripe invoice $id",
            'Items'          => self::payloadItems($stripeInvoice),
        ];
    }

    /**
     * Stripe's payment date of the invoice (Y-m-d), today when it says none.
     *
     * @param array<string, mixed> $stripeInvoice
     */
    private static function paidDate(array $stripeInvoice): string
    {
        $paidAt = $stripeInvoice['status_transitions']['paid_at'] ?? null;
        return is_int($paidAt) && $paidAt > 0 ? date('Y-m-d', $paidAt) : date('Y-m-d');
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
     * a pending, failed, prepared or skipped row; one held because iDoklad
     * gave no definite answer (after the admin checked in iDoklad), because
     * iDoklad's Recount did not match (checked again before the POST), or as
     * a credit under the rule before 19. 11. 2026. Not a mismatch held for
     * review, not a row without a document, and not a row without the Stripe
     * invoice snapshot — the daily run rebuilds that from Stripe first.
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
            || ($status === 'needs_review' && in_array((string) ($row['review_reason'] ?? ''), ['uncertain_send', 'vat_mismatch', 'credit'], true));
    }

    /**
     * Whether the admin's „Poslať znova" can work for a row: an issued
     * invoice whose marking as paid or whose e-mail failed or is uncertain.
     *
     * @param array<string, mixed> $row invoices row
     */
    public static function manualFollowUpAllowed(array $row): bool
    {
        return (string) ($row['status'] ?? '') === 'issued'
            && (in_array((string) ($row['send_state'] ?? ''), ['error', 'uncertain', 'pending'], true)
                || in_array((string) ($row['paid_mark_state'] ?? ''), ['error', 'pending'], true));
    }

    /**
     * A row without the Stripe invoice snapshot — written by the code before
     * 17. 11. 2026 (left `pending` / `error`), or one whose rebuild failed
     * (`needs_review` / `missing_snapshot`). Reads the invoice again from
     * Stripe by its id and stores it. The row is never sent from here: the
     * old code may have posted it to iDoklad before it stopped, so it goes
     * to `needs_review` / `uncertain_send` and a person checks iDoklad first
     * (a mismatch or a 0 € invoice gets its own reason).
     *
     * When Stripe cannot be read the row stays `missing_snapshot` (the daily
     * run alerts it once and tries again) — until Stripe answers 404 or the
     * read failed SNAPSHOT_READ_TRIES times: then `snapshot_unreadable`, a
     * terminal state the daily run no longer fetches, alerted once.
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
            $failures = (int) ($row['snapshot_read_failures'] ?? 0) + 1;
            $notFound = $e instanceof \Stripe\Exception\InvalidRequestException && $e->getHttpStatus() === 404;
            $legacy = 'Riadok je zo staršej verzie aplikácie — najprv skontroluj v iDoklade, či faktúra už neexistuje.';
            if ($notFound || $failures >= self::SNAPSHOT_READ_TRIES) {
                $msg = 'Faktúru zo Stripe sa nepodarilo načítať ' . ($notFound ? '(Stripe ju nepozná)' : "ani na $failures. pokus") . ' ('
                    . mb_substr($e->getMessage(), 0, 300) . '); denná kontrola to už neskúša. ' . $legacy;
                Db::pdo()->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'snapshot_unreadable', error_message = ?, snapshot_read_failures = ? WHERE id = ?")
                    ->execute([$msg, $failures, $rowId]);
                InvoiceAlert::notifyOnce($rowId, $msg);
                return 'needs_review';
            }
            Db::pdo()->prepare("UPDATE invoices SET status = 'needs_review', review_reason = 'missing_snapshot', error_message = ?, snapshot_read_failures = ? WHERE id = ?")
                ->execute(['Faktúru zo Stripe sa nepodarilo načítať (' . mb_substr($e->getMessage(), 0, 300) . '); denná kontrola to skúsi znova. ' . $legacy, $failures, $rowId]);
            return 'needs_review';
        }

        Db::pdo()->prepare(
            'UPDATE invoices
             SET    stripe_invoice_json = ?, stripe_total_cents = ?, stripe_amount_paid_cents = ?, stripe_tax_cents = ?, stripe_livemode = ?
             WHERE  id = ?'
        )->execute([
            json_encode($inv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int) ($inv['total'] ?? 0), (int) ($inv['amount_paid'] ?? 0), StripeShape::invoiceTax($inv),
            isset($inv['livemode']) ? ((bool) $inv['livemode'] ? 1 : 0) : null,
            $rowId,
        ]);

        $decision = self::classify($inv);
        if ($decision['decision'] === 'review') {
            self::setStatus($rowId, 'needs_review', $decision['reason'], $decision['detail']);
            InvoiceAlert::notifyOnce($rowId, 'Faktúra čaká na kontrolu: ' . $decision['detail']);
            return 'needs_review';
        }
        if ($decision['decision'] === 'skip' || $decision['decision'] === 'no_document') {
            self::setStatus($rowId, 'needs_review', 'zero', 'Za faktúru zo Stripe sa nič neplatilo — nič sa nevystavuje. Ak ju stará verzia aplikácie vystavila v iDoklade, treba ju tam opraviť.');
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
     * and counts the attempt ($attempt: a real send; preparing or skipping a
     * row is not one). False when another run took the row first.
     *
     * @param list<string> $from
     * @param array<string, mixed> $payload
     */
    private static function claim(int $rowId, array $from, string $to, ?string $message, array $payload, bool $attempt = true): bool
    {
        if ($from === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($from), '?'));
        $count = $attempt ? 'attempts = attempts + 1, last_attempt_at = NOW()' : 'last_attempt_at = NOW()';
        $stmt = Db::pdo()->prepare(
            "UPDATE invoices
             SET    status = ?, error_message = ?, review_reason = NULL, idoklad_payload_json = ?, $count
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
     * our fields are overwritten — the items' PriceType and VatRateType
     * always ours.
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
            'Unit'               => 'ks',
        ];
        unset($itemTpl['Id'], $itemTpl['Prices'], $itemTpl['VatRate']);
        $items = array_map(static fn (array $item): array => array_merge($itemTpl, $item), (array) $payload['Items']);

        $full = array_merge($tpl, $payload, ['PartnerId' => $contactId, 'Items' => $items]);
        // Server-managed / read-only keys that POST rejects.
        foreach (['Id', 'DateOfPayment', 'DateOfLastReminder', 'Attachments', 'Phases', 'PaymentStatus', 'Prices'] as $k) {
            unset($full[$k]);
        }
        return $full;
    }

    /** @param array<string, mixed> $line */
    private static function isExtraLine(array $line): bool
    {
        // The plan is never an extra line, whatever else the line matches
        // (a mis-set STRIPE_PRODUCT_EXTRA_TECHNICIAN, a legacy name in its
        // description).
        $priceId = StripeShape::linePriceId($line);
        $lineProduct = StripeShape::lineProductId($line);
        if (($priceId !== null && in_array($priceId, StripeClient::basePriceIds(), true))
            || ($lineProduct !== null && in_array($lineProduct, SeatSync::baseProductIds() ?? [], true))) {
            return false;
        }
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
     * In the normal case (the invoice issued) the customer gets only
     * iDoklad's invoice e-mail (followUps()), not this one.
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
     * from `accounts.invoice_*` fields on first invoice, with the main user's
     * e-mail (the address iDoklad sends the invoice to). Persists the id and
     * the e-mail so subsequent invoices skip the lookup.
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

        $email = self::recipientEmail($accountId);
        $payload = [
            'CompanyName'     => $row['invoice_company_name'] ?? 'Klient POapp',
            'Street'          => $row['invoice_street']       ?? '',
            'PostalCode'      => $row['invoice_postal_code']  ?? '',
            'City'            => $row['invoice_city']         ?? '',
            'CountryId'       => self::countryId((string) ($row['invoice_country'] ?? 'Slovensko')),
            'IdentificationNumber' => $row['invoice_ico']     ?? '',
            'VatIdentificationNumber' => $row['invoice_ic_dph'] ?? '',
            'VatIdentificationNumberSk' => $row['invoice_dic'] ?? '',
            'Email'           => $email,
        ];
        $created = $api->post('Contacts', $payload);
        $contactId = isset($created['Id']) ? (int) $created['Id'] : 0;
        if ($contactId === 0) {
            throw new \RuntimeException('iDoklad contact creation returned no Id');
        }

        Db::pdo()->prepare(
            'UPDATE accounts SET idoklad_contact_id = ?, idoklad_contact_email = ? WHERE id = ?'
        )->execute([$contactId, $email !== '' ? $email : null, $accountId]);

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
