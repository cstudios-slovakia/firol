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
 * `details` object; show() returns it decoded. PATCH replaces every field the
 * client owns (send every field), so a cleared field is expressible; the keys
 * the server writes stay ({@see self::mergeOver()}).
 */
final class InspectionDetails
{
    public static function supports(string $type): bool
    {
        return PersonList::isPersonType($type)
            // Block 2 — the opatrenia / záver of the BOZP row types.
            || BozpItems::supports($type)
            // POapp 26. 10. 2026 — the setup of a preventívna prehliadka.
            || PrehliadkaDetails::supports($type)
            // POapp 7. 11. 2026 — a požiarna kniha entry is its header (BookEntry).
            || $type === BookEntry::TYPE;
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
        if (PrehliadkaDetails::supports($type)) {
            return PrehliadkaDetails::validate($raw);
        }
        if ($type === BookEntry::TYPE) {
            // The shape only; the controller completes it with BookEntry::applyContext().
            return BookEntry::validateShape($raw);
        }
        return [];
    }

    /**
     * The header after a client's save: the validated fields over what is
     * stored (round 6 of the fix list of 16. 11. 2026). A validator always
     * returns its type's whole field set, so a field the client cleared is
     * still cleared; every other stored key stays — the keys the server writes,
     * listed below, and keys an older version stored that no validator returns
     * any more (e.g. `book_entry`; round 7 — they are not the server's, they are
     * simply left as they were):
     *
     *   carried_from, carried_positions   the origin of carried items (CarryOver,
     *                                     PrehliadkaChecklist) — the origin line
     *                                     and the „prevzaté" marks
     *   concept_from_prehliadka,
     *   concept_built_at, concept_item_count,
     *   concept_confirmed_at              a concept from a prehliadka
     *                                     (PrehliadkaConcepts) — without them an
     *                                     unconfirmed concept could be issued
     *   issued_contractor                 the Zhotoviteľ frozen at issue (Contractor)
     *   issued_record                     the prehliadka's frozen tables
     *                                     (PrehliadkaRecord); rewritten at the next issue
     *   issued                            what a book entry printed (BookEntry);
     *                                     removed by „Upraviť"
     *
     * Before this, saving opatrenia / záver of a BOZP item check stored the
     * validator's output alone and the keys above were lost.
     *
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed> $validated from {@see self::validate()}
     * @return array<string, mixed>
     */
    public static function mergeOver(?array $stored, array $validated): array
    {
        return array_replace($stored ?? [], $validated);
    }

    /**
     * The stored keys a client never sends — what is left of the header when
     * the client clears it (`details: null`).
     *
     * @param array<string, mixed>|null $stored
     * @return array<string, mixed>|null
     */
    public static function serverOwned(string $type, ?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }
        $left = array_diff_key($stored, self::clientKeys($type));
        return $left === [] ? null : $left;
    }

    /**
     * The keys a client owns: the field set its type's validator returns. The
     * validator of a preventívna prehliadka refuses an empty header (a scope
     * is required), so its set is read from the smallest valid one — round 7:
     * an empty set had made `details: null` keep every stored key.
     *
     * @return array<string, mixed>
     */
    private static function clientKeys(string $type): array
    {
        $sample = PrehliadkaDetails::supports($type) ? ['scope' => ['po' => true]] : [];
        try {
            return self::validate($type, $sample);
        } catch (\InvalidArgumentException) {
            return [];
        }
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
