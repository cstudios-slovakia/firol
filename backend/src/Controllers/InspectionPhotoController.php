<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;
use Firol\Support\ImageProcessor;

/**
 * Photo documentation for inspection items (change request 2.2).
 *
 * Photos are uploaded one per request: the browser resizes each shot before
 * sending, so a request is small and a flaky field connection retries a single
 * photo rather than a whole batch. Uploading while offline is handled entirely
 * by the frontend outbox — the request replays unchanged once back online.
 *
 * Every write is blocked once the parent protocol is finalized, matching the
 * spec's "po uzamknutí protokolu sú fotky uzamknuté rovnako ako ostatné údaje".
 */
final class InspectionPhotoController
{
    /**
     * Per-item cap. The spec asks for a "rozumný technický strop … len ako
     * ochrana proti omylu" — not a storage policy, so it is deliberately
     * generous.
     */
    public const MAX_PER_ITEM = 20;

    /**
     * Upload ceiling for the raw bytes we accept. The browser normally sends
     * ~300 KB; this only catches a client that skipped resizing (an old
     * browser, or a gallery pick that failed to decode).
     */
    private const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId    = Tenant::currentAccountId();
        $isAdmin      = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) $params['id'];
        $itemId       = (int) $params['item_id'];

        $inspection = self::loadInspectionOrFail($isAdmin ? null : $accountId, $inspectionId);
        // File the photo under the inspection's own account, not the
        // (possibly impersonating) admin's session account.
        $accountId = (int) $inspection['account_id'];
        self::assertEditable($inspection);
        self::assertItemBelongs($itemId, $inspectionId);

        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error(self::uploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE), 422);
        }
        if (($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            Response::error('Fotka je príliš veľká (max 12 MB).', 422);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('Nahratie fotky zlyhalo.', 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!is_string($mime) || !in_array($mime, ImageProcessor::acceptedMimeTypes(), true)) {
            Response::error('Podporované sú len obrázky vo formáte JPEG alebo PNG.', 422);
        }

        $countStmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM inspection_item_photos WHERE item_id = ?'
        );
        $countStmt->execute([$itemId]);
        if ((int) $countStmt->fetchColumn() >= self::MAX_PER_ITEM) {
            Response::error(
                'K položke je možné pripojiť najviac ' . self::MAX_PER_ITEM . ' fotiek.',
                422,
            );
        }

        $dir = Storage::photoDir($accountId, $inspectionId);
        Storage::ensureDir($dir);

        // Random token, not the row id — the file is written before the row
        // exists, and an unguessable name means a leaked storage path can't be
        // walked to a neighbouring tenant's photos.
        $token    = bin2hex(random_bytes(16));
        $relFull  = Storage::photoRelative($accountId, $inspectionId, $token);
        $relThumb = Storage::photoThumbRelative($accountId, $inspectionId, $token);
        $absFull  = Storage::absolute($relFull);
        $absThumb = Storage::absolute($relThumb);

        try {
            $meta = self::storeDerivatives($tmp, $mime, $absFull, $absThumb);
        } catch (\Throwable $e) {
            self::unlinkQuietly($absFull, $absThumb);
            error_log('[photo-upload] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Fotku sa nepodarilo spracovať.', 422);
        }

        $pdo = Db::pdo();
        try {
            $posStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(position), 0) + 1
                 FROM   inspection_item_photos WHERE item_id = ?'
            );
            $posStmt->execute([$itemId]);
            $position = (int) $posStmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO inspection_item_photos
                    (account_id, inspection_id, item_id, position,
                     file_path, thumb_path, byte_size, width, height)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId,
                $inspectionId,
                $itemId,
                $position,
                $relFull,
                $relThumb,
                $meta['bytes'],
                $meta['width'],
                $meta['height'],
            ]);
            $photoId = (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            // The files are already on disk — drop them so a failed insert
            // doesn't leave unreferenced bytes behind.
            self::unlinkQuietly($absFull, $absThumb);
            throw $e;
        }

        Response::json([
            'photo' => self::shape([
                'id'            => $photoId,
                'inspection_id' => $inspectionId,
                'item_id'       => $itemId,
                'position'      => $position,
                'byte_size'     => $meta['bytes'],
                'width'         => $meta['width'],
                'height'        => $meta['height'],
                'created_at'    => date('Y-m-d H:i:s'),
            ]),
        ], 201);
    }

    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId    = Tenant::currentAccountId();
        $isAdmin      = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) $params['id'];
        $itemId       = (int) $params['item_id'];
        $photoId      = (int) $params['photo_id'];

        $inspection = self::loadInspectionOrFail($isAdmin ? null : $accountId, $inspectionId);
        self::assertEditable($inspection);

        $stmt = Db::pdo()->prepare(
            'SELECT id, file_path, thumb_path FROM inspection_item_photos
             WHERE  id = ? AND item_id = ? AND inspection_id = ?'
        );
        $stmt->execute([$photoId, $itemId, $inspectionId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Fotka sa nenašla.', 404);
        }

        Db::pdo()->prepare('DELETE FROM inspection_item_photos WHERE id = ?')->execute([$photoId]);

        self::unlinkQuietly(
            Storage::absolute((string) $row['file_path']),
            Storage::absolute((string) $row['thumb_path']),
        );

        Response::noContent();
    }

    /**
     * Serve the image bytes. `?size=thumb` returns the list-sized derivative;
     * anything else returns the full-size photo used in the PDF appendix.
     */
    public static function download(Request $req, array $params): void
    {
        $accountId    = Tenant::currentAccountId();
        $isAdmin      = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) $params['id'];
        $itemId       = (int) $params['item_id'];
        $photoId      = (int) $params['photo_id'];

        $sql = 'SELECT p.file_path, p.thumb_path
                FROM   inspection_item_photos p
                JOIN   inspections i ON i.id = p.inspection_id
                WHERE  p.id = ? AND p.item_id = ? AND p.inspection_id = ?
                       AND i.archived_at IS NULL';
        $args = [$photoId, $itemId, $inspectionId];
        if (!$isAdmin) {
            $sql .= ' AND p.account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Fotka sa nenašla.', 404);
        }

        $rel = $req->query('size') === 'thumb'
            ? (string) $row['thumb_path']
            : (string) $row['file_path'];
        $abs = Storage::absolute($rel);
        if (!is_file($abs)) {
            error_log('[photo-download] file missing: ' . $abs);
            Response::error('Fotka sa nenašla.', 404);
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: inline; filename="foto-' . $photoId . '.jpg"');
        // Photos are immutable once uploaded, so the browser may hold them for
        // a long time; `private` keeps them out of shared proxies.
        header('Cache-Control: private, max-age=86400');
        readfile($abs);
        exit;
    }

    /**
     * Photos of every item of one inspection, grouped by item id. Used by the
     * inspection detail endpoint and by the PDF appendix builder.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public static function byItemForInspection(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, inspection_id, item_id, position, byte_size, width, height, created_at
             FROM   inspection_item_photos
             WHERE  inspection_id = ?
             ORDER  BY item_id ASC, position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['item_id']][] = self::shape($row);
        }
        return $grouped;
    }

    /**
     * Same grouping, but with the absolute file paths the PDF renderer needs
     * to embed the images. Kept separate so paths never leak into JSON.
     *
     * @return array<int, list<array{path: string, width: int, height: int}>>
     */
    public static function fullSizePathsByItem(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT item_id, file_path, width, height
             FROM   inspection_item_photos
             WHERE  inspection_id = ?
             ORDER  BY item_id ASC, position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $abs = Storage::absolute((string) $row['file_path']);
            if (!is_file($abs)) {
                // A missing file must not abort protocol generation — skip it
                // and leave a trace for ops.
                error_log('[pdf-photos] file missing: ' . $abs);
                continue;
            }
            $grouped[(int) $row['item_id']][] = [
                'path'   => $abs,
                'width'  => (int) $row['width'],
                'height' => (int) $row['height'],
            ];
        }
        return $grouped;
    }

    // --- helpers ------------------------------------------------------------

    /**
     * Write the full-size and thumbnail derivatives.
     *
     * Normal path: GD re-decodes and re-encodes, which caps the dimensions and
     * strips EXIF (so a phone's GPS tags never reach a client's protocol).
     *
     * Fallback path, when GD has no JPEG support: keep the bytes the browser
     * uploaded. Those are already resized and re-encoded client-side — a canvas
     * re-encode carries no EXIF either — so the result is correct, just not
     * independently verified by the server.
     *
     * @return array{width: int, height: int, bytes: int}
     */
    private static function storeDerivatives(
        string $tmp,
        string $mime,
        string $absFull,
        string $absThumb,
    ): array {
        if (ImageProcessor::jpegSupported()) {
            $meta = ImageProcessor::writeResizedJpeg(
                $tmp,
                $absFull,
                ImageProcessor::MAX_EDGE,
                ImageProcessor::FULL_QUALITY,
            );
            ImageProcessor::writeResizedJpeg(
                $tmp,
                $absThumb,
                ImageProcessor::THUMB_EDGE,
                ImageProcessor::THUMB_QUALITY,
            );
            return $meta;
        }

        if ($mime !== 'image/jpeg') {
            // Without GD-JPEG we cannot transcode, and the appendix embeds
            // JPEGs — so a PNG/WebP upload has nowhere to go.
            throw new \RuntimeException('GD has no JPEG support; cannot transcode ' . $mime . '.');
        }

        error_log('[photo-upload] GD has no JPEG support — storing client-resized bytes as-is.');
        if (!copy($tmp, $absFull) || !copy($tmp, $absThumb)) {
            throw new \RuntimeException('Failed to store photo.');
        }
        $size = @getimagesize($absFull);
        return [
            'width'  => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
            'bytes'  => (int) (filesize($absFull) ?: 0),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function shape(array $row): array
    {
        $base = '/api/inspections/' . (int) $row['inspection_id']
            . '/items/' . (int) $row['item_id']
            . '/photos/' . (int) $row['id'];

        return [
            'id'         => (int) $row['id'],
            'item_id'    => (int) $row['item_id'],
            'position'   => (int) $row['position'],
            'byte_size'  => (int) $row['byte_size'],
            'width'      => (int) $row['width'],
            'height'     => (int) $row['height'],
            'created_at' => $row['created_at'],
            'url'        => $base,
            'thumb_url'  => $base . '?size=thumb',
        ];
    }

    /** @return array<string, mixed> */
    private static function loadInspectionOrFail(?int $accountId, int $inspectionId): array
    {
        $sql = 'SELECT id, account_id, status FROM inspections
                WHERE id = ? AND archived_at IS NULL';
        $params = [$inspectionId];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
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

    /** @param array<string, mixed> $inspection */
    private static function assertEditable(array $inspection): void
    {
        if (($inspection['status'] ?? '') === 'finalized') {
            Response::error(
                'Protokol je uzamknutý — fotky sa už nedajú meniť.',
                409,
            );
        }
    }

    private static function assertItemBelongs(int $itemId, int $inspectionId): void
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM inspection_items WHERE id = ? AND inspection_id = ?'
        );
        $stmt->execute([$itemId, $inspectionId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Item not found', 404);
        }
    }

    private static function unlinkQuietly(string ...$paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fotka je príliš veľká pre server.',
            UPLOAD_ERR_PARTIAL                        => 'Prenos fotky sa prerušil — skús to znova.',
            UPLOAD_ERR_NO_FILE                        => 'Nebola priložená žiadna fotka.',
            default                                   => 'Nahratie fotky zlyhalo.',
        };
    }
}
