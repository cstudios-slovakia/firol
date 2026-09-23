<?php

declare(strict_types=1);

namespace Firol\Backup;

use Firol\Db;
use Firol\Storage\Storage;
use Firol\Support\Address;
use PDO;
use ZipArchive;

/**
 * Builds one account's backup archive on disk (see {@see Archive} for the
 * layout). The caller streams the returned file and deletes it.
 *
 * Everything is written straight from the database and from the existing
 * files on disk — nothing is loaded into memory except the manifest itself,
 * so a 5 GB photo library costs the same RSS as an empty account.
 */
final class Writer
{
    /**
     * @return array{path: string, filename: string}
     */
    public static function build(int $accountId, bool $withPhotos, bool $withDocuments): array
    {
        $pdo = Db::pdo();

        // Files to pull in, collected while walking the rows so each entry is
        // added to the zip exactly once: [entry name => absolute source path].
        $files = [];

        $manifest = [
            'format'      => Archive::FORMAT,
            'version'     => Archive::VERSION,
            'exported_at' => date('c'),
            'includes'    => [
                'photos'    => $withPhotos,
                'documents' => $withDocuments,
            ],
            'account'     => self::account($accountId, $pdo),
            'companies'   => self::companies($accountId, $pdo),
            'inspections' => self::inspections($accountId, $pdo, $withPhotos, $files),
            'trainings'   => self::trainings($accountId, $pdo, $files),
            'documents'   => $withDocuments ? self::documents($accountId, $pdo, $files) : [],
            // Chapter 21 — sklad: items with every holder's balance, the
            // výdajky and the movements journal.
            'stock'       => self::stock($accountId, $pdo),
            'tasks'       => self::tasks($accountId, $pdo),
            // Chapter 11.3 — which deadlines already had their automatic
            // client notice, so a restore does not send it a second time.
            'deadline_notices' => self::deadlineNotices($accountId, $pdo),
        ];

        $manifest['counts'] = [
            'companies'   => count($manifest['companies']),
            'facilities'  => array_sum(array_map(static fn (array $c): int => count($c['facilities']), $manifest['companies'])),
            'inspections' => count($manifest['inspections']),
            'trainings'   => count($manifest['trainings']),
            'documents'   => count($manifest['documents']),
            'tasks'       => count($manifest['tasks']),
            'photos'      => count(array_filter(array_keys($files), static fn (string $e): bool => str_starts_with($e, 'photos/') && !str_ends_with($e, '_t.jpg'))),
        ];

        $filename = 'POapp-zaloha-' . date('Y-m-d') . '.zip';
        $path     = Storage::tempDir() . '/backup-' . $accountId . '-' . bin2hex(random_bytes(8)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create backup archive at ' . $path);
        }

        $zip->addFromString(
            Archive::MANIFEST,
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        foreach ($files as $entry => $absolute) {
            if (!is_file($absolute)) {
                // Referenced in the manifest but gone from disk. Skip rather
                // than abort — a backup missing one photo still beats no
                // backup, and the manifest records what was expected.
                continue;
            }
            $zip->addFile($absolute, $entry);
            // JPEGs and PDFs are already compressed; deflating them again
            // burns CPU for ~1% and would make a large export crawl.
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }

        // Warning-suppressed on purpose: close() writes the archive out and can
        // fail (no space, unwritable temp dir), and its warning would be echoed
        // straight into the response body — corrupting the download and
        // "headers already sent"-ing the error handler. The return value is
        // checked instead, and the caller turns it into a clean 500.
        if (!@$zip->close()) {
            @unlink($path);
            throw new \RuntimeException('Failed to finalise backup archive: ' . $zip->getStatusString());
        }

        return ['path' => $path, 'filename' => $filename];
    }

    /** @return array{id: int, name: ?string} */
    private static function account(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT id, invoice_company_name FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'id'   => (int) ($row['id'] ?? $accountId),
            'name' => $row['invoice_company_name'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function companies(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, name, ico, street, postal_code, city, contact, contact_email, approver, billing_mode, created_at
             FROM   companies WHERE account_id = ? AND archived_at IS NULL ORDER BY name'
        );
        $stmt->execute([$accountId]);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $facStmt = $pdo->prepare(
            'SELECT id, company_id, name, street, postal_code, city, contact_person, notes, created_at
             FROM   facilities WHERE account_id = ? AND archived_at IS NULL ORDER BY name'
        );
        $facStmt->execute([$accountId]);
        $byCompany = [];
        foreach ($facStmt->fetchAll(PDO::FETCH_ASSOC) as $facility) {
            $facility['id'] = (int) $facility['id'];
            $byCompany[(int) $facility['company_id']][] = self::withReadableAddress($facility);
        }

        $out = [];
        foreach ($companies as $company) {
            $company['id']         = (int) $company['id'];
            $company              = self::withReadableAddress($company);
            $company['facilities'] = $byCompany[$company['id']] ?? [];
            $out[] = $company;
        }
        return $out;
    }

    /**
     * @param array<string, string> $files
     * @return list<array<string, mixed>>
     */
    private static function inspections(int $accountId, PDO $pdo, bool $withPhotos, array &$files): array
    {
        $stmt = $pdo->prepare(
            'SELECT i.id, i.company_id, i.facility_id, i.source_inspection_id, i.type,
                    i.periodicity_value, i.periodicity_unit, i.periodicity_is_custom,
                    i.is_preventive_inspection, i.executed_on,
                    i.status, i.notes, i.details, i.created_at,
                    i.effective_cert_number,
                    i.effective_cert_valid_from, i.effective_cert_valid_to,
                    i.billing_mode, i.invoiced, i.invoiced_at, i.billing_note,
                    u.email  AS inspector_email,
                    eu.email AS effective_inspector_email
             FROM   inspections i
             LEFT   JOIN users u  ON u.id  = i.inspector_user_id
             LEFT   JOIN users eu ON eu.id = i.effective_inspector_user_id
             WHERE  i.account_id = ? AND i.archived_at IS NULL
             ORDER  BY i.id'
        );
        $stmt->execute([$accountId]);
        $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($inspections === []) {
            return [];
        }

        $ids = implode(',', array_map(static fn (array $i): int => (int) $i['id'], $inspections));

        $photosByItem = $withPhotos
            ? self::photos($ids, $pdo, $files)
            : [];

        $itemStmt = $pdo->query(
            "SELECT id, inspection_id, position, fields, created_at FROM inspection_items
             WHERE  inspection_id IN ($ids) ORDER BY inspection_id, position"
        );
        $itemsByInspection = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $item['id']       = (int) $item['id'];
            $item['position'] = (int) $item['position'];
            $item['fields']   = json_decode((string) $item['fields'], true);
            $item['photos']   = $photosByItem[$item['id']] ?? [];
            $itemsByInspection[(int) $item['inspection_id']][] = $item;
        }

        $out = [];
        foreach ($inspections as $inspection) {
            $inspection['id']                       = (int) $inspection['id'];
            $inspection['company_id']               = (int) $inspection['company_id'];
            $inspection['facility_id']              = (int) $inspection['facility_id'];
            $inspection['periodicity_value']        = $inspection['periodicity_value'] !== null
                ? (int) $inspection['periodicity_value']
                : null;
            $inspection['periodicity_is_custom']    = (int) $inspection['periodicity_is_custom'];
            $inspection['is_preventive_inspection'] = (int) $inspection['is_preventive_inspection'];
            $inspection['source_inspection_id']     = $inspection['source_inspection_id'] !== null
                ? (int) $inspection['source_inspection_id']
                : null;
            // Chapter 22 — fakturácia úkonu (migration 046).
            $inspection['invoiced'] = (int) $inspection['invoiced'];
            $inspection['items'] = $itemsByInspection[$inspection['id']] ?? [];
            $out[] = $inspection;
        }
        return $out;
    }

    /**
     * @param array<string, string> $files
     * @return array<int, list<array<string, mixed>>> keyed by item id
     */
    private static function photos(string $inspectionIds, PDO $pdo, array &$files): array
    {
        $stmt = $pdo->query(
            "SELECT id, item_id, defect_key, position, file_path, thumb_path,
                    byte_size, width, height, created_at
             FROM   inspection_item_photos
             WHERE  inspection_id IN ($inspectionIds)
             ORDER  BY item_id, position, id"
        );

        $byItem = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $photoId = (int) $photo['id'];

            $entry      = Archive::photoEntry($photoId);
            $thumbEntry = Archive::photoThumbEntry($photoId);
            $files[$entry]      = Storage::absolute((string) $photo['file_path']);
            $files[$thumbEntry] = Storage::absolute((string) $photo['thumb_path']);

            $byItem[(int) $photo['item_id']][] = [
                'id'         => $photoId,
                'defect_key' => $photo['defect_key'],
                'position'   => (int) $photo['position'],
                'byte_size'  => (int) $photo['byte_size'],
                'width'      => (int) $photo['width'],
                'height'     => (int) $photo['height'],
                'created_at' => $photo['created_at'],
                'file'       => $entry,
                'thumb'      => $thumbEntry,
            ];
        }
        return $byItem;
    }

