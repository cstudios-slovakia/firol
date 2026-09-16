<?php

declare(strict_types=1);

namespace Firol\Support;

/**
 * Carrying items over from the previous inspection — block 1 / chapter 12.
 *
 * The single biggest time saver in the field: a technician standing in a
 * boiler room does not retype forty extinguishers they already typed a year
 * ago. What travels is IDENTIFICATION — make, type, serial, year, inventory
 * number, location. What does NOT travel is the assessment: stav, výsledok,
 * poznámka, photos and nedostatky all start empty, because carrying a result
 * forward would let a protocol claim something nobody checked this time.
 *
 * Two further rules from the spec:
 *   - an item disposed of last time ("vyradený") is not carried over at all;
 *     the technician is told how many were dropped, so a missing device reads
 *     as a decision rather than a bug.
 *   - each carried item keeps `previous_status`, shown next to it while
 *     entering results — the technician sees at a glance which extinguisher
 *     was on tlaková skúška last year. It is display-only and no protocol
 *     template reads it.
 */
final class CarryOver
{
    /** Meta key holding last time's stav/výsledok on a carried item. */
    public const PREVIOUS_STATUS_KEY = 'previous_status';

    /**
     * Identification fields kept per inspection type. Everything not listed
     * is an assessment and is dropped.
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
        // Checklist type: the list of workspaces walked and the activities
        // ticked are the "identification" — the result and the nedostatky are
        // not (chapter 12).
        'poziarna_kniha'     => ['is_preventive', 'workspaces', 'activities', 'custom_activities'],
    ];

    /**
     * Blank values written for the assessment fields, so an item carried over
     * has the same JSON shape the type's form expects rather than a hole the
     * form has to guess at.
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
        'poziarna_kniha'     => ['result' => '', 'defects' => [], 'notes' => null],
    ];

    /** True when the app can carry items over for this inspection type. */
    public static function supports(string $type): bool
    {
        return isset(self::KEEP[$type]);
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

        $out = [];
        foreach (self::KEEP[$type] as $key) {
            if (array_key_exists($key, $fields)) {
                $out[$key] = $fields[$key];
            }
        }
        $out += self::BLANK[$type] ?? [];

        $previous = self::previousStatus($type, $fields);
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
            'ts_hadic'          => ($fields['result'] ?? null) === 'vyradene',
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
            'ts_hadic' => $fields['result'] ?? null,
            default  => null,
        };
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
