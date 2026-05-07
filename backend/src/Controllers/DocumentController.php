<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Documents\NumberAllocator;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Pdf\PdfRenderer;
use Firol\Storage\Storage;

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
        $inspectionId = (int) $params['id'];

        $inspection = self::loadInspectionForGenerate($accountId, $inspectionId);

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

            $pdo->prepare(
                'UPDATE inspections SET status = "finalized" WHERE id = ? AND account_id = ?'
            )->execute([$inspectionId, $accountId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            // Best-effort cleanup of an orphaned PDF if the rollback
            // happened after file_put_contents.
            if (isset($absPath) && is_file($absPath)) {
                @unlink($absPath);
            }
            error_log('[generate-pdf] ' . $e::class . ': ' . $e->getMessage());
            Response::error('PDF generation failed.', 500);
        }

        Response::json([
            'document' => self::loadDocument($accountId, $documentId),
            'stats'    => $stats,
        ], 201);
    }

    public static function download(Request $req, array $params): void
    {
        $accountId  = Tenant::currentAccountId();
        $documentId = (int) $params['id'];

        $doc = self::loadDocument($accountId, $documentId);
        if (!$doc) {
            Response::error('Document not found', 404);
        }

        $abs = Storage::documentAbsolute($doc['file_path']);
        if (!is_file($abs)) {
            error_log('[document-download] file missing: ' . $abs);
            Response::error('Document file is missing on disk.', 410);
        }

        $filename = ($doc['number'] ?? 'protocol') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=300');
        readfile($abs);
        exit;
    }

    /** Lists documents for a single inspection (used by the UI to show download links). */
    public static function indexForInspection(Request $req, array $params): void
    {
        $accountId    = Tenant::currentAccountId();
        $inspectionId = (int) $params['id'];

        // Make sure the parent inspection belongs to this tenant before
        // exposing any documents.
        $check = Db::pdo()->prepare(
            'SELECT 1 FROM inspections WHERE id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $check->execute([$inspectionId, $accountId]);
        if ($check->fetchColumn() === false) {
            Response::error('Inspection not found', 404);
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
    private static function loadInspectionForGenerate(int $accountId, int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.id, i.type, i.periodicity_months, i.executed_on, i.status,
                    i.notes, i.inspector_user_id,
                    i.company_id, c.name AS company_name, c.ico AS company_ico,
                    c.address AS company_address,
                    i.facility_id, f.name AS facility_name, f.address AS facility_address,
                    f.contact_person AS facility_contact_person
             FROM   inspections i
             JOIN   companies   c ON c.id = i.company_id
             JOIN   facilities  f ON f.id = i.facility_id
             WHERE  i.id = ? AND i.account_id = ? AND i.archived_at IS NULL'
        );
        $stmt->execute([$inspectionId, $accountId]);
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

        $userStmt = Db::pdo()->prepare(
            'SELECT fullname FROM users WHERE id = ?'
        );
        $userStmt->execute([$inspectorUserId]);
        $userRow = $userStmt->fetch();

        $profStmt = Db::pdo()->prepare(
            'SELECT signature_path, certification_number, valid_from, valid_to
             FROM   inspector_profiles
             WHERE  user_id = ? AND account_id = ?'
        );
        $profStmt->execute([$inspectorUserId, $accountId]);
        $profile = $profStmt->fetch() ?: [];

        $signatureUri = self::signatureToDataUri($profile['signature_path'] ?? null);

        $accStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name FROM accounts WHERE id = ?'
        );
        $accStmt->execute([$accountId]);
        $accRow = $accStmt->fetch() ?: [];

        $stats = self::computeStats((string) $inspection['type'], $items);

        return [
            'brand' => [
                'name'  => $accRow['invoice_company_name'] ?? 'Firol',
                'color' => '#E8433A',
            ],
            'inspection' => [
                'executed_on'        => $inspection['executed_on'],
                'periodicity_months' => (int) $inspection['periodicity_months'],
                'notes'              => $inspection['notes'],
                'status'             => $inspection['status'],
            ],
            'company' => [
                'name'    => $inspection['company_name'],
                'ico'     => $inspection['company_ico'],
                'address' => $inspection['company_address'],
            ],
            'facility' => [
                'name'           => $inspection['facility_name'],
                'address'        => $inspection['facility_address'],
                'contact_person' => $inspection['facility_contact_person'],
            ],
            'inspector' => [
                'fullname'             => $userRow['fullname'] ?? '—',
                'certification_number' => $profile['certification_number'] ?? null,
                'valid_from'           => $profile['valid_from'] ?? null,
                'valid_to'             => $profile['valid_to'] ?? null,
                'signature_data_uri'   => $signatureUri,
            ],
            'items' => $items,
            'stats' => $stats,
        ];
    }

    /**
     * Per-type aggregate counts shown in the PDF stats row. RPHP cares
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
            case 'rphp':
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
            case 'oprava_ts_rphp':
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
     * @return array<string, mixed>|null
     */
    private static function loadDocument(int $accountId, int $documentId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, parent_type, parent_id, type, number, file_path,
                    generated_at, signed, signed_at
             FROM   documents
             WHERE  id = ? AND account_id = ?'
        );
        $stmt->execute([$documentId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'           => (int) $row['id'],
            'parent_type'  => $row['parent_type'],
            'parent_id'    => (int) $row['parent_id'],
            'type'         => $row['type'],
            'number'       => $row['number'],
            'file_path'    => $row['file_path'],
            'generated_at' => $row['generated_at'],
            'signed'       => (int) $row['signed'] === 1,
            'signed_at'    => $row['signed_at'],
            'download_url' => '/api/documents/' . (int) $row['id'] . '/download',
        ];
    }
}
