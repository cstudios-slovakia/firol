<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\DocumentController;
use Firol\Db;
use Firol\Documents\NumberAllocator;
use Firol\Pdf\PdfRenderer;
use Firol\Storage\Storage;

/**
 * Closing a preventívna prehliadka (spec 6.7, 9.5a, plan phase 8a).
 *
 * One transaction yields everything at once:
 *
 *   PO chosen    the Záznam o vykonaní preventívnej protipožiarnej prehliadky
 *                (PPP-RRRR-NNN), issued from the prehliadka itself;
 *   BOZP chosen  a child `kniha_bozp` inspection (`source_inspection_id` = the
 *                prehliadka) filled from the walk-through and issued
 *                (BOZP-RRRR-NNN) — the zápis do knihy BOZP IS the BOZP record,
 *                there is no separate document — and a BOZP book entry, always;
 *   PO book      an entry in the požiarna kniha when `book_entry` is on, carrying
 *                the PO defects (`inspection_ids`) so its print has the table;
 *   BOZP only    no PP record: the prehliadka closes with no document of its own;
 *   routine      one concept (draft úkon) per riadna kontrola ticked in step 1,
 *                see {@see PrehliadkaConcepts} (spec 7.3).
 *
 * Re-closing after „Upraviť" issues new numbers (the old ones are never reused),
 * refills the same BOZP child, and brings the entries written by the first close
 * up to date while the vedúci zamestnanec has not signed them — a signed one
 * keeps what was signed and gets a dopísanie with the new number.
 *
 * Nothing is half done: the numbers, the PDFs written to disk, the documents
 * rows, the entries and the finalized status all happen inside one transaction
 * and the files are removed again when it fails.
 */
final class PrehliadkaCloser
{
    /**
     * @return array{ok: true, po_document_id: ?int, bozp_inspection_id: ?int, bozp_document_id: ?int, concepts: list<array{inspection_id: int, type: string, state: string}>}
     *       | array{error: string, status: int}
     */
    public static function close(int $accountId, int $userId, int $inspectionId, bool $includePhotos): array
    {
        $pdo = Db::pdo();
        $inspection = DocumentController::loadInspectionForGenerate($accountId, $inspectionId);

        if ((string) $inspection['type'] !== PrehliadkaDetails::TYPE) {
            return ['error' => 'Toto nie je preventívna prehliadka.', 'status' => 422];
        }
        if ($inspection['status'] === 'finalized') {
            return ['error' => 'Prehliadka je už uzavretá.', 'status' => 409];
        }
        if ($inspection['executed_on'] === null) {
            return ['error' => 'Doplň dátum prehliadky.', 'status' => 422];
        }

        $rawDetails = InspectionDetails::decode($inspection['details'] ?? null) ?? [];
        try {
            $details = PrehliadkaDetails::validate($rawDetails);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage(), 'status' => 422];
        }
        if ($details['handed_over'] === null) {
            return ['error' => 'Doplň, kto bol oboznámený s výsledkom prehliadky.', 'status' => 422];
        }

        $lehotaZakon = (int) ($details['lehota_zakon'] ?? 12);
        $lehotaVlastna = isset($details['lehota_vlastna']) && $details['lehota_vlastna'] !== null ? (int) $details['lehota_vlastna'] : null;
        $effectiveMonths = ($lehotaVlastna !== null && $lehotaVlastna > 0) ? $lehotaVlastna : $lehotaZakon;
        $isCustom = ($lehotaVlastna !== null && $lehotaVlastna > 0) ? 1 : 0;

        $po = $details['scope']['po'] === true;
        $bozp = $details['scope']['bozp'] === true;

