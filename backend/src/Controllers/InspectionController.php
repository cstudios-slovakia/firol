<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use PDO;

final class InspectionController
{
    /**
     * Allowed periodicities per inspection type. Source of truth: locked
     * decisions in docs/Firol base document and docs/development-roadmap.md.
     * Step 1 must reject anything outside this map.
     *
     * @var array<string, list<int>>
     */
    private const TYPE_PERIODICITIES = [
        'php'                => [12, 24],
        'hydranty'           => [12],
        'oprava_ts_php'      => [60],
        'poziarna_kniha'     => [3, 6],
        'pu_akcieschopnost'  => [3],
        'pu_udrzba'          => [12],
        'nudzove_osvetlenie' => [12],
        'ts_hadic'           => [60],
    ];

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());

        $companyId  = self::queryInt($req, 'company_id');
        $facilityId = self::queryInt($req, 'facility_id');
        $type       = $req->query('type');

        $sql = 'SELECT i.id, i.type, i.periodicity_months, i.executed_on,
                       i.status, i.notes, i.created_at,
                       i.company_id, c.name AS company_name,
                       i.facility_id, f.name AS facility_name,
                       i.inspector_user_id, u.fullname AS inspector_name,
                       i.effective_inspector_user_id,
                       eu.fullname AS effective_inspector_name,
                       i.effective_cert_number
                FROM   inspections i
                JOIN   companies   c ON c.id = i.company_id
                JOIN   facilities  f ON f.id = i.facility_id
                JOIN   users       u ON u.id = i.inspector_user_id
                LEFT JOIN users    eu ON eu.id = i.effective_inspector_user_id
                WHERE  i.archived_at IS NULL';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND i.account_id = :account_id';
            $params['account_id'] = $accountId;
        }

        if ($companyId !== null) {
            $sql .= ' AND i.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($facilityId !== null) {
            $sql .= ' AND i.facility_id = :facility_id';
            $params['facility_id'] = $facilityId;
        }
        if ($type !== null) {
            $sql .= ' AND i.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY COALESCE(i.executed_on, i.created_at) DESC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shapeRow'], $stmt->fetchAll());

        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);

        $itemsStmt = Db::pdo()->prepare(
            'SELECT id, position, fields, created_at, updated_at
             FROM   inspection_items
             WHERE  inspection_id = ?
             ORDER  BY position ASC, id ASC'
        );
        $itemsStmt->execute([$id]);
        $rawItems = $itemsStmt->fetchAll();
        $items = array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'position'   => (int) $r['position'],
                'fields'     => json_decode((string) $r['fields'], true) ?? [],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $rawItems);

        Response::json([
            'inspection' => self::shapeRow($row),
            'items'      => $items,
        ]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId    = Tenant::currentUserId();
        $isAdmin   = Admin::isAdmin($userId);

        $type              = $req->jsonString('type');
        $periodicityMonths = $req->jsonInt('periodicity_months');
        $executedOn        = $req->jsonString('executed_on');
        $companyId         = $req->jsonInt('company_id');
        $facilityId        = $req->jsonInt('facility_id');
        $inspectorUserId   = $req->jsonInt('inspector_user_id') ?? $userId;
        $notes             = $req->jsonString('notes');

        if ($type === null || !isset(self::TYPE_PERIODICITIES[$type])) {
            Response::error('Invalid inspection type', 422);
        }
        if ($periodicityMonths === null
            || !in_array($periodicityMonths, self::TYPE_PERIODICITIES[$type], true)
        ) {
            Response::error('Invalid periodicity for this type', 422);
        }
        if ($companyId === null || $facilityId === null) {
            Response::error('Field required: company_id, facility_id', 422);
        }
        if ($executedOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
            Response::error('Invalid executed_on (expected YYYY-MM-DD)', 422);
        }

        // Verify the facility exists, belongs to the company, and (for
        // non-admins) sits inside the active account. Admins may create
        // inspections under any account — the inspection inherits the
        // facility's account_id.
        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT f.account_id
                 FROM   facilities f
                 JOIN   companies  c ON c.id = f.company_id
                 WHERE  f.id = ? AND f.company_id = ?
                    AND f.archived_at IS NULL AND c.archived_at IS NULL'
            );
            $check->execute([$facilityId, $companyId]);
            $facAccountId = $check->fetchColumn();
            if ($facAccountId === false) {
                Response::error('Company or facility not found', 404);
            }
            $accountId = (int) $facAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1
                 FROM   facilities f
                 JOIN   companies  c ON c.id = f.company_id
                 WHERE  f.id = ? AND f.company_id = ? AND f.account_id = ?
                    AND f.archived_at IS NULL AND c.archived_at IS NULL'
            );
            $check->execute([$facilityId, $companyId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Company or facility not found', 404);
            }
        }

        // Inspector must be a user attached to the inspection's account.
        // Sys-admins are exempt — they act across accounts and may assign
        // themselves as inspector even without an account_users row.
        if (!$isAdmin) {
            $auCheck = Db::pdo()->prepare(
                'SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?'
            );
            $auCheck->execute([$accountId, $inspectorUserId]);
            if ($auCheck->fetchColumn() === false) {
                Response::error('Inspector is not a member of this account', 422);
            }
        }

        // Block creation when the executing technician has no own cert AND
        // no usable fallback. Admins are exempt (they manage other accounts'
        // data without their own profile).
        if (!$isAdmin) {
            self::assertCanInspect($accountId, $inspectorUserId, $type);
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, type, periodicity_months,
                 executed_on, inspector_user_id, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?)'
        );
        $stmt->execute([
            $accountId, $companyId, $facilityId, $type, $periodicityMonths,
            $executedOn, $inspectorUserId, $notes,
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json([
            'inspection' => self::shapeRow(self::loadOrFail($accountId, $id)),
            'items'      => [],
        ], 201);
    }

    public static function updateBasic(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $row['account_id'] : $accountId;

        $executedOn = $req->jsonString('executed_on');
        $notes      = $req->jsonString('notes');
        $periodicityMonths = $req->jsonInt('periodicity_months');

        if ($executedOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
            Response::error('Invalid executed_on (expected YYYY-MM-DD)', 422);
        }
        if ($periodicityMonths !== null) {
            $allowed = self::TYPE_PERIODICITIES[$row['type']] ?? [];
            if (!in_array($periodicityMonths, $allowed, true)) {
                Response::error('Invalid periodicity for this type', 422);
            }
        }

        // COALESCE keeps existing values when the field is not in the body.
        Db::pdo()->prepare(
            'UPDATE inspections
             SET    executed_on        = COALESCE(?, executed_on),
                    notes              = COALESCE(?, notes),
                    periodicity_months = COALESCE(?, periodicity_months)
             WHERE  id = ? AND account_id = ?'
        )->execute([$executedOn, $notes, $periodicityMonths, $id, $scopeAccountId]);

        $fresh = self::loadOrFail($scopeAccountId, $id);
        Response::json(['inspection' => self::shapeRow($fresh)]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        Db::pdo()->prepare(
            'UPDATE inspections SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $scopeAccountId]);

        Response::noContent();
    }

    /**
     * "Opakovať" — clone a finalized inspection into a fresh draft so the
     * technician can re-issue the protocol with a new date and minor edits
     * (items copied verbatim). The source inspection and its document
     * remain untouched.
     */
    public static function repeat(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $sourceId  = (int) $params['id'];

        $source = self::loadOrFail($isAdmin ? null : $accountId, $sourceId);
        // Repeat lives in the same account as the source — admins repeating
        // a foreign-account inspection keep that account context.
        $accountId = (int) $source['account_id'];

        if ($source['status'] !== 'finalized') {
            Response::error('Opakovať možno len ukončenú kontrolu (s vystaveným PDF).', 422);
        }

        // Same cert guard as fresh creation — if the original inspector
        // lost their cert and no fallback is set, refuse to clone now
        // rather than at PDF time.
        if (!$isAdmin) {
            self::assertCanInspect(
                $accountId,
                (int) $source['inspector_user_id'],
                (string) $source['type'],
            );
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            // executed_on is intentionally NULL — Step 3 forces the
            // technician to enter a fresh date before generating PDF.
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type, periodicity_months,
                     executed_on, inspector_user_id, status, notes)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, "draft", ?)'
            )->execute([
                $accountId,
                $source['company_id'],
                $source['facility_id'],
                $source['type'],
                $source['periodicity_months'],
                $source['inspector_user_id'],
                $source['notes'],
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Cloning items in a single INSERT … SELECT keeps positions in
            // the original order without ferrying rows through PHP.
            $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields)
                 SELECT ?, position, fields
                 FROM   inspection_items
                 WHERE  inspection_id = ?
                 ORDER  BY position ASC, id ASC'
            )->execute([$newId, $sourceId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Re-use show()'s loader so the response shape matches what the
        // frontend expects when navigating directly to the new inspection.
        $fresh = self::loadOrFail($accountId, $newId);
        $itemsStmt = Db::pdo()->prepare(
            'SELECT id, position, fields, created_at, updated_at
             FROM   inspection_items
             WHERE  inspection_id = ?
             ORDER  BY position ASC, id ASC'
        );
        $itemsStmt->execute([$newId]);
        $items = array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'position'   => (int) $r['position'],
                'fields'     => json_decode((string) $r['fields'], true) ?? [],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $itemsStmt->fetchAll());

        Response::json([
            'inspection' => self::shapeRow($fresh),
            'items'      => $items,
            'source_id'  => $sourceId,
        ], 201);
    }

    /**
     * Refuse to create an inspection the executor can't legitimately
     * sign. Technik PO types (everything except php and oprava_ts_php)
     * require the executor's own cert_general — no borrowing. For PHP /
     * Oprava the executor may borrow the account-default technician's
     * cert; only when neither own nor default cert is available do we
     * block. Errors carry a `code` so the UI can show a useful hint.
     */
    private static function assertCanInspect(int $accountId, int $inspectorUserId, string $type): void
    {
        $stmt = Db::pdo()->prepare(
            'SELECT cert_php, cert_oprava, cert_general
             FROM   inspector_profiles
             WHERE  user_id = ? AND account_id = ?'
        );
        $stmt->execute([$inspectorUserId, $accountId]);
        $own = $stmt->fetch() ?: ['cert_php' => null, 'cert_oprava' => null, 'cert_general' => null];

        $has = static fn($v) => is_string($v) && trim($v) !== '';

        if ($type === 'php' || $type === 'oprava_ts_php') {
            $certKey    = $type === 'php' ? 'cert_php'             : 'cert_oprava';
            $defaultCol = $type === 'php' ? 'default_php_user_id'  : 'default_oprava_user_id';
            if ($has($own[$certKey] ?? null)) {
                return;
            }
            $defaultStmt = Db::pdo()->prepare(
                'SELECT ip.' . $certKey . '
                 FROM   accounts a
                 LEFT JOIN inspector_profiles ip
                        ON ip.user_id = a.' . $defaultCol . ' AND ip.account_id = a.id
                 WHERE  a.id = ?'
            );
            $defaultStmt->execute([$accountId]);
            $defaultCert = $defaultStmt->fetchColumn();
            if ($has($defaultCert)) {
                return;
            }
            $label = $type === 'php' ? 'Kontrola PHP' : 'Oprava / plnenie / TS PHP';
            Response::error(
                'Pre ' . $label . ' potrebuješ vlastné číslo oprávnenia, alebo nech account admin nastaví defaultného technika so zadaným číslom.',
                422,
                ['code' => 'cert_missing', 'cert' => $certKey],
            );
        }

        // All other types print cert_general (Technik PO). No fallback.
        if (!$has($own['cert_general'] ?? null)) {
            Response::error(
                'Tento typ kontroly vyžaduje platné číslo oprávnenia Technik PO. Doplň ho v Profile revízneho technika.',
                422,
                ['code' => 'cert_missing', 'cert' => 'cert_general'],
            );
        }
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT i.id, i.account_id, i.type, i.periodicity_months,
                       i.executed_on, i.status, i.notes,
                       i.created_at, i.updated_at,
                       i.company_id, c.name AS company_name, c.ico AS company_ico,
                       i.facility_id, f.name AS facility_name,
                       i.inspector_user_id, u.fullname AS inspector_name,
                       i.effective_inspector_user_id,
                       eu.fullname AS effective_inspector_name,
                       i.effective_cert_number
                FROM   inspections i
                JOIN   companies   c ON c.id = i.company_id
                JOIN   facilities  f ON f.id = i.facility_id
                JOIN   users       u ON u.id = i.inspector_user_id
                LEFT JOIN users    eu ON eu.id = i.effective_inspector_user_id
                WHERE  i.id = ? AND i.archived_at IS NULL';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND i.account_id = ?';
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeRow(array $row): array
    {
        $row['id']                 = (int) $row['id'];
        $row['company_id']         = (int) $row['company_id'];
        $row['facility_id']        = (int) $row['facility_id'];
        $row['inspector_user_id']  = (int) $row['inspector_user_id'];
        $row['periodicity_months'] = (int) $row['periodicity_months'];
        $row['effective_inspector_user_id'] = isset($row['effective_inspector_user_id'])
            ? (int) $row['effective_inspector_user_id']
            : null;
        $row['effective_inspector_name']    = $row['effective_inspector_name'] ?? null;
        $row['effective_cert_number']       = $row['effective_cert_number']    ?? null;
        unset($row['account_id']);
        return $row;
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
