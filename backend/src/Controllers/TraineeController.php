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
 * The body of `store` is multipart/form-data: text fields `fullname`,
 * `position` and `personal_number` alongside a binary `signature` PNG produced
 * by the on-screen canvas. `update` and `bulk` take JSON.
 *
 *   POST   /api/trainings/{id}/trainees              add one attendee
 *   PATCH  /api/trainings/{id}/trainees/{trainee_id} edit one attendee
 *   POST   /api/trainings/{id}/trainees/bulk         add many at once (spec 8.3)
 *   GET    /api/trainings/{id}/trainee-source        the list that could be taken over
 *   POST   /api/trainings/{id}/trainees/take-over    take it over (spec 8.3)
 *   DELETE /api/trainings/{id}/trainees/{trainee_id} remove one attendee
 */
final class TraineeController
{
    private const MAX_SIGNATURE_BYTES = 524288;

    /** Spec 8.3 — a shift of twenty is the case; this only stops a runaway paste. */
    private const MAX_BULK_ROWS = 500;

    private const MAX_NAME = 191;
    private const MAX_PERSONAL_NUMBER = 32;

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
        $personalNumber = self::trimmedPostString('personal_number', max: self::MAX_PERSONAL_NUMBER, required: false);

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
                'INSERT INTO trainees (training_id, fullname, position, personal_number)
                 VALUES (?, ?, ?, ?)'
            )->execute([$trainingId, $fullname, $position, $personalNumber]);
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

    /**
     * Edit one attendee (spec 8.3: „Účastník sa musí dať opraviť, nielen
     * zmazať"). JSON body: `fullname` (required), `position` and
     * `personal_number` (optional — an empty value clears them). A JSON PATCH
     * rather than multipart so a queued offline edit replays like any other.
     */
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

        $fullname       = self::jsonText($req, 'fullname', self::MAX_NAME, required: true);
        $position       = self::jsonText($req, 'position', self::MAX_NAME, required: false);
        $personalNumber = self::jsonText($req, 'personal_number', self::MAX_PERSONAL_NUMBER, required: false);

        /* SIGNATURE DISABLED — the on-screen capture is off, and a multipart body does not reach
           a PATCH. To restore: accept the PNG on a POST route, validate it like `store` does and
           move it to Storage::traineeSignaturePath() together with signature_path / signed_at. */

        Db::pdo()->prepare(
            'UPDATE trainees SET fullname = ?, position = ?, personal_number = ?
             WHERE id = ? AND training_id = ?'
        )->execute([$fullname, $position, $personalNumber, $traineeId, $trainingId]);

        Response::json(['trainee' => self::loadTrainee($traineeId)]);
    }

    /**
     * Add many attendees in one request (spec 8.3 „Hromadné pridanie"). The
     * client has already split the pasted text into rows; this validates each
     * one and inserts them all in a single transaction — either every row is
     * saved or none is, so a paste is never half-applied.
     *
     * Body: {"rows": [{"fullname", "position"?, "personal_number"?}, ...]}
     */
    public static function bulk(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];

        $training = self::loadTrainingOrFail($isAdmin ? null : $accountId, $trainingId);
        if ($training['status'] === 'finalized') {
            Response::error('Školenie je uzamknuté — účastníkov už nemožno meniť.', 409);
        }

        $rows = $req->json()['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            Response::error('Nalep aspoň jedno meno.', 422);
        }
        if (count($rows) > self::MAX_BULK_ROWS) {
            Response::error('Naraz sa dá pridať najviac ' . self::MAX_BULK_ROWS . ' ľudí.', 422);
        }

        $clean = [];
        foreach (array_values($rows) as $idx => $row) {
            $line = $idx + 1;
            if (!is_array($row)) {
                Response::error("Riadok $line: neplatné údaje.", 422);
            }
            $name = self::cleanText($row['fullname'] ?? null);
            if ($name === null) {
                Response::error("Riadok $line: chýba meno.", 422);
            }
            $position = self::cleanText($row['position'] ?? null);
            $number   = self::cleanText($row['personal_number'] ?? null);
            if (mb_strlen($name) > self::MAX_NAME || ($position !== null && mb_strlen($position) > self::MAX_NAME)) {
                Response::error("Riadok $line: text je príliš dlhý (najviac " . self::MAX_NAME . ' znakov).', 422);
            }
            if ($number !== null && mb_strlen($number) > self::MAX_PERSONAL_NUMBER) {
                Response::error("Riadok $line: osobné číslo je príliš dlhé (najviac " . self::MAX_PERSONAL_NUMBER . ' znakov).', 422);
            }
            $clean[] = [$name, $position, $number];
        }

        $ids = self::insertMany($trainingId, $clean);
        Response::json([
            'added'    => count($ids),
            'trainees' => array_map([self::class, 'loadTrainee'], $ids),
        ], 201);
    }

    /**
     * The earlier training whose attendees could be taken over: the latest
     * finalized training of the same firma that has an attendee list, the same
     * prevádzka first (spec 8.3 „Prevziať z minulého školenia"). `source` is
     * null when there is nothing to take over.
     */
    public static function source(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $training  = self::loadTrainingOrFail($isAdmin ? null : $accountId, (int) $params['id']);
        Response::json(['source' => self::findSource($training)]);
    }

    /**
     * Append the attendees of the source named by `source_id` (as offered by
     * {@see source()}) to this draft. People already on the list (same name,
     * case-insensitively) are skipped, so taking over twice never duplicates
     * anyone. Name, pracovné zaradenie and osobné číslo travel — never a
     * signature.
     */
    public static function takeOver(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId  = Tenant::currentAccountId();
        $isAdmin    = Admin::isAdmin(Tenant::currentUserId());
        $trainingId = (int) $params['id'];

        $training = self::loadTrainingOrFail($isAdmin ? null : $accountId, $trainingId);
        if ($training['status'] === 'finalized') {
            Response::error('Školenie je uzamknuté — účastníkov už nemožno meniť.', 409);
        }
        $sourceId = $req->jsonInt('source_id');
        if ($sourceId === null) {
            Response::error('Vyber školenie, z ktorého sa majú účastníci prevziať.', 422);
        }

        // The source must be a finalized training of the same firma — the same
        // rule that offered it — and in the same account.
        $check = Db::pdo()->prepare(
            'SELECT 1 FROM trainings
             WHERE  id = ? AND account_id = ? AND company_id = ? AND id <> ?
               AND  status = "finalized" AND archived_at IS NULL'
        );
        $check->execute([$sourceId, (int) $training['account_id'], (int) $training['company_id'], $trainingId]);
        if ($check->fetchColumn() === false) {
            Response::error('Zdroj sa nenašiel.', 404);
        }

        $people = Db::pdo()->prepare(
            'SELECT fullname, position, personal_number FROM trainees WHERE training_id = ? ORDER BY id ASC'
        );
        $people->execute([$sourceId]);
        $incoming = $people->fetchAll();

        $existing = Db::pdo()->prepare('SELECT fullname FROM trainees WHERE training_id = ?');
        $existing->execute([$trainingId]);
        $known = [];
        foreach ($existing->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $known[self::nameKey((string) $name)] = true;
        }

        $rows = [];
        foreach ($incoming as $p) {
            $key = self::nameKey((string) $p['fullname']);
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            $known[$key] = true;
            $rows[] = [(string) $p['fullname'], $p['position'], $p['personal_number']];
        }
        $ids = self::insertMany($trainingId, $rows);

        Response::json([
            'added'    => count($ids),
            'skipped'  => count($incoming) - count($ids),
            'trainees' => array_map([self::class, 'loadTrainee'], $ids),
        ], 201);
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
        $sql = 'SELECT id, account_id, company_id, facility_id, status FROM trainings
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
            'SELECT id, fullname, position, personal_number, signature_path, signed_at
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
            'SELECT id, fullname, position, personal_number, signature_path, signed_at,
                    created_at, updated_at
             FROM   trainees WHERE id = ?'
        );
        $stmt->execute([$traineeId]);
        $row = $stmt->fetch();
        return [
            'id'              => (int) $row['id'],
            'fullname'        => $row['fullname'],
            'position'        => $row['position'],
            'personal_number' => $row['personal_number'],
            'has_signature'   => !empty($row['signature_path']),
            'signed_at'       => $row['signed_at'],
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
        ];
    }

    /**
     * Insert attendees in one transaction and return their ids, in order.
     *
     * @param list<array{0: string, 1: string|null, 2: string|null}> $rows name, position, personal number
     * @return list<int>
     */
    private static function insertMany(int $trainingId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO trainees (training_id, fullname, position, personal_number)
                 VALUES (?, ?, ?, ?)'
            );
            $ids = [];
            foreach ($rows as [$name, $position, $number]) {
                $insert->execute([$trainingId, $name, $position, $number]);
                $ids[] = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            return $ids;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[trainee-bulk] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Účastníkov sa nepodarilo uložiť.', 500);
        }
    }

    /**
     * @param array<string, mixed> $training the current training (account, firma, prevádzka)
     * @return array<string, mixed>|null
     */
    private static function findSource(array $training): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.id, t.type, t.date, f.name AS facility_name,
                    (SELECT COUNT(*) FROM trainees tr WHERE tr.training_id = t.id) AS people
             FROM   trainings t
             LEFT   JOIN facilities f ON f.id = t.facility_id
             WHERE  t.account_id = ? AND t.company_id = ? AND t.id <> ?
               AND  t.status = "finalized" AND t.archived_at IS NULL
             HAVING people > 0
             ORDER  BY (t.facility_id <=> ?) DESC, COALESCE(t.date, "1000-01-01") DESC, t.id DESC
             LIMIT  1'
        );
        $stmt->execute([
            (int) $training['account_id'],
            (int) $training['company_id'],
            (int) $training['id'],
            $training['facility_id'] !== null ? (int) $training['facility_id'] : null,
        ]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        return [
            'id'            => (int) $r['id'],
            'type'          => (string) $r['type'],
            'date'          => $r['date'] !== null ? (string) $r['date'] : null,
            'facility_name' => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
            'people'        => (int) $r['people'],
        ];
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }

    /** Trimmed string, or null when the value is not a string or is blank. */
    private static function cleanText(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);
        return $value === '' ? null : $value;
    }

    private static function jsonText(Request $req, string $key, int $max, bool $required): ?string
    {
        $value = self::cleanText($req->json()[$key] ?? null);
        if ($value === null) {
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
