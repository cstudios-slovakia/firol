<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Termíny cudzích revízií of a firm (spec 6.6, plan phase 6): revisions the
 * client has done by someone else, remembered per firma and printed in the
 * „Termíny a kontroly" table of the record of their book.
 *
 * The list is kept per firma (spec 3.1); a term may be pinned to one prevádzka,
 * and then only that prevádzka's record prints it. Which book a term belongs to
 * is the technician's answer, never derived from its name — the names below
 * only suggest it.
 */
final class ExternalTerms
{
    public const PO = BookMapping::PO;
    public const BOZP = 'bozp';
    public const OB = 'ob';
    public const BOOKS = [self::PO, self::BOZP, self::OB];

    public static function isBook(string $book): bool
    {
        return in_array($book, self::BOOKS, true);
    }

    /**
     * Name → suggested book — the prototype's `REVIZIE`. Only a suggestion: the
     * form asks, and a free name is allowed.
     *
     * @var array<string, string>
     */
    public const SUGGESTIONS = [
        'Revízia elektrických spotrebičov a ručných nástrojov' => self::BOZP,
        'Revízia elektrickej inštalácie'                       => self::OB,
        'Revízia bleskozvodu'                                  => self::OB,
        'Kontrola a čistenie komína'                           => self::PO,
        'Revízia tlakových nádob'                              => self::BOZP,
        'Revízia plynového zariadenia'                         => self::BOZP,
        'Revízia zdvíhacieho zariadenia'                       => self::BOZP,
        'Kontrola elektrickej požiarnej signalizácie'          => self::PO,
        'Iná revízia'                                          => self::PO,
    ];

    /**
     * @return list<array{name: string, book: string}>
     */
    public static function suggestions(): array
    {
        $out = [];
        foreach (self::SUGGESTIONS as $name => $book) {
            $out[] = ['name' => $name, 'book' => $book];
        }
        return $out;
    }

    /**
     * The firm's terms, newest validity first within a book. `$facilityId`
     * narrows the list to the firm-wide terms plus those pinned to that prevádzka.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(int $accountId, int $companyId, ?string $book = null, ?int $facilityId = null): array
    {
        $sql = 'SELECT t.id, t.facility_id, f.name AS facility_name, t.book, t.name, t.performed_by,
                       t.done_on, t.valid_until, t.note
                FROM   company_external_terms t
                LEFT   JOIN facilities f ON f.id = t.facility_id
                WHERE  t.account_id = ? AND t.company_id = ? AND t.archived_at IS NULL';
        $args = [$accountId, $companyId];
        if ($book !== null) {
            if ($book === self::PO || $book === self::BOZP) {
                $sql .= ' AND t.book IN (?, ?)';
                $args[] = $book;
                $args[] = self::OB;
            } else {
                $sql .= ' AND t.book = ?';
                $args[] = $book;
            }
        }
        if ($facilityId !== null) {
            $sql .= ' AND (t.facility_id IS NULL OR t.facility_id = ?)';
            $args[] = $facilityId;
        }
        // Soonest-expiring first; a term with no date goes last.
        $sql .= ' ORDER BY t.valid_until IS NULL ASC, t.valid_until ASC, t.name ASC, t.id ASC';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public static function find(int $accountId, int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.id, t.company_id, t.facility_id, f.name AS facility_name, t.book, t.name,
                    t.performed_by, t.done_on, t.valid_until, t.note
             FROM   company_external_terms t
             LEFT   JOIN facilities f ON f.id = t.facility_id
             WHERE  t.id = ? AND t.account_id = ? AND t.archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::shape($row) + ['company_id' => (int) $row['company_id']];
    }

    /**
     * The sentence „Do knihy" proposes for a term (prototype `revDoKnihy`). Only
     * a proposal — the technician edits it before it goes into the book.
     */
    public static function bookSentence(string $name, ?string $validUntil): string
    {
        $what = mb_strtolower($name);
        if ($validUntil === null) {
            return 'Klient bol upozornený na termín: ' . $what . '.';
        }
        return 'Klient bol upozornený na termín: ' . $what . ' — vykonať do ' . self::skDate($validUntil) . '.';
    }

    /** „13. 10. 2027". */
    public static function skDate(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? (int) date('j', $ts) . '. ' . (int) date('n', $ts) . '. ' . date('Y', $ts) : $iso;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $validUntil = $row['valid_until'] !== null ? (string) $row['valid_until'] : null;
        return [
            'id'            => (int) $row['id'],
            'facility_id'   => $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
            'facility_name' => $row['facility_name'] !== null ? (string) $row['facility_name'] : null,
            'book'          => (string) $row['book'],
            'name'          => (string) $row['name'],
            'performed_by'  => $row['performed_by'] !== null ? (string) $row['performed_by'] : null,
            'done_on'       => $row['done_on'] !== null ? (string) $row['done_on'] : null,
            'valid_until'   => $validUntil,
            'note'          => $row['note'] !== null ? (string) $row['note'] : null,
            'book_sentence' => self::bookSentence((string) $row['name'], $validUntil),
        ];
    }
}
