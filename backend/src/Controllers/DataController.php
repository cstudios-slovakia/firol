<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;
use Firol\Support\Address;
use PDO;

/**
 * Bulk data operations: purge sections (companies / inspections / trainings)
 * and full-account JSON export. All mutations are scoped to the active tenant.
 */
final class DataController
{
    /**
     * Wipes all companies (+ facilities, inspections, trainings, documents).
     * FK cascades handle child rows; we handle document files on disk.
     */
    public static function purgeCompanies(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $pdo = Db::pdo();

        $s = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE account_id = ?');
        $s->execute([$accountId]);
        $count = (int) $s->fetchColumn();

        // Collect file paths before deletion
        $docPaths = self::docPathsForAccount($accountId, $pdo);
        $traineePaths = self::traineeSignaturePathsForAccount($accountId, $pdo);

        // Remove documents rows first (no FK to inspections/trainings)
        $pdo->prepare('DELETE FROM documents WHERE account_id = ?')->execute([$accountId]);

        // Remove companies — FK cascades delete facilities, inspections,
        // inspection_items, trainings, and trainees automatically.
        $pdo->prepare('DELETE FROM companies WHERE account_id = ?')->execute([$accountId]);

        self::unlinkFiles(array_merge($docPaths, $traineePaths));

        // Also clean up empty document year directories
        self::pruneDocumentDirs($accountId);

        Response::json(['deleted' => $count]);
    }

    /**
     * Wipes all inspections (+ items + inspection documents) for the account.
     * Companies, facilities, and trainings are preserved.
     */
    public static function purgeInspections(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $pdo = Db::pdo();

        $s = $pdo->prepare('SELECT COUNT(*) FROM inspections WHERE account_id = ?');
        $s->execute([$accountId]);
        $count = (int) $s->fetchColumn();

        $docStmt = $pdo->prepare("SELECT file_path FROM documents WHERE account_id = ? AND parent_type = 'inspection'");
        $docStmt->execute([$accountId]);
        $docPaths = $docStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM documents WHERE account_id = ? AND parent_type = 'inspection'")->execute([$accountId]);
        // inspection_items cascade automatically when inspections are deleted
        $pdo->prepare('DELETE FROM inspections WHERE account_id = ?')->execute([$accountId]);

        self::unlinkFiles($docPaths);
        self::pruneDocumentDirs($accountId);

        Response::json(['deleted' => $count]);
    }

    /**
     * Wipes all trainings (+ trainees + training documents) for the account.
     * Companies, facilities, and inspections are preserved.
     */
    public static function purgeTrainings(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $pdo = Db::pdo();

        $s = $pdo->prepare('SELECT COUNT(*) FROM trainings WHERE account_id = ?');
        $s->execute([$accountId]);
        $count = (int) $s->fetchColumn();

        $docStmt = $pdo->prepare("SELECT file_path FROM documents WHERE account_id = ? AND parent_type = 'training'");
        $docStmt->execute([$accountId]);
        $docPaths = $docStmt->fetchAll(PDO::FETCH_COLUMN);

        $traineePaths = self::traineeSignaturePathsForAccount($accountId, $pdo);

        $pdo->prepare("DELETE FROM documents WHERE account_id = ? AND parent_type = 'training'")->execute([$accountId]);
        // trainees cascade automatically when trainings are deleted
        $pdo->prepare('DELETE FROM trainings WHERE account_id = ?')->execute([$accountId]);

        self::unlinkFiles(array_merge($docPaths, $traineePaths));
        self::pruneDocumentDirs($accountId);

        Response::json(['deleted' => $count]);
    }

