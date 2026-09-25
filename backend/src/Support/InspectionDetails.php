<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Header data of an úkon — `inspections.details` (migration 042).
 *
 * Some úkony have data that belongs to the úkon as a whole rather than to one
 * of its rows: the breath analyser of a dychová skúška, the test kit of a
 * kontrola omamných látok, the druh and obsah of an oboznámenie BOZP. Rows
 * stay in inspection_items; this is what sits above them.
 *
 * A type registers its validator in {@see validate()}. A type without an entry
 * has no header, and a `details` payload for it is refused rather than stored
 * unchecked.
 *
 * Usage: POST /api/inspections and PATCH /api/inspections/{id} accept a
 * `details` object; show() returns it decoded. PATCH replaces the whole object
 * (send every field), so a cleared field is expressible.
 */
final class InspectionDetails
{
    public static function supports(string $type): bool
    {
        return PersonList::isPersonType($type)
            // Block 2 — the opatrenia / záver of the BOZP row types.
            || BozpItems::supports($type);
    }

    /**
     * @param mixed $raw the `details` value off the wire
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function validate(string $type, mixed $raw): array
    {
        if (!self::supports($type)) {
            throw new \InvalidArgumentException('Tento typ úkonu nemá údaje v hlavičke.');
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Údaje v hlavičke majú nesprávny tvar.');
        }
        if (PersonList::isPersonType($type)) {
            return PersonList::validateDetails($type, $raw);
        }
        if (BozpItems::supports($type)) {
            return BozpItems::validateDetails($type, $raw);
        }
        return [];
    }

    /**
     * What „Opakovať" copies into the new draft.
     *
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    public static function carry(string $type, ?array $details): ?array
    {
        if (PersonList::isPersonType($type)) {
            return PersonList::carryDetails($type, $details);
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public static function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $details */
    public static function encode(?array $details): ?string
    {
        return $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE);
    }
}
