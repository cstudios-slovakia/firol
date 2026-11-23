<?php

declare(strict_types=1);

namespace Firol\Backup;

use Firol\Db;
use Firol\Documents\BookNumber;
use Firol\Documents\NumberAllocator;
use Firol\Storage\Storage;
use Firol\Support\AccountPurge;
use Firol\Support\Address;
use Firol\Support\ClientKind;
use Firol\Support\Contractor;
use Firol\Support\Invoicing;
use Firol\Support\Periodicity;
use PDO;
use ZipArchive;

/**
 * Restores an account backup produced by {@see Writer}.
 *
 * Two modes:
 *
 *   merge   — additive. A record already present in the account is left
 *             untouched and everything under it is skipped; only what is
 *             missing gets inserted. Re-running the same restore is a no-op,
 *             so a half-finished restore can simply be repeated.
 *   replace — the account's companies, inspections, trainings, documents and
 *             their files are purged first, then the backup is written in
 *             whole. This is the "the database was wiped / is corrupt" path
 *             and reproduces the backup exactly.
 *
 * Ids are never reused: every row is inserted fresh and the old → new id maps
 * built along the way remap the foreign keys (facility → company, item →
 * inspection, document → its parent). What *is* preserved is `created_at` and
 * the dates on the records, because those are the record's identity in merge
 * mode and because a protocol dated by its restore date would be wrong.
 *
 * Files are copied out of the archive inside the same try as the DB writes: a
 * failure rolls back the transaction and unlinks everything already written,
 * so a failed restore leaves neither orphaned rows nor orphaned files.
 */
final class Restorer
{
    public const MODE_MERGE   = 'merge';
    public const MODE_REPLACE = 'replace';

    /**
     * Check scripts only (bin/test-prehliadka-followups.php, round 7): called
     * with the stage name — `locked` after the firms are locked, `settle`
     * before the book numbers are settled — inside the restore's transaction,
     * so a second connection can act while its locks are held. Null in the app.
     *
     * @var null|\Closure(string): void
     */
    public static ?\Closure $checkpoint = null;

    /** @var list<string> absolute paths written during this run */
    private array $written = [];

    /** @var array<string, int> */
    private array $restored = [
        'companies'   => 0,
        'facilities'  => 0,
        'inspections' => 0,
        'items'       => 0,
        'photos'      => 0,
        'trainings'   => 0,
        'trainees'    => 0,
        'documents'   => 0,
        'stock_items'     => 0,
        'stock_movements' => 0,
        'tasks'       => 0,
        'defect_catalog' => 0,
        'external_terms' => 0,
        'company_activities' => 0,
    ];

    /** @var array<string, int> */
    private array $skipped = [
        'companies'   => 0,
        'facilities'  => 0,
        'inspections' => 0,
        'trainings'   => 0,
        'documents'   => 0,
        'stock_items' => 0,
        'tasks'       => 0,
        'defect_catalog' => 0,
        'external_terms' => 0,
        'company_activities' => 0,
    ];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, int> old company id → new */
    private array $companyMap = [];
    /** @var array<int, int> old facility id → new */
    private array $facilityMap = [];
    /** @var array<int, int> old inspection id → new */
    private array $inspectionMap = [];
    /** @var array<int, int> old inspection item id → new (only items this run inserted) */
    private array $itemMap = [];
    /** @var list<int> new ids of the preventívna prehliadka defects this run inserted */
    private array $prehliadkaItemIds = [];
    /** @var list<int> new ids of the preventívna prehliadka inspections this run inserted */
    private array $prehliadkaInspectionIds = [];
    /** @var list<int> new ids of the concepts (plan 8b) this run inserted — each points at its prehliadka */
    private array $conceptInspectionIds = [];
    /** @var list<int> new ids of the new-format požiarna kniha entries this run inserted */
    private array $bookInspectionIds = [];
    /**
     * @var list<int> new ids of drafts restored from an archive written before
     *      `issued_before` existed (migration 078) and not yet known to be issued;
     *      a restored documents row still proves it ({@see self::markIssuedFromDocuments()})
     */
    private array $issuedUnknownIds = [];
    /**
     * @var array<int, true> new ids of úkony a manifest documents row names
     *      (parent type `inspection`), whether or not that row was restored —
     *      a row skipped as a duplicate number or for a missing PDF still
     *      proves the úkon was issued (round 5)
     */
    private array $documentedInspectionIds = [];
    /** Drafts of an archive without `issued_before` restored as issued (round 5). */
    private int $issuedAssumed = 0;
    /** @var list<int> new ids of the úkony this run inserted (merge never touches the others) */
    private array $insertedInspectionIds = [];
    /**
     * @var array<int, array{name: string, client_type: string, old: string, new: ?string}>
     *      new firma id → its archive code another firma holds here (round 6)
     */
    private array $bookCodeChanged = [];
    /** @var list<string> book numbers of the archive an entry lost because the account uses them (round 6) */
    private array $bookNumbersLost = [];
    /**
     * @var array<string, list<string>> archive code → book numbers entries brought
     *      back under it after their firma got another code (round 6; per code, round 7)
     */
    private array $bookNumbersForeign = [];
    /**
     * @var array<int, array{name: string, client_type: string, old: string, own: ?string, numbers: list<string>}>
     *      existing firma id → the other code its merged archive firma had, and the
     *      numbers of that code its entries brought back (round 7)
     */
    private array $bookCodeMerged = [];
    /** @var list<string> every book code of the archive — its firms' and its entries' numbers' (round 7) */
    private array $archiveBookCodes = [];
    /** @var array<int, true> new ids of entries restored as drafts because their number was taken (round 6) */
    private array $bookLostInspectionIds = [];
    /** @var array<int, string> new entry id → number of its book document the account already used (round 6) */
    private array $bookDocumentSkipped = [];
    /** @var array<int, int> old training id → new */
    private array $trainingMap = [];
    /** @var array<int, int> old own číselník item id → new (shipped ids are the same everywhere) */
    private array $defectCatalogMap = [];
    /** @var array<int, int> old výdajka (stock_issues) id → new */
    private array $stockIssueMap = [];
    /** @var array<string, int> lowercased email → user id on this account */
    private array $userMap = [];

    private function __construct(
        private readonly int $accountId,
        private readonly int $userId,
        private readonly PDO $pdo,
        private readonly ?ZipArchive $zip,
    ) {
    }

    /**
     * @return array{restored: array<string,int>, skipped: array<string,int>, warnings: list<string>, issued_assumed: int}
     */
    public static function run(int $accountId, int $userId, string $archivePath, string $mode): array
    {
        [$manifest, $zip] = self::openArchive($archivePath);

        $restorer = new self($accountId, $userId, Db::pdo(), $zip);
        try {
            return $restorer->restore($manifest, $mode);
        } finally {
            $zip?->close();
        }
    }

    /** {@see self::$checkpoint} */
    private static function reach(string $stage): void
    {
        if (self::$checkpoint !== null) {
            (self::$checkpoint)($stage);
        }
    }

