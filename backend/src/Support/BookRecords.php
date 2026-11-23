<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * „Priložiť záznamy" of a požiarna kniha entry (POapp 7. 11. 2026, spec 7).
 *
 * Every issued record of a company that can be attached: the PO úkony
 * (kontroly, the preventívna prehliadka) of its active prevádzky and its
 * trainings. A record counts once it has its document; an archived record or
 * prevádzka is not offered. A training without a prevádzka belongs to the
 * whole organisation and is offered once, in a group of its own headed
 * {@see self::COMPANY_GROUP_HEADING} (customer, 13. 11. 2026) — switched by
 * {@see self::OFFER_COMPANY_TRAININGS}.
 *
 * `is_latest` marks the newest record of each kind (`druh` = the úkon or
 * training type, spec 7: „druh sa berie z typu úkonu") per prevádzka, or per
 * organisation for its trainings; every record of that newest day counts.
 * The older ones are in the list too, for „Zobraziť aj staršie".
 *
 * Order: by prevádzka name, then the organisation's group; inside a group the
 * newest first.
 */
final class BookRecords
{
    /** Trainings without a prevádzka are offered (in their own group). */
    public const OFFER_COMPANY_TRAININGS = true;

    /** Heading of the group of the organisation's own trainings — not the company name (a client may be a town or a school). */
    public const COMPANY_GROUP_HEADING = 'Celá organizácia';

    /**
     * @return list<array<string, mixed>> rows with key, kind, id, facility_id, facility_name,
     *         druh, label, tag, date, number, meta, is_latest, text_full, text_ref,
     *         and the internal `items` / `attendees` the sentences are built from
     */
    public static function forCompany(int $accountId, int $companyId): array
    {
        $pdo = Db::pdo();
        // A record of a bytové družstvo happened „v bytovom dome" (round 6).
        $kind = $pdo->prepare('SELECT client_type FROM companies WHERE id = ? AND account_id = ?');
        $kind->execute([$companyId, $accountId]);
        $bytoveDruzstvo = ClientKind::isBytovyDom((string) $kind->fetchColumn());
        $types = array_values(array_diff(BookMapping::PO_INSPECTION_TYPES, ['poziarna_kniha']));
        $typeIn = implode(',', array_fill(0, count($types), '?'));

        $stmt = $pdo->prepare(
            "SELECT i.id, i.type, i.facility_id, f.name AS facility_name, i.executed_on,
                    (SELECT d.number FROM documents d
                      WHERE d.account_id = i.account_id AND d.parent_type = 'inspection'
                        AND d.parent_id = i.id AND d.type = i.type
                      ORDER BY d.id DESC LIMIT 1) AS number
             FROM   inspections i
             JOIN   facilities f ON f.id = i.facility_id AND f.archived_at IS NULL
             WHERE  i.account_id = ? AND i.company_id = ? AND i.status = 'finalized'
               AND  i.archived_at IS NULL AND i.executed_on IS NOT NULL
               AND  i.type IN ($typeIn)"
        );
        $stmt->execute([$accountId, $companyId, ...$types]);
        $inspections = array_values(array_filter(
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
            static fn (array $r): bool => $r['number'] !== null,
        ));

        $itemsBy = [];
        if ($inspections !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $inspections);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $items = $pdo->prepare(
                "SELECT id, inspection_id, fields FROM inspection_items
                 WHERE inspection_id IN ($in) ORDER BY inspection_id, position ASC, id ASC"
            );
            $items->execute($ids);
            foreach ($items->fetchAll(\PDO::FETCH_ASSOC) as $it) {
                $itemsBy[(int) $it['inspection_id']][] = [
                    'id'     => (int) $it['id'],
                    'fields' => json_decode((string) $it['fields'], true) ?: [],
                ];
            }
        }

        $records = [];
        foreach ($inspections as $r) {
            $id = (int) $r['id'];
            $rows = $itemsBy[$id] ?? [];
            $records[] = [
                'kind'          => 'inspection',
                'id'            => $id,
                'facility_id'   => (int) $r['facility_id'],
                'facility_name' => (string) $r['facility_name'],
                'druh'          => (string) $r['type'],
                'tag'           => $r['type'] === 'preventivna_prehliadka' ? 'ppp' : null,
                'date'          => (string) $r['executed_on'],
                'number'        => (string) $r['number'],
                // A prehliadka's points need the item ids; the counted úkony only the fields.
                'items'         => $r['type'] === 'preventivna_prehliadka'
                    ? $rows
                    : array_map(static fn (array $it): array => $it['fields'], $rows),
            ];
        }

        $tStmt = $pdo->prepare(
            "SELECT t.id, t.type, t.facility_id, f.name AS facility_name, t.date,
                    (SELECT d.number FROM documents d
                      WHERE d.account_id = t.account_id AND d.parent_type = 'training' AND d.parent_id = t.id
                      ORDER BY d.id DESC LIMIT 1) AS number,
                    (SELECT COUNT(*) FROM trainees tr WHERE tr.training_id = t.id) AS attendees
             FROM   trainings t
             LEFT   JOIN facilities f ON f.id = t.facility_id
             WHERE  t.account_id = ? AND t.company_id = ? AND t.status = 'finalized'
               AND  t.archived_at IS NULL AND t.date IS NOT NULL
               AND  (t.facility_id IS NULL OR f.archived_at IS NULL)"
        );
        $tStmt->execute([$accountId, $companyId]);
        foreach ($tStmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            if ($t['number'] === null) {
                continue;
            }
            $companyLevel = $t['facility_id'] === null;
            if ($companyLevel && !self::OFFER_COMPANY_TRAININGS) {
                continue;
            }
            $records[] = [
                'kind'          => 'training',
                'id'            => (int) $t['id'],
                'facility_id'   => $companyLevel ? null : (int) $t['facility_id'],
                'facility_name' => $companyLevel ? null : (string) $t['facility_name'],
                'druh'          => (string) $t['type'],
                // The žatva pokyn is a document for the employees, not a training
                // session (change request 2.3): it gets no „Školenie" tag.
                'tag'           => $t['type'] === 'pokyn_zatva' ? null : 'skolenie',
                'date'          => (string) $t['date'],
                'number'        => (string) $t['number'],
                'attendees'     => (int) $t['attendees'],
            ];
        }

        // Newest day per (prevádzka or organisation, druh).
        $newest = [];
        foreach ($records as $rec) {
            $g = self::groupKey($rec) . '|' . $rec['druh'];
            if (!isset($newest[$g]) || $rec['date'] > $newest[$g]) {
                $newest[$g] = $rec['date'];
            }
        }

        foreach ($records as &$rec) {
            $rec['key'] = ($rec['kind'] === 'training' ? 't' : 'i') . $rec['id'];
            $rec['label'] = BookSentences::heading($rec);
            $rec['meta'] = BookSentences::meta($rec);
            $rec['is_latest'] = $newest[self::groupKey($rec) . '|' . $rec['druh']] === $rec['date'];
            $rec['text_full'] = BookSentences::full($rec, $rec['facility_name'], $bytoveDruzstvo);
            $rec['text_ref'] = BookSentences::reference($rec);
        }
        unset($rec);

        usort($records, static function (array $a, array $b): int {
            // Prevádzky by name, the organisation's group last.
            $an = $a['facility_name'];
            $bn = $b['facility_name'];
            if ($an !== $bn) {
                if ($an === null) {
                    return 1;
                }
                if ($bn === null) {
                    return -1;
                }
                $c = strcoll($an, $bn) ?: strcmp($an, $bn);
                if ($c !== 0) {
                    return $c;
                }
                return ($a['facility_id'] ?? 0) <=> ($b['facility_id'] ?? 0);
            }
            return strcmp($b['date'], $a['date']) ?: ($b['id'] <=> $a['id']) ?: strcmp($a['kind'], $b['kind']);
        });
        return $records;
    }

    /**
     * The API shape: the internal `items` / `attendees` left out.
     *
     * @param array<string, mixed> $rec
     * @return array<string, mixed>
     */
    public static function publicShape(array $rec): array
    {
        return [
            'key'         => $rec['key'],
            'kind'        => $rec['kind'],
            'id'          => $rec['id'],
            'facility_id' => $rec['facility_id'],
            'druh'        => $rec['druh'],
            'label'       => $rec['label'],
            'tag'         => $rec['tag'],
            'date'        => $rec['date'],
            'number'      => $rec['number'],
            'meta'        => $rec['meta'],
            'is_latest'   => $rec['is_latest'],
            'text_full'   => $rec['text_full'],
            'text_ref'    => $rec['text_ref'],
        ];
    }

    /** @param array<string, mixed> $rec */
    private static function groupKey(array $rec): string
    {
        return $rec['facility_id'] === null ? 'firma' : (string) $rec['facility_id'];
    }
}
