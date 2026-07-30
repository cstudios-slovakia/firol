<?php

declare(strict_types=1);

namespace Firol\Backup;

use Firol\Db;
use Firol\Documents\NumberAllocator;
use Firol\Storage\Storage;
use Firol\Support\AccountPurge;
use Firol\Support\Address;
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
    ];

    /** @var array<string, int> */
    private array $skipped = [
        'companies'   => 0,
        'facilities'  => 0,
        'inspections' => 0,
        'trainings'   => 0,
        'documents'   => 0,
    ];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, int> old company id → new */
    private array $companyMap = [];
    /** @var array<int, int> old facility id → new */
    private array $facilityMap = [];
    /** @var array<int, int> old inspection id → new */
    private array $inspectionMap = [];
    /** @var array<int, int> old training id → new */
    private array $trainingMap = [];
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
     * @return array{restored: array<string,int>, skipped: array<string,int>, warnings: list<string>}
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
     * @return array{restored: array<string,int>, skipped: array<string,int>, warnings: list<string>}
     */
    private function restore(array $manifest, string $mode): array
    {
        $this->userMap = $this->loadUserMap();

        // Purging runs before the transaction: it deletes files off disk, which
        // no rollback can undo, so there is nothing to gain from holding it
        // inside one — and a great deal of lock contention to lose.
        if ($mode === self::MODE_REPLACE) {
            AccountPurge::everything($this->accountId);
        }

        $this->pdo->beginTransaction();
        try {
            $this->restoreCompanies($this->list($manifest, 'companies'));
            $this->restoreInspections($this->list($manifest, 'inspections'));
            $this->restoreTrainings($this->list($manifest, 'trainings'));
            $this->restoreDocuments($this->list($manifest, 'documents'));
            $this->relinkFollowUps($this->list($manifest, 'inspections'));

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

        return [
            'restored' => $this->restored,
            'skipped'  => $this->skipped,
            'warnings' => $this->warnings,
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
                (account_id, name, ico, street, postal_code, city, contact, contact_email, approver, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertFacility = $this->pdo->prepare(
            'INSERT INTO facilities
                (account_id, company_id, name, street, postal_code, city, contact_person, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

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
            } else {
                $insertCompany->execute([
                    $this->accountId,
                    $name,
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
                    $this->str($facility, 'contact_person'),
                    $this->str($facility, 'notes'),
                    $this->createdAt($facility),
                ]);
                $facilityId = (int) $this->pdo->lastInsertId();
                $existingFacilities[$facilityKey] = $facilityId;
                $this->facilityMap[(int) ($facility['id'] ?? 0)] = $facilityId;
                $this->restored['facilities']++;
            }
        }
    }

    // ── Inspections, items, photos ───────────────────────────────────────────

    /** @param list<array<string, mixed>> $inspections */
    private function restoreInspections(array $inspections): void
    {
        $existing = $this->loadInspectionKeys();

        $insert = $this->pdo->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, type, periodicity_months,
                 is_preventive_inspection, executed_on, inspector_user_id,
                 effective_inspector_user_id, effective_cert_number,
                 status, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                $this->inspectionMap[$oldId] = $existing[$key];
                $this->skipped['inspections']++;
                continue;
            }

            $insert->execute([
                $this->accountId,
                $companyId,
                $facilityId,
                $type,
                (int) ($inspection['periodicity_months'] ?? 12),
                (int) ($inspection['is_preventive_inspection'] ?? 1),
                $executedOn,
                $this->user($inspection, 'inspector_email') ?? $this->userId,
                $this->user($inspection, 'effective_inspector_email'),
                $this->str($inspection, 'effective_cert_number'),
                $this->status($inspection),
                $this->str($inspection, 'notes'),
                $createdAt,
            ]);
            $inspectionId = (int) $this->pdo->lastInsertId();
            $this->inspectionMap[$oldId] = $inspectionId;
            $existing[$key] = $inspectionId;
            $this->restored['inspections']++;

            foreach ($this->list($inspection, 'items') as $item) {
                $insertItem->execute([
                    $inspectionId,
                    (int) ($item['position'] ?? 0),
                    (string) json_encode($item['fields'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                    $this->createdAt($item),
                ]);
                $itemId = (int) $this->pdo->lastInsertId();
                $this->restored['items']++;

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
            if ($newId === null || $newSource === null) {
                continue;
            }
            $update->execute([$newSource, $newId, $this->accountId]);
        }
    }

    // ── Trainings & trainees ─────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $trainings */
    private function restoreTrainings(array $trainings): void
    {
        $existing = $this->loadTrainingKeys();

        $insert = $this->pdo->prepare(
            'INSERT INTO trainings
                (account_id, company_id, facility_id, type, date, trainer_id,
                 topics, duration_min, fields, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertTrainee = $this->pdo->prepare(
            'INSERT INTO trainees
                (training_id, fullname, position, signature_path, signed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
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
                $this->user($training, 'trainer_email'),
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
                (account_id, parent_type, parent_id, type, number, file_path,
                 generated_at, signed, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        /** @var array<string, int> highest sequence seen per "type|year" */
        $sequences = [];

        foreach ($documents as $document) {
            $number     = $this->str($document, 'number');
            $parentType = (string) ($document['parent_type'] ?? '');
            $entry      = isset($document['file']) ? (string) $document['file'] : null;
            if ($number === null || $entry === null) {
                continue;
            }
            if (isset($existing[$number])) {
                $this->skipped['documents']++;
                continue;
            }

            $parentId = match ($parentType) {
                'inspection' => $this->inspectionMap[(int) ($document['parent_id'] ?? 0)] ?? null,
                'training'   => $this->trainingMap[(int) ($document['parent_id'] ?? 0)] ?? null,
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
