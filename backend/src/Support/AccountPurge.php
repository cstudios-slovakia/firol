<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;
use Firol\Storage\Storage;
use PDO;

/**
 * Deletes a tenant's operational data — the rows *and* the files behind them.
 *
 * FK cascades take care of the child rows; the files on disk are ours to
 * remove, and photos in particular are large enough that leaving them behind
 * would quietly defeat the purge. Extracted from DataController because the
 * "replace" restore mode needs exactly the same wipe before it writes the
 * backup back in ({@see \Firol\Backup\Restorer}).
 *
 * Never touches the account itself, its users, branding or subscription.
 */
final class AccountPurge
{
    /**
     * Companies + facilities + everything hanging off them (inspections,
     * items, photos, trainings, trainees, documents), plus the account's audit
     * checklists.
     *
     * @return int number of companies removed
     */
    public static function everything(int $accountId): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $count = (int) $stmt->fetchColumn();

        // Collect the file paths before the rows that name them are gone.
        $documentPaths = self::documentPaths($accountId, $pdo);
        $signaturePaths = self::traineeSignaturePaths($accountId, $pdo);
        $handoverPaths = self::handoverSignaturePaths($accountId, $pdo);
        $photoPaths = self::photoPaths($accountId, $pdo);

        // documents has no FK to inspections/trainings, so it goes first.
        $pdo->prepare('DELETE FROM documents WHERE account_id = ?')->execute([$accountId]);
        // Audit checklists belong to the account rather than to a company, so
        // no cascade reaches them. A "replace" restore that left last year's
        // reworded checklists behind would merge two accounts' idea of what to
        // ask (see \Firol\Backup\Restorer).
        $pdo->prepare('DELETE FROM audit_templates WHERE account_id = ?')->execute([$accountId]);
        // Visits and work confirmations describe work done at a company, so the
        // company cascade takes them — but only once their protocols are gone.
        $pdo->prepare('DELETE FROM companies WHERE account_id = ?')->execute([$accountId]);

        self::unlink(array_merge($documentPaths, $signaturePaths, $handoverPaths, $photoPaths));
        self::pruneDocumentDirs($accountId);
        self::prunePhotoDirs($accountId);