    /**
     * Accepts both the current .zip archive and a bare legacy .json export, so
     * a user who only ever downloaded the old JSON can still get their records
     * back — without their photos and PDFs, which that format never contained.
     *
     * @return array{0: array<string, mixed>, 1: ?ZipArchive}
     */
    private static function openArchive(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) === true) {
            $json = $zip->getFromName(Archive::MANIFEST);
            if ($json === false) {
                $zip->close();
                throw new BackupException(
                    'Súbor je ZIP archív, ale neobsahuje ' . Archive::MANIFEST . ' — nevyzerá to ako záloha z tejto aplikácie.'
                );
            }
            return [self::decode($json), $zip];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new BackupException('Súbor sa nepodarilo prečítať.');
        }
        return [self::decode($raw), null];
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new BackupException('Neplatný súbor zálohy — nedá sa prečítať JSON.');
        }

        $version = (int) ($manifest['version'] ?? 0);
        // v1 had no `format` key at all; it is recognised by its version alone.
        $format  = (string) ($manifest['format'] ?? ($version === Archive::LEGACY_VERSION ? Archive::FORMAT : ''));
        if ($format !== Archive::FORMAT) {
            throw new BackupException('Toto nie je záloha z tejto aplikácie.');
        }
        if ($version > Archive::VERSION) {
            throw new BackupException(
                "Záloha je vo formáte v$version, ktorý táto verzia aplikácie ešte nepozná. Aktualizuj aplikáciu a skús znova."
            );
        }
        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{restored: array<string,int>, skipped: array<string,int>, warnings: list<string>, issued_assumed: int}
     */
    private function restore(array $manifest, string $mode): array
    {
        $this->userMap = $this->loadUserMap();
        $this->archiveBookCodes = self::bookCodesOf($manifest);

        // Purging runs before the transaction: it deletes files off disk, which
        // no rollback can undo, so there is nothing to gain from holding it
        // inside one — and a great deal of lock contention to lose.
        if ($mode === self::MODE_REPLACE) {
            AccountPurge::everything($this->accountId);
            // The sklad is not firm data, so the firm purge leaves it; a
            // replace restore writes it back whole and has to clear it first.
            AccountPurge::stock($this->accountId);
            // Own číselník items come back with the backup; an archive from
            // before the číselník existed has no key and must not wipe them.
            if (array_key_exists('defect_catalog', $manifest)) {
                AccountPurge::defectCatalog($this->accountId);
            }
        }

        // READ COMMITTED (round 7): at the database's REPEATABLE READ the lock
        // below also took gap locks on uq_companies_book_code beyond this
        // account, so another customer's new firma or changed code waited for
        // the whole restore (files written to disk included) and answered 500.
        // At READ COMMITTED a locking read locks the matching rows only, and
        // every plain read sees what is committed when it runs. Applies to the
        // next transaction only — issued right before it.
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->pdo->beginTransaction();
        try {
            // Lock order with an issue (round 6): an issue locks its entry, then
            // its firma, then writes the document. The restore takes every firma
            // row of the account first, so a book entry being issued meanwhile
            // waits here and then reads the numbers this restore brought back
            // (BookNumber::forIssue skips them) instead of deadlocking on the
            // document's unique key. After it the restore never locks a row it
            // did not insert (round 7): an issue holding its entry and waiting
            // for its firma would otherwise deadlock with it — every later
            // UPDATE goes by the primary key of a row this restore inserted, its
            // values read first with a plain SELECT.
            $this->pdo->prepare('SELECT id FROM companies WHERE account_id = ? ORDER BY id FOR UPDATE')
                ->execute([$this->accountId]);
            self::reach('locked');
            $this->restoreCompanies($this->list($manifest, 'companies'));
            // Before the inspections: a defect's fields.catalog_id is remapped onto it.
            $this->restoreDefectCatalog($this->list($manifest, 'defect_catalog'));
            $this->restoreInspections($this->list($manifest, 'inspections'));
            $this->relinkPrehliadkaDevices();
            $this->relinkPrehliadkaPrevious();
            $this->restoreTrainings($this->list($manifest, 'trainings'));
            // After the trainings: a book entry names attached trainings too.
            $this->relinkBookEntries();
            // Chapter 21 — before the documents, which map výdajky onto it.
            $this->restoreStock(is_array($manifest['stock'] ?? null) ? $manifest['stock'] : []);
            $this->restoreDocuments($this->list($manifest, 'documents'));
            // After the documents: an old archive's draft that owns one was issued.
            $this->markIssuedFromDocuments();
            // After the documents: book numbers onto the entries, counters up.
            self::reach('settle');
            $this->settleBookNumbers();
            // Phase 6 — termíny cudzích revízií, onto the restored firms and prevádzky.
            $this->restoreExternalTerms($this->list($manifest, 'external_terms'));
            // Phase 7b — the firms' own „vykonané činnosti".
            $this->restoreCompanyActivities($this->list($manifest, 'company_activities'));
            $this->relinkFollowUps($this->list($manifest, 'inspections'));
            // Chapter 20 — last, because a task points at companies,
            // facilities and (for one born from a nedostatok) an inspection.
            $this->restoreTasks($this->list($manifest, 'tasks'));
            // Chapter 11.3 — after the inspections, whose ids it maps onto.
            $this->restoreDeadlineNotices($this->list($manifest, 'deadline_notices'));
            // After the inspections and trainings, whose ids it maps onto.
            $this->restoreCalendarPlans($this->list($manifest, 'calendar_plans'));

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($this->written as $path) {
                @unlink($path);
            }
            throw $e;
        }

        if ($this->issuedAssumed > 0) {
            $n = $this->issuedAssumed;
            $this->warnings[] = ($n === 1 ? '1 rozpracovaná kontrola' : ($n < 5 ? "$n rozpracované kontroly" : "$n rozpracovaných kontrol"))
                . ' zo zálohy staršej verzie ' . ($n === 1 ? 'je označená' : ($n < 5 ? 'sú označené' : 'je označených'))
                . ' ako už ' . ($n === 1 ? 'vystavená' : 'vystavené') . ' (záloha to nerozlišuje), preto sa '
                . ($n === 1 ? 'nedá' : 'nedajú') . ' zahodiť.';
        }
        $this->bookWarnings();

        return [
            'restored' => $this->restored,
            'skipped'  => $this->skipped,
            'warnings' => $this->warnings,
            // Round 5 — drafts of an archive without `issued_before` restored as issued.
            'issued_assumed' => $this->issuedAssumed,
        ];
    }

    // ── Companies & facilities ───────────────────────────────────────────────

    /** @param list<array<string, mixed>> $companies */
    private function restoreCompanies(array $companies): void
    {
        $existingCompanies  = $this->loadCompanyKeys();
        $existingFacilities = $this->loadFacilityKeys();

        $insertCompany = $this->pdo->prepare(
            'INSERT INTO companies
                (account_id, name, client_type, ico, street, postal_code, city, contact, contact_email, approver, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertFacility = $this->pdo->prepare(
            'INSERT INTO facilities
                (account_id, company_id, name, street, postal_code, city, email, contact_person, notes,
                 main_power_location, main_gas_location, main_water_location, description,
                 floors_above, floors_below, entrances, places, vchody, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        /** @var list<array{id: int, name: string, company: array<string, mixed>}> $insertedFirms */
        $insertedFirms = [];
        foreach ($companies as $company) {
            $oldId = (int) ($company['id'] ?? 0);
            $name  = $this->str($company, 'name');
            if ($name === null) {
                $this->warnings[] = 'Firma bez názvu bola preskočená.';
                continue;
            }

            $ico  = $this->str($company, 'ico');
            $key  = $this->companyKey($name, $ico);
            $addr = $this->address($company);

            if (isset($existingCompanies[$key])) {
                $newId = $existingCompanies[$key];
                $this->skipped['companies']++;
                $this->noteMergedBookCode($newId, $name, $company);
            } else {
                $clientType = $this->str($company, 'client_type');
                if ($clientType !== 'firma' && $clientType !== 'bytove_druzstvo') {
                    $clientType = 'firma';
                }
                $insertCompany->execute([
                    $this->accountId,
                    $name,
                    $clientType,
                    $ico,
                    $addr['street'],
                    $addr['postal_code'],
                    $addr['city'],
                    $this->str($company, 'contact'),
                    $this->str($company, 'contact_email'),
                    $this->str($company, 'approver'),
                    $this->createdAt($company),
                ]);
                $newId = (int) $this->pdo->lastInsertId();
                $existingCompanies[$key] = $newId;
                $this->restored['companies']++;
                $this->restoreCompanyBillingMode($newId, $company);
                $insertedFirms[] = ['id' => $newId, 'name' => $name, 'company' => $company];
            }
            $this->companyMap[$oldId] = $newId;

            foreach ($this->list($company, 'facilities') as $facility) {
                $facilityName = $this->str($facility, 'name');
                if ($facilityName === null) {
                    $this->warnings[] = "Prevádzka bez názvu (firma „$name\") bola preskočená.";
                    continue;
                }
                $facilityKey = $newId . '|' . mb_strtolower($facilityName);
                if (isset($existingFacilities[$facilityKey])) {
                    $this->facilityMap[(int) ($facility['id'] ?? 0)] = $existingFacilities[$facilityKey];
                    $this->skipped['facilities']++;
                    continue;
                }

                $facilityAddr = $this->address($facility);
                $insertFacility->execute([
                    $this->accountId,
                    $newId,
                    $facilityName,
                    $facilityAddr['street'],
                    $facilityAddr['postal_code'],
                    $facilityAddr['city'],
                    $this->str($facility, 'email'),
                    $this->str($facility, 'contact_person'),
                    $this->str($facility, 'notes'),
                    $this->str($facility, 'main_power_location'),
                    $this->str($facility, 'main_gas_location'),
                    $this->str($facility, 'main_water_location'),
                    $this->str($facility, 'description'),
                    $this->floor($facility, 'floors_above', 1, 40),
                    $this->floor($facility, 'floors_below', 0, 5),
                    $this->jsonListEncoded($facility, 'entrances'),
                    $this->jsonListEncoded($facility, 'places'),
                    $this->str($facility, 'vchody'),
                    $this->createdAt($facility),
                ]);
                $facilityId = (int) $this->pdo->lastInsertId();
                $existingFacilities[$facilityKey] = $facilityId;
                $this->facilityMap[(int) ($facility['id'] ?? 0)] = $facilityId;
                $this->restored['facilities']++;
            }
        }

        $this->restoreBookCodes($insertedFirms);
    }

    /**
     * Every book code the archive uses: its firms' codes and the code of each
     * PK-<KÓD>-n number of its entries (a code always has a letter, so the old
     * PK-RRRR-NNN numbers name none).
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private static function bookCodesOf(array $manifest): array
    {
        $codes = [];
        foreach ((array) ($manifest['companies'] ?? []) as $company) {
            if (is_array($company) && ($code = self::archiveCode($company)) !== null) {
                $codes[$code] = true;
            }
        }
        foreach ((array) ($manifest['inspections'] ?? []) as $inspection) {
            $number = is_array($inspection) ? ($inspection['book_number'] ?? null) : null;
            if (is_string($number) && preg_match('/^' . BookNumber::PREFIX . '-([A-Z0-9]{2,4})-\d+$/', $number, $m) === 1
                && preg_match('/[A-Z]/', $m[1]) === 1) {
                $codes[$m[1]] = true;
            }
        }
        return array_map('strval', array_keys($codes));
    }

    /** The firma's code as the archive has it, or null (none, or not a valid code). */
    private static function archiveCode(array $company): ?string
    {
        try {
            return isset($company['book_code']) && $company['book_code'] !== null
                ? BookNumber::normalizeCode($company['book_code'])
                : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The codes and counters of the firms this restore inserted (customer's
     * answer 3 of 18. 11. 2026), in two passes (round 7). First every firma
     * whose code is free here keeps it with its counter. Then the others — a
     * code another firma of this account holds, or numbers of its series exist
     * here (round 6), or no code at all (an archive from before the code) — get
     * a proposal with their counter at 0, every code of the archive counted as
     * taken: a firma whose code was taken no longer takes the free code of
     * another firma of the archive (Stavby Novák STA used to get STV, so
     * „Stv Trade" lost STV and its book had two series). What happened to
     * each entry is reported after the entries ({@see self::bookWarnings()}).
     *
     * @param list<array{id: int, name: string, company: array<string, mixed>}> $firms
     */
    private function restoreBookCodes(array $firms): void
    {
        $archiveCodes = [];
        foreach ($this->archiveBookCodes as $code) {
            $archiveCodes[$code] = true;
        }

        $setCode = $this->pdo->prepare('UPDATE companies SET book_code = ?, book_last_seq = ? WHERE id = ? AND account_id = ?');
        $rest = [];
        foreach ($firms as $firm) {
            $code = self::archiveCode($firm['company']);
            if ($code !== null && !BookNumber::isCodeTaken($this->accountId, $code, $firm['id'])) {
                $setCode->execute([$code, max(0, (int) ($firm['company']['book_last_seq'] ?? 0)), $firm['id'], $this->accountId]);
                continue;
            }
            $rest[] = $firm + ['code' => $code];
        }
        foreach ($rest as $firm) {
            $new = BookNumber::assignProposal($this->accountId, $firm['id'], $firm['name'], $archiveCodes);
            if ($firm['code'] !== null) {
                $this->bookCodeChanged[$firm['id']] = [
                    'name'        => $firm['name'],
                    'client_type' => (string) ($firm['company']['client_type'] ?? ClientKind::FIRMA),
                    'old'         => $firm['code'],
                    'new'         => $new,
                ];
            }
        }
    }

    /**
     * A firma of the archive the merge matched to an existing one keeps the
     * existing firma's code; when the archive's code differs, its entries come
     * back with numbers of the archive's code, and the warning says so (round 7).
     *
     * @param array<string, mixed> $company
     */
    private function noteMergedBookCode(int $companyId, string $name, array $company): void
    {
        $code = self::archiveCode($company);
        if ($code === null) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT book_code, client_type FROM companies WHERE id = ? AND account_id = ?');
        $stmt->execute([$companyId, $this->accountId]);
        $own = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$own || $own['book_code'] === $code) {
            return;
        }
        $this->bookCodeMerged[$companyId] = [
            'name'        => $name,
            'client_type' => (string) $own['client_type'],
            'old'         => $code,
            'own'         => $own['book_code'] !== null ? (string) $own['book_code'] : null,
            'numbers'     => [],
        ];
    }

    /** The client of this account holding the code: its name and kind, or null when only numbers hold it. */
    private function bookCodeHolder(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT name, client_type FROM companies WHERE account_id = ? AND book_code = ?');
        $stmt->execute([$this->accountId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['name' => (string) $row['name'], 'client_type' => (string) $row['client_type']] : null;
    }

    /**
     * The end of the sentence about numbers kept under `$code`: who skips them —
     * the client holding the code, or, when only numbers hold it, nobody may
     * take the code (BookNumber::isCodeTaken).
     *
     * @param list<string> $numbers
     */
    private function holderSentence(string $code, array $numbers): string
    {
        $holder = $this->bookCodeHolder($code);
        $list = implode(', ', $numbers);
        return $holder !== null
            ? "ktorý teraz patrí klientovi „{$holder['name']}\": $list. Tento klient tieto čísla pri ďalších zápisoch preskočí."
            : "ktorý v tomto účte nemá žiadny klient, len čísla iných zápisov: $list. Kód „{$code}\" preto nedostane žiadny klient tohto účtu.";
    }

    /**
     * What the restore did to book codes and numbers, entry by entry (round 6;
     * the warning used to say the numbers were kept even when one was lost).
     * Round 7: the next number is the one the next issue really takes, or none
     * is named; a code held by numbers only is worded as such; a firma merged
     * into one with another code says what its numbers are.
     */
    private function bookWarnings(): void
    {
        foreach ($this->bookCodeChanged as $companyId => $c) {
            $bd = ClientKind::isBytovyDom($c['client_type']);
            $why = $this->bookCodeHolder($c['old']) !== null
                ? 'už v tomto účte patrí inému klientovi'
                : 'už v tomto účte používajú čísla iných zápisov';
            if ($c['new'] !== null) {
                $next = BookNumber::nextNumber($this->pdo, $this->accountId, $companyId);
                $tail = ($bd ? 'dostalo' : 'dostala') . " kód „{$c['new']}\""
                    . ($next !== null ? ", ďalší zápis bude $next." : '.');
            } else {
                $tail = 'kód treba doplniť na ' . ($bd ? 'jeho' : 'jej') . ' detaile pred ďalším zápisom.';
            }
            $this->warnings[] = ($bd ? 'Bytové družstvo' : 'Firma') . " „{$c['name']}\": kód „{$c['old']}\" $why — $tail";
        }
        foreach ($this->bookNumbersForeign as $code => $numbers) {
            $this->warnings[] = "Zápisy do požiarnej knihy si ponechali svoje čísla aj pod kódom „{$code}\"" . ', ' . $this->holderSentence($code, $numbers);
        }
        foreach ($this->bookCodeMerged as $companyId => $m) {
            if ($m['numbers'] === []) {
                continue;
            }
            $bd = ClientKind::isBytovyDom($m['client_type']);
            $next = $m['own'] !== null ? BookNumber::nextNumber($this->pdo, $this->accountId, $companyId) : null;
            $this->warnings[] = ($bd ? "Bytové družstvo „{$m['name']}\" zo zálohy sa spojilo s rovnakým bytovým družstvom tohto účtu, ktoré "
                    : "Firma „{$m['name']}\" zo zálohy sa spojila s rovnakou firmou tohto účtu, ktorá ")
                . ($m['own'] !== null ? "má kód „{$m['own']}\"" : 'ešte nemá kód')
                . " — zápisy zo zálohy si ponechali čísla s kódom „{$m['old']}\": " . implode(', ', $m['numbers']) . '.'
                . ($next !== null ? " Ďalší zápis bude $next." : '');
            $holder = $this->bookCodeHolder($m['old']);
            if ($holder !== null) {
                $this->warnings[] = "Kód „{$m['old']}\" v tomto účte patrí klientovi „{$holder['name']}\" — tento klient tieto čísla pri ďalších zápisoch preskočí.";
            }
        }
        foreach ($this->bookNumbersLost as $number) {
            $this->warnings[] = "Zápis do požiarnej knihy č. $number: toto číslo už v účte má iný zápis — zápis je obnovený "
                . 'ako rozpracovaný (už raz vystavený) a pri vystavení dostane nové číslo.';
        }
    }

    /**
     * After the documents: an issued book entry of an archive from before
     * migration 080 takes the number of its protocol, and every restored
     * firma's counter is pushed up to the highest number of its series, so
     * the next entry never repeats one.
     */
    private function settleBookNumbers(): void
    {
        // Plain reads, then one UPDATE per entry by its primary key — only the
        // entries this restore inserted are locked (round 7: the UPDATE … JOIN
        // with its subqueries locked other entries of the account and deadlocked
        // with an issue holding one of them).
        $inUse = $this->pdo->prepare('SELECT 1 FROM inspections WHERE account_id = ? AND book_number = ? LIMIT 1');
        $setNumber = $this->pdo->prepare(
            'UPDATE inspections SET book_number = ?, book_number_version = ? WHERE id = ? AND account_id = ? AND book_number IS NULL'
        );
        foreach (array_chunk($this->insertedInspectionIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            // The newest documents row when an entry has more than one (round 6).
            $stmt = $this->pdo->prepare(
                "SELECT i.id, d.number, d.version
                 FROM   inspections i
                 JOIN   documents d ON d.id = (SELECT d2.id FROM documents d2
                                               WHERE  d2.account_id = i.account_id AND d2.parent_type = 'inspection' AND d2.parent_id = i.id
                                               ORDER  BY d2.version DESC, d2.id DESC LIMIT 1)
                 WHERE  i.account_id = ? AND i.id IN ($in) AND i.type = 'poziarna_kniha' AND i.book_number IS NULL"
            );
            $stmt->execute([$this->accountId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $inUse->execute([$this->accountId, (string) $r['number']]);
                if ($inUse->fetchColumn() !== false) {
                    continue;
                }
                $setNumber->execute([(string) $r['number'], (int) $r['version'], (int) $r['id'], $this->accountId]);
            }
        }

        // An entry of an archive from before migration 080 whose protocol number
        // this account already used: no number and no document came back. It
        // returns as „Upraviť" leaves it (round 7: protocol_deferred_at cleared
        // too), like an entry of a newer archive whose number was taken (round 6).
        $state = $this->pdo->prepare(
            "SELECT i.status, i.book_number,
                    EXISTS (SELECT 1 FROM documents d WHERE d.account_id = i.account_id
                            AND d.parent_type = 'inspection' AND d.parent_id = i.id) AS has_document
             FROM   inspections i WHERE i.id = ? AND i.account_id = ?"
        );
        $reopen = $this->pdo->prepare(
            "UPDATE inspections
             SET    status = 'draft', issued_before = 1, protocol_deferred_at = NULL,
                    effective_inspector_user_id = NULL, effective_cert_number = NULL,
                    effective_cert_valid_from = NULL, effective_cert_valid_to = NULL,
                    details = IF(JSON_EXISTS(details, '$.issued'), JSON_REMOVE(details, '$.issued'), details)
             WHERE  id = ? AND account_id = ?"
        );
        foreach ($this->bookDocumentSkipped as $inspectionId => $number) {
            if (!in_array($inspectionId, $this->insertedInspectionIds, true)) {
                continue;
            }
            $state->execute([$inspectionId, $this->accountId]);
            $s = $state->fetch(PDO::FETCH_ASSOC);
            if (!$s || $s['status'] !== 'finalized' || $s['book_number'] !== null || (int) $s['has_document'] === 1) {
                continue;
            }
            $reopen->execute([$inspectionId, $this->accountId]);
            $this->bookNumbersLost[] = $number;
        }

        $companyIds = array_values(array_unique($this->companyMap));
        if ($companyIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($companyIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.book_code, c.book_last_seq, i.book_number
             FROM   companies c
             JOIN   inspections i ON i.company_id = c.id AND i.account_id = c.account_id
             WHERE  c.account_id = ? AND c.id IN ($in) AND c.book_code IS NOT NULL AND i.book_number IS NOT NULL"
        );
        $stmt->execute([$this->accountId, ...$companyIds]);
        $max = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seq = BookNumber::sequenceOf((string) $r['book_number'], (string) $r['book_code']);
            if ($seq !== null && $seq > (int) $r['book_last_seq']) {
                $max[(int) $r['id']] = max($max[(int) $r['id']] ?? 0, $seq);
            }
        }
        $upd = $this->pdo->prepare('UPDATE companies SET book_last_seq = GREATEST(book_last_seq, ?) WHERE id = ? AND account_id = ?');
        foreach ($max as $companyId => $seq) {
            $upd->execute([$seq, $companyId, $this->accountId]);
        }
    }

    // ── Inspections, items, photos ───────────────────────────────────────────

    /**
     * Periodicity of a backed-up inspection as [value, unit, is_custom].
     *
     * Archives written before block 1 carry `periodicity_months` instead; they
     * are read as months, and the 0 that used to mean "one-off" becomes the
     * NULL pair that says the same thing today.
     *
     * @param array<string, mixed> $inspection
     * @return array{0: int|null, 1: string|null, 2: int}
     */
    private function periodicity(array $inspection): array
    {
        if (array_key_exists('periodicity_value', $inspection)) {
            $value = $inspection['periodicity_value'];
            $unit  = $inspection['periodicity_unit'] ?? null;
            $value = is_numeric($value) ? (int) $value : null;
            $unit  = is_string($unit) && in_array($unit, Periodicity::UNITS, true) ? $unit : null;
            if ($value === null || $unit === null) {
                return [null, null, 0];
            }
            return [$value, $unit, (int) ($inspection['periodicity_is_custom'] ?? 0)];
        }

        $months = (int) ($inspection['periodicity_months'] ?? 0);
        return $months > 0 ? [$months, 'mesiac', 0] : [null, null, 0];
    }

    /** @param list<array<string, mixed>> $inspections */
    private function restoreInspections(array $inspections): void
    {
        $existing = $this->loadInspectionKeys();

        $insert = $this->pdo->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, type,
                 periodicity_value, periodicity_unit, periodicity_is_custom,
                 is_preventive_inspection, executed_on, inspector_user_id,
                 effective_inspector_user_id, effective_cert_number,
                 effective_cert_valid_from, effective_cert_valid_to,
                 status, issued_before, book_number, book_number_version, notes, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItem = $this->pdo->prepare(
            'INSERT INTO inspection_items (inspection_id, position, fields, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $insertPhoto = $this->pdo->prepare(
            'INSERT INTO inspection_item_photos
                (account_id, inspection_id, item_id, defect_key, position,
                 file_path, thumb_path, byte_size, width, height, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertCoverage = $this->pdo->prepare(
            'INSERT IGNORE INTO inspection_facilities
                (account_id, inspection_id, facility_id, periodicity_value, periodicity_unit)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($inspections as $inspection) {
            $oldId      = (int) ($inspection['id'] ?? 0);
            $type       = $this->str($inspection, 'type');
            $companyId  = $this->companyMap[(int) ($inspection['company_id'] ?? 0)] ?? null;
            $facilityId = $this->facilityMap[(int) ($inspection['facility_id'] ?? 0)] ?? null;

            if ($type === null || $companyId === null || $facilityId === null) {
                $this->warnings[] = "Kontrola #$oldId sa nedala priradiť k firme alebo prevádzke — preskočená.";
                continue;
            }

            $executedOn = $this->date($inspection, 'executed_on');
            $createdAt  = $this->createdAt($inspection, $executedOn);
            $key        = $this->inspectionKey($facilityId, $type, $executedOn, $createdAt);
            if (isset($existing[$key])) {
                // The record is already here — leave it and everything under it
                // alone. Merge never overwrites; it only fills gaps.
                $existingInspId = $existing[$key];
                $this->inspectionMap[$oldId] = $existingInspId;
                $this->skipped['inspections']++;

                // Map old item IDs to existing DB item IDs by position so that
                // relinking, tasks and defect photos can resolve them.
                if (!empty($inspection['items']) && is_array($inspection['items'])) {
                    $itemStmt = $this->pdo->prepare('SELECT id, position FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC');
                    $itemStmt->execute([$existingInspId]);
                    $existingItemsByPos = [];
                    while ($row = $itemStmt->fetch()) {
                        $existingItemsByPos[(int) $row['position']][] = (int) $row['id'];
                    }
                    foreach ($inspection['items'] as $oldItem) {
                        if (!is_array($oldItem) || !isset($oldItem['id'])) {
                            continue;
                        }
                        $oldItemId = (int) $oldItem['id'];
                        $pos = (int) ($oldItem['position'] ?? 0);
                        if (!empty($existingItemsByPos[$pos])) {
                            $this->itemMap[$oldItemId] = array_shift($existingItemsByPos[$pos]);
                        }
                    }
                }
                continue;
            }

            $status       = $this->status($inspection);
            $details      = self::details($inspection['details'] ?? null);
            $issuedBefore = $this->issuedBefore($inspection, $status, $details);
            $effective    = [
                $this->user($inspection, 'effective_inspector_email'),
                $this->str($inspection, 'effective_cert_number'),
                $this->date($inspection, 'effective_cert_valid_from'),
                $this->date($inspection, 'effective_cert_valid_to'),
            ];

            // The number a book entry took (18. 11. 2026). One this account
            // already uses — on an entry or a document — cannot come back: the
            // entry returns as „Upraviť" leaves it (a draft issued before, no
            // document, nothing printed remembered) and takes a new number at its
            // next issue, rather than finalized with neither (round 6).
            $bookNumber = null;
            $bookLost = false;
            if ($type === 'poziarna_kniha') {
                $archiveNumber = $this->str($inspection, 'book_number');
                $bookNumber = $this->freeBookNumber($archiveNumber);
                if ($archiveNumber !== null && $bookNumber === null) {
                    $bookLost = true;
                    $this->bookNumbersLost[] = $archiveNumber;
                    $status = 'draft';
                    $issuedBefore = true;
                    $effective = [null, null, null, null];
                    $decoded = $details !== null ? json_decode($details, true) : null;
                    if (is_array($decoded) && array_key_exists('issued', $decoded)) {
                        unset($decoded['issued']);
                        $details = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    }
                } elseif ($bookNumber !== null && isset($this->bookCodeChanged[$companyId])
                    && BookNumber::sequenceOf($bookNumber, $this->bookCodeChanged[$companyId]['old']) !== null) {
                    $this->bookNumbersForeign[$this->bookCodeChanged[$companyId]['old']][] = $bookNumber;
                } elseif ($bookNumber !== null && isset($this->bookCodeMerged[$companyId])
                    && BookNumber::sequenceOf($bookNumber, $this->bookCodeMerged[$companyId]['old']) !== null) {
                    $this->bookCodeMerged[$companyId]['numbers'][] = $bookNumber;
                }
            }

            $insert->execute([
                $this->accountId,
                $companyId,
                $facilityId,
                $type,
                ...$this->periodicity($inspection),
                (int) ($inspection['is_preventive_inspection'] ?? 1),
                $executedOn,
                $this->user($inspection, 'inspector_email') ?? $this->userId,
                ...$effective,
                $status,
                $issuedBefore ? 1 : 0,
                $bookNumber,
                $bookNumber !== null ? max(1, (int) ($inspection['book_number_version'] ?? 1)) : 0,
                $this->str($inspection, 'notes'),
                // Header data of a block 2 úkon (inspections.details); absent in
                // archives written before it existed.
                $details,
                $createdAt,
            ]);
            $inspectionId = (int) $this->pdo->lastInsertId();
            // With the safe default above an archive without the key restores
            // every draft as issued; the list stays for markIssuedFromDocuments().
            if (!$issuedBefore && !array_key_exists('issued_before', $inspection)) {
                $this->issuedUnknownIds[] = $inspectionId;
            }
            $this->restoreInvoicing('inspections', $inspectionId, $inspection);
            $this->inspectionMap[$oldId] = $inspectionId;
            $this->insertedInspectionIds[] = $inspectionId;
            if ($bookLost) {
                $this->bookLostInspectionIds[$inspectionId] = true;
            }
            $existing[$key] = $inspectionId;
            $this->restored['inspections']++;
            if ($type === 'preventivna_prehliadka') {
                $this->prehliadkaInspectionIds[] = $inspectionId;
            }
            if (str_contains((string) self::details($inspection['details'] ?? null), '"concept_from_prehliadka"')) {
                $this->conceptInspectionIds[] = $inspectionId;
            }

            // POapp 7. 11. 2026 — a new-format book entry: its prevádzky (onto
            // the restored ones; one that did not come back is left out), and
            // its defects are relinked like a prehliadka's.
            $isBook = $type === 'poziarna_kniha' && is_array($inspection['facilities'] ?? null);
            if ($isBook) {
                $this->bookInspectionIds[] = $inspectionId;
                foreach ($this->list($inspection, 'facilities') as $cov) {
                    $newFacility = $this->facilityMap[(int) ($cov['facility_id'] ?? 0)] ?? null;
                    if ($newFacility === null) {
                        $this->warnings[] = "Prevádzka zápisu #$oldId sa nedala priradiť — vynechaná.";
                        continue;
                    }
                    [$covValue, $covUnit] = $this->periodicity($cov);
                    $insertCoverage->execute([$this->accountId, $inspectionId, $newFacility, $covValue, $covUnit]);
                }
            }

            foreach ($this->list($inspection, 'items') as $item) {
                $itemFields = $this->remapCatalogId($item['fields'] ?? null);
                // A book defect names its prevádzka; never left without one —
                // the entry's own prevádzka when its old one did not come back.
                if ($isBook && is_array($itemFields) && isset($itemFields['facility_id'])) {
                    $itemFields['facility_id'] = $this->facilityMap[(int) $itemFields['facility_id']] ?? $facilityId;
                }
                $insertItem->execute([
                    $inspectionId,
                    (int) ($item['position'] ?? 0),
                    (string) json_encode($itemFields ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                    $this->createdAt($item),
                ]);
                $itemId = (int) $this->pdo->lastInsertId();
                $this->restored['items']++;
                $this->itemMap[(int) ($item['id'] ?? 0)] = $itemId;
                if ($type === 'preventivna_prehliadka' || $isBook) {
                    $this->prehliadkaItemIds[] = $itemId;
                }

                foreach ($this->list($item, 'photos') as $photo) {
                    $entry = isset($photo['file']) ? (string) $photo['file'] : null;
                    $thumb = isset($photo['thumb']) ? (string) $photo['thumb'] : null;
                    if ($entry === null || $thumb === null) {
                        // A v1 JSON export listed photos by download URL only —
                        // there are no bytes here to restore.
                        continue;
                    }

                    $token    = bin2hex(random_bytes(8));
                    $fileRel  = Storage::photoRelative($this->accountId, $inspectionId, $token);
                    $thumbRel = Storage::photoThumbRelative($this->accountId, $inspectionId, $token);

                    if (!$this->extract($entry, $fileRel) || !$this->extract($thumb, $thumbRel)) {
                        $this->warnings[] = "Fotka z kontroly #$oldId chýba v archíve — preskočená.";
                        continue;
                    }

                    $insertPhoto->execute([
                        $this->accountId,
                        $inspectionId,
                        $itemId,
                        $this->str($photo, 'defect_key'),
                        (int) ($photo['position'] ?? 0),
                        $fileRel,
                        $thumbRel,
                        (int) ($photo['byte_size'] ?? 0),
                        (int) ($photo['width'] ?? 0),
                        (int) ($photo['height'] ?? 0),
                        $this->createdAt($photo),
                    ]);
                    $this->restored['photos']++;
                }
            }
        }
    }

    /**
     * A defect of a preventívna prehliadka names the devices it concerns by the
     * id of the item they were listed from (`devices[].source_item_id`) — an
     * item of another úkon, which may only now have been inserted. Once every
     * item id is known the references are pointed at the restored items. One
     * that cannot be resolved (its úkon was already in the account, or is not
     * in the archive) is dropped rather than left to name a stranger's item;
     * the label / serial / location snapshot beside it still prints.
     */
    private function relinkPrehliadkaDevices(): void
    {
        if ($this->prehliadkaItemIds === []) {
            return;
        }
        $select = $this->pdo->prepare('SELECT fields FROM inspection_items WHERE id = ?');
        $update = $this->pdo->prepare('UPDATE inspection_items SET fields = ? WHERE id = ?');
        foreach ($this->prehliadkaItemIds as $itemId) {
            $select->execute([$itemId]);
            $fields = json_decode((string) $select->fetchColumn(), true);
            if (!is_array($fields) || !is_array($fields['devices'] ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($fields['devices'] as $i => $device) {
                if (!is_array($device) || !isset($device['source_item_id'])) {
                    continue;
                }
                $old = (int) $device['source_item_id'];
                if (isset($this->itemMap[$old])) {
                    $fields['devices'][$i]['source_item_id'] = $this->itemMap[$old];
                } else {
                    unset($fields['devices'][$i]['source_item_id']);
                }
                $changed = true;
            }
            if ($changed) {
                $update->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), $itemId]);
            }
        }
    }

    /**
     * Remaps defect references of a preventívna prehliadka:
     *   - `inspection_items.fields.persists_from`: remaps inspection_id, source_item_id and key
     *     (`status` and `reason` of a defect are plain values inside `fields` and need none)
     *   - `inspections.details.previous`: remaps source_inspection_id, source_item_id and key
     *   - `inspections.details.concept_from_prehliadka` of a concept (plan 8b)
     * so carried-over defects and previous-defect answers point to the restored rows.
     */
    private function relinkPrehliadkaPrevious(): void
    {
        // A concept names the prehliadka it came from in its header (`details`) as
        // well as in `source_inspection_id`; the header is remapped here.
        if ($this->conceptInspectionIds !== []) {
            $select = $this->pdo->prepare('SELECT details FROM inspections WHERE id = ?');
            $update = $this->pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?');
            foreach ($this->conceptInspectionIds as $inspId) {
                $select->execute([$inspId]);
                $details = json_decode((string) $select->fetchColumn(), true);
                $old = is_array($details) ? (int) ($details['concept_from_prehliadka'] ?? 0) : 0;
                if ($old > 0 && isset($this->inspectionMap[$old])) {
                    $details['concept_from_prehliadka'] = $this->inspectionMap[$old];
                    $update->execute([json_encode($details, JSON_UNESCAPED_UNICODE), $inspId]);
                }
            }
        }

        if ($this->prehliadkaItemIds !== []) {
            $select = $this->pdo->prepare('SELECT fields FROM inspection_items WHERE id = ?');
            $update = $this->pdo->prepare('UPDATE inspection_items SET fields = ? WHERE id = ?');
            foreach ($this->prehliadkaItemIds as $itemId) {
                $select->execute([$itemId]);
                $fields = json_decode((string) $select->fetchColumn(), true);
                if (!is_array($fields) || !is_array($fields['persists_from'] ?? null)) {
                    continue;
                }
                $changed = false;
                $oldInsp = (int) ($fields['persists_from']['inspection_id'] ?? 0);
                if ($oldInsp > 0 && isset($this->inspectionMap[$oldInsp])) {
                    $fields['persists_from']['inspection_id'] = $this->inspectionMap[$oldInsp];
                    $changed = true;
                }
                // The item of the previous record (spec 12.1); a row carried over
                // before it has only the key, remapped below.
                $oldSourceItem = $fields['persists_from']['source_item_id'] ?? null;
                if (is_int($oldSourceItem) && $oldSourceItem > 0) {
                    $fields['persists_from']['source_item_id'] = $this->itemMap[$oldSourceItem] ?? null;
                    $changed = true;
                }
                $oldKey = (string) ($fields['persists_from']['key'] ?? '');
                if (preg_match('/^p(\d+)-(\d+)$/', $oldKey, $m)) {
                    $kOldInsp = (int) $m[1];
                    $kOldItem = (int) $m[2];
                    $kNewInsp = $this->inspectionMap[$kOldInsp] ?? null;
                    $kNewItem = $this->itemMap[$kOldItem] ?? null;
                    if ($kNewInsp !== null && $kNewItem !== null) {
                        $fields['persists_from']['key'] = "p{$kNewInsp}-{$kNewItem}";
                        $changed = true;
                    }
                } elseif (preg_match('/^(?:pk|k)(\d+)-(\d+)$/', $oldKey, $m)) {
                    $kOldInsp = (int) $m[1];
                    $kIdx = $m[2];
                    $kNewInsp = $this->inspectionMap[$kOldInsp] ?? null;
                    if ($kNewInsp !== null) {
                        $fields['persists_from']['key'] = "k{$kNewInsp}-{$kIdx}";
                        $changed = true;
                    }
                }
                if ($changed) {
                    $update->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), $itemId]);
                }
            }
        }

        if ($this->prehliadkaInspectionIds !== []) {
            $select = $this->pdo->prepare('SELECT details FROM inspections WHERE id = ?');
            $update = $this->pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?');
            foreach ($this->prehliadkaInspectionIds as $inspId) {
                $select->execute([$inspId]);
                $details = json_decode((string) $select->fetchColumn(), true);
                if (!is_array($details) || !is_array($details['previous'] ?? null)) {
                    continue;
                }
                $changed = false;
                foreach ($details['previous'] as $idx => $prev) {
                    if (!is_array($prev)) {
                        continue;
                    }
                    $oldSourceInsp = (int) ($prev['source_inspection_id'] ?? 0);
                    if ($oldSourceInsp > 0 && isset($this->inspectionMap[$oldSourceInsp])) {
                        $details['previous'][$idx]['source_inspection_id'] = $this->inspectionMap[$oldSourceInsp];
                        $changed = true;
                    }
                    $oldSourceItem = isset($prev['source_item_id']) ? (int) $prev['source_item_id'] : null;
                    if ($oldSourceItem !== null && isset($this->itemMap[$oldSourceItem])) {
                        $details['previous'][$idx]['source_item_id'] = $this->itemMap[$oldSourceItem];
                        $changed = true;
                    }
                    $oldKey = (string) ($prev['key'] ?? '');
                    if (preg_match('/^p(\d+)-(\d+)$/', $oldKey, $m)) {
                        $kOldInsp = (int) $m[1];
                        $kOldItem = (int) $m[2];
                        $kNewInsp = $this->inspectionMap[$kOldInsp] ?? null;
                        $kNewItem = $this->itemMap[$kOldItem] ?? null;
                        if ($kNewInsp !== null && $kNewItem !== null) {
                            $details['previous'][$idx]['key'] = "p{$kNewInsp}-{$kNewItem}";
                            $changed = true;
                        }
                    } elseif (preg_match('/^(?:pk|k)(\d+)-(\d+)$/', $oldKey, $m)) {
                        $kOldInsp = (int) $m[1];
                        $kIdx = $m[2];
                        $kNewInsp = $this->inspectionMap[$kOldInsp] ?? null;
                        if ($kNewInsp !== null) {
                            $details['previous'][$idx]['key'] = "k{$kNewInsp}-{$kIdx}";
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $update->execute([json_encode($details, JSON_UNESCAPED_UNICODE), $inspId]);
                }
            }
        }
    }

    /**
     * A new-format book entry names records by id in its header: the attached
     * ones (`zaznamy`), the paragraphs it printed and the prevádzky it printed
     * (`issued`). They are pointed at the restored rows once every inspection
     * and training is in; a reference that did not come back is dropped.
     */
    private function relinkBookEntries(): void
    {
        if ($this->bookInspectionIds === []) {
            return;
        }
        $map = fn (string $kind, int $old): ?int => $kind === 'training'
            ? ($this->trainingMap[$old] ?? null)
            : ($this->inspectionMap[$old] ?? null);
        $select = $this->pdo->prepare('SELECT details FROM inspections WHERE id = ?');
        $update = $this->pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?');
        foreach ($this->bookInspectionIds as $inspId) {
            $select->execute([$inspId]);
            $details = json_decode((string) $select->fetchColumn(), true);
            if (!is_array($details)) {
                continue;
            }
            $zaznamy = [];
            foreach ((array) ($details['zaznamy'] ?? []) as $z) {
                $new = is_array($z) ? $map((string) ($z['kind'] ?? ''), (int) ($z['id'] ?? 0)) : null;
                if ($new !== null) {
                    $zaznamy[] = ['id' => $new] + $z;
                }
            }
            $details['zaznamy'] = $zaznamy;
            if (is_array($details['issued'] ?? null)) {
                $paragraphs = [];
                foreach ((array) ($details['issued']['paragraphs'] ?? []) as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    // The printed text stays as printed; only the link moves.
                    $p['id'] = $map((string) ($p['kind'] ?? ''), (int) ($p['id'] ?? 0));
                    $paragraphs[] = $p;
                }
                $details['issued']['paragraphs'] = $paragraphs;
                $facilities = [];
                foreach ((array) ($details['issued']['facilities'] ?? []) as $f) {
                    if (is_array($f)) {
                        $f['id'] = $this->facilityMap[(int) ($f['id'] ?? 0)] ?? null;
                        $facilities[] = $f;
                    }
                }
                $details['issued']['facilities'] = $facilities;
            }
            $update->execute([json_encode($details, JSON_UNESCAPED_UNICODE), $inspId]);
        }
    }

    /**
     * Second pass for `source_inspection_id`: a follow-up draft can reference an
     * inspection that had not been inserted yet when the draft was written, so
     * the link is only resolvable once every id is known.
     *
     * @param list<array<string, mixed>> $inspections
     */
    private function relinkFollowUps(array $inspections): void
    {
        $update = $this->pdo->prepare(
            'UPDATE inspections SET source_inspection_id = ?
             WHERE id = ? AND account_id = ? AND source_inspection_id IS NULL'
        );
        foreach ($inspections as $inspection) {
            $sourceOld = $inspection['source_inspection_id'] ?? null;
            if ($sourceOld === null) {
                continue;
            }
            $newId     = $this->inspectionMap[(int) ($inspection['id'] ?? 0)] ?? null;
            $newSource = $this->inspectionMap[(int) $sourceOld] ?? null;
            // Only an úkon this restore inserted: one the merge matched to an
            // existing record is left as it is — and unlocked (round 7).
            if ($newId === null || $newSource === null || !in_array($newId, $this->insertedInspectionIds, true)) {
                continue;
            }
            $update->execute([$newSource, $newId, $this->accountId]);
        }
    }

    /**
     * Automatic client notices already sent (chapter 11.3). Without them a
     * restored account would announce the same deadlines to its clients
     * again. A notice whose úkon was not restored has nothing to attach to;
     * INSERT IGNORE keeps an existing record on a merge.
     *
     * @param list<array<string, mixed>> $notices
     */
    private function restoreDeadlineNotices(array $notices): void
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO deadline_notices (account_id, inspection_id, facility_id, training_id, notice_date, recipient, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ownFacility = $this->pdo->prepare('SELECT facility_id FROM inspections WHERE id = ?');
        foreach ($notices as $notice) {
            // Exactly one of the two: a notice of a training (change request 6)
            // carries `training_id`; archives written before it have none.
            $inspectionId = ($notice['inspection_id'] ?? null) !== null
                ? ($this->inspectionMap[(int) $notice['inspection_id']] ?? null)
                : null;
            $trainingId = ($notice['training_id'] ?? null) !== null
                ? ($this->trainingMap[(int) $notice['training_id']] ?? null)
                : null;
            $noticeDate   = $this->date($notice, 'notice_date');
            if (($inspectionId === null) === ($trainingId === null) || $noticeDate === null) {
                continue;
            }
            // An inspection's notice is per prevádzka (a book entry covers
            // several); an archive without it means the úkon's own prevádzka.
            $facilityId = null;
            if ($inspectionId !== null) {
                $facilityId = ($notice['facility_id'] ?? null) !== null
                    ? ($this->facilityMap[(int) $notice['facility_id']] ?? null)
                    : null;
                if ($facilityId === null) {
                    $ownFacility->execute([$inspectionId]);
                    $facilityId = (int) $ownFacility->fetchColumn();
                }
            }
            $insert->execute([
                $this->accountId,
                $inspectionId,
                $facilityId,
                $trainingId,
                $noticeDate,
                mb_substr((string) ($this->str($notice, 'recipient') ?? ''), 0, 191),
                $this->dateTime($notice, 'sent_at') ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Planned visit dates of the deadlines. A plan whose úkon was not restored
     * has nothing to attach to; INSERT IGNORE keeps an existing plan on a
     * merge. Archives written before plans were backed up have none.
     *
     * @param list<array<string, mixed>> $plans
     */
    private function restoreCalendarPlans(array $plans): void
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO calendar_plans (account_id, inspection_id, facility_id, training_id, planned_date)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ownFacility = $this->pdo->prepare('SELECT facility_id FROM inspections WHERE id = ?');
        foreach ($plans as $plan) {
            $inspectionId = ($plan['inspection_id'] ?? null) !== null
                ? ($this->inspectionMap[(int) $plan['inspection_id']] ?? null)
                : null;
            $trainingId = ($plan['training_id'] ?? null) !== null
                ? ($this->trainingMap[(int) $plan['training_id']] ?? null)
                : null;
            $plannedDate = $this->date($plan, 'planned_date');
            if (($inspectionId === null) === ($trainingId === null) || $plannedDate === null) {
                continue;
            }
            // An inspection's plan is per prevádzka (a book entry covers several).
            $facilityId = null;
            if ($inspectionId !== null) {
                $facilityId = ($plan['facility_id'] ?? null) !== null
                    ? ($this->facilityMap[(int) $plan['facility_id']] ?? null)
                    : null;
                if ($facilityId === null) {
                    $ownFacility->execute([$inspectionId]);
                    $facilityId = (int) $ownFacility->fetchColumn();
                }
            }
            $insert->execute([$this->accountId, $inspectionId, $facilityId, $trainingId, $plannedDate]);
        }
    }

    // ── Trainings & trainees ─────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $trainings */
    private function restoreTrainings(array $trainings): void
    {
        $existing = $this->loadTrainingKeys();

        $insert = $this->pdo->prepare(
            'INSERT INTO trainings
                (account_id, company_id, facility_id, type, date,
                 periodicity_value, periodicity_unit, periodicity_is_custom, trainer_id,
                 valid_until_override, patrol_scope,
                 topics, duration_min, fields, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertTrainee = $this->pdo->prepare(
            'INSERT INTO trainees
                (training_id, fullname, position, personal_number, signature_path, signed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $updateSignature = $this->pdo->prepare(
            'UPDATE trainees SET signature_path = ? WHERE id = ?'
        );

        foreach ($trainings as $training) {
            $oldId     = (int) ($training['id'] ?? 0);
            $type      = $this->str($training, 'type');
            $companyId = $this->companyMap[(int) ($training['company_id'] ?? 0)] ?? null;
            if ($type === null || $companyId === null) {
                $this->warnings[] = "Školenie #$oldId sa nedalo priradiť k firme — preskočené.";
                continue;
            }
            $facilityId = ($training['facility_id'] ?? null) !== null
                ? ($this->facilityMap[(int) $training['facility_id']] ?? null)
                : null;

            $date      = $this->date($training, 'date');
            $createdAt = $this->createdAt($training, $date);
            $key       = $companyId . '|' . $type . '|' . ($date ?? '') . '|' . $createdAt;
            if (isset($existing[$key])) {
                $this->trainingMap[$oldId] = $existing[$key];
                $this->skipped['trainings']++;
                continue;
            }

            $insert->execute([
                $this->accountId,
                $companyId,
                $facilityId,
                $type,
                $date,
                // An archive written before periodicity on trainings has no
                // such keys: they come back as „bez opakovania", like the
                // trainings themselves did before the migration.
                ...$this->periodicity($training),
                $this->user($training, 'trainer_email'),
                // Spec 8.1 / 8.2 — absent in an archive written before they existed.
                $this->date($training, 'valid_until_override'),
                $this->str($training, 'patrol_scope'),
                $this->str($training, 'topics'),
                isset($training['duration_min']) ? (int) $training['duration_min'] : null,
                // Pokyn text, re-encoded from the manifest. Restored verbatim
                // rather than re-validated: a backup is a copy of what the
                // account had, not a fresh submission.
                isset($training['fields']) && is_array($training['fields'])
                    ? json_encode($training['fields'], JSON_UNESCAPED_UNICODE)
                    : null,
                $this->status($training),
                $createdAt,
            ]);
            $trainingId = (int) $this->pdo->lastInsertId();
            $this->restoreInvoicing('trainings', $trainingId, $training);
            $this->trainingMap[$oldId] = $trainingId;
            $existing[$key] = $trainingId;
            $this->restored['trainings']++;

            foreach ($this->list($training, 'trainees') as $trainee) {
                $fullname = $this->str($trainee, 'fullname');
                if ($fullname === null) {
                    continue;
                }
                $insertTrainee->execute([
                    $trainingId,
                    $fullname,
                    $this->str($trainee, 'position'),
                    $this->str($trainee, 'personal_number'),
                    null, // path needs the trainee id, filled in right below
                    $this->dateTime($trainee, 'signed_at'),
                    $this->createdAt($trainee),
                ]);
                $traineeId = (int) $this->pdo->lastInsertId();
                $this->restored['trainees']++;

                $entry = isset($trainee['signature']) ? (string) $trainee['signature'] : null;
                if ($entry === null || $entry === '') {
                    continue;
                }
                $relative = Storage::traineeSignatureRelative($trainingId, $traineeId);
                if ($this->extract($entry, $relative)) {
                    $updateSignature->execute([$relative, $traineeId]);
                }
            }
        }
    }

    // ── Generated protocols ──────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $documents */
    private function restoreDocuments(array $documents): void
    {
        $existing = $this->loadDocumentNumbers();

        $insert = $this->pdo->prepare(
            'INSERT INTO documents
                (account_id, parent_type, parent_id, type, number, version, form_variant, file_path,
                 generated_at, signed, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        /** @var array<string, int> highest sequence seen per "type|year" */
        $sequences = [];

        foreach ($documents as $document) {
            $number     = $this->str($document, 'number');
            $parentType = (string) ($document['parent_type'] ?? '');
            $entry      = isset($document['file']) ? (string) $document['file'] : null;
            // Whatever happens to the row below (a duplicate number, a PDF
            // missing from the archive), the úkon it names was issued.
            $mapped = null;
            if ($parentType === 'inspection') {
                $mapped = $this->inspectionMap[(int) ($document['parent_id'] ?? 0)] ?? null;
                if ($mapped !== null) {
                    $this->documentedInspectionIds[$mapped] = true;
                }
            }
            if ($number === null || $entry === null) {
                continue;
            }
            // The protocol of a book entry whose number this account already used
            // (round 6): the entry came back as a draft and is issued anew.
            if ($mapped !== null && isset($this->bookLostInspectionIds[$mapped])) {
                $this->skipped['documents']++;
                continue;
            }
            if (isset($existing[$number])) {
                $this->skipped['documents']++;
                // An archive from before migration 080 names a book entry's number
                // only here; settleBookNumbers() reopens the entry it leaves bare.
                if ($mapped !== null && ($document['type'] ?? null) === 'poziarna_kniha') {
                    $this->bookDocumentSkipped[$mapped] = $number;
                }
                continue;
            }

            $parentId = match ($parentType) {
                'inspection' => $this->inspectionMap[(int) ($document['parent_id'] ?? 0)] ?? null,
                'training'   => $this->trainingMap[(int) ($document['parent_id'] ?? 0)] ?? null,
                'stock_issue' => $this->stockIssueMap[(int) ($document['parent_id'] ?? 0)] ?? null,
                default      => null,
            };
            if ($parentId === null) {
                // Its inspection/training was skipped (already present) or could
                // not be restored — the protocol on that record is already there.
                $this->skipped['documents']++;
                continue;
            }

            // NOT NULL, and it also picks the storage year the PDF is filed
            // under — so it always has to resolve to a real timestamp.
            $generatedAt = $this->dateTime($document, 'generated_at') ?? date('Y-m-d H:i:s');
            $year        = (int) date('Y', strtotime($generatedAt) ?: time());
            $relative    = Storage::documentRelative($this->accountId, $year, $number);

            if (!$this->extract($entry, $relative)) {
                $this->warnings[] = "PDF protokol $number chýba v archíve — záznam ostal bez súboru.";
                continue;
            }

            $insert->execute([
                $this->accountId,
                $parentType,
                $parentId,
                (string) ($document['type'] ?? ''),
                $number,
                // The version it had (round 6) — a book entry issued again after
                // „Upraviť" continues from it; an archive without it is version 1.
                max(1, (int) ($document['version'] ?? 1)),
                // Chapter 8.1 — which printout of a test this is.
                in_array($document['form_variant'] ?? null, ['vyplneny', 'prazdny'], true)
                    ? (string) $document['form_variant']
                    : null,
                $relative,
                $generatedAt,
                (int) ($document['signed'] ?? 1),
                $this->dateTime($document, 'signed_at'),
            ]);
            $existing[$number] = 1;
            $this->restored['documents']++;

            $seq = self::sequenceOf($number);
            if ($seq !== null) {
                $bucket = ((string) ($document['type'] ?? '')) . '|' . $year;
                $sequences[$bucket] = max($sequences[$bucket] ?? 0, $seq);
            }
        }

        $this->bumpSequences($sequences);
    }

    /**
     * inspections.details as stored in an archive — a JSON string (Writer
     * dumps the column as is) or, defensively, an already decoded object.
     */
    private static function details(mixed $raw): ?string
    {
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($raw) || $raw === '' || !is_array(json_decode($raw, true))) {
            return null;
        }
        return $raw;
    }

    /**
     * Pushes each per-(type, year) counter up to the highest restored number.
     * Without this the next protocol generated after a restore would reuse a
     * number that already exists — and the unique key on (account, number)
     * would turn that into a hard failure at PDF generation time.
     *
     * @param array<string, int> $sequences keyed "type|year"
     */
    private function bumpSequences(array $sequences): void
    {
        if ($sequences === []) {
            return;
        }
        $upsert = $this->pdo->prepare(
            'INSERT INTO document_sequences (account_id, type, year, last_seq)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq))'
        );
        foreach ($sequences as $bucket => $seq) {
            [$type, $year] = explode('|', $bucket, 2);
            // Trainings all share the single "skolenie" counter regardless of
            // the training type stored on the document row.
            $sequenceType = isset(NumberAllocator::TYPE_PREFIXES[$type]) ? $type : 'skolenie';
            $upsert->execute([$this->accountId, $sequenceType, (int) $year, $seq]);
        }
    }

    /** `PHP-2026-014` → 14. Null when the number is not ours to parse. */
    private static function sequenceOf(string $number): ?int
    {
        if (preg_match('/-(\d+)$/', $number, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    // ── Číselník nedostatkov (Phase 4) ───────────────────────────────────────

    /**
     * The account's own číselník items (archived ones too, so a defect that
     * names one still resolves), remapped to fresh ids. Shipped items are not
     * in the backup — they are the same rows on every installation. Merge key:
     * created_at + title; an item already here is reused, not duplicated.
     * Authors are matched by e-mail like the inspector of an úkon.
     *
     * @param list<array<string, mixed>> $items
     */
    private function restoreDefectCatalog(array $items): void
    {
        $stmt = $this->pdo->prepare('SELECT id, created_at, title FROM defect_catalog WHERE account_id = ?');
        $stmt->execute([$this->accountId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[self::normalizeDateTime((string) $row['created_at']) . '|' . $row['title']] = (int) $row['id'];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO defect_catalog
                (account_id, title, scope, area, default_deadline, description, regulation,
                 justification, measure, created_by_user_id, archived_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            $title = $this->str($item, 'title');
            if ($title === null) {
                continue;
            }
            $oldId = (int) ($item['id'] ?? 0);
            $createdAt = $this->createdAt($item);
            $key = $createdAt . '|' . $title;
            if (isset($existing[$key])) {
                $this->defectCatalogMap[$oldId] = $existing[$key];
                $this->skipped['defect_catalog']++;
                continue;
            }

            $scope = $this->str($item, 'scope');
            $insert->execute([
                $this->accountId,
                $title,
                in_array($scope, ['po', 'bo', 'ob'], true) ? $scope : 'po',
                (string) ($this->str($item, 'area') ?? ''),
                (string) ($this->str($item, 'default_deadline') ?? 'do 30 dní'),
                (string) ($this->str($item, 'description') ?? ''),
                (string) ($this->str($item, 'regulation') ?? ''),
                (string) ($this->str($item, 'justification') ?? ''),
                (string) ($this->str($item, 'measure') ?? ''),
                $this->user($item, 'created_by_email') ?? $this->userId,
                $this->dateTime($item, 'archived_at'),
                $createdAt,
            ]);
            $newId = (int) $this->pdo->lastInsertId();
            $this->defectCatalogMap[$oldId] = $newId;
            $existing[$key] = $newId;
            $this->restored['defect_catalog']++;
        }
    }

    /**
     * A defect's `fields.catalog_id` (Phase 7a) points at a číselník row. A
     * shipped id is the same everywhere and stays; an own one is remapped to
     * the restored row, and one that could not be restored is dropped (the
     * defect keeps its copied texts) rather than left pointing at a stranger's
     * item.
     *
     * @return array<string, mixed>|null
     */
    private function remapCatalogId(mixed $fields): ?array
    {
        if (!is_array($fields)) {
            return null;
        }
        if (!isset($fields['catalog_id']) || !is_numeric($fields['catalog_id'])) {
            return $fields;
        }
        $old = (int) $fields['catalog_id'];
        if (isset($this->defectCatalogMap[$old])) {
            $fields['catalog_id'] = $this->defectCatalogMap[$old];
        } elseif (!$this->isShippedCatalogId($old)) {
            $fields['catalog_id'] = null;
        }
        return $fields;
    }

    private function isShippedCatalogId(int $id): bool
    {
        static $shipped = null;
        if ($shipped === null) {
            $shipped = array_flip(array_map(
                'intval',
                $this->pdo->query('SELECT id FROM defect_catalog WHERE account_id IS NULL')->fetchAll(PDO::FETCH_COLUMN),
            ));
        }
        return isset($shipped[$id]);
    }

    // ── Úlohy (chapter 20) ───────────────────────────────────────────────────

    /**
     * Tasks, remapped onto the restored firms, prevádzky and úkony. Merge key:
     * created_at + text. A task whose firm didn't make it into the account is
     * skipped rather than turned into a „všeobecná" one — that would change
     * what the task is about. Assignee and author are matched by e-mail like
     * the inspector of an úkon; an unknown one is left unassigned.
     *
     * @param list<array<string, mixed>> $tasks
     */
    private function restoreTasks(array $tasks): void
    {
        $stmt = $this->pdo->prepare('SELECT created_at, text FROM tasks WHERE account_id = ?');
        $stmt->execute([$this->accountId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[self::normalizeDateTime((string) $row['created_at']) . '|' . $row['text']] = true;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO tasks
                (account_id, text, company_id, facility_id, assignee_user_id, due_date, done, done_at,
                 source_inspection_id, source_defect_key, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($tasks as $task) {
            $text = $this->str($task, 'text');
            if ($text === null) {
                continue;
            }
            $createdAt = $this->createdAt($task);
            $key = $createdAt . '|' . $text;
            if (isset($existing[$key])) {
                $this->skipped['tasks']++;
                continue;
            }

            $companyId = null;
            if (($task['company_id'] ?? null) !== null) {
                $companyId = $this->companyMap[(int) $task['company_id']] ?? null;
                if ($companyId === null) {
                    $this->warnings[] = "Úloha „$text\" sa nedala priradiť k firme — preskočená.";
                    continue;
                }
            }
            $facilityId = $companyId !== null && ($task['facility_id'] ?? null) !== null
                ? ($this->facilityMap[(int) $task['facility_id']] ?? null)
                : null;
            $inspectionId = ($task['source_inspection_id'] ?? null) !== null
                ? ($this->inspectionMap[(int) $task['source_inspection_id']] ?? null)
                : null;
            $defectKey = $inspectionId !== null ? $this->str($task, 'source_defect_key') : null;
            if ($defectKey !== null && preg_match('/^p(\d+)$/', $defectKey, $m)) {
                $oldItemId = (int) $m[1];
                if (isset($this->itemMap[$oldItemId])) {
                    $defectKey = 'p' . $this->itemMap[$oldItemId];
                }
            }
            $done = (int) ($task['done'] ?? 0) === 1;

            $insert->execute([
                $this->accountId,
                $text,
                $companyId,
                $facilityId,
                $this->user($task, 'assignee_email'),
                $this->date($task, 'due_date'),
                $done ? 1 : 0,
                $done ? ($this->dateTime($task, 'done_at') ?? $createdAt) : null,
                $defectKey !== null ? $inspectionId : null,
                $defectKey,
                $this->user($task, 'created_by_email') ?? $this->userId,
                $createdAt,
            ]);
            $existing[$key] = true;
            $this->restored['tasks']++;
        }
    }

    // ── Termíny cudzích revízií and činnosti (phases 6, 7b) ──────────────────

    /**
     * Termíny cudzích revízií (phase 6), remapped onto the restored firms and
     * prevádzky. Merge key: firm + prevádzka + book + name + both dates — a term
     * already here is left alone, so a second restore does not double the list.
     *
     * @param list<array<string, mixed>> $terms
     */
    private function restoreExternalTerms(array $terms): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT company_id, facility_id, book, name, done_on, valid_until
             FROM   company_external_terms WHERE account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->accountId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$this->externalTermKey(
                (int) $row['company_id'],
                $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
                (string) $row['book'],
                (string) $row['name'],
                $row['done_on'] !== null ? (string) $row['done_on'] : null,
                $row['valid_until'] !== null ? (string) $row['valid_until'] : null,
            )] = true;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO company_external_terms
                (account_id, company_id, facility_id, book, name, performed_by, done_on, valid_until, note,
                 created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($terms as $term) {
            $companyId = $this->companyMap[(int) ($term['company_id'] ?? 0)] ?? null;
            $name = $this->str($term, 'name');
            $book = $this->str($term, 'book');
            if ($companyId === null || $name === null || ($book !== 'po' && $book !== 'bozp')) {
                $this->warnings[] = 'Termín cudzej revízie sa nedal priradiť k firme — preskočený.';
                continue;
            }
            $facilityId = null;
            if (($term['facility_id'] ?? null) !== null) {
                $facilityId = $this->facilityMap[(int) $term['facility_id']] ?? null;
                if ($facilityId === null) {
                    $this->warnings[] = 'Termín cudzej revízie prevádzky, ktorá sa neobnovila, bol preskočený.';
                    continue;
                }
            }
            $doneOn = $this->date($term, 'done_on');
            $validUntil = $this->date($term, 'valid_until');

            $key = $this->externalTermKey($companyId, $facilityId, $book, $name, $doneOn, $validUntil);
            if (isset($existing[$key])) {
                $this->skipped['external_terms']++;
                continue;
            }
            $insert->execute([
                $this->accountId,
                $companyId,
                $facilityId,
                $book,
                mb_substr($name, 0, 191),
                ($p = $this->str($term, 'performed_by')) !== null ? mb_substr($p, 0, 191) : null,
                $doneOn,
                $validUntil,
                ($n = $this->str($term, 'note')) !== null ? mb_substr($n, 0, 500) : null,
                $this->user($term, 'created_by_email'),
                $this->createdAt($term),
            ]);
            $existing[$key] = true;
            $this->restored['external_terms']++;
        }
    }

    /**
     * The firms' own „vykonané činnosti" (phase 7b), onto the restored firms.
     * Merge key: firm + set + text — one already on the list is left alone.
     *
     * @param list<array<string, mixed>> $activities
     */
    private function restoreCompanyActivities(array $activities): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT company_id, kind, label FROM company_activities
             WHERE  account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->accountId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[(int) $row['company_id'] . '|' . $row['kind'] . '|' . mb_strtolower((string) $row['label'])] = true;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO company_activities (account_id, company_id, kind, label, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($activities as $activity) {
            $companyId = $this->companyMap[(int) ($activity['company_id'] ?? 0)] ?? null;
            $kind = $this->str($activity, 'kind');
            $label = $this->str($activity, 'label');
            if ($companyId === null || $label === null || ($kind !== 'po' && $kind !== 'bozp')) {
                $this->warnings[] = 'Vlastná činnosť sa nedala priradiť k firme — preskočená.';
                continue;
            }
            $label = mb_substr($label, 0, 191);
            $key = $companyId . '|' . $kind . '|' . mb_strtolower($label);
            if (isset($existing[$key])) {
                $this->skipped['company_activities'] = ($this->skipped['company_activities'] ?? 0) + 1;
                continue;
            }
            $insert->execute([
                $this->accountId, $companyId, $kind, $label,
                $this->user($activity, 'created_by_email'), $this->createdAt($activity),
            ]);
            $existing[$key] = true;
            $this->restored['company_activities']++;
        }
    }

    private function externalTermKey(int $companyId, ?int $facilityId, string $book, string $name, ?string $doneOn, ?string $validUntil): string
    {
        return $companyId . '|' . ($facilityId ?? 0) . '|' . $book . '|' . mb_strtolower($name) . '|' . ($doneOn ?? '') . '|' . ($validUntil ?? '');
    }

    // ── Sklad (chapter 21) ───────────────────────────────────────────────────

    /**
     * Items with their balances, the výdajky and the movements journal.
     *
     * Balances are written as backed up, not replayed from the journal, so
     * the sum over holders is the same number it was. A technician who is
     * not on this account any more cannot hold anything: their balance goes
     * to Sklad, as removing them from the team would have done.
     *
     * Merging two sklady would double every count, so a merge restores the
     * sklad only into an account that has none yet; otherwise it is skipped
     * as a whole and the warning says why. (Replace mode has cleared it.)
     *
     * @param array<string, mixed> $stock
     */
    private function restoreStock(array $stock): void
    {
        $items = $this->list($stock, 'items');
        if ($items === []) {
            return;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM stock_items WHERE account_id = ?');
        $count->execute([$this->accountId]);
        if ((int) $count->fetchColumn() > 0) {
            $this->skipped['stock_items'] += count($items);
            $this->warnings[] = 'Sklad v účte už má položky — sklad zo zálohy sa nepridal, aby sa stavy nezdvojili.';
            return;
        }

        $insertItem = $this->pdo->prepare(
            'INSERT INTO stock_items (account_id, name, unit, warehouse_qty, retired_at, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertBalance = $this->pdo->prepare(
            'INSERT INTO stock_balances (item_id, user_id, account_id, qty) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
        );
        $addToWarehouse = $this->pdo->prepare(
            'UPDATE stock_items SET warehouse_qty = warehouse_qty + ? WHERE id = ?'
        );
        $units = ['ks', 'bal', 'm'];

        /** @var array<int, int> $itemMap */
        $itemMap = [];
        foreach ($items as $item) {
            $name = $this->str($item, 'name');
            if ($name === null) {
                continue;
            }
            $unit = in_array($item['unit'] ?? null, $units, true) ? (string) $item['unit'] : 'ks';
            $insertItem->execute([
                $this->accountId,
                mb_substr($name, 0, 191),
                $unit,
                max(0, (int) ($item['warehouse_qty'] ?? 0)),
                $this->str($item, 'retired_at'),
                $this->userId,
                $this->createdAt($item),
            ]);
            $newId = (int) $this->pdo->lastInsertId();
            $itemMap[(int) ($item['id'] ?? 0)] = $newId;
            $this->restored['stock_items']++;

            foreach ($this->list($item, 'balances') as $balance) {
                $qty = max(0, (int) ($balance['qty'] ?? 0));
                if ($qty === 0) {
                    continue;
                }
                $holderId = $this->user($balance, 'email');
                if ($holderId === null) {
                    $addToWarehouse->execute([$qty, $newId]);
                    $this->warnings[] = "Sklad — „{$name}\": {$qty} {$unit} technika, ktorý už nie je v tíme, sa vrátilo na Sklad.";
                    continue;
                }
                $insertBalance->execute([$newId, $holderId, $this->accountId, $qty]);
            }
        }

        $insertIssue = $this->pdo->prepare(
            'INSERT INTO stock_issues
                (account_id, company_id, facility_id, inspection_id, issued_on,
                 issuer_user_id, issuer_name, issuer_cert, contractor, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($this->list($stock, 'issues') as $issue) {
            $companyId = $this->companyMap[(int) ($issue['company_id'] ?? 0)] ?? null;
            $issuedOn  = $this->date($issue, 'issued_on');
            if ($companyId === null || $issuedOn === null) {
                $this->warnings[] = 'Výdajka sa nedala priradiť k firme — preskočená.';
                continue;
            }
            $facilityId = ($issue['facility_id'] ?? null) !== null
                ? ($this->facilityMap[(int) $issue['facility_id']] ?? null)
                : null;
            $inspectionId = ($issue['inspection_id'] ?? null) !== null
                ? ($this->inspectionMap[(int) $issue['inspection_id']] ?? null)
                : null;
            $insertIssue->execute([
                $this->accountId,
                $companyId,
                $facilityId,
                $inspectionId,
                $issuedOn,
                $this->user($issue, 'issuer_email'),
                (string) ($this->str($issue, 'issuer_name') ?? ''),
                $this->str($issue, 'issuer_cert'),
                is_array($issue['contractor'] ?? null) ? json_encode($issue['contractor'], JSON_UNESCAPED_UNICODE) : null,
                $this->userId,
                $this->createdAt($issue, $issuedOn),
            ]);
            $this->stockIssueMap[(int) ($issue['id'] ?? 0)] = (int) $this->pdo->lastInsertId();
        }

        $insertMovement = $this->pdo->prepare(
            'INSERT INTO stock_movements
                (account_id, item_id, item_name, unit, action,
                 from_holder, from_user_id, from_name, to_holder, to_user_id, to_name,
                 qty, company_id, inspection_id, note, issue_id,
                 to_invoice, invoiced, invoiced_at, created_by_user_id, created_by_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $holder = static fn (mixed $v): ?string => in_array($v, ['sklad', 'technik'], true) ? (string) $v : null;
        foreach ($this->list($stock, 'movements') as $m) {
            $itemId = $itemMap[(int) ($m['item_id'] ?? 0)] ?? null;
            $action = in_array($m['action'] ?? null, ['nakup', 'presun', 'pouzite'], true) ? (string) $m['action'] : null;
            $qty = (int) ($m['qty'] ?? 0);
            if ($itemId === null || $action === null || $qty < 1) {
                continue;
            }
            $fromHolder = $holder($m['from_holder'] ?? null);
            $toHolder = $holder($m['to_holder'] ?? null);
            $companyId = ($m['company_id'] ?? null) !== null ? ($this->companyMap[(int) $m['company_id']] ?? null) : null;
            $inspectionId = ($m['inspection_id'] ?? null) !== null ? ($this->inspectionMap[(int) $m['inspection_id']] ?? null) : null;
            $issueId = ($m['issue_id'] ?? null) !== null ? ($this->stockIssueMap[(int) $m['issue_id']] ?? null) : null;
            $toInvoice = $companyId !== null && (int) ($m['to_invoice'] ?? 0) === 1;
            $invoiced = $toInvoice && (int) ($m['invoiced'] ?? 0) === 1;
            $insertMovement->execute([
                $this->accountId,
                $itemId,
                mb_substr((string) ($this->str($m, 'item_name') ?? ''), 0, 191),
                in_array($m['unit'] ?? null, $units, true) ? (string) $m['unit'] : 'ks',
                $action,
                $fromHolder,
                $fromHolder === 'technik' ? $this->user($m, 'from_email') : null,
                $this->str($m, 'from_name'),
                $toHolder,
                $toHolder === 'technik' ? $this->user($m, 'to_email') : null,
                $this->str($m, 'to_name'),
                $qty,
                $companyId,
                $inspectionId,
                $this->str($m, 'note'),
                $issueId,
                $toInvoice ? 1 : 0,
                $invoiced ? 1 : 0,
                $invoiced ? $this->date($m, 'invoiced_at') : null,
                $this->user($m, 'created_by_email'),
                (string) ($this->str($m, 'created_by_name') ?? ''),
                $this->createdAt($m),
            ]);
            $this->restored['stock_movements']++;
        }
    }

    // ── Archive extraction ───────────────────────────────────────────────────

    /**
     * Streams one archive entry to its place in the storage tree. Streamed
     * rather than read into a string because a single photo is ~300 KB but a
     * restore walks thousands of them.
     */
    private function extract(string $entry, string $relativeDestination): bool
    {
        if ($this->zip === null) {
            return false;
        }
        $source = $this->zip->getStream($entry);
        if (!is_resource($source)) {
            return false;
        }

        $absolute = Storage::absolute($relativeDestination);
        Storage::ensureDir(dirname($absolute));

        $target = @fopen($absolute, 'wb');
        if (!is_resource($target)) {
            fclose($source);
            return false;
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if ($copied === false) {
            @unlink($absolute);
            return false;
        }
        $this->written[] = $absolute;
        return true;
    }

    // ── Existing-record lookups (merge keys) ─────────────────────────────────

    /** @return array<string, int> */
    private function loadCompanyKeys(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, ico FROM companies WHERE account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $this->companyKey((string) $row['name'], $row['ico'] !== null ? (string) $row['ico'] : null);
            $out[$key] = (int) $row['id'];
        }
        return $out;
    }

    /** @return array<string, int> keyed "companyId|lowercased name" */
    private function loadFacilityKeys(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, company_id, name FROM facilities WHERE account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['company_id'] . '|' . mb_strtolower(trim((string) $row['name']))] = (int) $row['id'];
        }
        return $out;
    }

    /** @return array<string, int> */
    private function loadInspectionKeys(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, facility_id, type, executed_on, created_at
             FROM   inspections WHERE account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $this->inspectionKey(
                (int) $row['facility_id'],
                (string) $row['type'],
                $row['executed_on'] !== null ? (string) $row['executed_on'] : null,
                $row['created_at'] !== null ? (string) $row['created_at'] : null,
            );
            $out[$key] = (int) $row['id'];
        }
        return $out;
    }

    /** @return array<string, int> */
    private function loadTrainingKeys(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, company_id, type, date, created_at
             FROM   trainings WHERE account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (int) $row['company_id'] . '|' . $row['type']
                . '|' . ($row['date'] ?? '')
                . '|' . self::normalizeDateTime($row['created_at'] !== null ? (string) $row['created_at'] : null);
            $out[$key] = (int) $row['id'];
        }
        return $out;
    }

    /** @return array<string, int> */
    private function loadDocumentNumbers(): array
    {
        $stmt = $this->pdo->prepare('SELECT number FROM documents WHERE account_id = ?');
        $stmt->execute([$this->accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
            $out[(string) $number] = 1;
        }
        return $out;
    }

    /** @return array<string, int> */
    private function loadUserMap(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email FROM users u
             JOIN   account_users au ON au.user_id = u.id
             WHERE  au.account_id = ? AND au.is_active = 1'
        );
        $stmt->execute([$this->accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[mb_strtolower((string) $row['email'])] = (int) $row['id'];
        }
        return $out;
    }

    /**
     * IČO identifies a company; a company recorded without one falls back to
     * its name, which is what the technician would use to tell them apart.
     */
    private function companyKey(string $name, ?string $ico): string
    {
        $ico = preg_replace('/\s+/u', '', (string) $ico) ?? '';
        return $ico !== '' ? 'ico:' . $ico : 'name:' . mb_strtolower(trim($name));
    }

    private function inspectionKey(int $facilityId, string $type, ?string $executedOn, ?string $createdAt): string
    {
        return $facilityId . '|' . $type . '|' . ($executedOn ?? '') . '|' . self::normalizeDateTime($createdAt);
    }

    // ── Value coercion ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function list(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string, mixed> $row */
    /**
     * Chapter 22 — the firm's default režim fakturácie. Archives written before
     * migration 046 have no key; the firm then keeps the column default.
     *
     * @param array<string, mixed> $company
     */
    private function restoreCompanyBillingMode(int $companyId, array $company): void
    {
        $mode = $company['billing_mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, Invoicing::COMPANY_MODES, true)) {
            return;
        }
        $this->pdo->prepare('UPDATE companies SET billing_mode = ? WHERE id = ? AND account_id = ?')
            ->execute([$mode, $companyId, $this->accountId]);
    }

    /**
     * Chapter 22 — fakturácia úkonu on a freshly restored inspection or
     * training. Written as its own UPDATE so archives without the keys (older
     * than migration 046) simply leave the úkon at NULL = "before invoicing
     * was tracked", the same state such an úkon had in the live app. Values
     * go through the same rules as the API, so a hand-edited archive cannot
     * produce a paušál úkon marked vyfakturované.
     *
     * @param 'inspections'|'trainings' $table
     * @param array<string, mixed> $row
     */
    private function restoreInvoicing(string $table, int $id, array $row): void
    {
        $mode = $row['billing_mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, Invoicing::MODES, true)) {
            return;
        }
        $body = [
            'billing_mode' => $mode,
            'invoiced'     => (bool) ($row['invoiced'] ?? false),
            'invoiced_at'  => $this->date($row, 'invoiced_at'),
            'billing_note' => $this->str($row, 'billing_note'),
        ];
        try {
            $next = Invoicing::merge([], $body);
        } catch (\InvalidArgumentException) {
            return;
        }
        $this->pdo->prepare(
            "UPDATE $table SET billing_mode = ?, invoiced = ?, invoiced_at = ?, billing_note = ?
             WHERE  id = ? AND account_id = ?"
        )->execute([
            $next['billing_mode'],
            $next['invoiced'],
            $next['invoiced_at'],
            $next['billing_note'],
            $id,
            $this->accountId,
        ]);
    }

    /** A floor count from a backup, or null when missing or out of range. */
    private function floor(array $row, string $key, int $min, int $max): ?int
    {
        $value = $row[$key] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            return null;
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function jsonListEncoded(array $row, string $key): string
    {
        $value = $row[$key] ?? [];
        if (!is_array($value)) {
            return '[]';
        }
        $encoded = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '[]';
    }

    private function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || is_array($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private function user(array $row, string $key): ?int
    {
        $email = $this->str($row, $key);
        return $email !== null ? ($this->userMap[mb_strtolower($email)] ?? null) : null;
    }

    /**
     * Whether a restored úkon was issued before (inspections.issued_before,
     * migration 078). Without it an unlocked old protocol would come back as a
     * never-issued draft and could be deleted for good. An archive written
     * before the column existed has no key: then a finalized row or one with
     * the contractor snapshot counts as issued here, and one with a restored
     * documents row later ({@see self::markIssuedFromDocuments()}).
     *
     * @param array<string, mixed> $inspection
     */
    private function issuedBefore(array $inspection, string $status, ?string $details): bool
    {
        if ($status === 'finalized') {
            return true;
        }
        if (array_key_exists('issued_before', $inspection)) {
            return !empty($inspection['issued_before']);
        }
        // An archive written before migration 078 cannot tell an unlocked
        // protocol from a draft that was never issued: every draft counts as
        // issued (round 5 — the safe default). The cost is that such a draft
        // offers no permanent delete; the restore result says how many.
        $decoded = $details !== null ? json_decode($details, true) : null;
        if (!(is_array($decoded) && array_key_exists(Contractor::SNAPSHOT_KEY, $decoded))) {
            $this->issuedAssumed++;
        }
        return true;
    }

    /**
     * An archive from before migration 078: a restored draft that owns a
     * restored documents row (a test printed blank and reopened with „Doplniť
     * výsledky") was issued.
     */
    private function markIssuedFromDocuments(): void
    {
        // Every draft this run inserted that a manifest documents row names —
        // restored or not (round 5, (a)).
        $named = array_values(array_filter(
            array_keys($this->documentedInspectionIds),
            fn (int $id): bool => in_array($id, $this->insertedInspectionIds, true),
        ));
        // One UPDATE per entry by its primary key, so no row of the account
        // other than the ones this restore inserted is locked (round 7).
        $mark = $this->pdo->prepare(
            "UPDATE inspections SET issued_before = 1 WHERE id = ? AND account_id = ? AND status = 'draft'"
        );
        foreach ($named as $id) {
            $mark->execute([$id, $this->accountId]);
        }
        foreach (array_chunk($this->issuedUnknownIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $documented = $this->pdo->prepare(
                "SELECT DISTINCT parent_id FROM documents
                 WHERE  account_id = ? AND parent_type = 'inspection' AND parent_id IN ($in)"
            );
            $documented->execute([$this->accountId, ...$chunk]);
            $set = $this->pdo->prepare('UPDATE inspections SET issued_before = 1 WHERE id = ? AND account_id = ?');
            foreach ($documented->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $set->execute([(int) $id, $this->accountId]);
            }
        }
    }

    /**
     * The book number when neither an entry nor a document of this account
     * holds it yet, else null (a documents row with it would be skipped, and
     * the entry would stay finalized without its protocol — round 6).
     */
    private function freeBookNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inspections WHERE account_id = ? AND book_number = ?
             UNION ALL
             SELECT 1 FROM documents WHERE account_id = ? AND number = ?'
        );
        $stmt->execute([$this->accountId, $number, $this->accountId, $number]);
        return $stmt->fetchColumn() ? null : $number;
    }

    /** @param array<string, mixed> $row */
    private function status(array $row): string
    {
        return ($row['status'] ?? '') === 'finalized' ? 'finalized' : 'draft';
    }

    /**
     * Structured columns win; a v1 export only carried the combined string,
     * which {@see Address::parse} splits the same way every write does.
     *
     * @param array<string, mixed> $row
     * @return array{street: ?string, postal_code: ?string, city: ?string}
     */
    private function address(array $row): array
    {
        return Address::resolve(
            $this->str($row, 'street'),
            $this->str($row, 'postal_code'),
            $this->str($row, 'city'),
            $this->str($row, 'address'),
        );
    }

    /** @param array<string, mixed> $row */
    private function date(array $row, string $key): ?string
    {
        $value = $this->str($row, $key);
        if ($value === null) {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /**
     * `created_at` is NOT NULL on every table here, and a v1 export carried it
     * only on the top-level records — so a missing value has to resolve to
     * something rather than blowing up the insert.
     *
     * The fallback is the record's own date where it has one, never `now()`,
     * because for inspections and trainings this value is half of the merge
     * key: a wall-clock fallback would make the same file restore as new
     * records every time it is re-uploaded.
     *
     * @param array<string, mixed> $row
     * @param ?string $fallbackDate Y-m-d to fall back to (the record's own date)
     */
    private function createdAt(array $row, ?string $fallbackDate = null): string
    {
        $value = $this->dateTime($row, 'created_at');
        if ($value !== null) {
            return $value;
        }
        if ($fallbackDate !== null) {
            return $fallbackDate . ' 00:00:00';
        }
        return date('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function dateTime(array $row, string $key): ?string
    {
        $value = $this->str($row, $key);
        if ($value === null) {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * The manifest carries ISO-8601 while MySQL hands back `Y-m-d H:i:s`;
     * both have to reduce to the same string or merge would never match.
     */
    private static function normalizeDateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : $value;
    }
}