        // Each record is signed under the technician's own oprávnenie, as on creation.
        $cert = $pdo->prepare('SELECT cert_general, cert_bt FROM inspector_profiles WHERE user_id = ? AND account_id = ?');
        $cert->execute([(int) $inspection['inspector_user_id'], $accountId]);
        $certs = $cert->fetch() ?: [];
        $has = static fn (mixed $v): bool => is_string($v) && trim($v) !== '';
        if ($po && !$has($certs['cert_general'] ?? null)) {
            return ['error' => 'Protipožiarna prehliadka vyžaduje platné číslo oprávnenia Technik PO. Doplň ho v Profile technika.', 'status' => 422];
        }
        if ($bozp && !$has($certs['cert_bt'] ?? null)) {
            return ['error' => 'Prehliadka BOZP vyžaduje číslo oprávnenia bezpečnostného technika. Doplň ho v Profile technika.', 'status' => 422];
        }

        $items = DocumentController::loadItems($inspectionId);
        $facts = PrehliadkaTerms::forFacility($accountId, (int) $inspection['facility_id'], (string) $inspection['executed_on']);
        $previous = PrehliadkaPrevious::forFacility($accountId, (int) $inspection['facility_id'], $inspectionId);
        $company = BookLedger::company($accountId, (int) $inspection['company_id']);
        if ($company === null || $company['archived_at'] !== null) {
            return ['error' => 'Firma je archivovaná — prehliadku už nemožno uzavrieť.', 'status' => 409];
        }

