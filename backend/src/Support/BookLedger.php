<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;
use PDO;

/**
 * The numbered rows of a book (spec 3.6, 9) — reading and writing them.
 *
 * A ledger is one (firm, book, prevádzka-or-none) triple: with the firm's
 * `book_scope` = firma every entry has `facility_id` NULL and the firm has two
 * ledgers (po, bozp); with `prevadzka` every prevádzka has two of its own.
 * Entries keep the scope they were written in, so after a switch the other
 * scope's rows are still there — listed apart, never renumbered.
 *
 * An entry is never edited or deleted (Q8): a later fact is an addition under
 * it, and signing only fills in the two signature columns.
 */
final class BookLedger
{
    public const SCOPE_FIRMA     = 'firma';
    public const SCOPE_PREVADZKA = 'prevadzka';

    private const ENTRY_COLUMNS = 'e.id, e.company_id, e.facility_id, f.name AS facility_name, e.book, e.seq,
        e.entry_date, e.`text`, e.source, e.protocol_numbers, e.inspection_ids, e.training_ids,
        e.no_defects_statement, e.signed_technician_at, e.signed_manager_at, e.signer_name, e.signer_role,
        e.created_by_user_id, u.fullname AS created_by_name, e.created_at';

    /**
     * The firm row the book screens work on, or null when it is not this
     * account's. Archived firms are returned too — their book stays readable.
     *
     * @return array{id: int, name: string, client_type: string, book_scope: string, archived_at: ?string}|null
     */
    public static function company(int $accountId, int $companyId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, client_type, book_scope, archived_at
             FROM   companies WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([$companyId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'client_type' => (string) $row['client_type'],
            'book_scope'  => (string) $row['book_scope'],
            'archived_at' => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
        ];
    }

    /**
     * Active prevádzky of the firm, by name — what the per-prevádzka books are
     * picked from.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function facilities(int $accountId, int $companyId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name FROM facilities
             WHERE  account_id = ? AND company_id = ? AND archived_at IS NULL
             ORDER  BY name, id'
        );
        $stmt->execute([$accountId, $companyId]);
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $stmt->fetchAll(),
        );
    }

    /**
     * The prevádzka a ledger belongs to: null for the firm-wide book, otherwise
     * the asked-for prevádzka — or the first one, as long as it is one of the
     * firm's active ones. Null with no prevádzka at all (then there is no
     * per-prevádzka ledger to write into).
     *
     * @param list<array{id: int, name: string}> $facilities
     */
    public static function resolveFacility(string $scope, ?int $asked, array $facilities): ?int
    {
        if ($scope !== self::SCOPE_PREVADZKA) {
            return null;
        }
        foreach ($facilities as $f) {
            if ($f['id'] === $asked) {
                return $f['id'];
            }
        }
        return $facilities[0]['id'] ?? null;
    }

    /**
     * Every protocol number any entry of the firm's book already covers, in any
     * scope. Keys are the numbers.
     *
     * @return array<string, true>
     */
    public static function bookedNumbers(int $accountId, int $companyId, string $book): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT protocol_numbers FROM book_entries
             WHERE  account_id = ? AND company_id = ? AND book = ? AND protocol_numbers IS NOT NULL'
        );
        $stmt->execute([$accountId, $companyId, $book]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach (self::decodeStrings($json) as $number) {
                $out[$number] = true;
            }
        }
        return $out;
    }

    /**
     * Entries of one ledger, oldest first (the book is read in the order it was
     * written), each with its additions.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(int $accountId, int $companyId, string $book, ?int $facilityId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . '
             FROM   book_entries e
             LEFT   JOIN facilities f ON f.id = e.facility_id
             LEFT   JOIN users u ON u.id = e.created_by_user_id
             WHERE  e.account_id = ? AND e.company_id = ? AND e.book = ? AND e.scope_key = ?
             ORDER  BY e.seq'
        );
        $stmt->execute([$accountId, $companyId, $book, $facilityId ?? 0]);
        return self::shapeAll($accountId, $stmt->fetchAll());
    }

    /**
     * The entries written under the other scope: with the firm-wide book in
     * use that is every per-prevádzka ledger, with per-prevádzka books in use
     * it is the firm-wide one. Shown collapsed (spec 9.2, plan 5.7).
     *
     * @return list<array<string, mixed>>
     */
    public static function previousScopeEntries(int $accountId, int $companyId, string $book, string $scope): array
    {
        $condition = $scope === self::SCOPE_FIRMA ? 'e.facility_id IS NOT NULL' : 'e.facility_id IS NULL';
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . '
             FROM   book_entries e
             LEFT   JOIN facilities f ON f.id = e.facility_id
             LEFT   JOIN users u ON u.id = e.created_by_user_id
             WHERE  e.account_id = ? AND e.company_id = ? AND e.book = ? AND ' . $condition . '
             ORDER  BY e.facility_id, e.seq'
        );
        $stmt->execute([$accountId, $companyId, $book]);
        return self::shapeAll($accountId, $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public static function entry(int $accountId, int $entryId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . ', e.account_id
             FROM   book_entries e
             LEFT   JOIN facilities f ON f.id = e.facility_id
             LEFT   JOIN users u ON u.id = e.created_by_user_id
             WHERE  e.id = ? AND e.account_id = ?'
        );
        $stmt->execute([$entryId, $accountId]);
        $row = $stmt->fetch();
        return $row ? (self::shapeAll($accountId, [$row])[0] ?? null) : null;
    }

    /**
     * Counts for the card on the firm: entries and offers, per book.
     *
     * @return array{po: array{entries: int, offers: int}, bozp: array{entries: int, offers: int}}
     */
    public static function summary(int $accountId, array $company): array
    {
        $out = [];
        $facilities = self::facilities($accountId, $company['id']);
        foreach (BookMapping::BOOKS as $book) {
            $stmt = Db::pdo()->prepare(
                'SELECT COUNT(*) FROM book_entries WHERE account_id = ? AND company_id = ? AND book = ?'
            );
            $stmt->execute([$accountId, $company['id'], $book]);
            $entries = (int) $stmt->fetchColumn();

            if ($company['book_scope'] === self::SCOPE_PREVADZKA) {
                $offers = 0;
                foreach ($facilities as $f) {
                    $offers += count(BookOffers::forLedger($accountId, $company['id'], $book, $f['id']));
                }
            } else {
                $offers = count(BookOffers::forLedger($accountId, $company['id'], $book, null));
            }
            $out[$book] = ['entries' => $entries, 'offers' => $offers];
        }
        return $out;
    }

    /**
     * Writes one entry under the next poradové číslo of its ledger.
     *
     * The firm row is locked for the duration, which serialises concurrent
     * writers of the same firm's books; the unique key on
     * (account, company, scope_key, book, seq) is the safety net behind it.
     *
     * @param list<string> $protocolNumbers
     * @param list<int>    $inspectionIds
     * @param list<int>    $trainingIds
     * @return int id of the new entry
     */
    public static function create(
        int $accountId,
        int $companyId,
        ?int $facilityId,
        string $book,
        string $dateIso,
        string $text,
        string $source,
        array $protocolNumbers,
        array $inspectionIds,
        array $trainingIds,
        bool $noDefects,
        int $userId,
    ): int {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM companies WHERE id = ? AND account_id = ? FOR UPDATE')
                ->execute([$companyId, $accountId]);

            $next = $pdo->prepare(
                'SELECT COALESCE(MAX(seq), 0) + 1 FROM book_entries
                 WHERE  account_id = ? AND company_id = ? AND scope_key = ? AND book = ?'
            );
            $next->execute([$accountId, $companyId, $facilityId ?? 0, $book]);
            $seq = (int) $next->fetchColumn();

            $pdo->prepare(
                'INSERT INTO book_entries
                    (account_id, company_id, facility_id, book, seq, entry_date, `text`, source,
                     protocol_numbers, inspection_ids, training_ids, no_defects_statement,
                     signed_technician_at, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            )->execute([
                $accountId,
                $companyId,
                $facilityId,
                $book,
                $seq,
                $dateIso,
                $text,
                $source,
                $protocolNumbers === [] ? null : json_encode($protocolNumbers, JSON_UNESCAPED_UNICODE),
                $inspectionIds === [] ? null : json_encode($inspectionIds),
                $trainingIds === [] ? null : json_encode($trainingIds),
                $noDefects ? 1 : 0,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function addAddition(int $accountId, int $entryId, string $text, int $userId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO book_entry_additions (account_id, entry_id, `text`, created_by_user_id)
             VALUES (?, ?, ?, ?)'
        )->execute([$accountId, $entryId, $text, $userId]);
    }

    /**
     * Marks entries as signed by the vedúci zamestnanec. Only entries not
     * signed yet are touched, so a repeat is harmless.
     *
     * @param list<int> $entryIds
     * @return int how many entries were marked
     */
    public static function markSigned(int $accountId, int $companyId, array $entryIds, ?string $name, ?string $role): int
    {
        if ($entryIds === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = Db::pdo()->prepare(
            "UPDATE book_entries
             SET    signed_manager_at = NOW(), signer_name = ?, signer_role = ?
             WHERE  account_id = ? AND company_id = ? AND signed_manager_at IS NULL AND id IN ($in)"
        );
        $stmt->execute([$name, $role, $accountId, $companyId, ...$entryIds]);
        return $stmt->rowCount();
    }

    /**
     * Ids of the inspections / trainings of this firm among `$ids`, so an
     * entry cannot point at another firm's úkon.
     *
     * @param list<int> $ids
     * @param 'inspections'|'trainings' $table
     * @return list<int>
     */
    public static function existingIds(string $table, int $accountId, int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT id FROM $table WHERE account_id = ? AND company_id = ? AND id IN ($in)"
        );
        $stmt->execute([$accountId, $companyId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Whether any of the inspections is a preventívna prehliadka that carries
     * defects — then the „bez nedostatkov" statement cannot be ticked (spec 9.7).
     * The prehliadka arrives with phase 7; until then this is always false.
     *
     * @param list<int> $inspectionIds
     */
    public static function carriesDefects(int $accountId, array $inspectionIds): bool
    {
        if ($inspectionIds === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($inspectionIds), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT COUNT(*)
             FROM   inspection_items ii
             JOIN   inspections i ON i.id = ii.inspection_id
             WHERE  i.account_id = ? AND i.type = 'preventivna_prehliadka' AND i.id IN ($in)"
        );
        $stmt->execute([$accountId, ...$inspectionIds]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ── Shaping ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function shapeAll(int $accountId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT a.id, a.entry_id, a.`text`, a.created_at, u.fullname AS created_by_name
             FROM   book_entry_additions a
             LEFT   JOIN users u ON u.id = a.created_by_user_id
             WHERE  a.account_id = ? AND a.entry_id IN ($in)
             ORDER  BY a.id"
        );
        $stmt->execute([$accountId, ...$ids]);
        $additions = [];
        foreach ($stmt->fetchAll() as $a) {
            $additions[(int) $a['entry_id']][] = [
                'id'              => (int) $a['id'],
                'text'            => (string) $a['text'],
                'created_at'      => (string) $a['created_at'],
                'created_by_name' => $a['created_by_name'] !== null ? (string) $a['created_by_name'] : null,
            ];
        }

        return array_map(static function (array $r) use ($additions): array {
            $id = (int) $r['id'];
            return [
                'id'                   => $id,
                'facility_id'          => $r['facility_id'] !== null ? (int) $r['facility_id'] : null,
                'facility_name'        => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
                'book'                 => (string) $r['book'],
                'seq'                  => (int) $r['seq'],
                'entry_date'           => (string) $r['entry_date'],
                'text'                 => (string) $r['text'],
                'source'               => (string) $r['source'],
                'protocol_numbers'     => self::decodeStrings($r['protocol_numbers']),
                'inspection_ids'       => self::decodeInts($r['inspection_ids']),
                'training_ids'         => self::decodeInts($r['training_ids']),
                'no_defects_statement' => (int) $r['no_defects_statement'] === 1,
                'signed_technician'    => $r['signed_technician_at'] !== null,
                'signed_manager'       => $r['signed_manager_at'] !== null,
                'signed_manager_at'    => $r['signed_manager_at'] !== null ? (string) $r['signed_manager_at'] : null,
                'signer_name'          => $r['signer_name'] !== null ? (string) $r['signer_name'] : null,
                'signer_role'          => $r['signer_role'] !== null ? (string) $r['signer_role'] : null,
                'created_by_user_id'   => $r['created_by_user_id'] !== null ? (int) $r['created_by_user_id'] : null,
                'created_by_name'      => $r['created_by_name'] !== null ? (string) $r['created_by_name'] : null,
                'created_at'           => (string) $r['created_at'],
                'additions'            => $additions[$id] ?? [],
            ];
        }, $rows);
    }

    /** @return list<string> */
    public static function decodeStrings(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $decoded),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /** @return list<int> */
    public static function decodeInts(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('intval', array_filter($decoded, 'is_numeric')));
    }
}
