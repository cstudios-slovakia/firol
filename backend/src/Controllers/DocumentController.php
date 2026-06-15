<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Documents\NumberAllocator;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Mail\Mailer;
use Firol\Mail\Templates\DocumentEmail;
use Firol\Pdf\DocxRenderer;
use Firol\Pdf\PdfConverter;
use Firol\Pdf\PdfRenderer;
use Firol\Storage\Storage;
use Firol\Support\Address;
use Firol\Support\Documentation\DocumentationPayload;

/**
 * Generates PDF protocols and serves the stored binaries back. Generation
 * runs inside a transaction so the document number reservation, file
 * write, documents row insert and inspection finalization either all
 * succeed or none do — nothing leaves a half-formed protocol behind.
 */
final class DocumentController
{
    /**
     * Generate the PDF for an inspection. Inspection must:
     *   - belong to the active account
     *   - be in `draft` status (re-generation goes through Opakovať flow)
     *   - have at least one item
     *
     * On success: documents row inserted, file written, inspection
     * promoted to `finalized`, response includes the document descriptor.
     */
    public static function generateForInspection(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId    = Tenant::currentAccountId();
        $userId       = Tenant::currentUserId();
        $isAdmin      = Admin::isAdmin($userId);
        $inspectionId = (int) $params['id'];

        $inspection = self::loadInspectionForGenerate($isAdmin ? null : $accountId, $inspectionId);
        // PDF is filed under the inspection's own account, not the
        // (possibly impersonating) admin's session account.
        $accountId = (int) $inspection['account_id'];

        if ($inspection['status'] === 'finalized') {
            Response::error(
                'Inspection is already finalized. Use Opakovať to issue a fresh protocol.',
                409,
            );
        }

        $items = self::loadItems($inspectionId);
        if (count($items) === 0) {
            Response::error('Add at least one item before generating the PDF.', 422);
        }
        if ($inspection['executed_on'] === null) {
            Response::error('Inspection date is required before generating the PDF.', 422);
        }

        // Year for the sequence is taken from the inspection's executed_on
        // (not "now") so re-issuing in early January for a December
        // inspection still uses the correct year bucket.
        $year = (int) substr((string) $inspection['executed_on'], 0, 4);

        $payload = self::buildPayload($accountId, $userId, $inspection, $items);
        $stats   = $payload['stats'];
        $insType = (string) $inspection['type'];

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $allocated = NumberAllocator::allocate($accountId, $insType, $year);
            $payload['number'] = $allocated['number'];
            $payload['generated_at'] = date('c');

            $pdfBytes = PdfRenderer::renderForType($insType, $payload);

            $relPath = Storage::documentRelative($accountId, $year, $allocated['number']);
            $absPath = Storage::documentAbsolute($relPath);
            Storage::ensureDir(dirname($absPath));
            if (file_put_contents($absPath, $pdfBytes) === false) {
                throw new \RuntimeException('Failed to write PDF to storage.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO documents
                    (account_id, parent_type, parent_id, type, number,
                     file_path, signed, signed_at)
                 VALUES (?, "inspection", ?, ?, ?, ?, 1, NOW())'
            );
            $insert->execute([
                $accountId,
                $inspectionId,
                $inspection['type'],
                $allocated['number'],
                $relPath,
            ]);
            $documentId = (int) $pdo->lastInsertId();

            // Freeze the inspector identity + cert that ended up on the
            // PDF, so admin/list views show the borrowed cert exactly as
            // printed even if the default technician changes later.
            $pdo->prepare(
                'UPDATE inspections
                 SET    status = "finalized",
                        effective_inspector_user_id = ?,
                        effective_cert_number       = ?
                 WHERE  id = ? AND account_id = ?'
            )->execute([
                $payload['_effective_inspector_user_id'] ?? null,
                $payload['_effective_cert_number']       ?? null,
                $inspectionId,
                $accountId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            // Best-effort cleanup of an orphaned PDF if the rollback
            // happened after file_put_contents. If unlink fails (file
            // locked / permissions) the file leaks — log it loudly so
            // ops can run a cleanup pass instead of silently piling up.
            if (isset($absPath) && is_file($absPath)) {
                if (!@unlink($absPath)) {
                    error_log('[generate-pdf] orphan PDF cleanup failed: ' . $absPath);
                }
            }
            error_log('[generate-pdf] ' . $e::class . ': ' . $e->getMessage());
            Response::error('PDF generation failed.', 500);
        }

        Response::json([
            'document' => self::loadDocument($accountId, $documentId),
            'stats'    => $stats,
        ], 201);
    }

    /**
     * Send a generated PDF protocol to an arbitrary email address. The
     * document file is read from disk and attached to a short cover email
     * (subject: "Protokol <číslo> — <brand>"). Optional `note` lets the
     * technician add a free-form message that's shown in the email body.
     */
    public static function emailDocument(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $documentId = (int) $params['id'];

        $email = $req->jsonString('email');
        if ($email === null || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Zadaj platnú e-mailovú adresu.', 422);
        }

        $note = $req->jsonString('note');

        $doc = self::loadDocument($isAdmin ? null : $accountId, $documentId);
        if (!$doc) {
            Response::error('Document not found', 404);
        }
        if ($isAdmin) {
            $accountId = (int) $doc['account_id'];
        }

        $abs = Storage::documentAbsolute($doc['file_path']);
        if (!is_file($abs)) {
            error_log('[document-email] file missing: ' . $abs);
            Response::error('Súbor protokolu nie je k dispozícii.', 410);
        }

        $pdfBytes = file_get_contents($abs);
        if ($pdfBytes === false) {
            Response::error('Súbor protokolu sa nepodarilo načítať.', 500);
        }

        $accStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name FROM accounts WHERE id = ?'
        );
        $accStmt->execute([$accountId]);
        $brandName = (string) ($accStmt->fetchColumn() ?: 'Firol');

        $filename = ($doc['number'] ?? 'protocol') . '.pdf';

        $message = DocumentEmail::build(
            to:             $email,
            documentNumber: (string) ($doc['number'] ?? ''),
            brandName:      $brandName,
            pdfFilename:    $filename,
            pdfBytes:       $pdfBytes,
            note:           $note,
        );

        $sent = Mailer::send($message);
        if (!$sent) {
            Response::error('E-mail sa nepodarilo odoslať. Skús to neskôr.', 502);
        }

        Response::json(['sent' => true, 'to' => $email]);
    }

    public static function download(Request $req, array $params): void
    {
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $documentId = (int) $params['id'];

        $doc = self::loadDocument($isAdmin ? null : $accountId, $documentId);
        if (!$doc) {
            Response::error('Document not found', 404);
        }

        // Dokumentácia bundles ship in two formats; `?format=docx` serves
        // the editable Word file, anything else the canonical PDF.
        $wantDocx = $req->query('format') === 'docx';
        if ($wantDocx && empty($doc['docx_path'])) {
            Response::error('Document has no DOCX version.', 404);
        }

        $relPath  = $wantDocx ? $doc['docx_path'] : $doc['file_path'];
        $abs      = Storage::documentAbsolute($relPath);
        if (!is_file($abs)) {
            error_log('[document-download] file missing: ' . $abs);
            Response::error('Document file is missing on disk.', 410);
        }

        $ext      = $wantDocx ? 'docx' : 'pdf';
        $mime     = $wantDocx
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';
        $filename = ($doc['number'] ?? 'protocol') . '.' . $ext;
        // DOCX is meant to be saved/opened in Word, so force a download;
        // PDFs stay inline so the browser viewer can show them.
        $disposition = $wantDocx ? 'attachment' : 'inline';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=300');
        readfile($abs);
        exit;
    }

    /**
     * Generate the Dokumentácia PO bundle for a documentation record. The
     * form payload (stored as JSON on the record) is mapped to the
     * docxtemplater placeholders, the Word template is filled (DocxRenderer)
     * and converted to PDF (PdfConverter); both files are stored and a
     * documents row is inserted. Unlike inspections, regeneration is
     * allowed — each call issues a fresh DOK number and a new document, so
     * the technician can "edit and generate again" any time.
     */
    public static function generateForDocumentation(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $docId     = (int) $params['id'];

        $doc = self::loadDocumentationForGenerate($isAdmin ? null : $accountId, $docId);
        $accountId = (int) $doc['account_id'];

        if ($doc['issued_on'] === null) {
            Response::error('Doplň dátum vyhotovenia pred generovaním.', 422);
        }

        $data = json_decode((string) $doc['data'], true);
        $data = is_array($data) ? $data : [];

        // Konateľ údaje sú povinné (§5.2).
        foreach (['konatel_meno', 'konatel_priezvisko', 'konatel_tel'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                Response::error('Doplň údaje konateľa (meno, priezvisko, telefón).', 422);
            }
        }

        // Technik PO identity comes from the author's user profile (§5.1).
        $authorStmt = Db::pdo()->prepare('SELECT fullname, phone FROM users WHERE id = ?');
        $authorStmt->execute([(int) $doc['author_user_id']]);
        $author = $authorStmt->fetch() ?: ['fullname' => '', 'phone' => ''];

        $payload = DocumentationPayload::build($doc, $data, $author);

        $year = (int) substr((string) $doc['issued_on'], 0, 4);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $allocated = NumberAllocator::allocate($accountId, 'dokumentacia', $year);

            $docxBytes = DocxRenderer::render(Storage::documentationTemplatePath(), $payload);
            $pdfBytes  = PdfConverter::convert($docxBytes);

            $pdfRel  = Storage::documentRelative($accountId, $year, $allocated['number']);
            $docxRel = Storage::documentDocxRelative($accountId, $year, $allocated['number']);
            $pdfAbs  = Storage::documentAbsolute($pdfRel);
            $docxAbs = Storage::documentAbsolute($docxRel);
            Storage::ensureDir(dirname($pdfAbs));

            if (file_put_contents($docxAbs, $docxBytes) === false
                || file_put_contents($pdfAbs, $pdfBytes) === false) {
                throw new \RuntimeException('Failed to write documentation files to storage.');
            }

            $pdo->prepare(
                'INSERT INTO documents
                    (account_id, parent_type, parent_id, type, number,
                     file_path, docx_path, signed, signed_at)
                 VALUES (?, "documentation", ?, "dokumentacia", ?, ?, ?, 1, NOW())'
            )->execute([
                $accountId,
                $docId,
                $allocated['number'],
                $pdfRel,
                $docxRel,
            ]);
            $documentId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE documentations SET status = "finalized" WHERE id = ? AND account_id = ?'
            )->execute([$docId, $accountId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            foreach ([$pdfAbs ?? null, $docxAbs ?? null] as $orphan) {
                if (is_string($orphan) && is_file($orphan) && !@unlink($orphan)) {
                    error_log('[generate-doc] orphan cleanup failed: ' . $orphan);
                }
            }
            error_log('[generate-doc] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Dokumentáciu sa nepodarilo vygenerovať. Skontroluj nastavenie generátora (Node/konverzia).', 500);
        }

        Response::json([
            'document' => self::loadDocument($accountId, $documentId),
        ], 201);
    }

    public static function indexForDocumentation(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $docId     = (int) $params['id'];

        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT account_id FROM documentations WHERE id = ? AND archived_at IS NULL'
            );
            $check->execute([$docId]);
            $docAccountId = $check->fetchColumn();
            if ($docAccountId === false) {
                Response::error('Documentation not found', 404);
            }
            $accountId = (int) $docAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1 FROM documentations WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$docId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Documentation not found', 404);
            }
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, type, number, file_path, docx_path, generated_at, signed
             FROM   documents
             WHERE  account_id = ? AND parent_type = "documentation" AND parent_id = ?
             ORDER  BY generated_at DESC'
        );
        $stmt->execute([$accountId, $docId]);
        $items = array_map(static function (array $r): array {
            return [
                'id'                => (int) $r['id'],
                'type'              => $r['type'],
                'number'            => $r['number'],
                'generated_at'      => $r['generated_at'],
                'signed'            => (int) $r['signed'] === 1,
                'download_url'      => '/api/documents/' . (int) $r['id'] . '/download',
                'docx_download_url' => !empty($r['docx_path'])
                    ? '/api/documents/' . (int) $r['id'] . '/download?format=docx'
                    : null,
            ];
        }, $stmt->fetchAll());

        Response::json(['items' => $items]);
    }

    /** @return array<string, mixed> */
    private static function loadDocumentationForGenerate(?int $accountId, int $docId): array
    {
        $sql = 'SELECT d.id, d.account_id, d.company_id, d.facility_id, d.author_user_id,
                       d.issued_on, d.data, d.status,
                       c.name AS company_name, c.ico AS company_ico,
                       c.street AS company_street, c.postal_code AS company_postal_code, c.city AS company_city,
                       f.name AS facility_name,
                       f.street AS facility_street, f.postal_code AS facility_postal_code, f.city AS facility_city
                FROM   documentations d
                JOIN   companies  c ON c.id = d.company_id
                LEFT JOIN facilities f ON f.id = d.facility_id
                WHERE  d.id = ? AND d.archived_at IS NULL';
        $params = [$docId];
        if ($accountId !== null) {
            $sql .= ' AND d.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Documentation not found', 404);
        }
        return $row;
    }

    /** Lists documents for a single inspection (used by the UI to show download links). */
    /**
     * Generate the PDF protocol for a training. All trainings share the
     * SKO number prefix and a single per-account+year sequence regardless
     * of the training type (per spec — the type is recorded in the body).
     * Training must be a draft with at least one trainee and a chosen
     * trainer (otherwise the protocol can't be signed).
     */
    public static function generateForTraining(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];

        $training = self::loadTrainingForGenerate($isAdmin ? null : $accountId, $trainingId);
        $accountId = (int) $training['account_id'];

        if ($training['status'] === 'finalized') {
            Response::error(
                'Školenie už je uzamknuté a má vystavený PDF protokol.',
                409,
            );
        }
        if ($training['date'] === null) {
            Response::error('Doplň dátum školenia pred generovaním PDF.', 422);
        }
        if ($training['trainer_id'] === null) {
            Response::error('Vyber školiteľa pred generovaním PDF.', 422);
        }

        $trainees = self::loadTrainees($trainingId);
        if (count($trainees) === 0) {
            Response::error(
                'Pridaj aspoň jedného účastníka pred generovaním PDF.',
                422,
            );
        }

        $year = (int) substr((string) $training['date'], 0, 4);
        $payload = self::buildTrainingPayload($accountId, $training, $trainees);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // All training types share the SKO bucket; pass the literal
            // 'skolenie' slug as the sequence type.
            $allocated = NumberAllocator::allocate($accountId, 'skolenie', $year);
            $payload['number'] = $allocated['number'];
            $payload['generated_at'] = date('c');

            $pdfBytes = PdfRenderer::renderTraining($payload);

            $relPath = Storage::documentRelative($accountId, $year, $allocated['number']);
            $absPath = Storage::documentAbsolute($relPath);
            Storage::ensureDir(dirname($absPath));
            if (file_put_contents($absPath, $pdfBytes) === false) {
                throw new \RuntimeException('Failed to write PDF to storage.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO documents
                    (account_id, parent_type, parent_id, type, number,
                     file_path, signed, signed_at)
                 VALUES (?, "training", ?, "skolenie", ?, ?, 1, NOW())'
            );
            $insert->execute([
                $accountId,
                $trainingId,
                $allocated['number'],
                $relPath,
            ]);
            $documentId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE trainings SET status = "finalized" WHERE id = ? AND account_id = ?'
            )->execute([$trainingId, $accountId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (isset($absPath) && is_file($absPath)) {
                if (!@unlink($absPath)) {
                    error_log('[generate-pdf-training] orphan PDF cleanup failed: ' . $absPath);
                }
            }
            error_log('[generate-pdf-training] ' . $e::class . ': ' . $e->getMessage());
            Response::error('PDF sa nepodarilo vygenerovať.', 500);
        }

        Response::json([
            'document' => self::loadDocument($accountId, $documentId),
        ], 201);
    }

    public static function indexForTraining(Request $req, array $params): void
    {
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];

        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT account_id FROM trainings WHERE id = ? AND archived_at IS NULL'
            );
            $check->execute([$trainingId]);
            $trainingAccountId = $check->fetchColumn();
            if ($trainingAccountId === false) {
                Response::error('Training not found', 404);
            }
            $accountId = (int) $trainingAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1 FROM trainings WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$trainingId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Training not found', 404);
            }
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, type, number, file_path, generated_at, signed
             FROM   documents
             WHERE  account_id = ? AND parent_type = "training" AND parent_id = ?
             ORDER  BY generated_at DESC'
        );
        $stmt->execute([$accountId, $trainingId]);
        $rows = $stmt->fetchAll();
        $items = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'type'         => $r['type'],
                'number'       => $r['number'],
                'generated_at' => $r['generated_at'],
                'signed'       => (int) $r['signed'] === 1,
                'download_url' => '/api/documents/' . (int) $r['id'] . '/download',
            ];
        }, $rows);

        Response::json(['items' => $items]);
    }

    public static function indexForInspection(Request $req, array $params): void
    {
        $accountId    = Tenant::currentAccountId();
        $isAdmin      = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) $params['id'];

        // Make sure the parent inspection belongs to this tenant before
        // exposing any documents. Admins may inspect any account's data.
        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT account_id FROM inspections WHERE id = ? AND archived_at IS NULL'
            );
            $check->execute([$inspectionId]);
            $insAccountId = $check->fetchColumn();
            if ($insAccountId === false) {
                Response::error('Inspection not found', 404);
            }
            $accountId = (int) $insAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1 FROM inspections WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$inspectionId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Inspection not found', 404);
            }
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, type, number, file_path, generated_at, signed
             FROM   documents
             WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?
             ORDER  BY generated_at DESC'
        );
        $stmt->execute([$accountId, $inspectionId]);
        $rows = $stmt->fetchAll();
        $items = array_map(static function (array $r): array {
            return [
                'id'            => (int) $r['id'],
                'type'          => $r['type'],
                'number'        => $r['number'],
                'generated_at'  => $r['generated_at'],
                'signed'        => (int) $r['signed'] === 1,
                'download_url'  => '/api/documents/' . (int) $r['id'] . '/download',
            ];
        }, $rows);

        Response::json(['items' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadInspectionForGenerate(?int $accountId, int $inspectionId): array
    {
        $sql = 'SELECT i.id, i.account_id, i.type, i.periodicity_months, i.executed_on, i.status,
                       i.notes, i.inspector_user_id,
                       i.company_id, c.name AS company_name, c.ico AS company_ico,
                       c.street AS company_street, c.postal_code AS company_postal_code, c.city AS company_city,
                       i.facility_id, f.name AS facility_name,
                       f.street AS facility_street, f.postal_code AS facility_postal_code, f.city AS facility_city,
                       f.contact_person AS facility_contact_person
                FROM   inspections i
                JOIN   companies   c ON c.id = i.company_id
                JOIN   facilities  f ON f.id = i.facility_id
                WHERE  i.id = ? AND i.archived_at IS NULL';
        $params = [$inspectionId];
        if ($accountId !== null) {
            $sql .= ' AND i.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Inspection not found', 404);
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private static function loadItems(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, position, fields FROM inspection_items
             WHERE inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);
        $rows = $stmt->fetchAll();
        return array_map(static function (array $r): array {
            return [
                'id'       => (int) $r['id'],
                'position' => (int) $r['position'],
                'fields'   => json_decode((string) $r['fields'], true) ?? [],
            ];
        }, $rows);
    }

    /**
     * Assembles the renderer payload (and side-effect: computes stats).
     *
     * @param array<string, mixed> $inspection
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function buildPayload(
        int $accountId,
        int $userId,
        array $inspection,
        array $items,
    ): array {
        $inspectorUserId = (int) $inspection['inspector_user_id'];
        $insType         = (string) $inspection['type'];

        // Resolve effective inspector (who appears in the inspector block on
        // the PDF). For PHP / Oprava the executor can borrow the cert+name
        // of the account-default technician when they have no own number.
        // Technik PO types never fall back — store guard runs upstream.
        [$effectiveUserId, $profile] = self::resolveEffectiveInspector(
            $accountId,
            $inspectorUserId,
            $insType,
        );

        $userStmt = Db::pdo()->prepare('SELECT fullname FROM users WHERE id = ?');
        $userStmt->execute([$effectiveUserId]);
        $userRow = $userStmt->fetch();

        $signatureUri = self::signatureToDataUri($profile['signature_path'] ?? null);

        $accStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name, theme_color, logo_path FROM accounts WHERE id = ?'
        );
        $accStmt->execute([$accountId]);
        $accRow = $accStmt->fetch() ?: [];

        $stats = self::computeStats((string) $inspection['type'], $items);

        $certNumber = self::certForType($insType, $profile);

        return [
            'brand' => self::buildBrand($accRow),
            'inspection' => [
                'executed_on'        => $inspection['executed_on'],
                'periodicity_months' => (int) $inspection['periodicity_months'],
                'notes'              => $inspection['notes'],
                'status'             => $inspection['status'],
            ],
            'company' => [
                'name'    => $inspection['company_name'],
                'ico'     => $inspection['company_ico'],
                'address' => Address::format($inspection['company_street'], $inspection['company_postal_code'], $inspection['company_city']),
                'city'    => $inspection['company_city'],
            ],
            'facility' => [
                'name'           => $inspection['facility_name'],
                'address'        => Address::format($inspection['facility_street'], $inspection['facility_postal_code'], $inspection['facility_city']),
                'city'           => $inspection['facility_city'],
                'contact_person' => $inspection['facility_contact_person'],
            ],
            'inspector' => [
                'fullname'             => $userRow['fullname'] ?? '—',
                'certification_number' => $certNumber,
                ...self::validityForType($insType, $profile),
                'signature_data_uri'   => $signatureUri,
            ],
            'items' => $items,
            'stats' => $stats,
            // Internal — written back as a snapshot onto the inspection
            // row, never reaches the PDF templates.
            '_effective_inspector_user_id' => $effectiveUserId,
            '_effective_cert_number'       => $certNumber,
        ];
    }

    /**
     * Resolve which inspector identity ends up on the protocol.
     *
     * Borrowing rules:
     *   - php / oprava_ts_php: if the executing technician's own
     *     cert_php / cert_oprava is blank, fall back to the
     *     account-default technician (accounts.default_php_user_id /
     *     default_oprava_user_id). The borrowed identity replaces the
     *     executor in the inspector block (name + cert + signature).
     *   - All other types print cert_general (Technik PO) and never
     *     fall back; InspectionController::store guarantees the executor
     *     has an own cert_general for those types.
     *
     * @return array{0:int,1:array<string,mixed>}  [effective_user_id, profile_row]
     */
    private static function resolveEffectiveInspector(
        int $accountId,
        int $executorUserId,
        string $insType,
    ): array {
        $loadProfile = static function (int $uid) use ($accountId): array {
            $stmt = Db::pdo()->prepare(
                'SELECT signature_path, cert_php, cert_oprava, cert_general,
                        certification_number,
                        valid_from_php, valid_to_php,
                        valid_from_oprava, valid_to_oprava,
                        valid_from_general, valid_to_general,
                        valid_from, valid_to
                 FROM   inspector_profiles
                 WHERE  user_id = ? AND account_id = ?'
            );
            $stmt->execute([$uid, $accountId]);
            return $stmt->fetch() ?: [];
        };

        $executorProfile = $loadProfile($executorUserId);

        if ($insType !== 'php' && $insType !== 'oprava_ts_php') {
            return [$executorUserId, $executorProfile];
        }

        $certKey = $insType === 'php' ? 'cert_php' : 'cert_oprava';
        $own     = $executorProfile[$certKey] ?? null;
        if (is_string($own) && trim($own) !== '') {
            return [$executorUserId, $executorProfile];
        }

        $defaultCol = $insType === 'php' ? 'default_php_user_id' : 'default_oprava_user_id';
        $stmt = Db::pdo()->prepare('SELECT ' . $defaultCol . ' FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $defaultUserId = $stmt->fetchColumn();
        if (!$defaultUserId) {
            return [$executorUserId, $executorProfile];
        }
        $defaultUserId = (int) $defaultUserId;
        $defaultProfile = $loadProfile($defaultUserId);
        $defaultCert    = $defaultProfile[$certKey] ?? null;
        if (!is_string($defaultCert) || trim($defaultCert) === '') {
            return [$executorUserId, $executorProfile];
        }
        return [$defaultUserId, $defaultProfile];
    }

    /**
     * Per-type aggregate counts shown in the PDF stats row. PHP cares
     * about A/TS/O/V; pass-fail types (hydranty, PU, NO, TS-HAD) reduce
     * to vyhovuje/nevyhovuje. Always exposes `total` so templates can
     * print the headline number without knowing the type.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, int>
     */
    private static function computeStats(string $type, array $items): array
    {
        $stats = ['total' => count($items)];
        switch ($type) {
            case 'php':
                $stats += ['A' => 0, 'TS' => 0, 'O' => 0, 'V' => 0];
                foreach ($items as $it) {
                    $st = (string) ($it['fields']['status'] ?? '');
                    if (isset($stats[$st])) {
                        $stats[$st]++;
                    }
                }
                return $stats;
            case 'hydranty':
                $stats += ['vyhovuje' => 0, 'nevyhovuje' => 0];
                foreach ($items as $it) {
                    $r = (string) ($it['fields']['result'] ?? '');
                    if (isset($stats[$r])) {
                        $stats[$r]++;
                    }
                }
                return $stats;
            case 'oprava_ts_php':
                // Counts how many items had each action performed. An item
                // can contribute to multiple buckets (tlakova_skuska +
                // plnenie are typically combined on the same prístroj).
                $stats += ['tlakova_skuska' => 0, 'oprava' => 0, 'plnenie' => 0];
                foreach ($items as $it) {
                    $actions = $it['fields']['actions'] ?? [];
                    if (is_array($actions)) {
                        foreach ($actions as $a) {
                            if (isset($stats[$a])) {
                                $stats[$a]++;
                            }
                        }
                    }
                }
                return $stats;
            case 'poziarna_kniha':
                // Single-record protocol — total is always 0 or 1; the
                // overall result is what matters.
                $stats += ['result' => 'bez_nedostatkov'];
                $first = $items[0]['fields'] ?? [];
                $r = (string) ($first['result'] ?? 'bez_nedostatkov');
                if (in_array($r, ['bez_nedostatkov', 'zistene_nedostatky'], true)) {
                    $stats['result'] = $r;
                }
                return $stats;
            case 'pu_akcieschopnost':
            case 'pu_udrzba':
            case 'nudzove_osvetlenie':
            case 'ts_hadic':
                // All four types reduce to the shared pass/fail enum.
                $stats += ['vyhovuje' => 0, 'nevyhovuje' => 0];
                foreach ($items as $it) {
                    $r = (string) ($it['fields']['result'] ?? '');
                    if (isset($stats[$r])) {
                        $stats[$r]++;
                    }
                }
                return $stats;
        }
        return $stats;
    }

    private static function signatureToDataUri(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }
        $abs = Storage::documentAbsolute($relativePath);
        if (!is_file($abs)) {
            return null;
        }
        $bytes = file_get_contents($abs);
        if ($bytes === false) {
            return null;
        }
        // mPDF supports inline data URIs directly in <img src> — keeps the
        // signature in-document so we don't need to expose a public URL.
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    /**
     * Build the brand block (name + theme color + logo) from an account
     * row. Falls back to Firol defaults so the PDFs stay branded even
     * before the user uploads a logo or picks a custom color.
     *
     * Pick the right certification number for the given inspection type.
     * Falls back to the legacy certification_number column for profiles
     * that predate the three-column split.
     *
     * Mapping:
     *   php            → cert_php
     *   oprava_ts_php  → cert_oprava
     *   everything else → cert_general
     *
     * @param array<string, mixed> $profile inspector_profiles row
     */
    private static function certForType(string $type, array $profile): ?string
    {
        $key = match ($type) {
            'php'            => 'cert_php',
            'oprava_ts_php'  => 'cert_oprava',
            default          => 'cert_general',
        };
        $val = $profile[$key] ?? null;
        // Backward compat: fall back to the legacy single column
        if ($val === null || $val === '') {
            $val = $profile['certification_number'] ?? null;
        }
        return $val ?: null;
    }

    /**
     * Returns valid_from / valid_to for the given inspection type, preferring
     * per-cert columns and falling back to the legacy shared pair.
     *
     * @param array<string, mixed> $profile
     * @return array{valid_from: string|null, valid_to: string|null}
     */
    private static function validityForType(string $type, array $profile): array
    {
        [$fromKey, $toKey] = match ($type) {
            'php'            => ['valid_from_php',     'valid_to_php'],
            'oprava_ts_php'  => ['valid_from_oprava',  'valid_to_oprava'],
            default          => ['valid_from_general', 'valid_to_general'],
        };
        $from = $profile[$fromKey] ?? null;
        $to   = $profile[$toKey]   ?? null;
        // Backward compat: fall back to legacy single pair
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            $from = $profile['valid_from'] ?? null;
            $to   = $profile['valid_to']   ?? null;
        }
        return ['valid_from' => $from ?: null, 'valid_to' => $to ?: null];
    }

    /**
     * @param array<string, mixed> $accRow Result of SELECT invoice_company_name, theme_color, logo_path
     * @return array{name: string, color: string, logo_data_uri: string|null}
     */
    private static function buildBrand(array $accRow): array
    {
        $logoUri = null;
        $logoRel = $accRow['logo_path'] ?? null;
        if (is_string($logoRel) && $logoRel !== '') {
            $abs = Storage::documentAbsolute($logoRel);
            if (is_file($abs)) {
                $bytes = @file_get_contents($abs);
                if ($bytes !== false) {
                    $mime = str_ends_with($logoRel, '.jpg') ? 'image/jpeg' : 'image/png';
                    $logoUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            }
        }

        return [
            'name'          => $accRow['invoice_company_name'] ?? 'Firol',
            'color'         => $accRow['theme_color'] ?: '#E8433A',
            'logo_data_uri' => $logoUri,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadDocument(?int $accountId, int $documentId): ?array
    {
        $sql = 'SELECT id, account_id, parent_type, parent_id, type, number, file_path, docx_path,
                       generated_at, signed, signed_at
                FROM   documents
                WHERE  id = ?';
        $params = [$documentId];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $hasDocx = !empty($row['docx_path']);
        return [
            'id'                => (int) $row['id'],
            'account_id'        => (int) $row['account_id'],
            'parent_type'       => $row['parent_type'],
            'parent_id'         => (int) $row['parent_id'],
            'type'              => $row['type'],
            'number'            => $row['number'],
            'file_path'         => $row['file_path'],
            'docx_path'         => $row['docx_path'],
            'generated_at'      => $row['generated_at'],
            'signed'            => (int) $row['signed'] === 1,
            'signed_at'         => $row['signed_at'],
            'download_url'      => '/api/documents/' . (int) $row['id'] . '/download',
            'docx_download_url' => $hasDocx
                ? '/api/documents/' . (int) $row['id'] . '/download?format=docx'
                : null,
        ];
    }

    /** Spec-locked Slovak labels for the 6 training types. */
    private const TRAINING_TYPE_LABELS = [
        'vstupne'      => 'Vstupné školenie vedúcich a ostatných zamestnancov',
        'opakovane'    => 'Opakované školenie vedúcich a ostatných zamestnancov',
        'opp_mimo'     => 'Školenie osôb zabezpečujúcich OPP v mimopracovnom čase',
        'zdrzujuca_sa' => 'Školenie osôb zdržujúcich sa na pracovisku',
        'hliadka_oph'  => 'Odborná príprava protipožiarnej hliadky pracoviska',
        'hliadka_opah' => 'Odborná príprava protipožiarnej asistenčnej hliadky',
    ];

    /** @return array<string, mixed> */
    private static function loadTrainingForGenerate(?int $accountId, int $trainingId): array
    {
        $sql = 'SELECT t.id, t.account_id, t.type, t.date, t.duration_min, t.topics, t.status,
                       t.company_id, c.name AS company_name, c.ico AS company_ico,
                       c.street AS company_street, c.postal_code AS company_postal_code, c.city AS company_city,
                       t.facility_id, f.name AS facility_name,
                       f.street AS facility_street, f.postal_code AS facility_postal_code, f.city AS facility_city,
                       t.trainer_id, tr.fullname AS trainer_name,
                       ip.cert_general   AS trainer_certification_number,
                       ip.signature_path AS trainer_signature_path
                FROM   trainings t
                JOIN   companies  c  ON c.id = t.company_id
                LEFT JOIN facilities f  ON f.id = t.facility_id
                LEFT JOIN users      tr ON tr.id = t.trainer_id
                LEFT JOIN inspector_profiles ip
                       ON ip.user_id = t.trainer_id AND ip.account_id = t.account_id
                WHERE  t.id = ? AND t.archived_at IS NULL';
        $params = [$trainingId];
        if ($accountId !== null) {
            $sql .= ' AND t.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Training not found', 404);
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private static function loadTrainees(int $trainingId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fullname, position, signature_path, signed_at
             FROM   trainees WHERE training_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$trainingId]);
        $rows = $stmt->fetchAll();
        return array_map(static function (array $r): array {
            return [
                'id'             => (int) $r['id'],
                'fullname'       => $r['fullname'],
                'position'       => $r['position'],
                'signature_path' => $r['signature_path'],
                'signed_at'      => $r['signed_at'],
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed>            $training
     * @param list<array<string, mixed>>       $trainees
     * @return array<string, mixed>
     */
    private static function buildTrainingPayload(
        int $accountId,
        array $training,
        array $trainees,
    ): array {
        $accStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name, theme_color, logo_path FROM accounts WHERE id = ?'
        );
        $accStmt->execute([$accountId]);
        $accRow = $accStmt->fetch() ?: [];

        $trainerSignatureUri = self::signatureToDataUri(
            $training['trainer_signature_path'] ?? null,
        );

        $traineesPayload = array_map(static function (array $r): array {
            return [
                'id'                 => (int) $r['id'],
                'fullname'           => $r['fullname'],
                'position'           => $r['position'],
                'signature_data_uri' => self::signatureToDataUri($r['signature_path'] ?? null),
                'signed_at'          => $r['signed_at'],
            ];
        }, $trainees);

        $type = (string) $training['type'];

        return [
            'brand' => self::buildBrand($accRow),
            'training' => [
                'type'                => $type,
                'training_type_label' => self::TRAINING_TYPE_LABELS[$type] ?? $type,
                'date'                => $training['date'],
                'duration_min'        => $training['duration_min'],
                'topics'              => $training['topics'],
                'status'              => $training['status'],
            ],
            'company' => [
                'name'    => $training['company_name'],
                'ico'     => $training['company_ico'],
                'address' => Address::format($training['company_street'], $training['company_postal_code'], $training['company_city']),
                'city'    => $training['company_city'],
            ],
            'facility' => [
                'name'    => $training['facility_name'],
                'address' => Address::format($training['facility_street'], $training['facility_postal_code'], $training['facility_city']),
                'city'    => $training['facility_city'],
            ],
            'trainer' => [
                'fullname'             => $training['trainer_name'],
                'certification_number' => $training['trainer_certification_number'],
                'signature_data_uri'   => $trainerSignatureUri,
            ],
            'trainees' => $traineesPayload,
        ];
    }
}
