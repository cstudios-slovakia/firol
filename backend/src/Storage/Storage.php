<?php

declare(strict_types=1);

namespace Firol\Storage;

/**
 * Filesystem helper for app-private files (signatures, generated PDFs).
 * The storage root sits OUTSIDE the document root so files can never be
 * fetched directly — every read goes through an authenticated PHP
 * endpoint that verifies tenant ownership first.
 *
 * Layout (relative to root()):
 *   signatures/{account_id}/{user_id}.png
 *   documents/{account_id}/{year}/{number}.pdf
 *   photos/{account_id}/{inspection_id}/{token}.jpg      (full size)
 *   photos/{account_id}/{inspection_id}/{token}_t.jpg    (thumbnail)
 */
final class Storage
{
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/storage';
    }

    public static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            // 0775 lets the web server (php-fpm) and CLI both write here in
            // dev. Production runs as the same user so it's a non-issue.
            if (!mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException("Cannot create dir: $path");
            }
            // mkdir()'s mode is masked by the process umask (022 by default),
            // which strips exactly the group-write bit the line above is for —
            // so whichever of php-fpm/CLI created the directory first would own
            // it outright and lock the other one out. chmod is not masked.
            @chmod($path, 0775);
        }
    }

    public static function signaturePath(int $accountId, int $userId): string
    {
        return self::root() . "/signatures/$accountId/$userId.png";
    }

    public static function signatureRelative(int $accountId, int $userId): string
    {
        return "signatures/$accountId/$userId.png";
    }

    public static function accountLogoPath(int $accountId, string $ext): string
    {
        return self::root() . "/accounts/$accountId/logo.$ext";
    }

    public static function accountLogoRelative(int $accountId, string $ext): string
    {
        return "accounts/$accountId/logo.$ext";
    }

    public static function traineeSignaturePath(int $trainingId, int $traineeId): string
    {
        return self::root() . "/trainings/$trainingId/$traineeId.png";
    }

    public static function traineeSignatureRelative(int $trainingId, int $traineeId): string
    {
        return "trainings/$trainingId/$traineeId.png";
    }

    public static function documentDir(int $accountId, int $year): string
    {
        return self::root() . "/documents/$accountId/$year";
    }

    public static function documentRelative(int $accountId, int $year, string $number): string
    {
        return "documents/$accountId/$year/$number.pdf";
    }

    /**
     * Path of a re-rendered protocol. The number never changes when a
     * signature is added (chapter 13) — only the version does, and every
     * version keeps its own file because a customer may already hold the
     * earlier one.
     */
    public static function documentVersionRelative(
        int $accountId,
        int $year,
        string $number,
        int $version,
    ): string {
        return $version <= 1
            ? self::documentRelative($accountId, $year, $number)
            : "documents/$accountId/$year/$number-v$version.pdf";
    }

    /** Signature captured when the client took the protocol over (chapter 13). */
    public static function handoverSignatureRelative(int $accountId, int $documentId): string
    {
        return "handovers/$accountId/$documentId.png";
    }

    public static function documentAbsolute(string $relativePath): string
    {
        return self::root() . '/' . $relativePath;
    }

    /**
     * Directory holding one inspection's item photos (change request 2.2).
     * Grouped per inspection so deleting/archiving a protocol is a single
     * recursive unlink, and so a single directory never holds more than one
     * protocol's worth of files.
     */
    public static function photoDir(int $accountId, int $inspectionId): string
    {
        return self::root() . "/photos/$accountId/$inspectionId";
    }

    public static function photoRelative(int $accountId, int $inspectionId, string $token): string
    {
        return "photos/$accountId/$inspectionId/$token.jpg";
    }

    public static function photoThumbRelative(int $accountId, int $inspectionId, string $token): string
    {
        return "photos/$accountId/$inspectionId/{$token}_t.jpg";
    }

    /**
     * Resolve a stored relative path to an absolute one. Shared by photos and
     * documents — the stored path always comes from our own writers, never
     * from user input, but the traversal guard keeps that assumption honest.
     */
    public static function absolute(string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Path traversal in storage path.');
        }
        return self::root() . '/' . $relativePath;
    }

    /**
     * Recursively delete an account's photo tree (change request 2.2).
     *
     * The DB rows cascade away with the account, but photos are the one kind
     * of file large enough that leaving them orphaned actually costs storage —
     * a deleted account could otherwise keep gigabytes on disk forever.
     */
    public static function purgeAccountPhotos(int $accountId): void
    {
        self::removeTree(self::root() . "/photos/$accountId");
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Scratch directory for files we build then stream and delete — currently
     * the account backup .zip. Inside the storage root (not sys_get_temp_dir)
     * because a full backup can be hundreds of MB and shared hosting often
     * puts /tmp on a small tmpfs; here it shares the same disk the photos it
     * archives already live on.
     */
    public static function tempDir(): string
    {
        $dir = self::root() . '/tmp';
        self::ensureDir($dir);
        return $dir;
    }

    /**
     * mPDF needs a writable scratch directory for font cache and intermediate
     * files. Keep it inside our storage root so backups capture it and
     * permissions stay consistent with other writable paths.
     */
    public static function mpdfTempDir(): string
    {
        $dir = self::root() . '/mpdf-tmp';
        self::ensureDir($dir);
        return $dir;
    }
}
