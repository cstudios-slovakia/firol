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
        $booked = BookLedger::bookedNumbers($accountId, $companyId, $book);

        $groups = [];
        foreach (self::inspections($accountId, $companyId, $book, $facilityId) as $row) {
            if (isset($booked[$row['number']])) {
                continue;
            }
            $key = 'i|' . $row['type'] . '|' . $row['date'];
            $groups[$key][] = $row;
        }
        if ($book === BookMapping::PO) {
            foreach (self::trainings($accountId, $companyId, $facilityId) as $row) {
                if ($row['number'] !== '' && isset($booked[$row['number']])) {
                    continue;
                }
                $key = 't|' . $row['type'] . '|' . $row['date'];
                $groups[$key][] = $row;
            }
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
            ];
        }

        usort($offers, static fn (array $a, array $b): int => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);

        // Phase 6 — „Termíny ďalších kontrol", requested for the požiarna kniha only.
        $terms = self::termsOffer($accountId, $companyId, $book, $facilityId);
        if ($terms !== null) {
            array_unshift($offers, $terms);
        }
        return $offers;
    }

    /**
     * The next due dates of the firm's own kontroly as one proposed sentence
     * (prototype `knihaTerminy`, `source = termin`). Offered only while no entry
     * of this book carries exactly that sentence — an unchanged sentence is not
     * offered again, a new úkon changes it and so brings the offer back.
     *
     * @return array<string, mixed>|null
     */
    private static function termsOffer(int $accountId, int $companyId, string $book, ?int $facilityId): ?array
    {
        if ($book !== BookMapping::PO) {
            return null;
        }
        $today = date('Y-m-d');
        $upcoming = ClientTerms::upcoming($accountId, $companyId, $facilityId, $today, $book);
        if ($upcoming === []) {
            return null;
        }

        $parts = array_map(
            static fn (array $t): string => mb_strtolower($t['label']) . ' do ' . ExternalTerms::skDate($t['valid']),
            $upcoming,
        );
        $text = 'Termíny ďalších kontrol: ' . implode(', ', $parts) . '.';

        // Any scope of this firm's book, like the protocol numbers.
        $seen = Db::pdo()->prepare(
            'SELECT 1 FROM book_entries
             WHERE  account_id = ? AND company_id = ? AND book = ? AND source = "termin" AND `text` = ?
             LIMIT  1'
        );
        $seen->execute([$accountId, $companyId, $book, $text]);
        if ($seen->fetchColumn() !== false) {
            return null;
        }

        return [
            'key'              => 'termin|' . md5($text),
            'kind'             => 'termin',
            'type'             => 'termin',
            'title'            => 'Termíny ďalších kontrol',
            'date'             => $today,
            'facilities'       => [],
            'merged'           => 0,
            'protocol_numbers' => [],
            'inspection_ids'   => [],
            'training_ids'     => [],
            'text'             => $text,
            'has_defects'      => false,
        ];
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
}
