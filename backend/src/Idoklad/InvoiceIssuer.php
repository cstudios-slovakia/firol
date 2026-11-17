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
 *       - a credit (negative total or line, or part of it paid from the
 *         customer balance), a discount, or totals that do not add up:
 *         status `needs_review` — never issued automatically, shown in the
 *         admin panel with the Stripe numbers (how credits appear on a tax
 *         document is an open owner decision);
 *       - otherwise status `pending`.
 *  2. issue() — builds the iDoklad payload (one item per Stripe line) and,
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
 * An invoice POST that gets no definite answer (timeout, HTTP 5xx) may have
 * created the invoice in iDoklad, so that row goes to `needs_review`
 * (review_reason `uncertain_send`) instead of being retried blindly. A row
 * left in `sending` by a crash is treated the same way by the daily command.
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
     * @param array<string, mixed> $stripeInvoice with all lines
     * @return array{decision: 'skip'|'review'|'issue', reason: string, detail: string}
     */
    public static function classify(array $stripeInvoice): array
    {
        $total = (int) ($stripeInvoice['total'] ?? 0);
        $paid  = (int) ($stripeInvoice['amount_paid'] ?? 0);
        $lines = (array) ($stripeInvoice['lines']['data'] ?? []);
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
        foreach ($lines as $line) {
            $amount = (int) ($line['amount'] ?? 0);
            if ($amount < 0) {
                return ['decision' => 'review', 'reason' => 'credit', 'detail' => 'a credit line of ' . self::eur($amount) . " ($numbers)"];
            }
            $sum += $amount;
        }
        if ($sum !== $total) {
            return ['decision' => 'review', 'reason' => 'discount', 'detail' => 'lines add up to ' . self::eur($sum) . " — a discount or tax ($numbers)"];
        }
        return ['decision' => 'issue', 'reason' => '', 'detail' => ''];
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
            self::setStatus($rowId, 'needs_review', 'missing_snapshot', 'The Stripe invoice snapshot is missing — issue it by hand.');
            return 'needs_review';
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
        foreach ((array) ($stripeInvoice['lines']['data'] ?? []) as $line) {
            $amount = (int) ($line['amount'] ?? 0);
            if ($amount === 0) {
                continue;
            }
            $qty       = max(1, (int) ($line['quantity'] ?? 1));
            $proration = StripeShape::lineIsProration($line);
            $name      = self::isExtraLine($line) ? 'POapp — extra technik' : 'POapp — predplatné';
            if ($proration) {
                $name .= ', pomerná časť' . ($qty > 1 ? " ($qty technici)" : '');
            }
            $name .= self::periodText(StripeShape::linePeriod($line));

            if (!$proration && $amount % $qty === 0) {
                $items[] = ['Name' => $name, 'Amount' => $qty, 'UnitPrice' => round($amount / $qty / 100, 2), 'Unit' => 'ks'];
            } else {
                $items[] = ['Name' => $name, 'Amount' => 1, 'UnitPrice' => round($amount / 100, 2), 'Unit' => 'ks'];
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
        if ($status === 'pending') {
            return true;
        }
        if ($status === 'error') {
            return $manual || (int) $row['attempts'] < self::MAX_AUTO_ATTEMPTS;
        }
        if (!$manual) {
            return false;
        }
        return in_array($status, ['prepared', 'skipped'], true)
            || ($status === 'needs_review' && in_array((string) $row['review_reason'], ['uncertain_send', 'missing_snapshot'], true));
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
        // A legacy item (inline product of an old Checkout): Stripe puts the
        // product name into the line description („1 × POapp — extra technik
        // (at …)", „Remaining time on POapp — extra technik after …").
        return str_contains((string) ($line['description'] ?? ''), SeatSync::LEGACY_PRODUCT_NAME);
    }

    /** @param array{0: int, 1: int}|null $period */
    private static function periodText(?array $period): string
    {
        if ($period === null) {
            return '';
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
