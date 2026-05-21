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

/**
 * Trainee = one attendee of a training, identified by fullname + position
 * with a captured PNG signature. The signature is required because that's
 * the whole point of the training protocol — without it the row carries
 * no legal weight.
 *
 * The body is multipart/form-data: text fields `fullname` and `position`
 * alongside a binary `signature` PNG produced by the on-screen canvas.
 */
final class TraineeController
{
    private const MAX_SIGNATURE_BYTES = 524288;

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];

        $training = self::loadTrainingOrFail($isAdmin ? null : $accountId, $trainingId);
        if ($training['status'] === 'finalized') {
            Response::error('Školenie je uzamknuté — účastníkov už nemožno meniť.', 409);
        }

        $fullname = self::trimmedPostString('fullname', max: 191, required: true);
        $position = self::trimmedPostString('position', max: 191, required: false);

        /* SIGNATURE DISABLED — on-screen capture removed; PDF has a blank field for manual signing.
           To restore: uncomment this block and the file-move block inside the transaction below.
        $file = $_FILES['signature'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Podpis je povinný — bez neho protokol nemá platnosť.', 422);
        }
        if (($file['size'] ?? 0) > self::MAX_SIGNATURE_BYTES) {
            Response::error('Podpis je príliš veľký (max 512 KB).', 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('Upload zlyhal.', 422);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== 'image/png') {
            Response::error('Podpis musí byť PNG obrázok.', 422);
        }
        */

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO trainees (training_id, fullname, position)
                 VALUES (?, ?, ?)'
            )->execute([$trainingId, $fullname, $position]);
            $traineeId = (int) $pdo->lastInsertId();

            /* SIGNATURE DISABLED — file-move + path update; restore together with the block above.
            $dest = Storage::traineeSignaturePath($trainingId, $traineeId);
            Storage::ensureDir(dirname($dest));
            if (!move_uploaded_file($tmp, $dest)) {
                throw new \RuntimeException('Failed to store signature file.');
            }
            $rel = Storage::traineeSignatureRelative($trainingId, $traineeId);
            $pdo->prepare(
                'UPDATE trainees SET signature_path = ? WHERE id = ?'
            )->execute([$rel, $traineeId]);
            */

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[trainee-store] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Účastníka sa nepodarilo uložiť.', 500);
        }

        Response::json(['trainee' => self::loadTrainee($traineeId)], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];
        $traineeId  = (int) $params['trainee_id'];

        $training = self::loadTrainingOrFail($isAdmin ? null : $accountId, $trainingId);
        if ($training['status'] === 'finalized') {
            Response::error('Školenie je uzamknuté — účastníkov už nemožno meniť.', 409);
        }
        self::loadTraineeForTrainingOrFail($traineeId, $trainingId);

        $fullname = self::trimmedPostString('fullname', max: 191, required: true);
        $position = self::trimmedPostString('position', max: 191, required: false);

        $file           = $_FILES['signature'] ?? null;
        $hasNewSig      = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($hasNewSig) {
            if (($file['size'] ?? 0) > self::MAX_SIGNATURE_BYTES) {
                Response::error('Podpis je príliš veľký (max 512 KB).', 422);
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                Response::error('Upload zlyhal.', 422);
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
            if ($finfo) {
                finfo_close($finfo);
            }
            if ($mime !== 'image/png') {
                Response::error('Podpis musí byť PNG obrázok.', 422);
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($hasNewSig) {
                $dest = Storage::traineeSignaturePath($trainingId, $traineeId);
                Storage::ensureDir(dirname($dest));
                if (!move_uploaded_file($tmp, $dest)) {
                    throw new \RuntimeException('Failed to store signature file.');
                }
                $rel = Storage::traineeSignatureRelative($trainingId, $traineeId);
                $pdo->prepare(
                    'UPDATE trainees SET fullname = ?, position = ?, signature_path = ?, signed_at = NOW()
                     WHERE id = ? AND training_id = ?'
                )->execute([$fullname, $position, $rel, $traineeId, $trainingId]);
            } else {
                $pdo->prepare(
                    'UPDATE trainees SET fullname = ?, position = ?
                     WHERE id = ? AND training_id = ?'
                )->execute([$fullname, $position, $traineeId, $trainingId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[trainee-update] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Účastníka sa nepodarilo uložiť.', 500);
        }

        Response::json(['trainee' => self::loadTrainee($traineeId)]);
    }

    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];
        $traineeId  = (int) $params['trainee_id'];

        $training = self::loadTrainingOrFail($isAdmin ? null : $accountId, $trainingId);
        if ($training['status'] === 'finalized') {
            Response::error('Školenie je uzamknuté — účastníkov už nemožno meniť.', 409);
        }

        $row = self::loadTraineeForTrainingOrFail($traineeId, $trainingId);

        Db::pdo()->prepare(
            'DELETE FROM trainees WHERE id = ? AND training_id = ?'
        )->execute([$traineeId, $trainingId]);

        // Clean up the signature file. Best-effort — DB row is already gone.
        if (!empty($row['signature_path'])) {
            $abs = Storage::documentAbsolute((string) $row['signature_path']);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        Response::noContent();
    }

    /**
     * Streams the trainee's signature PNG. Tenant check goes through the
     * parent training so a leaked trainee id by itself is not enough.
     */
    public static function downloadSignature(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $traineeId = (int) $params['id'];

        if ($isAdmin) {
            $stmt = Db::pdo()->prepare(
                'SELECT t.signature_path, tr.account_id
                 FROM   trainees  t
                 JOIN   trainings tr ON tr.id = t.training_id
                 WHERE  t.id = ? AND tr.archived_at IS NULL'
            );
            $stmt->execute([$traineeId]);
        } else {
            $stmt = Db::pdo()->prepare(
                'SELECT t.signature_path, tr.account_id
                 FROM   trainees  t
                 JOIN   trainings tr ON tr.id = t.training_id
                 WHERE  t.id = ? AND tr.account_id = ? AND tr.archived_at IS NULL'
            );
            $stmt->execute([$traineeId, $accountId]);
        }
        $row = $stmt->fetch();
        if (!$row || empty($row['signature_path'])) {
            Response::error('Podpis sa nenašiel.', 404);
        }

        $abs = Storage::documentAbsolute((string) $row['signature_path']);
        if (!is_file($abs)) {
            Response::error('Podpis sa nenašiel.', 404);
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=300');
        readfile($abs);
        exit;
    }

    /** @return array<string, mixed> */
    private static function loadTrainingOrFail(?int $accountId, int $trainingId): array
    {
        $sql = 'SELECT id, status FROM trainings
                WHERE id = ? AND archived_at IS NULL';
        $params = [$trainingId];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Školenie sa nenašlo.', 404);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private static function loadTraineeForTrainingOrFail(int $traineeId, int $trainingId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fullname, position, signature_path, signed_at
             FROM   trainees WHERE id = ? AND training_id = ?'
        );
        $stmt->execute([$traineeId, $trainingId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Účastník sa nenašiel.', 404);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private static function loadTrainee(int $traineeId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, fullname, position, signature_path, signed_at,
                    created_at, updated_at
             FROM   trainees WHERE id = ?'
        );
        $stmt->execute([$traineeId]);
        $row = $stmt->fetch();
        return [
            'id'            => (int) $row['id'],
            'fullname'      => $row['fullname'],
            'position'      => $row['position'],
            'has_signature' => !empty($row['signature_path']),
            'signed_at'     => $row['signed_at'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
        ];
    }

    private static function trimmedPostString(string $key, int $max, bool $required): ?string
    {
        $raw = $_POST[$key] ?? null;
        if (!is_string($raw)) {
            if ($required) {
                Response::error("Pole je povinné: $key", 422);
            }
            return null;
        }
        $value = trim($raw);
        if ($value === '') {
            if ($required) {
                Response::error("Pole je povinné: $key", 422);
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            Response::error("Pole je príliš dlhé: $key (max $max znakov)", 422);
        }
        return $value;
    }
}