        return $count;
    }

    /** Inspections + items + photos + their protocols. Companies survive. */
    public static function inspections(int $accountId): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inspections WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $count = (int) $stmt->fetchColumn();

        // A potvrdenie o vykonaní práce only lists protocols of these
        // inspections, and a visit is only the thread between them — neither
        // survives the work it describes, so their PDFs go in the same sweep.
        $docStmt = $pdo->prepare(
            "SELECT file_path FROM documents
             WHERE  account_id = ? AND parent_type IN ('inspection', 'work_confirmation')"
        );
        $docStmt->execute([$accountId]);
        $documentPaths = array_merge(
            $docStmt->fetchAll(PDO::FETCH_COLUMN),
            self::versionPaths($accountId, $pdo, ['inspection', 'work_confirmation']),
        );
        $photoPaths = self::photoPaths($accountId, $pdo);
        $handoverPaths = self::handoverSignaturePaths($accountId, $pdo);

        $pdo->prepare(
            "DELETE FROM documents
             WHERE  account_id = ? AND parent_type IN ('inspection', 'work_confirmation')"
        )->execute([$accountId]);
        $pdo->prepare('DELETE FROM work_confirmations WHERE account_id = ?')->execute([$accountId]);
        // inspection_items — and their photos — cascade with the inspections.
        $pdo->prepare('DELETE FROM inspections WHERE account_id = ?')->execute([$accountId]);
        $pdo->prepare('DELETE FROM visits WHERE account_id = ?')->execute([$accountId]);

        self::unlink(array_merge($documentPaths, $photoPaths, $handoverPaths));
        self::pruneDocumentDirs($accountId);
        self::prunePhotoDirs($accountId);

        return $count;
    }

    /** Trainings + trainees + their protocols. Companies and inspections survive. */
    public static function trainings(int $accountId): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trainings WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $count = (int) $stmt->fetchColumn();

        $docStmt = $pdo->prepare(
            "SELECT file_path FROM documents WHERE account_id = ? AND parent_type = 'training'"
        );
        $docStmt->execute([$accountId]);
        $documentPaths = array_merge(
            $docStmt->fetchAll(PDO::FETCH_COLUMN),
            self::versionPaths($accountId, $pdo, ['training']),
        );
        $signaturePaths = self::traineeSignaturePaths($accountId, $pdo);

        $pdo->prepare("DELETE FROM documents WHERE account_id = ? AND parent_type = 'training'")
            ->execute([$accountId]);
        // trainees cascade with the trainings.
        $pdo->prepare('DELETE FROM trainings WHERE account_id = ?')->execute([$accountId]);

        self::unlink(array_merge($documentPaths, $signaturePaths));
        self::pruneDocumentDirs($accountId);

        return $count;
    }

    // ── File collection ──────────────────────────────────────────────────────

    /** @return list<string> */
    private static function documentPaths(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT file_path FROM documents WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $versions = $pdo->prepare(
            'SELECT v.file_path FROM document_versions v
             JOIN   documents d ON d.id = v.document_id
             WHERE  d.account_id = ?'
        );
        $versions->execute([$accountId]);
        return array_merge($paths, $versions->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every superseded version of a protocol. Since chapter 13 a signature
     * re-renders the document under the same number, so `documents.file_path`
     * names only the newest file and the earlier ones would be left on disk.
     *
     * @param list<string> $parentTypes
     * @return list<string>
     */
    private static function versionPaths(int $accountId, PDO $pdo, array $parentTypes): array
    {
        $placeholders = implode(',', array_fill(0, count($parentTypes), '?'));
        $stmt = $pdo->prepare(
            "SELECT v.file_path FROM document_versions v
             JOIN   documents d ON d.id = v.document_id
             WHERE  d.account_id = ? AND d.parent_type IN ($placeholders)"
        );
        $stmt->execute(array_merge([$accountId], $parentTypes));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Signatures captured when a client took a protocol over (chapter 13).
     *
     * @return list<string>
     */
    private static function handoverSignaturePaths(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT signature_path FROM document_handovers WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private static function photoPaths(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT file_path, thumb_path FROM inspection_item_photos WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paths[] = (string) $row['file_path'];
            $paths[] = (string) $row['thumb_path'];
        }
        return $paths;
    }

    /** @return list<string> */
    private static function traineeSignaturePaths(int $accountId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT tr.signature_path FROM trainees tr
             JOIN   trainings t ON t.id = tr.training_id
             WHERE  t.account_id = ? AND tr.signature_path IS NOT NULL'
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── Disk cleanup ─────────────────────────────────────────────────────────

    /** @param list<mixed> $paths */
    private static function unlink(array $paths): void
    {
        $root = Storage::root();
        foreach ($paths as $relative) {
            if (!is_string($relative) || $relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $absolute = $root . '/' . $relative;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    private static function pruneDocumentDirs(int $accountId): void
    {
        $baseDir = Storage::root() . '/documents/' . $accountId;
        if (!is_dir($baseDir)) {
            return;
        }
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
            if (empty(glob($yearDir . '/*') ?: [])) {
                @rmdir($yearDir);
            }
        }
        if (empty(glob($baseDir . '/*') ?: [])) {
            @rmdir($baseDir);
        }
    }

    private static function prunePhotoDirs(int $accountId): void
    {
        $baseDir = Storage::root() . '/photos/' . $accountId;
        if (!is_dir($baseDir)) {
            return;
        }
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $inspectionDir) {
            if (empty(glob($inspectionDir . '/*') ?: [])) {
                @rmdir($inspectionDir);
            }
        }
        if (empty(glob($baseDir . '/*') ?: [])) {
            @rmdir($baseDir);
        }
    }
}
