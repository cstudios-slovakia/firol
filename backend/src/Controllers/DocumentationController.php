<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Dokumentácia PO — CRUD for the fire-protection documentation record.
 *
 * Mirrors TrainingController's tenant/admin handling, but the whole form
 * payload lives in a single JSON column (`data`) instead of per-item
 * rows: the technician fills a short multi-step form, we store it, and
 * DocumentController turns it into a DOCX + PDF on demand (open, edit,
 * generate again — same principle as inspections).
 */
final class DocumentationController
{
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());

        $companyId  = self::queryInt($req, 'company_id');
        $facilityId = self::queryInt($req, 'facility_id');

        $sql = 'SELECT d.id, d.title, d.issued_on, d.status, d.created_at, d.updated_at,
                       d.company_id, c.name AS company_name,
                       d.facility_id, f.name AS facility_name,
                       d.author_user_id, au.fullname AS author_name,
                       (SELECT COUNT(*) FROM documents
                         WHERE parent_type = "documentation" AND parent_id = d.id) AS documents_count
                FROM   documentations d
                JOIN   companies  c  ON c.id = d.company_id
                LEFT JOIN facilities f  ON f.id = d.facility_id
                LEFT JOIN users      au ON au.id = d.author_user_id
                WHERE  d.archived_at IS NULL';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND d.account_id = :account_id';
            $params['account_id'] = $accountId;
        }
        if ($companyId !== null) {
            $sql .= ' AND d.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($facilityId !== null) {
            $sql .= ' AND d.facility_id = :facility_id';
            $params['facility_id'] = $facilityId;
        }
        $sql .= ' ORDER BY COALESCE(d.issued_on, d.created_at) DESC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shapeListRow'], $stmt->fetchAll());
        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        Response::json(['documentation' => self::shapeFull($row)]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId    = Tenant::currentUserId();

        $companyId  = $req->jsonInt('company_id');
        $facilityId = $req->jsonInt('facility_id');
        $issuedOn   = $req->jsonString('issued_on');
        $title      = $req->jsonString('title');
        $data       = self::dataFromRequest($req);

        if ($companyId === null) {
            Response::error('Field required: company_id', 422);
        }
        if ($issuedOn !== null && $issuedOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedOn)) {
            Response::error('Invalid issued_on (expected YYYY-MM-DD)', 422);
        }

        // Verify company ownership; facility (optional) must belong to it.
        // Admins may author for any account; the record is filed under the
        // company's own account_id.
        $isAdmin = Admin::isAdmin($userId);
        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT account_id FROM companies WHERE id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId]);
            $companyAccountId = $check->fetchColumn();
            if ($companyAccountId === false) {
                Response::error('Company not found', 404);
            }
            $accountId = (int) $companyAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1 FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Company not found', 404);
            }
        }
        if ($facilityId !== null) {
            $fc = Db::pdo()->prepare(
                'SELECT 1 FROM facilities
                 WHERE id = ? AND company_id = ? AND archived_at IS NULL'
            );
            $fc->execute([$facilityId, $companyId]);
            if ($fc->fetchColumn() === false) {
                Response::error('Facility does not belong to the chosen company', 422);
            }
        }

        Db::pdo()->prepare(
            'INSERT INTO documentations
                (account_id, company_id, facility_id, author_user_id,
                 title, issued_on, data, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "draft")'
        )->execute([
            $accountId,
            $companyId,
            $facilityId,
            $userId,
            $title,
            $issuedOn !== '' ? $issuedOn : null,
            json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['documentation' => self::shapeFull(self::loadOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];
        $existing  = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        $body = $req->json();

        $issuedOn = $req->jsonString('issued_on');
        if ($issuedOn !== null && $issuedOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedOn)) {
            Response::error('Invalid issued_on (expected YYYY-MM-DD)', 422);
        }

        // Facility may be changed/cleared; when present it must still belong
        // to the documentation's company.
        $facilityId = null;
        $facilityProvided = array_key_exists('facility_id', $body);
        if ($facilityProvided) {
            $facilityId = $req->jsonInt('facility_id');
            if ($facilityId !== null) {
                $fc = Db::pdo()->prepare(
                    'SELECT 1 FROM facilities
                     WHERE id = ? AND company_id = ? AND archived_at IS NULL'
                );
                $fc->execute([$facilityId, (int) $existing['company_id']]);
                if ($fc->fetchColumn() === false) {
                    Response::error('Facility does not belong to the documentation company', 422);
                }
            }
        }

        $dataProvided = array_key_exists('data', $body);
        $dataJson = $dataProvided
            ? json_encode(self::dataFromRequest($req), JSON_UNESCAPED_UNICODE)
            : null;

        Db::pdo()->prepare(
            'UPDATE documentations
             SET    title       = COALESCE(?, title),
                    issued_on   = ' . ($issuedOn !== null ? '?' : 'issued_on') . ',
                    facility_id = ' . ($facilityProvided ? '?' : 'facility_id') . ',
                    data        = COALESCE(?, data)
             WHERE  id = ? AND account_id = ?'
        )->execute(array_merge(
            [$req->jsonString('title')],
            $issuedOn !== null ? [$issuedOn !== '' ? $issuedOn : null] : [],
            $facilityProvided ? [$facilityId] : [],
            [$dataJson, $id, $scopeAccountId],
        ));

        Response::json(['documentation' => self::shapeFull(self::loadOrFail($isAdmin ? null : $accountId, $id))]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];
        $existing  = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        // Soft-delete so already-issued documents (and their numbers) stay
        // intact and auditable.
        Db::pdo()->prepare(
            'UPDATE documentations SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $scopeAccountId]);

        Response::noContent();
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT d.id, d.account_id, d.company_id, d.facility_id, d.author_user_id,
                       d.title, d.issued_on, d.data, d.status, d.created_at, d.updated_at,
                       c.name AS company_name, c.ico AS company_ico,
                       c.street AS company_street, c.postal_code AS company_postal_code, c.city AS company_city,
                       f.name AS facility_name,
                       f.street AS facility_street, f.postal_code AS facility_postal_code, f.city AS facility_city,
                       f.contact_person AS facility_contact_person,
                       au.fullname AS author_name
                FROM   documentations d
                JOIN   companies  c  ON c.id = d.company_id
                LEFT JOIN facilities f  ON f.id = d.facility_id
                LEFT JOIN users      au ON au.id = d.author_user_id
                WHERE  d.id = ? AND d.archived_at IS NULL';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND d.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Documentation not found', 404);
        }
        return $row;
    }

    /**
     * Pull and lightly normalise the `data` blob from the request. We only
     * enforce that it's an object; the shape is owned by the wizard and the
     * generation payload builder, not the DB.
     *
     * @return array<string, mixed>
     */
    private static function dataFromRequest(Request $req): array
    {
        $data = $req->json()['data'] ?? null;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeListRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'title'           => $row['title'],
            'issued_on'       => $row['issued_on'],
            'status'          => $row['status'],
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
            'company_id'      => (int) $row['company_id'],
            'company_name'    => $row['company_name'],
            'facility_id'     => $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
            'facility_name'   => $row['facility_name'],
            'author_user_id'  => $row['author_user_id'] !== null ? (int) $row['author_user_id'] : null,
            'author_name'     => $row['author_name'],
            'documents_count' => isset($row['documents_count']) ? (int) $row['documents_count'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeFull(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'company_id'     => (int) $row['company_id'],
            'company_name'   => $row['company_name'],
            'company_ico'    => $row['company_ico'],
            'facility_id'    => $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
            'facility_name'  => $row['facility_name'],
            'author_user_id' => $row['author_user_id'] !== null ? (int) $row['author_user_id'] : null,
            'author_name'    => $row['author_name'],
            'title'          => $row['title'],
            'issued_on'      => $row['issued_on'],
            'status'         => $row['status'],
            'data'           => json_decode((string) $row['data'], true) ?: new \stdClass(),
            'created_at'     => $row['created_at'],
            'updated_at'     => $row['updated_at'],
        ];
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $v = $req->query($key);
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }
}
