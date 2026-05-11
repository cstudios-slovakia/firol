<?php

declare(strict_types=1);

namespace Firol\Idoklad;

use Firol\Db;

/**
 * Issues an iDoklad invoice for a Stripe payment, persisting the link
 * in our `invoices` table so the user's billing history page can list
 * them without round-tripping to iDoklad.
 *
 * Idempotent on `stripe_invoice_id` — Stripe occasionally retries the
 * `invoice.payment_succeeded` webhook, and we don't want duplicate
 * iDoklad invoices in that case.
 */
final class InvoiceIssuer
{
    /**
     * @param array<string, mixed> $stripeInvoice  Subset of the Stripe
     *        Invoice object we get in the webhook payload (id, customer,
     *        amount_paid, currency, lines.data[].description).
     */
    public function issueForStripePayment(int $accountId, array $stripeInvoice): void
    {
        $stripeInvoiceId = (string) ($stripeInvoice['id'] ?? '');
        if ($stripeInvoiceId === '') {
            return;
        }
        if (self::alreadyIssued($stripeInvoiceId)) {
            return;
        }

        $amountCents = (int)   ($stripeInvoice['amount_paid'] ?? 0);
        $currency    = strtoupper((string) ($stripeInvoice['currency'] ?? 'eur'));
        $description = self::deriveDescription($stripeInvoice);

        // Persist a row up front (without idoklad_invoice_id) so a
        // subsequent retry hits the idempotency check above even if
        // iDoklad fails or this process crashes mid-call.
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                 (account_id, stripe_invoice_id, amount_cents, currency, status, issued_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$accountId, $stripeInvoiceId, $amountCents, $currency, 'pending']);
        $localInvoiceId = (int) $pdo->lastInsertId();

        if (!IDokladClient::isConfigured()) {
            $pdo->prepare(
                'UPDATE invoices SET status = ?, error_message = ? WHERE id = ?'
            )->execute(['skipped', 'iDoklad not configured', $localInvoiceId]);
            return;
        }

        try {
            $client    = IDokladClient::fromEnv();
            $contactId = self::ensureContact($client, $accountId);

            // iDoklad's POST /IssuedInvoices is strict about required
            // metadata (DocumentSerialNumber, NumericSequenceId, item
            // discount + tax flags). The /IssuedInvoices/Default
            // endpoint returns a fully-populated template — we only
            // overwrite the fields that are actually ours.
            $tpl = $client->get('IssuedInvoices/Default');

            $today  = date('Y-m-d');
            $itemTpl = $tpl['Items'][0] ?? [
                'DiscountPercentage' => 0,
                'IsTaxMovement'      => true,
                'PriceType'          => 1,
                'VatRateType'        => 0,
                'Unit'               => 'ks',
            ];
            $item = array_merge($itemTpl, [
                'Name'      => $description,
                'Amount'    => 1,
                'UnitPrice' => round($amountCents / 100, 2),
            ]);

            $payload = array_merge($tpl, [
                'PartnerId'      => $contactId,
                'CurrencyId'     => self::currencyId($currency),
                'DateOfIssue'    => $today,
                'DateOfTaxing'   => $today,
                'DateOfMaturity' => $today,
                'Description'    => $description,
                'Note'           => "Stripe invoice $stripeInvoiceId",
                'Items'          => [$item],
            ]);
            // Drop server-managed/optional read-only keys that POST rejects.
            foreach (['Id', 'DateOfPayment', 'DateOfLastReminder', 'Attachments', 'Phases', 'PaymentStatus'] as $k) {
                unset($payload[$k]);
            }

            $created = $client->post('IssuedInvoices', $payload);

            $pdo->prepare(
                'UPDATE invoices
                 SET    idoklad_invoice_id = ?,
                        document_number    = ?,
                        status             = ?
                 WHERE  id = ?'
            )->execute([
                isset($created['Id']) ? (int) $created['Id'] : null,
                (string) ($created['DocumentNumber'] ?? ''),
                IDokladClient::isDraftMode() ? 'draft' : 'issued',
                $localInvoiceId,
            ]);
        } catch (\Throwable $e) {
            error_log('[idoklad.issue] ' . $e->getMessage());
            $pdo->prepare(
                'UPDATE invoices SET status = ?, error_message = ? WHERE id = ?'
            )->execute(['error', $e->getMessage(), $localInvoiceId]);
        }
    }

    private static function alreadyIssued(string $stripeInvoiceId): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM invoices WHERE stripe_invoice_id = ?'
        );
        $stmt->execute([$stripeInvoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Reuse the existing iDoklad Contact for this account, or create one
     * from `accounts.invoice_*` fields on first invoice. Persists the id
     * so subsequent invoices skip the lookup.
     */
    private static function ensureContact(IDokladClient $client, int $accountId): int
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
            'CompanyName'     => $row['invoice_company_name'] ?? 'Klient Firol',
            'Street'          => $row['invoice_street']       ?? '',
            'PostalCode'      => $row['invoice_postal_code']  ?? '',
            'City'            => $row['invoice_city']         ?? '',
            'CountryId'       => self::countryId((string) ($row['invoice_country'] ?? 'Slovensko')),
            'IdentificationNumber' => $row['invoice_ico']     ?? '',
            'VatIdentificationNumber' => $row['invoice_ic_dph'] ?? '',
            'VatIdentificationNumberSk' => $row['invoice_dic'] ?? '',
        ];
        $created = $client->post('Contacts', $payload);
        $contactId = isset($created['Id']) ? (int) $created['Id'] : 0;
        if ($contactId === 0) {
            throw new \RuntimeException('iDoklad contact creation returned no Id');
        }

        Db::pdo()->prepare(
            'UPDATE accounts SET idoklad_contact_id = ? WHERE id = ?'
        )->execute([$contactId, $accountId]);

        return $contactId;
    }

    /** @param array<string, mixed> $stripeInvoice */
    private static function deriveDescription(array $stripeInvoice): string
    {
        $period = '';
        $lines  = $stripeInvoice['lines']['data'][0] ?? null;
        if (is_array($lines)) {
            $start = isset($lines['period']['start']) ? (int) $lines['period']['start'] : 0;
            $end   = isset($lines['period']['end'])   ? (int) $lines['period']['end']   : 0;
            if ($start > 0 && $end > 0) {
                $period = ' (' . date('d.m.Y', $start) . ' – ' . date('d.m.Y', $end) . ')';
            }
        }
        return 'Firol — predplatné' . $period;
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
}
