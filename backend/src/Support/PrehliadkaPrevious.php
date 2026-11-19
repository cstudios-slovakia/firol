<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\InspectionPhotoController;
use Firol\Db;

/**
 * „Nedostatky z minulej prehliadky" (spec 6.5, 12.1, plan phase 7b): the defects
 * of the last finished prehliadka at a prevádzka that are still OPEN (state
 * zistený or trvá, {@see PrehliadkaDefects::isOpen()}). Defects removed or whose
 * reason ceased stay in that record and in history and are not offered again.
 * Each offered one is opened on the defect screen and saved as a row of the new
 * prehliadka with one of the four states.
 *
 * Source: the latest FINALIZED preventívna prehliadka here (both scopes). When
 * there is none but an old `poziarna_kniha` record exists, its defects are
 * offered instead — text and deadline only, which is all that record holds.
 *
 * Next to them, the open defects of every finalized new-format požiarna kniha
 * entry (either spôsob, {@see BookEntry}) covering this prevádzka that is newer
 * than that prehliadka — all of them when there is none — written for this
 * prevádzka (POapp 7. 11. 2026, O3). A book entry replaces no defects: only a
 * prehliadka makes the technician decide on each of them, so they are offered
 * until a newer prehliadka has done so. Every defect row carries its own
 * `source` (kind prehliadka | poziarna_kniha); the top-level `source` stays the
 * prehliadka when there is one, else the newest contributing entry.
 * `source.number` is the number of the source's document (the PO record; the
 * BOZP record for a BOZP-only prehliadka; the book entry), null when none.
 * Each defect of a prehliadka carries its photos (the shape the inspection
 * detail gives them), so the defect screen can show them before they are copied.
 *
 * This class only reads. What the technician decided is a defect row of the
 * new prehliadka with `persists_from` ({@see PrehliadkaDefects}).
 */
final class PrehliadkaPrevious
{
    /**
     * @return array{
     *   source: array{kind: string, inspection_id: int, executed_on: ?string, number: ?string}|null,
     *   defects: list<array<string, mixed>>
     * }
     */
    public static function forFacility(int $accountId, int $facilityId, ?int $excludeInspectionId = null): array
    {
        $source = null;
        $defects = [];

        $prehliadka = self::latest($accountId, $facilityId, PrehliadkaDetails::TYPE, $excludeInspectionId);
        if ($prehliadka !== null) {
            $source = self::sourceInfo('prehliadka', $prehliadka);
            $defects = self::withSource(self::fromPrehliadka($prehliadka), $source);
        }

        foreach (BookEntry::openDefectEntries($accountId, $facilityId, $prehliadka['executed_on'] ?? null, $prehliadka['id'] ?? null) as $entry) {
            $info = self::sourceInfo('poziarna_kniha', $entry);
            $rows = self::fromPrehliadka($entry, $facilityId);
            if ($rows !== []) {
                array_push($defects, ...self::withSource($rows, $info));
                if ($prehliadka === null) {
                    $source = $info;
                }
            }
        }

        if ($prehliadka === null) {
            $legacy = self::latestLegacy($accountId, $facilityId);
            if ($legacy !== null) {
                $info = self::sourceInfo('poziarna_kniha', $legacy);
                $rows = self::fromLegacy($legacy);
                if ($rows !== []) {
                    array_push($defects, ...self::withSource($rows, $info));
                    $source ??= $info;
                }
            }
        }
        return ['source' => $source, 'defects' => $defects];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{kind: string, inspection_id: int, executed_on: ?string, number: ?string} $source
     * @return list<array<string, mixed>>
     */
    private static function withSource(array $rows, array $source): array
    {
        return array_map(static fn (array $r): array => $r + ['source' => $source], $rows);
    }

    /**
     * The latest finalized old-format book entry here (no `format` in its header).
     *
     * @return array{id: int, executed_on: ?string}|null
     */
    private static function latestLegacy(int $accountId, int $facilityId): ?array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT id, executed_on FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = 'poziarna_kniha'
               AND  status = 'finalized' AND archived_at IS NULL
               AND  (details IS NULL OR JSON_VALUE(details, '$.format') IS NULL)
             ORDER  BY COALESCE(executed_on, '1000-01-01') DESC, id DESC
             LIMIT  1"
        );
        $stmt->execute([$accountId, $facilityId]);
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
     * @return array{kind: string, inspection_id: int, executed_on: ?string, number: ?string}
     */
    private static function sourceInfo(string $kind, array $row): array
    {
        return [
            'kind'          => $kind,
            'inspection_id' => $row['id'],
            'executed_on'   => $row['executed_on'],
            'number'        => self::documentNumber($kind, $row['id']),
        ];
    }

