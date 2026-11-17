<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Carrying items over from the previous inspection — block 1 / chapter 12.
 *
 * The single biggest time saver in the field: a technician standing in a
 * boiler room does not retype forty extinguishers they already typed a year
 * ago. What travels depends on the kind of úkon:
 *
 *   - The fire-protection types ({@see self::FULL}: PHP, hydranty, tlaková
 *     skúška hadíc, both požiarne uzávery, núdzové osvetlenie) carry EVERY
 *     field of the last protocol — identification, measured values, the
 *     result / stav and the texts (fix list of 16. 11. 2026, spec 12.2:
 *     „preberú sa tie isté údaje, čo tam boli predtým, vrátane výsledku").
 *     The technician changes only what changed; where the values came from is
 *     recorded on the draft (`carried_from` / `carried_positions`, see
 *     {@see PrehliadkaChecklist}) and shown on its summary, and nothing is
 *     issued with a required value empty
 *     ({@see \Firol\Controllers\DocumentController::generateForInspectionInternal()}).
 *   - Every other type (the BOZP rows and records, the person lists) carries
 *     IDENTIFICATION only — make, type, serial, inventory number, location.
 *     Its assessment (výsledok, poznámka, nedostatky) starts empty and each
 *     carried item keeps `previous_status`, shown next to it while entering
 *     results; it is display-only and no protocol template reads it (BOZP
 *     block spec ch. 12).
 *
 * Photos never travel, and an item disposed of last time ("vyradený") is not
 * carried over at all; the technician is told how many were dropped, so a
 * missing device reads as a decision rather than a bug.
 */
final class CarryOver
{
    /** Meta key holding last time's stav/výsledok on a carried item. */
    public const PREVIOUS_STATUS_KEY = 'previous_status';

    /**
     * Types whose items travel whole — values and result included (fix list
     * of 16. 11. 2026, point 1). Their field set is KEEP plus the keys of BLANK.
     */
    public const FULL = [
        'php', 'hydranty', 'ts_hadic', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie',
    ];

    /**
     * Identification fields kept per inspection type. For a type outside
     * {@see self::FULL} everything not listed is an assessment and is dropped.
     *
     * @var array<string, list<string>>
     */
    private const KEEP = [
        'php'                => ['manufacturer', 'type', 'serial', 'year', 'location'],
        'oprava_ts_php'      => ['manufacturer', 'type', 'serial', 'year', 'location'],
        'vyradenie'          => ['manufacturer', 'type', 'serial', 'year', 'location'],
        'hydranty'           => ['type', 'type_other', 'location', 'hose_count'],
        'pu_akcieschopnost'  => ['kind', 'identifier', 'manufacturer', 'location'],
        'pu_udrzba'          => ['kind', 'identifier', 'manufacturer', 'location'],
        'nudzove_osvetlenie' => ['evid_number', 'floor', 'luminaire_type', 'manufacturer', 'location'],
        'ts_hadic'           => ['hose_type', 'location', 'manufacturer', 'length', 'year_of_manufacture'],
        // The požiarna kniha carries nothing over: its items are defects, and a
        // repeated entry starts with nothing ticked (POapp 7. 11. 2026, spec 6.4;
        // BookEntry::repeat).
        // Block 2 — single-record BOZP úkony (BozpRecords). The workplaces
        // walked, the activities ticked and the rows checked travel; every
        // result inside a row is blanked by ROW_KEEP below.
        'kniha_bozp'         => ['workspaces', 'activities', 'custom_activities'],
        'pracovisko'         => ['spaces', 'areas'],
        'osamele_pracovisko' => ['rows'],
        'fajcenie'           => ['scope', 'rows'],
        // Block 2 — a person list carries names and pracovné zaradenie; the
        // result, time, value and signature are this test's (PersonList).
        'dychova_skuska'     => ['name', 'position'],
        'omamne_latky'       => ['name', 'position'],
        'skolenie_bozp'      => ['name', 'position'],
        // Block 2 (Firol\Support\BozpItems). Whether a regál carries its
        // nosnosť label is an observation, not identification, so it stays.
        'rebriky'              => ['inventory_number', 'type', 'manufacturer', 'year', 'location'],
        'regale'               => ['label', 'type', 'capacity', 'location'],
        'oopp'                 => ['position', 'equipment'],
        'pracovne_prostriedky' => ['name', 'manufacturer_type', 'inventory_number', 'location'],
        'oznacenie'            => ['kind', 'location'],
    ];

    /**
     * Blank values written for the assessment fields, so an item carried over
     * has the same JSON shape the type's form expects rather than a hole the
     * form has to guess at. For a {@see self::FULL} type these are the value
     * fields that travel too; the blank stands in only where the source item
     * lacks the key.
     *
     * @var array<string, array<string, mixed>>
     */
    private const BLANK = [
        'php'                => ['status' => '', 'notes' => null],
        'oprava_ts_php'      => ['notes' => null],
        'vyradenie'          => ['reason' => ''],
        'hydranty'           => ['hs' => null, 'hd' => null, 'q' => null, 'defects' => null, 'result' => ''],
        'pu_akcieschopnost'  => ['result' => '', 'notes' => null],
        'pu_udrzba'          => ['maintenance_work' => '', 'result' => '', 'notes' => null],
        'nudzove_osvetlenie' => ['duration_min' => null, 'result' => '', 'notes' => null],
        'ts_hadic'           => ['working_pressure' => null, 'test_pressure' => null, 'result' => '', 'notes' => null],
        'kniha_bozp'         => ['result' => '', 'defects' => []],
        'pracovisko'         => ['overall' => '', 'defects' => []],
        'osamele_pracovisko' => ['overall' => '', 'defects' => []],
        'fajcenie'           => ['measures' => null, 'defects' => []],
        'dychova_skuska'     => ['time' => null, 'value' => null, 'result' => null, 'signature' => null, 'defects' => []],
        'omamne_latky'       => ['time' => null, 'result' => null, 'signature' => null, 'defects' => []],
        'skolenie_bozp'      => ['date' => null, 'signature' => null, 'defects' => []],
        'rebriky'              => ['faults' => null, 'result' => '', 'defects' => []],
        'regale'               => ['capacity_marked' => null, 'faults' => null, 'result' => '', 'defects' => []],
        'oopp'                 => ['provided' => null, 'used' => null, 'condition' => null, 'notes' => null, 'defects' => []],
        'pracovne_prostriedky' => ['faults' => null, 'result' => '', 'defects' => []],
        'oznacenie'            => ['result' => '', 'notes' => null, 'defects' => []],
    ];

    /**
     * Lists of rows kept inside a carried record, and which keys of each row
     * travel. Every other key of the row is blanked: which workplace was
     * checked is identification, how it came out („nevyhovuje", the note, the
     * spojenie found there) is this year's observation.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const ROW_KEEP = [
        'pracovisko'         => ['areas' => ['name']],
        'osamele_pracovisko' => ['rows' => ['workplace', 'activity']],
        'fajcenie'           => ['rows' => ['area']],
    ];

    /**
     * The blank assessment fields of one type — what a device the app has not
     * seen before starts with (a prehliadka's concept builds those, plan 8b).
     *
     * @return array<string, mixed>
     */
    public static function blank(string $type): array
    {
        return self::BLANK[$type] ?? [];
    }

    /** True when the app can carry items over for this inspection type. */
    public static function supports(string $type): bool
    {
        return isset(self::KEEP[$type]);
    }

    /** True when this type's items travel whole, result included ({@see self::FULL}). */
    public static function carriesValues(string $type): bool
    {
        return in_array($type, self::FULL, true);
    }

    /**
     * Whether a draft's items must be checked for empty required fields before
     * its protocol is issued (fix list of 16. 11. 2026, rounds 2 and 3).
     *
     * The check exists to stop a protocol carrying values nobody typed, so it
     * runs only on a draft whose items were inserted without the item validator:
     *
     *  - a concept of a prehliadka (any type);
     *  - a draft with a recorded origin (`carried_from` — Opakovať, „Prevziať
     *    položky", the follow-ups built from another úkon, the checklist),
     *    whatever its type: an oprava / vyradenie follow-up included;
     *  - a draft made with Opakovať before the origin was recorded: it has
     *    `carried_over_from_id` and was never issued.
     *
     * A draft typed through the forms, an Excel import (a draft, `''` for an
     * empty cell) and an issued protocol opened again with „Upraviť" (it keeps
     * its `carried_over_from_id`) generate as they always did.
     *
     * @param array<string, mixed> $row the úkon: `details` (raw JSON or decoded),
     *        `carried_over_from_id` and `issued_before` when known
     */
    public static function checksCompleteness(array $row): bool
    {
        $details = $row['details'] ?? null;
        if (!is_array($details)) {
            $details = InspectionDetails::decode($details);
        }
        if (PrehliadkaConcepts::isConcept($details)) {
            return true;
        }
        if (is_array($details) && array_key_exists(PrehliadkaChecklist::CARRIED_FROM_KEY, $details)) {
            return true;
        }
        return !empty($row['carried_over_from_id']) && !self::wasIssued($row, $details);
    }

    /**
     * Whether the úkon was ever issued: it is finalized, or a draft that was
     * issued and opened again („Upraviť", „Doplniť výsledky"). `issued_before`
     * (migration 078) is set on every issue and reopen; the contractor snapshot
     * written at issue time backs it up for a row read without the column.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $details decoded `details`
     */
    public static function wasIssued(array $row, ?array $details): bool
    {
        if (($row['status'] ?? null) === 'finalized' || !empty($row['issued_before'])) {
            return true;
        }
        return is_array($details) && array_key_exists(Contractor::SNAPSHOT_KEY, $details);
    }

    /**
     * The latest finalized úkon of this type at this prevádzka — the source a
     * repeat and the device list both draw from. `$exceptId` leaves the draft
     * being filled out of the search.
     */
    public static function latestSourceId(
        int $accountId,
        int $facilityId,
        string $type,
        ?int $exceptId = null,
    ): ?int {
        $sql = 'SELECT id FROM inspections
                WHERE  account_id = ? AND facility_id = ? AND type = ?
                  AND  status = "finalized" AND archived_at IS NULL';
        $params = [$accountId, $facilityId, $type];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' ORDER BY COALESCE(executed_on, "1000-01-01") DESC, id DESC LIMIT 1';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Map one source item onto the fields of a carried-over item, or null when
     * it must not be carried (disposed last time).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    public static function mapItem(string $type, array $fields): ?array
    {
        if (!isset(self::KEEP[$type])) {
            return null;
        }
        if (self::isDisposed($type, $fields)) {
            return null;
        }

        // A fire type takes the value fields as well; meta keys of the source
        // (its own `previous_status`, the defects of a prehliadka it came from)
        // are not fields of the protocol and stay behind.
        $keep = self::carriesValues($type)
            ? array_merge(self::KEEP[$type], array_keys(self::BLANK[$type] ?? []))
            : self::KEEP[$type];
        $out = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $fields)) {
                $out[$key] = $fields[$key];
            }
        }
        $out += self::BLANK[$type] ?? [];

        foreach (self::ROW_KEEP[$type] ?? [] as $listKey => $rowKeys) {
            $rows = is_array($out[$listKey] ?? null) ? $out[$listKey] : [];
            $out[$listKey] = array_values(array_map(static function (mixed $row) use ($rowKeys): array {
                $row = is_array($row) ? $row : [];
                $kept = [];
                foreach ($row as $k => $v) {
                    $kept[$k] = in_array($k, $rowKeys, true) ? $v : (is_string($v) ? '' : null);
                }
                return $kept;
            }, $rows));
        }

        // The carried value itself is last time's stav; a badge would only repeat it.
        $previous = self::carriesValues($type) ? null : self::previousStatus($type, $fields);
        if ($previous !== null) {
            $out[self::PREVIOUS_STATUS_KEY] = $previous;
        }
        return $out;
    }

    /**
     * Items the source inspection disposed of. Counted so the UI can say
     * "z minulej kontroly boli 2 prístroje vyradené — neprenášam ich".
     *
     * @param list<array<string, mixed>> $items each with a `fields` array
     */
    public static function countDisposed(string $type, array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            if (self::isDisposed($type, (array) ($item['fields'] ?? []))) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string, mixed> $fields */
    private static function isDisposed(string $type, array $fields): bool
    {
        return match ($type) {
            'php'               => ($fields['status'] ?? null) === 'V',
            'pu_akcieschopnost',
            'pu_udrzba',
            'hydranty',
            'nudzove_osvetlenie',
            'ts_hadic',
            'rebriky',
            'regale'            => ($fields['result'] ?? null) === 'vyradene',
            default             => false,
        };
    }

    /**
     * Last time's assessment, as a short code the UI renders with the type's
     * own labels. Null for types where there is nothing single-valued to show.
     *
     * @param array<string, mixed> $fields
     */
    private static function previousStatus(string $type, array $fields): ?string
    {
        $raw = match ($type) {
            'php'    => $fields['status'] ?? null,
            'hydranty',
            'pu_akcieschopnost',
            'pu_udrzba',
            'nudzove_osvetlenie',
            'ts_hadic',
            'rebriky',
            'regale',
            'pracovne_prostriedky',
            'oznacenie' => $fields['result'] ?? null,
            // OOPP has no výsledok; its stav (vyhovujúci / opotrebený / chýba)
            // is what the technician wants to see from last time.
            'oopp'   => $fields['condition'] ?? null,
            default  => null,
        };
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