        $files = [];
        $staleFiles = [];
        $concepts = [];
        $pdo->beginTransaction();
        try {
            // Two closes at once (a double tap, a queued retry) must not both issue numbers.
            $lock = $pdo->prepare('SELECT status FROM inspections WHERE id = ? AND account_id = ? FOR UPDATE');
            $lock->execute([$inspectionId, $accountId]);
            if ($lock->fetchColumn() !== 'draft') {
                $pdo->rollBack();
                return ['error' => 'Prehliadka je už uzavretá.', 'status' => 409];
            }

            $pdo->prepare('UPDATE inspections SET periodicity_value = ?, periodicity_unit = "mesiac", periodicity_is_custom = ? WHERE id = ? AND account_id = ?')
                ->execute([$effectiveMonths, $isCustom, $inspectionId, $accountId]);

            $year = (int) substr((string) $inspection['executed_on'], 0, 4);
            $bozpInspectionId = null;
            $bozpDocumentId = null;

            // ── BOZP: the child record (Preventívna prehliadka BOZP) ─────────
            if ($bozp) {
                $bozpPoints = PrehliadkaRecord::points($items, PrehliadkaRecord::BOZP, PrehliadkaRecord::photoCounts($inspectionId));
                $bozpPrevious = PrehliadkaRecord::previousRows($details, $previous, PrehliadkaRecord::BOZP, $bozpPoints);
                $fields = PrehliadkaRecord::bozpFields($inspection, $details, $bozpPrevious, $bozpPoints);

                $bozpInspectionId = self::upsertBozpChild($accountId, $userId, $inspection, $fields, $effectiveMonths, $isCustom);
                $child = DocumentController::loadInspectionForGenerate($accountId, $bozpInspectionId);
                $childItems = DocumentController::loadItems($bozpInspectionId);

                $number = NumberAllocator::allocate($accountId, 'kniha_bozp', $year)['number'];
                // BOZP inspection produces its standalone record and never creates a book_entry (spec 02.11 / decision D4)

                $terms = ClientTerms::forPrehliadka(
                    $accountId,
                    (int) $inspection['company_id'],
                    (int) $inspection['facility_id'],
                    (string) $inspection['executed_on'],
                    BookMapping::BOZP,
                    PrehliadkaRecord::autoTerms($inspection, $details, PrehliadkaRecord::BOZP, $facts),
                );
                // Frozen on the item, where a re-render for the client's signature reads it.
                $frozenFields = (array) $childItems[0]['fields'];
                $frozenFields[ClientTerms::SNAPSHOT_KEY] = $terms;
                $pdo->prepare('UPDATE inspection_items SET fields = ? WHERE id = ? AND inspection_id = ?')
                    ->execute([json_encode($frozenFields, JSON_UNESCAPED_UNICODE), (int) $childItems[0]['id'], $bozpInspectionId]);
                $childItems[0]['fields'] = $frozenFields;

                $payload = DocumentController::buildPayload($accountId, $userId, $child, $childItems);
                $payload['client_terms'] = $terms;
                $payload['company']['approver'] = PrehliadkaRecord::signerName((array) $details['handed_over']);
                $payload['photos'] = $includePhotos
                    ? PrehliadkaRecord::photoAppendix($inspectionId, PrehliadkaRecord::BOZP, $items)
                    : [];

                $bozpDocumentId = self::issue($accountId, $child, 'kniha_bozp', $number, $year, $payload, $includePhotos, $files);
            } else {
                // The prehliadka was BOZP once and is not any more: its reopened
                // BOZP record would otherwise stay behind as a draft nobody asked for.
                $pdo->prepare(
                    'UPDATE inspections SET archived_at = NOW()
                     WHERE  account_id = ? AND source_inspection_id = ? AND type = "kniha_bozp"
                       AND  status = "draft" AND archived_at IS NULL'
                )->execute([$accountId, $inspectionId]);
            }

            // ── PO: the record and the book entry ────────────────────────────
            $poDocumentId = null;
            if ($po) {
                $poPoints = PrehliadkaRecord::points($items, PrehliadkaRecord::PO);
                $number = NumberAllocator::allocate($accountId, PrehliadkaDetails::TYPE, $year)['number'];

                $bookSeq = null;
                if ($details['book_entry'] === true) {
                    $entryId = self::writeEntry(
                        $accountId, $userId, $inspection, BookMapping::PO, $inspectionId, $number,
                        BookSentences::prehliadka(BookMapping::PO, (string) $inspection['executed_on'], (string) $inspection['facility_name'], $number, count($poPoints)),
                        // Spec 9.7 as the plan reads it: no defect → the entry prints ZISTENIA.
                        $poPoints === [],
                    );
                    $seq = $pdo->prepare('SELECT seq FROM book_entries WHERE id = ?');
                    $seq->execute([$entryId]);
                    $bookSeq = (int) $seq->fetchColumn();
                }

                $payload = DocumentController::buildPayload($accountId, $userId, $inspection, $items);
                $block = PrehliadkaRecord::poBlock($accountId, $inspection, $items, $details, $includePhotos, null, $bookSeq);
                $issuedCopy = $block['_issued'];
                unset($block['_issued']);
                $payload['prehliadka'] = $block;
                $payload['photos'] = $includePhotos
                    ? PrehliadkaRecord::photoAppendix($inspectionId, PrehliadkaRecord::PO, $items)
                    : [];

                $poDocumentId = self::issue($accountId, $inspection, PrehliadkaDetails::TYPE, $number, $year, $payload, $includePhotos, $files);
                PrehliadkaRecord::freeze($inspectionId, $issuedCopy);
            } else {
                // BOZP only: nothing is issued from the prehliadka itself. It is
                // finalized so it can be found again, and — having no PO record —
                // it advances no fire-protection term (spec 8a.6).
                $pdo->prepare('UPDATE inspections SET status = "finalized" WHERE id = ? AND account_id = ?')
                    ->execute([$inspectionId, $accountId]);
            }
            $pdo->prepare('UPDATE inspections SET is_preventive_inspection = ? WHERE id = ? AND account_id = ?')
                ->execute([$po ? 1 : 0, $inspectionId, $accountId]);

            // ── Koncepty riadnych kontrol (spec 7.3) ─────────────────────────
            $concepts = PrehliadkaConcepts::build($accountId, $userId, $inspection, $details, $items, $files, $staleFiles);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($files as $abs) {
                if (is_file($abs) && !@unlink($abs)) {
                    error_log('[close-prehliadka] orphan PDF cleanup failed: ' . $abs);
                }
            }
            error_log('[close-prehliadka] ' . $e::class . ': ' . $e->getMessage());
            return ['error' => 'Prehliadku sa nepodarilo uzavrieť.', 'status' => 500];
        }

