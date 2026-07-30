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
     * items, photos, trainings, trainees, documents).
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
        $photoPaths = self::photoPaths($accountId, $pdo);

        // documents has no FK to inspections/trainings, so it goes first.
        $pdo->prepare('DELETE FROM documents WHERE account_id = ?')->execute([$accountId]);
        $pdo->prepare('DELETE FROM companies WHERE account_id = ?')->execute([$accountId]);

        self::unlink(array_merge($documentPaths, $signaturePaths, $photoPaths));
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

        $docStmt = $pdo->prepare(
            "SELECT file_path FROM documents WHERE account_id = ? AND parent_type = 'inspection'"
        );
        $docStmt->execute([$accountId]);
        $documentPaths = $docStmt->fetchAll(PDO::FETCH_COLUMN);
        $photoPaths = self::photoPaths($accountId, $pdo);

        $pdo->prepare("DELETE FROM documents WHERE account_id = ? AND parent_type = 'inspection'")
            ->execute([$accountId]);
        // inspection_items — and their photos — cascade with the inspections.
        $pdo->prepare('DELETE FROM inspections WHERE account_id = ?')->execute([$accountId]);

        self::unlink(array_merge($documentPaths, $photoPaths));
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
        $documentPaths = $docStmt->fetchAll(PDO::FETCH_COLUMN);
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
