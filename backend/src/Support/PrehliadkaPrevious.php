<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * „Nedostatky z minulej prehliadky" (spec 6.5, plan phase 7b): the defects of
 * the last finished prehliadka at a prevádzka, which the app puts in front of
 * the technician at the start of the walk-through. Each one ends in exactly one
 * of three states — odstránené, trvá, dôvod zanikol — and the first and the
 * last are never merged.
 *
 * Source: the latest FINALIZED preventívna prehliadka here (both scopes). When
 * there is none but an old `poziarna_kniha` record exists, its defects are
 * offered instead — text and deadline only, which is all that record holds.
 *
 * This class only reads. What the technician decided is stored by the
 * prehliadka itself in `details.previous` ({@see PrehliadkaDetails}).
 */
final class PrehliadkaPrevious
{
    /**
     * @return array{
     *   source: array{kind: string, inspection_id: int, executed_on: ?string}|null,
     *   defects: list<array<string, mixed>>
     * }
     */
    public static function forFacility(int $accountId, int $facilityId, ?int $excludeInspectionId = null): array
    {
        $source = self::latest($accountId, $facilityId, PrehliadkaDetails::TYPE, $excludeInspectionId);
        if ($source !== null) {
            return [
                'source'  => self::sourceInfo('prehliadka', $source),
                'defects' => self::fromPrehliadka($source),
            ];
        }

        $legacy = self::latest($accountId, $facilityId, 'poziarna_kniha', null);
        if ($legacy !== null) {
            $defects = self::fromLegacy($legacy);
            if ($defects !== []) {
                return ['source' => self::sourceInfo('poziarna_kniha', $legacy), 'defects' => $defects];
            }
        }
        return ['source' => null, 'defects' => []];
    }

    /**
     * @return array{id: int, executed_on: ?string}|null
     */
    private static function latest(int $accountId, int $facilityId, string $type, ?int $exclude): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, executed_on FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
               AND  status = "finalized" AND archived_at IS NULL AND id <> ?
             ORDER  BY COALESCE(executed_on, "1000-01-01") DESC, id DESC
             LIMIT  1'
        );
        $stmt->execute([$accountId, $facilityId, $type, $exclude ?? 0]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'executed_on' => $row['executed_on'] !== null ? (string) $row['executed_on'] : null,
        ];
    }

    /**
     * @param array{id: int, executed_on: ?string} $row
     * @return array{kind: string, inspection_id: int, executed_on: ?string}
     */
    private static function sourceInfo(string $kind, array $row): array
    {
        return ['kind' => $kind, 'inspection_id' => $row['id'], 'executed_on' => $row['executed_on']];
    }

    /**
     * @param array{id: int, executed_on: ?string} $source
     * @return list<array<string, mixed>>
     */
    private static function fromPrehliadka(array $source): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$source['id']]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $f = json_decode((string) $row['fields'], true);
            if (!is_array($f) || !is_string($f['title'] ?? null)) {
                continue;
            }
            $where = is_array($f['where'] ?? null) ? $f['where'] : [];
            // Snapshots only — the references of the old record point at a device
            // list that has moved on; the label, serial and place still print.
            $devices = [];
            foreach ((array) ($f['devices'] ?? []) as $d) {
                if (is_array($d) && is_string($d['type'] ?? null) && is_string($d['label'] ?? null) && $d['label'] !== '') {
                    $devices[] = [
                        'type'     => $d['type'],
                        'label'    => $d['label'],
                        'serial'   => is_string($d['serial'] ?? null) ? $d['serial'] : null,
                        'location' => is_string($d['location'] ?? null) ? $d['location'] : null,
                    ];
                }
            }
            $out[] = [
                'key'             => 'p' . $source['id'] . '-' . (int) $row['id'],
                'legacy'          => false,
                'title'           => $f['title'],
                'scope'           => in_array($f['scope'] ?? null, PrehliadkaDefects::SCOPES, true) ? $f['scope'] : 'po',
                'where'           => is_array($f['where'] ?? null) ? $where : null,
                'where_text'      => is_string($where['text'] ?? null) ? $where['text'] : '',
                'devices'         => $devices,
                'device_manual'   => is_string($f['device_manual'] ?? null) && $f['device_manual'] !== '' ? $f['device_manual'] : null,
                'description'     => (string) ($f['description'] ?? ''),
                'regulation'      => (string) ($f['regulation'] ?? ''),
                'justification'   => (string) ($f['justification'] ?? ''),
                'measure'         => (string) ($f['measure'] ?? ''),
                'catalog_id'      => is_int($f['catalog_id'] ?? null) ? $f['catalog_id'] : null,
                'deadline_date'   => is_string($f['deadline_date'] ?? null) ? $f['deadline_date'] : null,
                'responsible'     => is_string($f['responsible'] ?? null) ? $f['responsible'] : null,
                'source_item_id'  => (int) $row['id'],
            ];
        }
        return $out;
    }

    /**
     * The defects of an old požiarna kniha record: description and deadline.
     *
     * @param array{id: int, executed_on: ?string} $source
     * @return list<array<string, mixed>>
     */
    private static function fromLegacy(array $source): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$source['id']]);

        $out = [];
        $n = 0;
        foreach ($stmt->fetchAll() as $row) {
            $f = json_decode((string) $row['fields'], true);
            if (!is_array($f)) {
                continue;
            }
            foreach (PkDefects::rows($f)['rows'] as $d) {
                $n++;
                $text = $d['description'];
                $out[] = [
                    'key'            => 'k' . $source['id'] . '-' . $n,
                    'legacy'         => true,
                    // The old record has no title; the text itself is the heading (cut short).
                    'title'          => mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text,
                    'scope'          => 'po',
                    'where'          => null,
                    'where_text'     => '',
                    'devices'        => [],
                    'device_manual'  => null,
                    'description'    => $text,
                    'regulation'     => '',
                    'justification'  => '',
                    'measure'        => '',
                    'catalog_id'     => null,
                    'deadline_date'  => $d['deadline'],
                    'responsible'    => null,
                    'source_item_id' => (int) $row['id'],
                ];
            }
        }
        return $out;
    }
}
