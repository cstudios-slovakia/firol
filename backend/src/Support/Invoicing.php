<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Fakturácia úkonu — block 4 / chapter 22.
 *
 * Four fields on every úkon (inspections and trainings alike, migration 046):
 * the režim, a yes/no „vyfakturované" check-off with its date, and a note.
 * Nothing here ever touches a protocol: invoicing happens after the protocol
 * is issued, so these fields stay editable on a locked úkon and are printed on
 * no document.
 *
 * Deliberately no payment status — the spec rules it out (the technician's
 * invoicing system tracks that, and nobody would type it in twice).
 */
final class Invoicing
{
    public const PAUSAL = 'pausal';
    public const NA_FAKTURU = 'na_fakturu';
    public const NEFAKTURUJE_SA = 'nefakturuje_sa';

    /** Režim of an úkon — ciselniky.json `rezim_fakturacie`. */
    public const MODES = [self::PAUSAL, self::NA_FAKTURU, self::NEFAKTURUJE_SA];

    /** What a firm can be set to — the spec offers only these two there. */
    public const COMPANY_MODES = [self::PAUSAL, self::NA_FAKTURU];

    /** Column default for a firm that never had the setting chosen. */
    public const COMPANY_DEFAULT = self::NA_FAKTURU;

    /**
     * SQL condition for „nevyfakturované" on a table aliased `$alias` — the
     * exact rule of chapters 18 and 22: režim na faktúru and not yet checked
     * off. Shared by the list filters and the count, so the Dnes card and the
     * list it opens can never disagree.
     */
    public static function uninvoicedCondition(string $alias): string
    {
        return "$alias.billing_mode = '" . self::NA_FAKTURU . "' AND $alias.invoiced = 0";
    }

    /**
     * The režim a new úkon for this firm starts with. Falls back to the column
     * default when the firm is missing — the caller has already validated it,
     * this only keeps a create from failing over a setting.
     */
    public static function companyMode(int $companyId): string
    {
        $stmt = Db::pdo()->prepare('SELECT billing_mode FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $mode = $stmt->fetchColumn();
        return is_string($mode) && in_array($mode, self::COMPANY_MODES, true)
            ? $mode
            : self::COMPANY_DEFAULT;
    }

    /**
     * Normalise the four columns of a fetched row for the API. Rows fetched
     * without them (a query that does not select them) are left untouched.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        if (!array_key_exists('billing_mode', $row)) {
            return $row;
        }
        $mode = $row['billing_mode'];
        $row['billing_mode'] = is_string($mode) && in_array($mode, self::MODES, true) ? $mode : null;
        $row['invoiced'] = (bool) ($row['invoiced'] ?? false);
        $row['invoiced_at'] = $row['invoiced_at'] ?? null;
        $row['billing_note'] = $row['billing_note'] ?? null;
        return $row;
    }

    /**
     * Validate a partial update against the current state and return the full
     * next state. Keys absent from `$body` keep their current value.
     *
     * Rules:
     *   - the check-off only exists with režim na faktúru; any other režim
     *     clears it (and its date), so a paušál úkon can never linger as
     *     "vyfakturované" in some report;
     *   - ticking it without a date stamps today; unticking clears the date;
     *   - the note is free text, trimmed, empty means none.
     *
     * @param array<string, mixed> $current row with the four columns
     * @param array<string, mixed> $body    decoded request body
     * @return array{billing_mode: string|null, invoiced: int, invoiced_at: string|null, billing_note: string|null}
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function merge(array $current, array $body): array
    {
        $mode = $current['billing_mode'] ?? null;
        if (array_key_exists('billing_mode', $body)) {
            $mode = $body['billing_mode'];
            if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
                throw new \InvalidArgumentException('Neplatný režim fakturácie.');
            }
        }

        $invoiced = (bool) ($current['invoiced'] ?? false);
        $invoicedAt = $current['invoiced_at'] ?? null;
        if (array_key_exists('invoiced', $body)) {
            if (!is_bool($body['invoiced'])) {
                throw new \InvalidArgumentException('Príznak „Vyfakturované" musí byť áno alebo nie.');
            }
            $invoiced = $body['invoiced'];
            if (!$invoiced) {
                $invoicedAt = null;
            }
        }
        if (array_key_exists('invoiced_at', $body) && $invoiced) {
            $date = $body['invoiced_at'];
            if ($date !== null && (!is_string($date) || !self::isDate($date))) {
                throw new \InvalidArgumentException('Neplatný dátum vyfakturovania.');
            }
            $invoicedAt = $date;
        }

        $note = $current['billing_note'] ?? null;
        if (array_key_exists('billing_note', $body)) {
            $raw = $body['billing_note'];
            if ($raw !== null && !is_string($raw)) {
                throw new \InvalidArgumentException('Poznámka musí byť text.');
            }
            $note = $raw === null ? null : trim($raw);
            if ($note === '') {
                $note = null;
            }
            if ($note !== null && mb_strlen($note) > 1000) {
                throw new \InvalidArgumentException('Poznámka môže mať najviac 1000 znakov.');
            }
        }

        if ($mode !== self::NA_FAKTURU) {
            $invoiced = false;
            $invoicedAt = null;
        } elseif ($invoiced && $invoicedAt === null) {
            $invoicedAt = (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        return [
            'billing_mode' => $mode,
            'invoiced'     => $invoiced ? 1 : 0,
            'invoiced_at'  => $invoicedAt,
            'billing_note' => $note,
        ];
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }
}
