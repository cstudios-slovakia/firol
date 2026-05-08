<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

final class TrainingController
{
    /** Locked enum slugs from docs/Firol base document. */
    private const TYPES = [
        'vstupne',
        'opakovane',
        'opp_mimo',
        'zdrzujuca_sa',
        'hliadka_oph',
        'hliadka_opah',
    ];

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();

        $companyId  = self::queryInt($req, 'company_id');
        $facilityId = self::queryInt($req, 'facility_id');
        $type       = $req->query('type');

        $sql = 'SELECT t.id, t.type, t.date, t.duration_min, t.topics, t.status,
                       t.created_at,
                       t.company_id, c.name AS company_name,
                       t.facility_id, f.name AS facility_name,
                       t.trainer_id, tr.fullname AS trainer_name,
                       (SELECT COUNT(*) FROM trainees WHERE training_id = t.id) AS trainees_count
                FROM   trainings t
                JOIN   companies  c  ON c.id = t.company_id
                LEFT JOIN facilities f  ON f.id = t.facility_id
                LEFT JOIN trainers   tr ON tr.id = t.trainer_id
                WHERE  t.account_id = :account_id AND t.archived_at IS NULL';
        $params = ['account_id' => $accountId];

        if ($companyId !== null) {
            $sql .= ' AND t.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($facilityId !== null) {
            $sql .= ' AND t.facility_id = :facility_id';
            $params['facility_id'] = $facilityId;
        }
        if ($type !== null) {
            $sql .= ' AND t.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY COALESCE(t.date, t.created_at) DESC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shape'], $stmt->fetchAll());
        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        $row = self::loadOrFail($accountId, $id);

        $tStmt = Db::pdo()->prepare(
            'SELECT id, fullname, position, signature_path, signed_at,
                    created_at, updated_at
             FROM   trainees
             WHERE  training_id = ?
             ORDER  BY id ASC'
        );
        $tStmt->execute([$id]);
        $trainees = array_map(static function (array $r): array {
            return [
                'id'             => (int) $r['id'],
                'fullname'       => $r['fullname'],
                'position'       => $r['position'],
                'has_signature'  => !empty($r['signature_path']),
                'signed_at'      => $r['signed_at'],
                'created_at'     => $r['created_at'],
                'updated_at'     => $r['updated_at'],
            ];
        }, $tStmt->fetchAll());

        Response::json([
            'training' => self::shape($row),
            'trainees' => $trainees,
        ]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $type        = $req->jsonString('type');
        $companyId   = $req->jsonInt('company_id');
        $facilityId  = $req->jsonInt('facility_id');
        $date        = $req->jsonString('date');
        $trainerId   = $req->jsonInt('trainer_id');
        $topics      = $req->jsonString('topics');
        $durationMin = $req->jsonInt('duration_min');

        if ($type === null || !in_array($type, self::TYPES, true)) {
            Response::error('Invalid training type', 422);
        }
        if ($companyId === null) {
            Response::error('Field required: company_id', 422);
        }
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Invalid date (expected YYYY-MM-DD)', 422);
        }

        // Verify company belongs to the account; facility (optional) must
        // belong to the same company. Trainer (optional) must belong to
        // the account.
        $check = Db::pdo()->prepare(
            'SELECT 1 FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $check->execute([$companyId, $accountId]);
        if ($check->fetchColumn() === false) {
            Response::error('Company not found', 404);
        }

        if ($facilityId !== null) {
            $fc = Db::pdo()->prepare(
                'SELECT 1 FROM facilities
                 WHERE id = ? AND company_id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $fc->execute([$facilityId, $companyId, $accountId]);
            if ($fc->fetchColumn() === false) {
                Response::error('Facility does not belong to the chosen company', 422);
            }
        }

        if ($trainerId !== null) {
            $tc = Db::pdo()->prepare(
                'SELECT 1 FROM trainers
                 WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $tc->execute([$trainerId, $accountId]);
            if ($tc->fetchColumn() === false) {
                Response::error('Trainer not found', 422);
            }
        }

        Db::pdo()->prepare(
            'INSERT INTO trainings
                (account_id, company_id, facility_id, type, date,
                 trainer_id, topics, duration_min, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft")'
        )->execute([
            $accountId, $companyId, $facilityId, $type, $date,
            $trainerId, $topics, $durationMin,
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['training' => self::shape(self::loadOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];
        $existing  = self::loadOrFail($accountId, $id);

        $date        = $req->jsonString('date');
        $trainerId   = $req->jsonInt('trainer_id');
        $topics      = $req->jsonString('topics');
        $durationMin = $req->jsonInt('duration_min');

        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Response::error('Invalid date (expected YYYY-MM-DD)', 422);
        }
        if ($trainerId !== null) {
            $tc = Db::pdo()->prepare(
                'SELECT 1 FROM trainers
                 WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $tc->execute([$trainerId, $accountId]);
            if ($tc->fetchColumn() === false) {
                Response::error('Trainer not found', 422);
            }
        }

        Db::pdo()->prepare(
            'UPDATE trainings
             SET    date         = COALESCE(?, date),
                    trainer_id   = COALESCE(?, trainer_id),
                    topics       = COALESCE(?, topics),
                    duration_min = COALESCE(?, duration_min)
             WHERE  id = ? AND account_id = ?'
        )->execute([$date, $trainerId, $topics, $durationMin, $id, $accountId]);
        unset($existing);

        Response::json(['training' => self::shape(self::loadOrFail($accountId, $id))]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];
        self::loadOrFail($accountId, $id);

        Db::pdo()->prepare(
            'UPDATE trainings SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $accountId]);

        Response::noContent();
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(int $accountId, int $id): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.id, t.account_id, t.type, t.date, t.duration_min, t.topics,
                    t.status, t.created_at, t.updated_at,
                    t.company_id, c.name AS company_name, c.ico AS company_ico,
                    t.facility_id, f.name AS facility_name,
                    t.trainer_id, tr.fullname AS trainer_name,
                    tr.certification_number AS trainer_certification_number,
                    (SELECT COUNT(*) FROM trainees WHERE training_id = t.id) AS trainees_count
             FROM   trainings t
             JOIN   companies   c  ON c.id = t.company_id
             LEFT JOIN facilities f  ON f.id = t.facility_id
             LEFT JOIN trainers   tr ON tr.id = t.trainer_id
             WHERE  t.id = ? AND t.account_id = ? AND t.archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Training not found', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['id']             = (int) $row['id'];
        $row['company_id']     = (int) $row['company_id'];
        $row['facility_id']    = $row['facility_id'] !== null ? (int) $row['facility_id'] : null;
        $row['trainer_id']     = $row['trainer_id'] !== null ? (int) $row['trainer_id'] : null;
        $row['duration_min']   = $row['duration_min'] !== null ? (int) $row['duration_min'] : null;
        $row['trainees_count'] = isset($row['trainees_count']) ? (int) $row['trainees_count'] : 0;
        unset($row['account_id']);
        return $row;
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $v = $req->query($key);
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }
}