    /**
     * Streams a full-account JSON export (companies, inspections, trainings
     * with all child records). Does NOT include generated PDFs or signatures —
     * those are accessible through the normal download endpoints.
     */
    public static function exportData(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $pdo = Db::pdo();

        $accStmt = $pdo->prepare('SELECT id, invoice_company_name FROM accounts WHERE id = ?');
        $accStmt->execute([$accountId]);
        $acc = $accStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // ── Companies + Facilities ─────────────────────────────────────────────
        $compStmt = $pdo->prepare(
            'SELECT id, name, ico, street, postal_code, city, contact, created_at
             FROM   companies WHERE account_id = ? AND archived_at IS NULL ORDER BY name'
        );
        $compStmt->execute([$accountId]);
        $companies = array_map([self::class, 'withCombinedAddress'], $compStmt->fetchAll(PDO::FETCH_ASSOC));

        $facStmt = $pdo->prepare(
            'SELECT id, company_id, name, street, postal_code, city, contact_person, notes, created_at
             FROM   facilities WHERE account_id = ? AND archived_at IS NULL ORDER BY name'
        );
        $facStmt->execute([$accountId]);
        $facByCompany = [];
        foreach ($facStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $facByCompany[(int) $f['company_id']][] = self::withCombinedAddress($f);
        }
        foreach ($companies as &$c) {
            $c['id'] = (int) $c['id'];
            $c['facilities'] = $facByCompany[$c['id']] ?? [];
        }
        unset($c);

        // ── Inspections + Items ────────────────────────────────────────────────
        $insStmt = $pdo->prepare(
            'SELECT i.id, i.company_id, c.name AS company_name,
                    i.facility_id, f.name AS facility_name,
                    i.type, i.periodicity_months, i.executed_on,
                    i.status, i.notes, i.created_at
             FROM   inspections i
             JOIN   companies  c ON c.id = i.company_id
             JOIN   facilities f ON f.id = i.facility_id
             WHERE  i.account_id = ? AND i.archived_at IS NULL
             ORDER  BY COALESCE(i.executed_on, i.created_at) DESC, i.id DESC'
        );
        $insStmt->execute([$accountId]);
        $inspections = $insStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($inspections)) {
            $insIds = implode(',', array_map(static fn($i) => (int) $i['id'], $inspections));
            $itemStmt = $pdo->query(
                "SELECT id, inspection_id, position, fields FROM inspection_items
                 WHERE  inspection_id IN ($insIds) ORDER BY inspection_id, position"
            );
            $itemsByIns = [];
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $item['fields'] = json_decode((string) $item['fields'], true);
                $itemsByIns[(int) $item['inspection_id']][] = $item;
            }
            foreach ($inspections as &$ins) {
                $ins['id'] = (int) $ins['id'];
                $ins['items'] = $itemsByIns[$ins['id']] ?? [];
            }
            unset($ins);
        }

        // ── Trainings + Trainees ───────────────────────────────────────────────
        $tStmt = $pdo->prepare(
            'SELECT t.id, t.company_id, c.name AS company_name,
                    t.facility_id, f.name AS facility_name,
                    t.type, t.date, t.topics, t.duration_min, t.status, t.created_at
             FROM   trainings t
             JOIN   companies  c ON c.id = t.company_id
             LEFT JOIN facilities f ON f.id = t.facility_id
             WHERE  t.account_id = ? AND t.archived_at IS NULL
             ORDER  BY COALESCE(t.date, t.created_at) DESC, t.id DESC'
        );
        $tStmt->execute([$accountId]);
        $trainings = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($trainings)) {
            $tIds = implode(',', array_map(static fn($t) => (int) $t['id'], $trainings));
            $traineeStmt = $pdo->query(
                "SELECT id, training_id, fullname, position, signed_at
                 FROM   trainees WHERE training_id IN ($tIds) ORDER BY training_id, id"
            );
            $traineesByT = [];
            foreach ($traineeStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                $traineesByT[(int) $tr['training_id']][] = $tr;
            }
            foreach ($trainings as &$t) {
                $t['id'] = (int) $t['id'];
                $t['trainees'] = $traineesByT[$t['id']] ?? [];
            }
            unset($t);
        }

        $payload = [
            'exported_at' => date('c'),
            'version'     => 1,
            'account'     => [
                'id'   => (int) ($acc['id'] ?? $accountId),
                'name' => $acc['invoice_company_name'] ?? null,
            ],
            'companies'   => $companies,
            'inspections' => $inspections,
            'trainings'   => $trainings,
        ];

        $filename = 'firol-export-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function docPathsForAccount(int $accountId, \PDO $pdo): array
    {
        $s = $pdo->prepare('SELECT file_path FROM documents WHERE account_id = ?');
        $s->execute([$accountId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private static function traineeSignaturePathsForAccount(int $accountId, \PDO $pdo): array
    {
        $s = $pdo->prepare(
            'SELECT tr.signature_path FROM trainees tr
             JOIN   trainings t ON t.id = tr.training_id
             WHERE  t.account_id = ? AND tr.signature_path IS NOT NULL'
        );
        $s->execute([$accountId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<mixed> $paths */
    private static function unlinkFiles(array $paths): void
    {
        $root = Storage::root();
        foreach ($paths as $rel) {
            if (!is_string($rel) || $rel === '') continue;
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    private static function pruneDocumentDirs(int $accountId): void
    {
        $baseDir = Storage::root() . '/documents/' . $accountId;
        if (!is_dir($baseDir)) return;
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
            $files = glob($yearDir . '/*') ?: [];
            if (empty($files)) {
                @rmdir($yearDir);
            }
        }
        $remaining = glob($baseDir . '/*') ?: [];
        if (empty($remaining)) {
            @rmdir($baseDir);
        }
    }

    /**
     * Collapses the split address columns into a single `address` field so the
     * export keeps the same shape the import expects.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withCombinedAddress(array $row): array
    {
        $row['address'] = Address::format($row['street'] ?? null, $row['postal_code'] ?? null, $row['city'] ?? null);
        unset($row['street'], $row['postal_code'], $row['city']);
        return $row;
    }
}
