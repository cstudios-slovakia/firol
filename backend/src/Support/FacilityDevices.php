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
            $shaped = self::shape($type, $fields);
            if ($shaped === null) {
                continue;
            }
            $shaped['source_item_id'] = (int) $row['id'];
            $out[] = $shaped;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{label: string, serial: ?string, description: ?string, location: ?string}|null
     */
    public static function shape(string $type, array $fields): ?array
    {
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
                $description = self::join([
                    self::text($fields, 'luminaire_type'),
                    self::text($fields, 'manufacturer'),
                ]);
                $floor = self::text($fields, 'floor');
                $location = self::join([$floor, $location]);
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
            return null;
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
