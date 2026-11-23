<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * The device list of a prevádzka (spec 3.3, decision D7).
 *
 * There is no device register. The list is the items of the latest finalized
 * úkon of each type at this prevádzka — the same source {@see CarryOver}
 * offers to repeat from, and an item that úkon disposed of stays behind.
 * Read-only.
 */
final class FacilityDevices
{
    /** Types a walk-through can name a device of. Order is the screen's. */
    public const TYPES = [
        'php',
        'hydranty',
        'pu_akcieschopnost',
        'pu_udrzba',
        'nudzove_osvetlenie',
        'rebriky',
        'regale',
    ];

    /**
     * @return list<array{type: string, items: list<array<string, mixed>>}>
     */
    public static function forFacility(int $accountId, int $facilityId): array
    {
        $groups = [];
        foreach (self::TYPES as $type) {
            $sourceId = CarryOver::latestSourceId($accountId, $facilityId, $type);
            if ($sourceId === null) {
                continue;
            }
            $items = self::itemsOf($type, $sourceId);
            if ($items === []) {
                continue;
            }
            $groups[] = ['type' => $type, 'items' => $items];
        }
        return $groups;
    }

    /**
     * @return list<array{source_item_id: int, label: string, serial: ?string, description: ?string, location: ?string}>
     */
    private static function itemsOf(string $type, int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fields FROM inspection_items
             WHERE  inspection_id = ?
             ORDER  BY position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $fields = json_decode((string) $row['fields'], true);
            if (!is_array($fields)) {
                continue;
            }
            // Disposed last time — the same rule as carrying the úkon forward.
            if (CarryOver::mapItem($type, $fields) === null) {
                continue;
            }
            $shaped = self::shape($type, $fields, true);
            if ($shaped === null) {
                continue;
            }
            $shaped['source_item_id'] = (int) $row['id'];
            $out[] = $shaped;
        }
        return $out;
    }

    /** Longest device label a defect of the prehliadka accepts. */
    private const MAX_LABEL = 80;

    /** What a device is called when its úkon gave it no label at all. */
    private const NOUNS = [
        'php'                => 'Hasiaci prístroj',
        'hydranty'           => 'Hydrant',
        'pu_akcieschopnost'  => 'Požiarny uzáver',
        'pu_udrzba'          => 'Požiarny uzáver',
        'nudzove_osvetlenie' => 'Svietidlo',
        'rebriky'            => 'Rebrík',
        'regale'             => 'Regál',
    ];

    /**
     * How the walk-through and the checklist name a device.
     *
     * In the device list (`$alwaysLabel`) a device is never dropped for want of
     * a label (fix list of 16. 11. 2026, point 4): the evidenčné číslo of a
     * svietidlo is optional and an Excel import writes it empty, which once hid
     * núdzové osvetlenie from step 1 of the prehliadka. Without its own label a
     * device is named by what else describes it, then by its place, then by the
     * kind of device — so the count in step 1 equals the items its concept
     * gets. A svietidlo is named by its type before anything else, everywhere.
     * Without `$alwaysLabel` an item with no label at all gives null, and the
     * caller names it by its position.
     *
     * @param array<string, mixed> $fields
     * @return array{label: string, serial: ?string, description: ?string, location: ?string}|null
     */
    public static function shape(string $type, array $fields, bool $alwaysLabel = false): ?array
    {
        if (!isset(self::NOUNS[$type])) {
            return null;
        }
        $label = null;
        $serial = null;
        $description = null;
        $location = self::text($fields, 'location');

        switch ($type) {
            case 'php':
                $serial = self::text($fields, 'serial');
                $label = $serial;
                $description = self::join([self::text($fields, 'manufacturer'), self::text($fields, 'type')]);
                break;
            case 'hydranty':
                $kind = self::text($fields, 'type');
                $label = $kind === 'other' ? self::text($fields, 'type_other') : $kind;
                $location = $location ?? self::text($fields, 'location');
                break;
            case 'pu_akcieschopnost':
            case 'pu_udrzba':
                $label = self::text($fields, 'identifier');
                $description = self::join([
                    self::puKind(self::text($fields, 'kind')),
                    self::text($fields, 'manufacturer'),
                ]);
                break;
            case 'nudzove_osvetlenie':
                $label = self::text($fields, 'evid_number');
                $luminaire = self::text($fields, 'luminaire_type');
                if ($label === null && $luminaire !== null) {
                    // Named by its type, which then need not be repeated below.
                    $label = $luminaire;
                    $luminaire = null;
                }
                $description = self::join([$luminaire, self::text($fields, 'manufacturer')]);
                $floor = self::text($fields, 'floor');
                $location = self::join([$floor, $location]);
                // Spec order of the fallback: evidenčné číslo, typ svietidla, umiestnenie,
                // „Svietidlo" — a place that became the name is not repeated as the place.
                if ($alwaysLabel && $label === null) {
                    $label = $location ?? self::NOUNS[$type];
                    $location = null;
                }
                break;
            case 'rebriky':
                $label = self::text($fields, 'inventory_number');
                $description = self::join([self::text($fields, 'type'), self::text($fields, 'manufacturer')]);
                break;
            case 'regale':
                $label = self::text($fields, 'label');
                $description = self::join([self::text($fields, 'type'), self::text($fields, 'capacity')]);
                break;
        }

        if ($label === null || $label === '') {
            if (!$alwaysLabel) {
                return null;
            }
            // The text that became the name is not printed a second time.
            if ($description !== null) {
                $label = $description;
                $description = null;
            } elseif ($location !== null) {
                $label = $location;
                $location = null;
            } else {
                $label = self::NOUNS[$type];
            }
        }
        // A defect stores the device label in 80 characters at most
        // (PrehliadkaDefects::devices()); a long place or description is cut.
        if (mb_strlen($label) > self::MAX_LABEL) {
            $label = rtrim(mb_substr($label, 0, self::MAX_LABEL - 1)) . '…';
        }
        return [
            'label'       => $label,
            'serial'      => $serial,
            'description' => $description,
            'location'    => $location,
        ];
    }

    /** @param array<string, mixed> $fields */
    private static function text(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /** @param list<?string> $parts */
    private static function join(array $parts): ?string
    {
        $kept = array_values(array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== ''));
        return $kept === [] ? null : implode(' · ', $kept);
    }

    private static function puKind(?string $kind): ?string
    {
        return match ($kind) {
            'dvere'  => 'Požiarne dvere',
            'okno'   => 'Požiarne okno',
            'klapka' => 'Požiarna klapka',
            default  => $kind,
        };
    }
}
