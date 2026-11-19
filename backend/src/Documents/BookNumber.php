<?php

declare(strict_types=1);

namespace Firol\Documents;

use Firol\Db;
use Firol\Support\ClientKind;
use PDO;

/**
 * Numbers of požiarna kniha entries — the customer's answer 3 of 18. 11. 2026:
 *
 *   „vlastný rad za každú firmu, bez nulovania … PK-<kód firmy>-NNN, kde kód
 *    firmy sú prvé tri písmená názvu firmy … NNN beží ďalej a nikdy sa
 *    nenuluje … opravený zápis si ponechá pôvodné číslo, aby v knihe nevznikla
 *    medzera"
 *
 * One series per firma (a bytové družstvo too), counted from 001, never reset
 * by year, at least three digits (PK-STA-001 … PK-STA-999, PK-STA-1000). It
 * applies to book entries of both formats; every other document — the kniha
 * BOZP record included — keeps {@see NumberAllocator}.
 *
 * The code. The first three letters of the firma's name, letters only,
 * diacritics removed, upper case („Stavby Novák" → STA, „Železiarne" → ZEL,
 * „3M Slovensko" → MSL). `documents` is unique per (account, number), so the
 * code must be unique within the account (this follows from two firms
 * starting with the same letters and is listed for the customer's
 * confirmation): when the first proposal is taken, the next free variant is
 * proposed — the first two letters with each following letter of the name in
 * turn, then the first two letters with a digit, then the first three with a
 * digit. The user may change it (2–4 letters or digits, at least one letter so
 * it can never read as a year of the old PK-RRRR-NNN series) until the firma's
 * first entry is issued; then it is locked. A firma renamed later keeps it.
 *
 * The number. Taken once, at the entry's first issue, inside the issue
 * transaction with the firma's row locked, and stored on the entry
 * (`inspections.book_number`). „Upraviť" deletes the document but not that
 * column, so the next issue is the same number as a new version; no number is
 * handed out twice and none is skipped. A draft holds no number, so a draft
 * discarded before it was issued leaves no gap; an archived entry keeps its.
 */
final class BookNumber
{
    public const PREFIX = 'PK';

    /** Letters the transliteration below does not reach by stripping marks. */
    private const EXTRA = [
        'ß' => 'SS', 'Æ' => 'AE', 'æ' => 'AE', 'Œ' => 'OE', 'œ' => 'OE',
        'Ø' => 'O', 'ø' => 'O', 'Ł' => 'L', 'ł' => 'L', 'Đ' => 'D', 'đ' => 'D',
        'Þ' => 'TH', 'þ' => 'TH',
    ];

