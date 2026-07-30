<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Backup\BackupException;
use Firol\Backup\Restorer;
use Firol\Backup\Writer;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\AccountPurge;
use Throwable;

/**
 * Bulk data operations for the active tenant: the full-account backup archive
 * (download + restore) and the sectional purges.
 *
 * The backup is a .zip — manifest JSON plus the actual photo, protocol and
 * signature files. It used to be bare JSON that referenced photos by download
 * URL, which meant a backup taken before a database wipe pointed at files that
 * were no longer there. See {@see \Firol\Backup\Archive} for the layout.
 */
final class DataController
{
    /** Wipes all companies (+ facilities, inspections, trainings, documents). */
    public static function purgeCompanies(Request $req): void
    {
        Csrf::require($req);
        Response::json(['deleted' => AccountPurge::everything(Tenant::currentAccountId())]);
    }

    /** Wipes all inspections (+ items + photos + inspection protocols). */
    public static function purgeInspections(Request $req): void
    {
        Csrf::require($req);
        Response::json(['deleted' => AccountPurge::inspections(Tenant::currentAccountId())]);
    }

    /** Wipes all trainings (+ trainees + training protocols). */
    public static function purgeTrainings(Request $req): void
    {
        Csrf::require($req);
        Response::json(['deleted' => AccountPurge::trainings(Tenant::currentAccountId())]);
    }

    /**
     * Streams the account backup archive.
     *
     * `?photos=0` / `?documents=0` leave those files out for a quick
     * data-only snapshot; both default to on, because a backup that silently
     * omits things is the failure mode this whole feature exists to fix.
     */
    public static function exportData(Request $req): void
    {
        $accountId = Tenant::currentAccountId();

        $withPhotos    = $req->query('photos') !== '0';
        $withDocuments = $req->query('documents') !== '0';

        // Archiving a large photo library takes as long as it takes, and the
        // client is waiting on a file — don't let PHP's default time limit cut
        // a backup in half.
        @set_time_limit(0);

        try {
            $archive = Writer::build($accountId, $withPhotos, $withDocuments);
        } catch (Throwable $e) {
            error_log('[backup.export] ' . $e->getMessage());
            Response::error('Zálohu sa nepodarilo vytvoriť. Skús to znova.', 500);
        }

        // Keep streaming even if the browser looks gone, so the temp file is
        // always unlinked below rather than left behind on the first flaky
        // mobile connection.
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $archive['filename'] . '"');
        header('Content-Length: ' . (string) filesize($archive['path']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($archive['path'], 'rb');
        if (is_resource($handle)) {
            // Chunked rather than readfile() so a multi-GB archive never has to
            // fit in memory_limit.
            while (!feof($handle)) {
                echo (string) fread($handle, 1 << 20);
                flush();
            }
            fclose($handle);
        }
        @unlink($archive['path']);
        exit;
    }

    /**
     * Restores a backup archive into the active account.
     *
     * multipart/form-data: `file` (.zip, or a legacy .json export) and `mode`
     * — `merge` (default, additive) or `replace` (wipe the account's data
     * first, then write the backup back in whole).
     */
    public static function restoreData(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId    = Tenant::currentUserId();

        $mode = ($_POST['mode'] ?? Restorer::MODE_MERGE) === Restorer::MODE_REPLACE
            ? Restorer::MODE_REPLACE
            : Restorer::MODE_MERGE;

        $upload = self::takeUpload();

        @set_time_limit(0);

        // Response::* exits, which skips `finally` — so the temp copy is
        // removed on every branch explicitly rather than in one place.
        try {
            $result = Restorer::run($accountId, $userId, $upload, $mode);
        } catch (BackupException $e) {
            // The user picked the wrong file, or one we can't read — say so.
            @unlink($upload);
            Response::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            @unlink($upload);
            error_log('[backup.restore] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            Response::error('Obnova zlyhala: ' . $e->getMessage(), 500);
        }

        @unlink($upload);
        Response::json($result + ['mode' => $mode]);
    }

    /**
     * Validates the upload and moves it somewhere we control, returning the
     * absolute path. Moved off the PHP temp dir because a restore can run for
     * minutes and the request's temp file is only guaranteed for its lifetime.
     */
    private static function takeUpload(): string
    {
        // An upload larger than post_max_size arrives with empty $_POST AND
        // empty $_FILES, and PHP raises no error of its own — without this the
        // user would just see "nahraj súbor" for a file they clearly picked.
        if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) {
            Response::error(
                'Súbor je väčší, než server dovoľuje nahrať (limit ' . self::uploadLimit() . '). '
                . 'Vyexportuj zálohu bez fotiek alebo bez PDF protokolov, alebo požiadaj o zvýšenie limitu.',
                413,
            );
        }

        $file = $_FILES['file'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            Response::error(
                'Súbor je väčší, než server dovoľuje nahrať (limit ' . self::uploadLimit() . ').',
                413,
            );
        }
        if (!is_array($file) || $error !== UPLOAD_ERR_OK) {
            Response::error('Nahraj .zip súbor so zálohou.', 422);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('Nahrávanie zlyhalo.', 422);
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['zip', 'json'], true)) {
            Response::error('Očakávam .zip zálohu (alebo starší .json export).', 422);
        }

        $destination = \Firol\Storage\Storage::tempDir()
            . '/restore-' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!move_uploaded_file($tmp, $destination)) {
            Response::error('Nahraný súbor sa nepodarilo uložiť.', 500);
        }
        return $destination;
    }

    /** The effective ceiling, so the error message names a real number. */
    private static function uploadLimit(): string
    {
        $upload = (string) ini_get('upload_max_filesize');
        $post   = (string) ini_get('post_max_size');
        return self::toBytes($upload) <= self::toBytes($post) ? $upload : $post;
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
