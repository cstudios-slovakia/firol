<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\DocumentController;
use Firol\Db;

/**
 * „Appka ponúka pridať" (spec 9.3a): the finished úkony and trainings of a
 * firm that belong to a book and are not in it yet.
 *
 * Source: finalized inspections that hold an issued protocol number, and
 * finalized trainings. Which book they belong to is {@see BookMapping}.
 * Pairing is by protocol number, against every entry of that firm's book in
 * any scope — an úkon already booked is not offered a second time, not even
 * after the book's scope was switched, and a merged entry covers each of its
 * numbers.
 *
 * Scope `firma`: the same type on the same day at several prevádzky is ONE
 * offer carrying all their numbers („Záznamy č. …"). Scope `prevadzka`: only
 * that prevádzka's úkony are offered (plus a training of the whole firm, which
 * has no prevádzka — it stays on offer until it is booked in any ledger).
 *
 * Newest first. The screen folds the offers older than 12 months.
 */
final class BookOffers
{
    /**
     * @return list<array{
     *   key: string, kind: string, type: string, title: string, date: string,
     *   facilities: list<string>, merged: int, protocol_numbers: list<string>,
     *   inspection_ids: list<int>, training_ids: list<int>, text: string, has_defects: bool
     * }>
     */
    public static function forLedger(int $accountId, int $companyId, string $book, ?int $facilityId): array
    {
        if ($book !== BookMapping::PO) {
            return [];
        }

        $booked = BookLedger::bookedNumbers($accountId, $companyId, $book);

        $groups = [];
        foreach (self::inspections($accountId, $companyId, $book, $facilityId) as $row) {
            if (isset($booked[$row['number']])) {
                continue;
            }
            $key = 'i|' . $row['type'] . '|' . $row['date'];
            $groups[$key][] = $row;
        }
        foreach (self::trainings($accountId, $companyId, $facilityId) as $row) {
            if ($row['number'] !== '' && isset($booked[$row['number']])) {
                continue;
            }
            $key = 't|' . $row['type'] . '|' . $row['date'];
            $groups[$key][] = $row;
        }

        $offers = [];
        foreach ($groups as $key => $rows) {
            $first = $rows[0];
            $isTraining = $first['kind'] === 'training';
            $facilities = array_values(array_unique(array_filter(
                array_map(static fn (array $r): string => (string) $r['facility'], $rows),
                static fn (string $n): bool => $n !== '',
            )));
            $numbers = array_values(array_filter(
                array_map(static fn (array $r): string => (string) $r['number'], $rows),
                static fn (string $n): bool => $n !== '',
            ));

            if ($isTraining) {
                $attendees = array_sum(array_map(static fn (array $r): int => (int) $r['count'], $rows));
                $text = BookSentences::training($first['type'], $first['date'], $facilities, $numbers, $attendees);
                $title = DocumentController::TRAINING_TYPE_LABELS[$first['type']] ?? $first['type'];
            } elseif ($first['type'] === 'preventivna_prehliadka' && count($rows) === 1 && count($numbers) === 1) {
                // A prehliadka that was not written into the book on close: the same
                // sentence the automatic entry would have carried.
                $text = BookSentences::prehliadka(
                    BookMapping::PO,
                    $first['date'],
                    (string) ($facilities[0] ?? ''),
                    $numbers[0],
                    PrehliadkaRecord::pointCount((int) $first['id'], PrehliadkaRecord::PO),
                );
                $title = BookSentences::ukonTitle($first['type']);
            } else {
                $count = count($rows) === 1 ? (int) $first['count'] : null;
                $text = BookSentences::ukon($first['type'], $first['date'], $facilities, $numbers, $count);
                $title = BookSentences::ukonTitle($first['type']);
            }

            $offers[] = [
                'key'              => $key . '|' . implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows)),
                'kind'             => $first['kind'],
                'type'             => $first['type'],
                'title'            => $title,
                'date'             => $first['date'],
                'facilities'       => $facilities,
                'merged'           => count($rows),
                'protocol_numbers' => $numbers,
                'inspection_ids'   => $isTraining ? [] : array_map(static fn (array $r): int => (int) $r['id'], $rows),
                'training_ids'     => $isTraining ? array_map(static fn (array $r): int => (int) $r['id'], $rows) : [],
                'text'             => $text,
                // Only a preventívna prehliadka (phase 7) carries defects an
                // entry can be attached to; „bez nedostatkov" is then off.
                'has_defects'      => !$isTraining && $first['type'] === 'preventivna_prehliadka'
                    && BookLedger::carriesDefects($accountId, array_map(static fn (array $r): int => (int) $r['id'], $rows)),
                'defects_text'     => (!$isTraining && $first['type'] === 'preventivna_prehliadka')
                    ? self::defectsTextOfInspections($accountId, array_map(static fn (array $r): int => (int) $r['id'], $rows))
                    : null,
            ];
        }

        usort($offers, static fn (array $a, array $b): int => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);

        // Mark the date-wise newest row of each activity type group with is_latest and preselected (spec 9.3).
        $seenTitles = [];
        foreach ($offers as &$offer) {
            $t = $offer['title'];
            if (!isset($seenTitles[$t])) {
                $offer['is_latest'] = true;
                $offer['preselected'] = true;
                $seenTitles[$t] = true;
            } else {
                $offer['is_latest'] = false;
                $offer['preselected'] = false;
            }
        }
        unset($offer);

        return $offers;
    }

    /**
     * Grouped unbooked PO úkony of the firm for the 02.11 „Nový zápis" pull flow (spec 9.3).
     *
     * @return list<array{type: string, title: string, count: int, items: list<array<string, mixed>>}>
     */
    public static function availableActions(int $accountId, int $companyId, string $book, ?int $facilityId): array
    {
        $offers = self::forLedger($accountId, $companyId, $book, $facilityId);
        $byTitle = [];
        foreach ($offers as $offer) {
            $title = $offer['title'];
            $byTitle[$title][] = $offer;
        }
        $groups = [];
        foreach ($byTitle as $title => $items) {
            $groups[] = [
                'type'  => $items[0]['type'] ?? 'ukon',
                'title' => $title,
                'count' => count($items),
                'items' => $items,
            ];
        }
        return $groups;
    }

    /**
     * @return list<array{kind: string, id: int, type: string, date: string, facility: string, number: string, count: int}>
     */
    private static function inspections(int $accountId, int $companyId, string $book, ?int $facilityId): array
    {
        $types = BookMapping::inspectionTypesOf($book);
        $in = implode(',', array_fill(0, count($types), '?'));
        $params = [$accountId, $companyId, ...$types];
        $facilitySql = '';
        if ($facilityId !== null) {
            $facilitySql = ' AND i.facility_id = ?';
            $params[] = $facilityId;
        }

        $stmt = Db::pdo()->prepare(
            "SELECT i.id, i.type, i.executed_on, f.name AS facility_name,
                    (SELECT d.number FROM documents d
                     WHERE  d.account_id = i.account_id AND d.parent_type = 'inspection' AND d.parent_id = i.id
                     ORDER  BY d.id DESC LIMIT 1) AS number,
                    (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = i.id) AS item_count
             FROM   inspections i
             JOIN   facilities f ON f.id = i.facility_id
             WHERE  i.account_id = ? AND i.company_id = ?
               AND  i.status = 'finalized' AND i.archived_at IS NULL AND i.executed_on IS NOT NULL
               AND  i.type IN ($in)$facilitySql
             ORDER  BY i.executed_on DESC, i.id"
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            // No issued protocol number, nothing to pair the entry with.
            if ($row['number'] === null || (string) $row['number'] === '') {
                continue;
            }
            $out[] = [
                'kind'     => 'inspection',
                'id'       => (int) $row['id'],
                'type'     => (string) $row['type'],
                'date'     => (string) $row['executed_on'],
                'facility' => (string) $row['facility_name'],
                'number'   => (string) $row['number'],
                'count'    => (int) $row['item_count'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{kind: string, id: int, type: string, date: string, facility: string, number: string, count: int}>
     */
    private static function trainings(int $accountId, int $companyId, ?int $facilityId): array
    {
        $params = [$accountId, $companyId];
        $facilitySql = '';
        if ($facilityId !== null) {
            $facilitySql = ' AND (t.facility_id = ? OR t.facility_id IS NULL)';
            $params[] = $facilityId;
        }

        $stmt = Db::pdo()->prepare(
            "SELECT t.id, t.type, t.date, f.name AS facility_name,
                    (SELECT d.number FROM documents d
                     WHERE  d.account_id = t.account_id AND d.parent_type = 'training' AND d.parent_id = t.id
                     ORDER  BY d.id DESC LIMIT 1) AS number,
                    (SELECT COUNT(*) FROM trainees tr WHERE tr.training_id = t.id) AS attendees
             FROM   trainings t
             LEFT   JOIN facilities f ON f.id = t.facility_id
             WHERE  t.account_id = ? AND t.company_id = ?
               AND  t.status = 'finalized' AND t.archived_at IS NULL AND t.date IS NOT NULL$facilitySql
             ORDER  BY t.date DESC, t.id"
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['number'] === null || (string) $row['number'] === '') {
                continue;
            }
            $out[] = [
                'kind'     => 'training',
                'id'       => (int) $row['id'],
                'type'     => (string) $row['type'],
                'date'     => (string) $row['date'],
                'facility' => (string) ($row['facility_name'] ?? ''),
                'number'   => (string) $row['number'],
                'count'    => (int) $row['attendees'],
            ];
        }
        return $out;
    }

    /**
     * Formats PO defects from preventívna prehliadka inspection items for pre-populating defects_text.
     *
     * @param list<int> $inspectionIds
     */
    private static function defectsTextOfInspections(int $accountId, array $inspectionIds): ?string
    {
        if ($inspectionIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($inspectionIds), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT ii.fields FROM inspection_items ii
             JOIN inspections i ON i.id = ii.inspection_id
             WHERE i.account_id = ? AND ii.inspection_id IN ($in)
             ORDER BY ii.position, ii.id"
        );
        $stmt->execute([$accountId, ...$inspectionIds]);
        $lines = [];
        $n = 0;
        foreach ($stmt->fetchAll() as $row) {
            $f = json_decode((string) ($row['fields'] ?? '{}'), true);
            if (!is_array($f)) {
                continue;
            }
            $scope = $f['scope'] ?? 'po';
            if (!in_array($scope, ['po', 'ob'], true)) {
                continue;
            }
            $title = trim((string) ($f['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $desc = trim((string) ($f['description'] ?? ''));
            $n++;
            $lines[] = $n . '. ' . $title . ($desc !== '' ? ' — ' . $desc : '');
        }
        return $lines !== [] ? implode("\n", $lines) : null;
    }
}