    /**
     * @param array<string, string> $files
     * @return list<array<string, mixed>>
     */
    private static function trainings(int $accountId, PDO $pdo, array &$files): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.company_id, t.facility_id, t.type, t.date, t.topics,
                    t.duration_min, t.fields, t.status, t.created_at,
                    t.billing_mode, t.invoiced, t.invoiced_at, t.billing_note,
                    u.email AS trainer_email
             FROM   trainings t
             LEFT   JOIN users u ON u.id = t.trainer_id
             WHERE  t.account_id = ? AND t.archived_at IS NULL
             ORDER  BY t.id'
        );
        $stmt->execute([$accountId]);
        $trainings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($trainings === []) {
            return [];
        }

        $ids = implode(',', array_map(static fn (array $t): int => (int) $t['id'], $trainings));
        $traineeStmt = $pdo->query(
            "SELECT id, training_id, fullname, position, signature_path, signed_at, created_at
             FROM   trainees WHERE training_id IN ($ids) ORDER BY training_id, id"
        );

        $byTraining = [];
        foreach ($traineeStmt->fetchAll(PDO::FETCH_ASSOC) as $trainee) {
            $traineeId = (int) $trainee['id'];
            $signature = (string) ($trainee['signature_path'] ?? '');

            $entry = null;
            if ($signature !== '') {
                $entry = Archive::traineeSignatureEntry($traineeId);
                $files[$entry] = Storage::absolute($signature);
            }

            $byTraining[(int) $trainee['training_id']][] = [
                'id'        => $traineeId,
                'fullname'  => $trainee['fullname'],
                'position'  => $trainee['position'],
                'signed_at' => $trainee['signed_at'],
                'created_at' => $trainee['created_at'],
                'signature' => $entry,
            ];
        }

        $out = [];
        foreach ($trainings as $training) {
            $training['id']          = (int) $training['id'];
            $training['company_id']  = (int) $training['company_id'];
            $training['facility_id'] = $training['facility_id'] !== null ? (int) $training['facility_id'] : null;
            $training['duration_min'] = $training['duration_min'] !== null ? (int) $training['duration_min'] : null;
            // Pokyn — žatevné práce carries its instruction text here; the six
            // attendance-based types have no payload. Decoded so the manifest
            // stays readable JSON rather than a string of escaped JSON.
            $training['fields']      = $training['fields'] !== null
                ? json_decode((string) $training['fields'], true)
                : null;
            // Chapter 22 — fakturácia úkonu (migration 046).
            $training['invoiced']    = (int) $training['invoiced'];
            $training['trainees']    = $byTraining[$training['id']] ?? [];
            $out[] = $training;
        }
        return $out;
    }

    /**
     * @param array<string, string> $files
     * @return list<array<string, mixed>>
     */
    private static function documents(int $accountId, PDO $pdo, array &$files): array
    {
        // Only protocols whose parent is itself in the backup. A document
        // belonging to an archived (or already vanished) inspection has
        // nothing to hang off after a restore, so shipping it would put bytes
        // in the archive that can never come back out — and would make the
        // "27 protocols in, 25 restored" arithmetic look like data loss.
        $stmt = $pdo->prepare(
            "SELECT d.id, d.parent_type, d.parent_id, d.type, d.number, d.form_variant, d.file_path,
                    d.generated_at, d.signed, d.signed_at
             FROM   documents d
             LEFT   JOIN inspections i ON d.parent_type = 'inspection' AND i.id = d.parent_id
             LEFT   JOIN trainings   t ON d.parent_type = 'training'   AND t.id = d.parent_id
             LEFT   JOIN stock_issues si ON d.parent_type = 'stock_issue' AND si.id = d.parent_id
             LEFT   JOIN companies  sic ON sic.id = si.company_id
             WHERE  d.account_id = ?
               AND  (i.id IS NOT NULL AND i.archived_at IS NULL
                  OR t.id IS NOT NULL AND t.archived_at IS NULL
                  OR si.id IS NOT NULL AND sic.archived_at IS NULL)
             ORDER  BY d.id"
        );
        $stmt->execute([$accountId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
            $documentId = (int) $doc['id'];
            $entry      = Archive::documentEntry($documentId);
            $files[$entry] = Storage::absolute((string) $doc['file_path']);

            $out[] = [
                'id'           => $documentId,
                'parent_type'  => $doc['parent_type'],
                'parent_id'    => (int) $doc['parent_id'],
                'type'         => $doc['type'],
                'number'       => $doc['number'],
                'generated_at' => $doc['generated_at'],
                'signed'       => (int) $doc['signed'],
                'signed_at'    => $doc['signed_at'],
                'file'         => $entry,
            ];
        }
        return $out;
    }

    /**
     * Úlohy (chapter 20). Tasks of an archived firm are left out — the app no
     * longer shows them, and the firm itself is not in the backup either.
     * People are carried by e-mail, like the inspector of an úkon.
     *
     * @return list<array<string, mixed>>
     */
    private static function tasks(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.text, t.company_id, t.facility_id, t.due_date, t.done, t.done_at,
                    t.source_inspection_id, t.source_defect_key, t.created_at,
                    au.email AS assignee_email, cu.email AS created_by_email
             FROM   tasks t
             LEFT   JOIN companies c  ON c.id  = t.company_id
             LEFT   JOIN users     au ON au.id = t.assignee_user_id
             LEFT   JOIN users     cu ON cu.id = t.created_by_user_id
             WHERE  t.account_id = ? AND c.archived_at IS NULL
             ORDER  BY t.id'
        );
        $stmt->execute([$accountId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            foreach (['id', 'company_id', 'facility_id', 'source_inspection_id'] as $key) {
                $task[$key] = $task[$key] !== null ? (int) $task[$key] : null;
            }
            $task['done'] = (int) $task['done'];
            $out[] = $task;
        }
        return $out;
    }

    /**
     * Automatic client notices already sent (chapter 11.3), keyed by the
     * úkon whose deadline they announced.
     *
     * @return list<array<string, mixed>>
     */
    private static function deadlineNotices(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT n.inspection_id, n.notice_date, n.recipient, n.sent_at
             FROM   deadline_notices n
             JOIN   inspections i ON i.id = n.inspection_id AND i.archived_at IS NULL
             WHERE  n.account_id = ?
             ORDER  BY n.id'
        );
        $stmt->execute([$accountId]);
        return array_map(static fn (array $r): array => [
            'inspection_id' => (int) $r['inspection_id'],
            'notice_date'   => (string) $r['notice_date'],
            'recipient'     => (string) $r['recipient'],
            'sent_at'       => (string) $r['sent_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Sklad (chapter 21). Balances are written as they stand — Sklad on the
     * item, technicians by e-mail — so a restore reproduces the same sum over
     * holders without replaying the journal. Výdajky of an archived firm are
     * left out with the firm; their movements stay (the journal is the
     * record of what happened) and lose only the link.
     *
     * @return array{items: list<array<string, mixed>>, issues: list<array<string, mixed>>, movements: list<array<string, mixed>>}
     */
    private static function stock(int $accountId, PDO $pdo): array
    {
        $itemStmt = $pdo->prepare(
            'SELECT id, name, unit, warehouse_qty, created_at FROM stock_items WHERE account_id = ? ORDER BY id'
        );
        $itemStmt->execute([$accountId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $balStmt = $pdo->prepare(
            'SELECT b.item_id, b.qty, u.email
             FROM   stock_balances b JOIN users u ON u.id = b.user_id
             WHERE  b.account_id = ? AND b.qty > 0'
        );
        $balStmt->execute([$accountId]);
        $balances = [];
        foreach ($balStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $balances[(int) $b['item_id']][] = ['email' => (string) $b['email'], 'qty' => (int) $b['qty']];
        }
        foreach ($items as &$item) {
            $item['id']            = (int) $item['id'];
            $item['warehouse_qty'] = (int) $item['warehouse_qty'];
            $item['balances']      = $balances[$item['id']] ?? [];
        }
        unset($item);

        $issueStmt = $pdo->prepare(
            'SELECT si.id, si.company_id, si.facility_id, si.inspection_id, si.issued_on,
                    si.issuer_name, si.issuer_cert, si.contractor, si.created_at,
                    iu.email AS issuer_email
             FROM   stock_issues si
             JOIN   companies c ON c.id = si.company_id AND c.archived_at IS NULL
             LEFT   JOIN users iu ON iu.id = si.issuer_user_id
             WHERE  si.account_id = ?
             ORDER  BY si.id'
        );
        $issueStmt->execute([$accountId]);
        $issues = array_map(static function (array $r): array {
            foreach (['id', 'company_id', 'facility_id', 'inspection_id'] as $key) {
                $r[$key] = $r[$key] !== null ? (int) $r[$key] : null;
            }
            $r['contractor'] = $r['contractor'] !== null ? json_decode((string) $r['contractor'], true) : null;
            return $r;
        }, $issueStmt->fetchAll(PDO::FETCH_ASSOC));

        $movStmt = $pdo->prepare(
            'SELECT m.id, m.item_id, m.item_name, m.unit, m.action,
                    m.from_holder, fu.email AS from_email, m.from_name,
                    m.to_holder, tu.email AS to_email, m.to_name,
                    m.qty, m.company_id, m.inspection_id, m.note, m.issue_id,
                    m.to_invoice, m.invoiced, m.invoiced_at,
                    cu.email AS created_by_email, m.created_by_name, m.created_at
             FROM   stock_movements m
             LEFT   JOIN users fu ON fu.id = m.from_user_id
             LEFT   JOIN users tu ON tu.id = m.to_user_id
             LEFT   JOIN users cu ON cu.id = m.created_by_user_id
             WHERE  m.account_id = ?
             ORDER  BY m.id'
        );
        $movStmt->execute([$accountId]);
        $movements = array_map(static function (array $r): array {
            foreach (['id', 'item_id', 'company_id', 'inspection_id', 'issue_id'] as $key) {
                $r[$key] = $r[$key] !== null ? (int) $r[$key] : null;
            }
            $r['qty']        = (int) $r['qty'];
            $r['to_invoice'] = (int) $r['to_invoice'];
            $r['invoiced']   = (int) $r['invoiced'];
            return $r;
        }, $movStmt->fetchAll(PDO::FETCH_ASSOC));

        return ['items' => $items, 'issues' => $issues, 'movements' => $movements];
    }

    /**
     * Keeps the structured columns (what the restore writes back verbatim) and
     * adds the combined string the UI shows, so the manifest is also readable
     * by a human opening backup.json in an editor.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withReadableAddress(array $row): array
    {
        $row['address'] = Address::format(
            isset($row['street']) ? (string) $row['street'] : null,
            isset($row['postal_code']) ? (string) $row['postal_code'] : null,
            isset($row['city']) ? (string) $row['city'] : null,
        );
        return $row;
    }
}