    /**
     * The number the source was issued under — its latest filled-in document.
     * A BOZP-only prehliadka issues nothing of its own; its record is the kniha
     * BOZP child it made.
     */
    private static function documentNumber(string $kind, int $inspectionId): ?string
    {
        $pdo = Db::pdo();
        $own = $pdo->prepare(
            "SELECT number FROM documents
             WHERE  parent_type = 'inspection' AND parent_id = ? AND type = ?
               AND  (form_variant IS NULL OR form_variant = 'vyplneny')
             ORDER  BY id DESC LIMIT 1"
        );
        $own->execute([$inspectionId, $kind === 'prehliadka' ? PrehliadkaDetails::TYPE : 'poziarna_kniha']);
        $number = $own->fetchColumn();
        if ($number === false && $kind === 'prehliadka') {
            $child = $pdo->prepare(
                "SELECT d.number FROM documents d
                 JOIN   inspections c ON c.id = d.parent_id
                 WHERE  d.parent_type = 'inspection' AND d.type = 'kniha_bozp'
                   AND  c.source_inspection_id = ? AND c.type = 'kniha_bozp' AND c.archived_at IS NULL
                 ORDER  BY d.id DESC LIMIT 1"
            );
            $child->execute([$inspectionId]);
            $number = $child->fetchColumn();
        }
        return $number !== false && $number !== null ? (string) $number : null;
    }

    /**
     * Whether `$key` names a defect of record `$sourceId` — the keys this class
     * hands out: `p<record>-<item>` for a defect of a prehliadka or a book
     * entry (the item must be a defect row of that record), `k<record>-<n>`
     * (also the older `pk…`) for the n-th defect of an old book record. Round 6:
     * the walk checks an origin with it, so a new defect cannot borrow a
     * made-up one to become „dôvod zanikol".
     */
    public static function namesDefectOf(string $key, int $sourceId): bool
    {
        if (preg_match('/^p(\d+)-(\d+)$/', $key, $m) === 1) {
            if ((int) $m[1] !== $sourceId) {
                return false;
            }
            $stmt = Db::pdo()->prepare('SELECT fields FROM inspection_items WHERE id = ? AND inspection_id = ?');
            $stmt->execute([(int) $m[2], $sourceId]);
            $f = json_decode((string) $stmt->fetchColumn(), true);
            return is_array($f) && is_string($f['title'] ?? null);
        }
        if (preg_match('/^(?:pk|k)(\d+)-(\d+)$/', $key, $m) === 1) {
            if ((int) $m[1] !== $sourceId || (int) $m[2] < 1) {
                return false;
            }
            return (int) $m[2] <= count(self::fromLegacy(['id' => $sourceId, 'executed_on' => null]));
        }
        return false;
    }

    /**
     * The open defects of a prehliadka — or of a new-format book entry, then
     * only those written for `$facilityId` (the same row shape).
     *
     * @param array{id: int, executed_on: ?string} $source
     * @return list<array<string, mixed>>
     */
    private static function fromPrehliadka(array $source, ?int $facilityId = null): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$source['id']]);
        $photos = InspectionPhotoController::byItemForInspection($source['id']);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $f = json_decode((string) $row['fields'], true);
            if (!is_array($f) || !is_string($f['title'] ?? null)) {
                continue;
            }
            // Removed, or its reason ceased: it stays in that record, it is not offered again.
            if (!PrehliadkaDefects::isOpen($f)) {
                continue;
            }
            if ($facilityId !== null && (int) ($f['facility_id'] ?? 0) !== $facilityId) {
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
                'photos'          => $photos[(int) $row['id']] ?? [],
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
                    'photos'         => [],
                ];
            }
        }
        return $out;
    }
}
