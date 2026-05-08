<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;

/**
 * Trainer pool. Each account keeps its own list of qualified trainers
 * (typically contractors) used as the "Vykonal školenie" entry on the
 * generated PDF. Signatures are stored as PNG outside the docroot, same
 * pattern as inspector signatures.
 */
final class TrainerController
{
    private const MAX_SIGNATURE_BYTES = 524288;

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $stmt = Db::pdo()->prepare(
            'SELECT id, fullname, certification_number, signature_path
             FROM   trainers
             WHERE  account_id = ? AND archived_at IS NULL
             ORDER  BY fullname ASC'
        );
        $stmt->execute([$accountId]);
        $items = array_map([self::class, 'shape'], $stmt->fetchAll());
        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $row = self::loadOrFail($accountId, (int) $params['id']);
        Response::json(['trainer' => self::shape($row)]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        [$fullname, $cert] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO trainers (account_id, fullname, certification_number)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$accountId, $fullname, $cert]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['trainer' => self::shape(self::loadOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];
        self::loadOrFail($accountId, $id);

        [$fullname, $cert] = self::readBody($req);

        Db::pdo()->prepare(
            'UPDATE trainers
             SET    fullname = ?, certification_number = ?
             WHERE  id = ? AND account_id = ?'
        )->execute([$fullname, $cert, $id, $accountId]);

        Response::json(['trainer' => self::shape(self::loadOrFail($accountId, $id))]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];
        self::loadOrFail($accountId, $id);

        Db::pdo()->prepare(
            'UPDATE trainers SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $accountId]);

        Response::noContent();
    }

    public static function uploadSignature(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];
        self::loadOrFail($accountId, $id);

        $file = $_FILES['signature'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('No signature file uploaded', 422);
        }
        if (($file['size'] ?? 0) > self::MAX_SIGNATURE_BYTES) {
            Response::error('Signature too large (max 512 KB)', 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('Upload failed', 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== 'image/png') {
            Response::error('Signature must be a PNG image', 422);
        }

        $dest = Storage::trainerSignaturePath($accountId, $id);
        Storage::ensureDir(dirname($dest));
        if (!move_uploaded_file($tmp, $dest)) {
            Response::error('Failed to store signature', 500);
        }

        Db::pdo()->prepare(
            'UPDATE trainers SET signature_path = ?
             WHERE  id = ? AND account_id = ?'
        )->execute([Storage::trainerSignatureRelative($accountId, $id), $id, $accountId]);

        Response::json(['trainer' => self::shape(self::loadOrFail($accountId, $id))]);
    }

    public static function downloadSignature(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];
        $row = self::loadOrFail($accountId, $id);

        $abs = $row['signature_path']
            ? Storage::documentAbsolute((string) $row['signature_path'])
            : '';
        if (empty($row['signature_path']) || !is_file($abs)) {
            Response::error('No signature on file', 404);
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=30');
        readfile($abs);
        exit;
    }

    /** @return array{0:string,1:?string} */
    private static function readBody(Request $req): array
    {
        $fullname = $req->jsonString('fullname');
        $cert     = $req->jsonString('certification_number');
        if ($fullname === null || $fullname === '') {
            Response::error('Field required: fullname', 422);
        }
        return [$fullname, $cert];
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(int $accountId, int $id): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fullname, certification_number, signature_path
             FROM   trainers
             WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Trainer not found', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'fullname'             => $row['fullname'],
            'certification_number' => $row['certification_number'],
            'has_signature'        => !empty($row['signature_path']),
        ];
    }
}
