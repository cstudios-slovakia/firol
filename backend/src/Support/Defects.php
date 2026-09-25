<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * „Zistené nedostatky" — the generic nedostatok block every úkon can carry
 * (block 2 / chapter 7: „pri každom type sa naviac dá pridať nedostatok
 * (popis, opatrenie, termín, fotky)"; data model chapter 4.4).
 *
 * ─── USAGE (for every type that wants the block) ────────────────────────────
 *
 * Storage. Nedostatky live in the `fields` JSON of an inspection item, under
 * the key `defects` ({@see self::FIELD}), as a list of
 *
 *     { key: string, description: string, measure: ?string, deadline: ?'YYYY-MM-DD' }
 *
 *   description  popis *            (required, max 500 chars)
 *   measure      opatrenie          (optional, max 500 chars)
 *   deadline     termín odstránenia (optional ISO date)
 *   key          stable id the photos of this nedostatok point at
 *
 * A single-record type (kniha_bozp, pracovisko, …) keeps them on its one
 * record item. A list type (rebríky, OOPP rows, …) keeps them on the item
 * they concern. There is no separate table on purpose: photos, the offline
 * outbox, backup/restore and purge all already work per item, and a
 * nedostatok's photos are ordinary item photos whose `defect_key` column
 * (migration 031) holds the nedostatok's `key`.
 *
 * Validation. In your item validator:
 *
 *     try {
 *         $fields['defects'] = Defects::normalize($body['defects'] ?? []);
 *     } catch (\InvalidArgumentException $e) {
 *         self::failValidation($e->getMessage());   // Slovak message, 422
 *     }
 *
 * Numbering. Defects::collect($items) numbers every nedostatok of the úkon,
 * across all its items, in item order — `number` is what the PDF table
 * (Firol\Pdf\DefectsTable) prints and what the photo captions refer to, so
 * pass the SAME item list (ordered by position) to both.
 *
 * Photos. DocumentController::buildPhotoAppendix() already captions every
 * photo that carries a `defect_key` via {@see self::photoAppendix()} —
 * „Nedostatok č. 2 — popis" — for any type that has no appendix branch of its
 * own. Nothing to do per type.
 *
 * Carry-over. Nedostatky never carry over (chapter 12). List `defects` with
 * `[]` in the type's CarryOver::BLANK entry and never in KEEP.
 *
 * Frontend counterpart: frontend/src/components/DefectsEditor.tsx.
 * ────────────────────────────────────────────────────────────────────────────
 */
final class Defects
{
    /** Key under which an item's nedostatky are stored in `fields`. */
    public const FIELD = 'defects';

    /** Fat-finger guard, not a policy — nobody records fifty on one row. */
    public const MAX_PER_ITEM = 50;

    private const MAX_TEXT = 500;

    /**
     * Validate and normalise the nedostatky coming off the wire.
     *
     * Null or a missing key is an empty list. A row with nothing typed in it
     * at all (no popis, no opatrenie, no termín) is dropped silently — it is
     * the empty row the editor adds, not a nedostatok. A row with an opatrenie
     * or a termín but no popis is refused: popis is the one required field.
     *
     * @return list<array{key: string, description: string, measure: ?string, deadline: ?string}>
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function normalize(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Nedostatky musia byť zoznam.');
        }
        if (count($raw) > self::MAX_PER_ITEM) {
            throw new \InvalidArgumentException(
                'Najviac ' . self::MAX_PER_ITEM . ' nedostatkov pri jednej položke.',
            );
        }

        $out = [];
        $seen = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Nedostatok má nesprávny tvar.');
            }
            $description = self::text($row['description'] ?? null);
            $measure     = self::text($row['measure'] ?? null);
            $deadline    = self::date($row['deadline'] ?? null);
            if ($deadline === null && self::text($row['deadline'] ?? null) !== null) {
                throw new \InvalidArgumentException('Termín odstránenia nedostatku nie je platný dátum.');
            }

            if ($description === null && $measure === null && $deadline === null) {
                continue;
            }
            if ($description === null) {
                throw new \InvalidArgumentException('Nedostatok č. ' . ($i + 1) . ' nemá popis — popis je povinný.');
            }
            if (mb_strlen($description) > self::MAX_TEXT) {
                throw new \InvalidArgumentException('Popis nedostatku je príliš dlhý (najviac ' . self::MAX_TEXT . ' znakov).');
            }
            if ($measure !== null && mb_strlen($measure) > self::MAX_TEXT) {
                throw new \InvalidArgumentException('Opatrenie je príliš dlhé (najviac ' . self::MAX_TEXT . ' znakov).');
            }

            // The client mints the key so photos taken before the first save
            // can already point at it. Regenerate a missing or mangled one
            // rather than failing the save; a duplicate would merge two
            // nedostatky's photos, so it is regenerated too.
            $rawKey = $row['key'] ?? null;
            $key = is_string($rawKey) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $rawKey) && !isset($seen[$rawKey])
                ? $rawKey
                : bin2hex(random_bytes(8));
            $seen[$key] = true;

            $out[] = [
                'key'         => $key,
                'description' => $description,
                'measure'     => $measure,
                'deadline'    => $deadline,
            ];
        }
        return $out;
    }

    /**
     * The nedostatky stored on one item, tolerant of anything malformed
     * (hydranty uses a plain-text `defects` field of its own — that is not a
     * list and yields nothing here).
     *
     * @param array<string, mixed> $fields
     * @return list<array{key: ?string, description: string, measure: ?string, deadline: ?string}>
     */
    public static function fromFields(array $fields): array
    {
        $raw = $fields[self::FIELD] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $description = self::text($row['description'] ?? null);
            if ($description === null) {
                continue;
            }
            $key = $row['key'] ?? null;
            $out[] = [
                'key'         => is_string($key) && $key !== '' ? $key : null,
                'description' => $description,
                'measure'     => self::text($row['measure'] ?? null),
                'deadline'    => self::date($row['deadline'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Every nedostatok of an úkon, numbered 1…n across its items in item
     * order. This numbering is the one the PDF prints and the photos refer to.
     *
     * @param list<array{id?: int, fields?: array<string, mixed>}> $items ordered by position
     * @return list<array{number: int, item_id: ?int, key: ?string, description: string, measure: ?string, deadline: ?string}>
     */
    public static function collect(array $items): array
    {
        $rows = [];
        $n = 0;
        foreach ($items as $item) {
            $itemId = isset($item['id']) ? (int) $item['id'] : null;
            foreach (self::fromFields((array) ($item['fields'] ?? [])) as $defect) {
                $rows[] = ['number' => ++$n, 'item_id' => $itemId] + $defect;
            }
        }
        return $rows;
    }

    /** Number of nedostatky across the úkon. */
    public static function count(array $items): int
    {
        return count(self::collect($items));
    }

    /**
     * Photo-appendix entries for the photos that document a nedostatok:
     * captioned „Nedostatok č. N — popis" and ordered like the table. A photo
     * whose nedostatok has since been deleted is left out — it documents
     * something the protocol no longer reports.
     *
     * @param list<array<string, mixed>> $items ordered by position
     * @param array<int, list<array{path: string, width: int, height: int, defect_key: ?string}>> $pathsByItem
     * @return list<array{caption: string, path: string, width: int, height: int}>
     */
    public static function photoAppendix(array $items, array $pathsByItem): array
    {
        $appendix = [];
        foreach (self::collect($items) as $defect) {
            if ($defect['item_id'] === null || $defect['key'] === null) {
                continue;
            }
            $caption = PhotoCaption::buildForDefect($defect['number'], $defect['description']);
            foreach ($pathsByItem[$defect['item_id']] ?? [] as $photo) {
                if (($photo['defect_key'] ?? null) !== $defect['key']) {
                    continue;
                }
                $appendix[] = [
                    'caption' => $caption,
                    'path'    => $photo['path'],
                    'width'   => $photo['width'],
                    'height'  => $photo['height'],
                ];
            }
        }
        return $appendix;
    }

    private static function text(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) ? $v : null;
    }
}
