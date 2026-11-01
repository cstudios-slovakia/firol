<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * What a prehliadka's walk-through teaches the prevádzka (spec 3.2, 5, 6.3.4).
 *
 * Entrances are never set up front: the technician types „Vchod 3" while
 * writing a defect and the app offers it as a tile from then on
 * (`facilities.entrances`, bytový dom only). The same for a place the tiles
 * did not have (`facilities.places`, every kind of client).
 *
 * Called from the defect save, inside its transaction, so the memory rides
 * the same request as the defect — including when that request was queued
 * offline and replays later.
 */
final class FacilityWalkMemory
{
    /** Per list; a runaway list would only make the tile row unusable. */
    private const MAX_ENTRIES = 60;

    public static function remember(int $accountId, int $facilityId, ?string $entrance, ?string $place): void
    {
        $entrance = self::clean($entrance);
        $place = self::clean($place);
        if ($entrance === null && $place === null) {
            return;
        }

        $stmt = Db::pdo()->prepare(
            'SELECT f.entrances, f.places, c.client_type
             FROM   facilities f
             JOIN   companies  c ON c.id = f.company_id
             WHERE  f.id = ? AND f.account_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$facilityId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        $entrances = self::list($row['entrances'] ?? null);
        $places = self::list($row['places'] ?? null);

        $changed = false;
        if ($entrance !== null && ClientKind::isBytovyDom((string) $row['client_type'])) {
            $changed = self::add($entrances, $entrance) || $changed;
        }
        if ($place !== null) {
            $changed = self::add($places, $place) || $changed;
        }
        if (!$changed) {
            return;
        }

        Db::pdo()->prepare('UPDATE facilities SET entrances = ?, places = ? WHERE id = ? AND account_id = ?')
            ->execute([
                json_encode($entrances, JSON_UNESCAPED_UNICODE),
                json_encode($places, JSON_UNESCAPED_UNICODE),
                $facilityId,
                $accountId,
            ]);
    }

    /** @param list<string> $list */
    private static function add(array &$list, string $value): bool
    {
        $key = mb_strtolower($value);
        foreach ($list as $existing) {
            if (mb_strtolower($existing) === $key) {
                return false;
            }
        }
        if (count($list) >= self::MAX_ENTRIES) {
            return false;
        }
        $list[] = $value;
        return true;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 120);
    }

    /** @return list<string> */
    private static function list(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }
}