        // Photos of a concept that this close replaced: the rows are gone with the commit.
        foreach ($staleFiles as $abs) {
            if (is_file($abs) && !@unlink($abs)) {
                error_log('[close-prehliadka] stale concept photo cleanup failed: ' . $abs);
            }
        }

        return [
            'ok'                 => true,
            'po_document_id'     => $poDocumentId,
            'bozp_inspection_id' => $bozpInspectionId,
            'bozp_document_id'   => $bozpDocumentId,
            'concepts'           => $concepts,
        ];
    }

    /**
     * The kniha BOZP child of a prehliadka: created on the first close, refilled
     * (same row, same item) on every later one.
     *
     * @param array<string, mixed> $inspection the prehliadka row
     * @param array<string, mixed> $fields
     */
    private static function upsertBozpChild(
        int $accountId,
        int $userId,
        array $inspection,
        array $fields,
        int $periodicityValue,
        int $isCustom,
    ): int {
        $pdo = Db::pdo();
        $find = $pdo->prepare(
            'SELECT id FROM inspections
             WHERE  account_id = ? AND source_inspection_id = ? AND type = "kniha_bozp" AND archived_at IS NULL
             ORDER  BY id DESC LIMIT 1'
        );
        $find->execute([$accountId, (int) $inspection['id']]);
        $childId = $find->fetchColumn();

        $periodicityUnit = 'mesiac';

        if ($childId === false) {
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, visit_id, source_inspection_id, type,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     executed_on, inspector_user_id, status, is_preventive_inspection, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, "kniha_bozp", ?, ?, ?, ?, ?, "draft", 1, ?)'
            )->execute([
                $accountId,
                (int) $inspection['company_id'],
                (int) $inspection['facility_id'],
                self::visitOf((int) $inspection['id']),
                (int) $inspection['id'],
                $periodicityValue,
                $periodicityUnit,
                $isCustom,
                (string) $inspection['executed_on'],
                (int) $inspection['inspector_user_id'],
                $userId,
            ]);
            $childId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([Invoicing::companyMode((int) $inspection['company_id']), $childId]);
            $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, 1, ?)')
                ->execute([$childId, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
            return $childId;
        }

        $childId = (int) $childId;
        // The prehliadka may have moved to another date or periodicity while it was open.
        $pdo->prepare(
            'UPDATE inspections
             SET    executed_on = ?, periodicity_value = ?, periodicity_unit = ?, periodicity_is_custom = ?,
                    inspector_user_id = ?, status = "draft"
             WHERE  id = ? AND account_id = ?'
        )->execute([
            (string) $inspection['executed_on'],
            $periodicityValue,
            $periodicityUnit,
            $isCustom,
            (int) $inspection['inspector_user_id'],
            $childId,
            $accountId,
        ]);
        $item = $pdo->prepare('SELECT id FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC LIMIT 1');
        $item->execute([$childId]);
        $itemId = $item->fetchColumn();
        if ($itemId === false) {
            $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, 1, ?)')
                ->execute([$childId, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        } else {
            $pdo->prepare('UPDATE inspection_items SET fields = ? WHERE id = ?')
                ->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), (int) $itemId]);
        }
        return $childId;
    }

    private static function visitOf(int $inspectionId): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT visit_id FROM inspections WHERE id = ?');
        $stmt->execute([$inspectionId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (int) $v : null;
    }

    /**
     * The entry a record writes into its book — a new one, or, when this
     * prehliadka was closed before, the one it wrote then: refreshed while
     * unsigned, otherwise left alone and followed by a dopísanie with the new
     * number (the signed text is never edited, Q8).
     *
     * @param array<string, mixed> $inspection the prehliadka row
     * @return int id of the entry
     */
    private static function writeEntry(
        int $accountId,
        int $userId,
        array $inspection,
        string $book,
        int $recordInspectionId,
        string $number,
        string $text,
        bool $noDefects,
    ): int {
        $companyId = (int) $inspection['company_id'];
        $date = (string) $inspection['executed_on'];

        $existing = BookLedger::entryOfInspection($accountId, $companyId, $book, $recordInspectionId);
        if ($existing !== null) {
            if (!$existing['signed_manager']) {
                BookLedger::refreshUnsigned($accountId, $existing['id'], $date, $text, [$number], $noDefects);
            } else {
                BookLedger::addProtocolNumber($accountId, $existing['id'], $number);
                BookLedger::addAddition(
                    $accountId,
                    $existing['id'],
                    'Záznam z prehliadky bol po úprave vydaný znova pod číslom ' . $number . '.',
                    $userId,
                );
            }
            return $existing['id'];
        }

        $company = BookLedger::company($accountId, $companyId);
        $facilityId = $company !== null && $company['book_scope'] === BookLedger::SCOPE_PREVADZKA
            ? (int) $inspection['facility_id']
            : null;

        return BookLedger::create(
            $accountId, $companyId, $facilityId, $book, $date, $text, 'ukon',
            [$number], [$recordInspectionId], [], $noDefects, $userId,
        );
    }

    /**
     * Render, write and register one protocol — the persisting half of
     * {@see DocumentController::generateForInspectionInternal()}, without its own
     * transaction (the caller's covers several documents and the book entries).
     *
     * @param array<string, mixed> $inspection the record's row
     * @param array<string, mixed> $payload
     * @param list<string> $files absolute paths written, for cleanup on failure
     * @return int the documents row id
     */
    private static function issue(
        int $accountId,
        array $inspection,
        string $type,
        string $number,
        int $year,
        array $payload,
        bool $includePhotos,
        array &$files,
    ): int {
        $pdo = Db::pdo();
        $inspectionId = (int) $inspection['id'];

        $payload['number'] = $number;
        $payload['generated_at'] = date('c');
        $pdf = PdfRenderer::renderForType($type, $payload);

        $relPath = Storage::documentRelative($accountId, $year, $number);
        $absPath = Storage::documentAbsolute($relPath);
        Storage::ensureDir(dirname($absPath));
        if (file_put_contents($absPath, $pdf) === false) {
            throw new \RuntimeException('Failed to write PDF to storage.');
        }
        $files[] = $absPath;

        $pdo->prepare(
            'INSERT INTO documents
                (account_id, parent_type, parent_id, type, number, include_photos, file_path, signed, signed_at)
             VALUES (?, "inspection", ?, ?, ?, ?, ?, 1, NOW())'
        )->execute([$accountId, $inspectionId, $type, $number, $includePhotos ? 1 : 0, $relPath]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO document_versions (document_id, version, file_path) VALUES (?, 1, ?)')
            ->execute([$documentId, $relPath]);

        // The firm and the oprávnenie as printed, so a later re-render says the same.
        Contractor::freezeInspection($inspectionId, $inspection, $payload['contractor']);
        $pdo->prepare(
            'UPDATE inspections
             SET    status = "finalized",
                    effective_inspector_user_id = ?, effective_cert_number = ?,
                    effective_cert_valid_from = ?, effective_cert_valid_to = ?
             WHERE  id = ? AND account_id = ?'
        )->execute([
            $payload['_effective_inspector_user_id'] ?? null,
            $payload['_effective_cert_number'] ?? null,
            $payload['_effective_cert_valid_from'] ?? null,
            $payload['_effective_cert_valid_to'] ?? null,
            $inspectionId,
            $accountId,
        ]);
        return $documentId;
    }
}
