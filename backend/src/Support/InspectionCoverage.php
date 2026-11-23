<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Which prevádzky an úkon covers (POapp 7. 11. 2026, K1).
 *
 * A new-format požiarna kniha entry ({@see BookEntry}) covers every prevádzka
 * of its `inspection_facilities` rows, each with its own periodicity. Every
 * other úkon — an old book entry included — has no rows and covers only
 * `inspections.facility_id`, with `inspections.periodicity_*`.
 *
 * These SQL fragments let a query read the term chain from both shapes:
 *
 *   join()             LEFT JOIN of the rows (one row per covered prevádzka)
 *   facility()         the covered prevádzka of a joined row
 *   periodicityValue() / periodicityUnit()
 *                      the periodicity of a joined row. While an entry is
 *                      spôsob 2 (is_preventive_inspection = 0) the stored
 *                      per-row values are hidden — the entry moves no term —
 *                      but they stay stored, so switching back restores them.
 *   covers()           whether record `$s` covers the prevádzka `$f`; `$f`
 *                      appears twice, so a bound value has to be bound twice
 *                      (named placeholders cannot repeat with real prepares)
 *   chain()            the records that hold the prehliadka term
 */
final class InspectionCoverage
{
    public static function join(string $i = 'i', string $x = 'ix'): string
    {
        return "LEFT JOIN inspection_facilities $x ON $x.inspection_id = $i.id";
    }

    public static function facility(string $i = 'i', string $x = 'ix'): string
    {
        return "COALESCE($x.facility_id, $i.facility_id)";
    }

    public static function periodicityValue(string $i = 'i', string $x = 'ix'): string
    {
        return "IF($x.id IS NULL, $i.periodicity_value, IF($i.is_preventive_inspection = 1, $x.periodicity_value, NULL))";
    }

    public static function periodicityUnit(string $i = 'i', string $x = 'ix'): string
    {
        return "IF($x.id IS NULL, $i.periodicity_unit, IF($i.is_preventive_inspection = 1, $x.periodicity_unit, NULL))";
    }

    public static function covers(string $s, string $f): string
    {
        return "($s.facility_id = $f OR EXISTS (SELECT 1 FROM inspection_facilities {$s}x"
            . " WHERE {$s}x.inspection_id = $s.id AND {$s}x.facility_id = $f))";
    }

    public static function chain(string $s): string
    {
        return "($s.type IN ('preventivna_prehliadka', 'poziarna_kniha') AND $s.is_preventive_inspection = 1)";
    }

    /**
     * The covered prevádzky of each given entry that has rows, as the API
     * returns them: stored periodicity (shown only in spôsob 1 by the screens),
     * the term it defines (null in spôsob 2) and whether a newer prehliadka at
     * that prevádzka superseded it.
     *
     * @param int|null  $accountId null = any account (platform admin)
     * @param list<int> $ids
     * @return array<int, list<array{facility_id: int, name: string, periodicity_value: ?int, periodicity_unit: ?string, valid_until: ?string, is_superseded: bool}>>
     */
    public static function forInspections(?int $accountId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT ix.inspection_id, ix.facility_id, f.name,
                       ix.periodicity_value, ix.periodicity_unit,
                       i.executed_on, i.is_preventive_inspection,
                       EXISTS (
                           SELECT 1 FROM inspections s
                           WHERE  i.status = 'finalized'
                             AND  s.account_id = i.account_id
                             AND  " . self::covers('s', 'ix.facility_id') . "
                             AND  s.type IN ('preventivna_prehliadka', 'poziarna_kniha')
                             AND  s.archived_at IS NULL
                             AND  s.status = 'finalized'
                             AND  s.is_preventive_inspection = 1
                             AND  (COALESCE(s.executed_on, '1000-01-01'), s.id)
                                > (COALESCE(i.executed_on, '1000-01-01'), i.id)
                       ) AS is_superseded
                FROM   inspection_facilities ix
                JOIN   inspections i ON i.id = ix.inspection_id
                JOIN   facilities  f ON f.id = ix.facility_id
                WHERE  ix.inspection_id IN ($in)";
        $args = $ids;
        if ($accountId !== null) {
            $sql .= ' AND i.account_id = ?';
            $args[] = $accountId;
        }
        $sql .= ' ORDER BY f.name ASC, ix.facility_id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $value = $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null;
            $unit = $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null;
            $preventive = (int) $r['is_preventive_inspection'] === 1;
            $out[(int) $r['inspection_id']][] = [
                'facility_id'       => (int) $r['facility_id'],
                'name'              => (string) $r['name'],
                'periodicity_value' => $value,
                'periodicity_unit'  => $unit,
                'valid_until'       => $preventive
                    ? Periodicity::validUntil($r['executed_on'] !== null ? (string) $r['executed_on'] : null, $value, $unit)
                    : null,
                'is_superseded'     => (bool) $r['is_superseded'],
            ];
        }
        return $out;
    }

    /**
     * The one prevádzka that speaks for an entry with several in a single-row
     * view (the unfiltered list, the detail header): the not-superseded one
     * with the earliest term. The entry counts as superseded only when every
     * prevádzka is.
     *
     * @param list<array{facility_id: int, name: string, periodicity_value: ?int, periodicity_unit: ?string, valid_until: ?string, is_superseded: bool}> $facilities
     * @return array{periodicity_value: ?int, periodicity_unit: ?string, valid_until: ?string, is_superseded: bool}|null
     */
    public static function representative(array $facilities, bool $preventive): ?array
    {
        if ($facilities === []) {
            return null;
        }
        $open = array_values(array_filter($facilities, static fn (array $f): bool => !$f['is_superseded']));
        $pool = $open !== [] ? $open : $facilities;
        usort($pool, static function (array $a, array $b): int {
            if ($a['valid_until'] === $b['valid_until']) {
                return $a['facility_id'] <=> $b['facility_id'];
            }
            if ($a['valid_until'] === null) {
                return 1;
            }
            if ($b['valid_until'] === null) {
                return -1;
            }
            return strcmp($a['valid_until'], $b['valid_until']);
        });
        $pick = $pool[0];
        return [
            'periodicity_value' => $preventive ? $pick['periodicity_value'] : null,
            'periodicity_unit'  => $preventive ? $pick['periodicity_unit'] : null,
            'valid_until'       => $preventive ? $pick['valid_until'] : null,
            'is_superseded'     => $open === [],
        ];
    }
}
