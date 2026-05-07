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

    public static function documentDir(int $accountId, int $year): string
    {
        return self::root() . "/documents/$accountId/$year";
    }

    public static function documentRelative(int $accountId, int $year, string $number): string
    {
        return "documents/$accountId/$year/$number.pdf";
    }

    public static function documentAbsolute(string $relativePath): string
    {
        return self::root() . '/' . $relativePath;
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