    /** Latin letters with diacritics → base letter (Slovak, Czech, German, Hungarian, Polish, …). */
    private const MARKS = [
        'á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'ă' => 'a',
        'č' => 'c', 'ć' => 'c', 'ç' => 'c',
        'ď' => 'd',
        'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'è' => 'e', 'ê' => 'e', 'ę' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ĺ' => 'l', 'ľ' => 'l',
        'ň' => 'n', 'ń' => 'n', 'ñ' => 'n',
        'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ő' => 'o', 'ò' => 'o', 'õ' => 'o',
        'ŕ' => 'r', 'ř' => 'r',
        'š' => 's', 'ś' => 's', 'ş' => 's', 'ș' => 's',
        'ť' => 't', 'ţ' => 't', 'ț' => 't',
        'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ű' => 'u', 'ù' => 'u', 'û' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    /** The letters of a name: diacritics removed, upper case, nothing else. */
    public static function letters(string $name): string
    {
        $s = strtr($name, self::EXTRA);
        $lower = mb_strtolower($s, 'UTF-8');
        $s = strtr($lower, self::MARKS);
        return strtoupper((string) preg_replace('/[^a-z]/', '', $s));
    }

    /**
     * The codes proposed for a name, best first: the first three letters; the
     * first two with each following letter in turn; the first two with a
     * digit; the first three with a digit. A name of two letters starts with
     * those two; one with fewer has no proposal — the user writes the code.
     *
     * @return list<string>
     */
    public static function candidates(string $name): array
    {
        $l = self::letters($name);
        $len = strlen($l);
        if ($len < 2) {
            return [];
        }
        $out = [];
        if ($len >= 3) {
            $out[] = substr($l, 0, 3);
            for ($k = 3; $k < $len; $k++) {
                $out[] = $l[0] . $l[1] . $l[$k];
            }
        } else {
            $out[] = $l;
        }
        for ($d = 1; $d <= 9; $d++) {
            $out[] = $l[0] . $l[1] . $d;
        }
        if ($len >= 3) {
            for ($d = 1; $d <= 9; $d++) {
                $out[] = substr($l, 0, 3) . $d;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The first proposal no other firma of the account holds, or null when the
     * name has too few letters or every variant is taken.
     */
    public static function propose(int $accountId, string $name, ?int $exceptCompanyId = null): ?string
    {
        $candidates = self::candidates($name);
        if ($candidates === []) {
            return null;
        }
        $taken = self::takenCodes($accountId, $exceptCompanyId);
        foreach ($candidates as $c) {
            if (!isset($taken[$c])) {
                return $c;
            }
        }
        return null;
    }

    /**
     * A code typed by the user, canonical (trimmed, upper case).
     *
     * @throws \InvalidArgumentException with a Slovak message
     */
    public static function normalizeCode(mixed $raw, string $clientType = ClientKind::FIRMA): string
    {
        $code = is_string($raw) ? strtoupper(trim($raw)) : '';
        if (preg_match('/^[A-Z0-9]{2,4}$/', $code) !== 1) {
            throw new \InvalidArgumentException(ClientKind::bookCodeShape($clientType));
        }
        if (preg_match('/[A-Z]/', $code) !== 1) {
            throw new \InvalidArgumentException(ClientKind::bookCodeLetter($clientType));
        }
        return $code;
    }

    /**
     * Writes the proposal onto a firma that has no code yet — when it is
     * created (the app, the Excel import). Best effort: a name without a
     * proposal leaves the code empty for the user, and the first issue
     * proposes again.
     */
    public static function assignProposal(int $accountId, int $companyId, string $name): ?string
    {
        foreach (self::candidates($name) as $code) {
            if (isset(self::takenCodes($accountId, $companyId)[$code])) {
                continue;
            }
            try {
                $stmt = Db::pdo()->prepare(
                    'UPDATE companies SET book_code = ? WHERE id = ? AND account_id = ? AND book_code IS NULL'
                );
                $stmt->execute([$code, $companyId, $accountId]);
                return $stmt->rowCount() > 0 ? $code : null;
            } catch (\PDOException $e) {
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
                // Taken by a firma created at the same moment: next variant.
            }
        }
        return null;
    }

    /**
     * The number of an entry being issued, and the version of its document.
     * The caller holds the transaction and the entry's row lock.
     *
     * @param array<string, mixed> $inspection the entry (id, company_id)
     * @return array{number: string, version: int}
     * @throws BookNumberRefused when the firma has no code and none can be proposed
     */
    public static function forIssue(PDO $pdo, int $accountId, array $inspection): array
    {
        $id = (int) $inspection['id'];
        // Locking reads only until the firma's row is locked: under MariaDB's
        // snapshot isolation a plain read here would fix a read view, and the
        // firma's row changed by a parallel issue afterwards would then fail
        // the lock with „Record has changed since last read" (1020).
        $own = $pdo->prepare('SELECT book_number, book_number_version FROM inspections WHERE id = ? AND account_id = ? FOR UPDATE');
        $own->execute([$id, $accountId]);
        $row = $own->fetch(PDO::FETCH_ASSOC) ?: [];
        if (is_string($row['book_number'] ?? null) && $row['book_number'] !== '') {
            // Issued before and opened again with „Upraviť": the same number,
            // the next version.
            $version = max(1, (int) ($row['book_number_version'] ?? 0)) + 1;
            $pdo->prepare('UPDATE inspections SET book_number_version = ? WHERE id = ? AND account_id = ?')
                ->execute([$version, $id, $accountId]);
            return ['number' => (string) $row['book_number'], 'version' => $version];
        }

        $companyId = (int) $inspection['company_id'];
        $lock = $pdo->prepare(
            'SELECT name, client_type, book_code, book_last_seq FROM companies WHERE id = ? AND account_id = ? FOR UPDATE'
        );
        $lock->execute([$companyId, $accountId]);
        $company = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new BookNumberRefused('Firma zápisu sa nenašla.');
        }

        $code = is_string($company['book_code']) && $company['book_code'] !== '' ? $company['book_code'] : null;
        if ($code === null) {
            $code = self::claimCode($pdo, $accountId, $companyId, (string) $company['name'], (string) $company['client_type']);
        }

        // A number of this series may exist already without the counter knowing
        // it — entries a merge restore brought back under a code that another
        // firma holds now (round 6). It is skipped: a gap is better than an
        // issue refused by the unique key on every retry. Read after the firma's
        // lock, so a restore that held it has committed by now.
        $next = (int) $company['book_last_seq'] + 1;
        $used = self::usedSequences($pdo, $accountId, $code, $next);
        while (isset($used[$next])) {
            $next++;
        }
        $pdo->prepare('UPDATE companies SET book_last_seq = ? WHERE id = ? AND account_id = ?')
            ->execute([$next, $companyId, $accountId]);

        $number = self::format($code, $next);
        $pdo->prepare('UPDATE inspections SET book_number = ?, book_number_version = 1 WHERE id = ? AND account_id = ?')
            ->execute([$number, $id, $accountId]);
        return ['number' => $number, 'version' => 1];
    }

    /**
     * Whether issuing this entry needs a code the app cannot propose — checked
     * before the PDF is rendered, so the refusal is a 422 with the reason.
     *
     * @param array<string, mixed> $inspection
     */
    public static function missingCodeError(int $accountId, array $inspection): ?string
    {
        $pdo = Db::pdo();
        $own = $pdo->prepare('SELECT book_number FROM inspections WHERE id = ? AND account_id = ?');
        $own->execute([(int) $inspection['id'], $accountId]);
        $number = $own->fetchColumn();
        if (is_string($number) && $number !== '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT name, client_type, book_code FROM companies WHERE id = ? AND account_id = ?');
        $stmt->execute([(int) $inspection['company_id'], $accountId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || (is_string($company['book_code']) && $company['book_code'] !== '')) {
            return null;
        }
        if (self::propose($accountId, (string) $company['name'], (int) $inspection['company_id']) !== null) {
            return null;
        }
        return ClientKind::bookCodeMissing((string) $company['client_type']);
    }

    /** The refusal for a firma (ClientKind::bookCodeMissing() words it for a bytové družstvo). */
    public const NO_CODE_MESSAGE = 'Firma nemá kód pre čísla zápisov do požiarnej knihy a z názvu sa nedá navrhnúť. Doplň ho na detaile firmy (2 – 4 písmená alebo číslice).';

    /** PK-STA-001 — at least three digits, more when the series outgrows them. */
    public static function format(string $code, int $seq): string
    {
        return sprintf('%s-%s-%03d', self::PREFIX, $code, $seq);
    }

    /** The sequence of a number of this series for this code, or null. */
    public static function sequenceOf(string $number, string $code): ?int
    {
        $prefix = self::PREFIX . '-' . $code . '-';
        if (!str_starts_with($number, $prefix)) {
            return null;
        }
        $rest = substr($number, strlen($prefix));
        return ctype_digit($rest) ? (int) $rest : null;
    }

    /** The code under the firma's row lock: the first free proposal, written at once. */
    private static function claimCode(PDO $pdo, int $accountId, int $companyId, string $name, string $clientType): string
    {
        foreach (self::candidates($name) as $code) {
            if (isset(self::takenCodes($accountId, $companyId)[$code])) {
                continue;
            }
            try {
                $pdo->prepare('UPDATE companies SET book_code = ? WHERE id = ? AND account_id = ?')
                    ->execute([$code, $companyId, $accountId]);
                return $code;
            } catch (\PDOException $e) {
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
            }
        }
        throw new BookNumberRefused(ClientKind::bookCodeMissing($clientType));
    }

    /**
     * The sequences from `$from` up that already exist as PK-<code>-n in the
     * account — on an entry or as a document number.
     *
     * @return array<int, true>
     */
    private static function usedSequences(PDO $pdo, int $accountId, string $code, int $from): array
    {
        $like = self::PREFIX . '-' . $code . '-%';
        $stmt = $pdo->prepare(
            'SELECT book_number FROM inspections WHERE account_id = ? AND book_number LIKE ?
             UNION
             SELECT number FROM documents WHERE account_id = ? AND number LIKE ?'
        );
        $stmt->execute([$accountId, $like, $accountId, $like]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
            $seq = self::sequenceOf((string) $number, $code);
            if ($seq !== null && $seq >= $from) {
                $out[$seq] = true;
            }
        }
        return $out;
    }

    /**
     * Whether another firma of the account holds the code, or any number of
     * its series exists in the account (round 6 — numbers a merge restore
     * brought back after their firma got another code).
     */
    public static function isCodeTaken(int $accountId, string $code, ?int $exceptCompanyId = null): bool
    {
        return isset(self::takenCodes($accountId, $exceptCompanyId)[$code]);
    }

    /**
     * Codes no other firma may take: the codes other firms hold, and the code
     * of every PK-<KÓD>-n number in the account (an entry's or a document's)
     * that is not the firma's own. The old PK-RRRR-NNN numbers name no code —
     * a code always has a letter.
     *
     * @return array<string, true>
     */
    private static function takenCodes(int $accountId, ?int $exceptCompanyId): array
    {
        $sql = 'SELECT book_code FROM companies WHERE account_id = ? AND book_code IS NOT NULL';
        $params = [$accountId];
        if ($exceptCompanyId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptCompanyId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $out[(string) $c] = true;
        }

        $numbers = Db::pdo()->prepare(
            "SELECT i.book_number FROM inspections i
             WHERE  i.account_id = ? AND i.book_number LIKE 'PK-%' AND (? IS NULL OR i.company_id <> ?)
             UNION
             SELECT d.number FROM documents d
             LEFT   JOIN inspections i ON d.parent_type = 'inspection' AND i.id = d.parent_id
             WHERE  d.account_id = ? AND d.number LIKE 'PK-%' AND (? IS NULL OR i.company_id IS NULL OR i.company_id <> ?)"
        );
        $numbers->execute([$accountId, $exceptCompanyId, $exceptCompanyId, $accountId, $exceptCompanyId, $exceptCompanyId]);
        foreach ($numbers->fetchAll(PDO::FETCH_COLUMN) as $number) {
            if (preg_match('/^' . self::PREFIX . '-([A-Z0-9]{2,4})-\d+$/', (string) $number, $m) === 1 && preg_match('/[A-Z]/', $m[1]) === 1) {
                $out[$m[1]] = true;
            }
        }
        return $out;
    }

    private static function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }
}
