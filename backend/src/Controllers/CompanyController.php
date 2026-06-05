<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Address;
use PDO;

final class CompanyController
{
    public static function index(Request $req): void
    {
        $userId    = Tenant::currentUserId();
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin($userId);
        $search    = $req->query('search');

        // Pull facilities count + a quick "last finalized inspection"
        // summary so the dashboard can render status without an extra
        // round-trip per company. Subqueries are scoped to the same
        // tenant via the account_id condition on the parent query.
        // Admins get all companies across all accounts (no tenant filter).
        if ($isAdmin) {
            $sql = 'SELECT c.id, c.name, c.ico, c.street, c.postal_code, c.city, c.contact,
                           c.account_id,
                           a.invoice_company_name AS account_name,
                           (SELECT COUNT(*) FROM facilities f
                             WHERE f.company_id = c.id AND f.archived_at IS NULL) AS facilities_count,
                           (SELECT MAX(i.executed_on) FROM inspections i
                             WHERE i.company_id = c.id
                               AND i.status = "finalized" AND i.archived_at IS NULL) AS last_inspection_at,
                           (SELECT COUNT(*) FROM inspections i
                             WHERE i.company_id = c.id
                               AND i.status = "finalized" AND i.archived_at IS NULL) AS inspections_count
                    FROM   companies c
                    JOIN   accounts a ON a.id = c.account_id
                    WHERE  c.archived_at IS NULL';
            $params = [];
        } else {
            $sql = 'SELECT c.id, c.name, c.ico, c.street, c.postal_code, c.city, c.contact,
                           (SELECT COUNT(*) FROM facilities f
                             WHERE f.company_id = c.id AND f.archived_at IS NULL) AS facilities_count,
                           (SELECT MAX(i.executed_on) FROM inspections i
                             WHERE i.company_id = c.id
                               AND i.status = "finalized" AND i.archived_at IS NULL) AS last_inspection_at,
                           (SELECT COUNT(*) FROM inspections i
                             WHERE i.company_id = c.id
                               AND i.status = "finalized" AND i.archived_at IS NULL) AS inspections_count
                    FROM   companies c
                    WHERE  c.account_id = :account_id AND c.archived_at IS NULL';
            $params = ['account_id' => $accountId];
        }

        if ($search !== null) {
            // Distinct placeholders: PDO with emulate_prepares=false rejects
            // reusing the same named param across multiple positions.
            $sql .= ' AND (c.name LIKE :search_name OR c.ico LIKE :search_ico)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_ico']  = '%' . $search . '%';
        }
        $sql .= ' ORDER BY c.name ASC LIMIT 500';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shape'], $stmt->fetchAll());

        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $userId    = Tenant::currentUserId();
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin($userId);
        $id        = (int) $params['id'];

        $row = self::findOrFail($isAdmin ? null : $accountId, $id);

        $facStmt = Db::pdo()->prepare(
            'SELECT id, name, street, postal_code, city, contact_person, notes
             FROM   facilities
             WHERE  company_id = ? AND archived_at IS NULL
             ORDER  BY name ASC'
        );
        $facStmt->execute([$id]);
        $facilities = $facStmt->fetchAll();

        // Last-used periodicity per (facility, inspection type) — used by
        // Step 1 to prefill the periodicity dropdown for types where it's
        // selectable (PHP, požiarna kniha). The window function picks the
        // most recent finalized inspection per facility+type.
        $defStmt = Db::pdo()->prepare(
            'SELECT facility_id, type, periodicity_months
             FROM (
                 SELECT i.facility_id, i.type, i.periodicity_months,
                        ROW_NUMBER() OVER (
                            PARTITION BY i.facility_id, i.type
                            ORDER BY i.executed_on DESC, i.id DESC
                        ) AS rn
                 FROM   inspections i
                 JOIN   facilities  f ON f.id = i.facility_id
                 WHERE  f.company_id = ?
                   AND  i.account_id = ?
                   AND  i.archived_at IS NULL
             ) t
             WHERE t.rn = 1'
        );
        $defStmt->execute([$id, (int) $row['account_id']]);
        $defaultsByFacility = [];
        foreach ($defStmt->fetchAll() as $r) {
            $fid = (int) $r['facility_id'];
            $defaultsByFacility[$fid] ??= [];
            $defaultsByFacility[$fid][(string) $r['type']] = (int) $r['periodicity_months'];
        }
        foreach ($facilities as &$fac) {
            $fac['id'] = (int) $fac['id'];
            $fac['address'] = Address::format($fac['street'], $fac['postal_code'], $fac['city']);
            $fac['last_periodicities'] = $defaultsByFacility[$fac['id']] ?? new \stdClass();
        }
        unset($fac);

        Response::json([
            'company'    => self::shape($row),
            'facilities' => $facilities,
        ]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        [$name, $ico, $addr, $contact] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO companies (account_id, name, ico, street, postal_code, city, contact)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $name, $ico, $addr['street'], $addr['postal_code'], $addr['city'], $contact]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['company' => self::shape(self::findOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::findOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        [$name, $ico, $addr, $contact] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'UPDATE companies SET name = ?, ico = ?, street = ?, postal_code = ?, city = ?, contact = ?
             WHERE  id = ? AND account_id = ?'
        );
        $stmt->execute([$name, $ico, $addr['street'], $addr['postal_code'], $addr['city'], $contact, $id, $scopeAccountId]);

        Response::json(['company' => self::shape(self::findOrFail($isAdmin ? null : $accountId, $id))]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::findOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        Db::pdo()->prepare('UPDATE companies SET archived_at = NOW() WHERE id = ? AND account_id = ?')
            ->execute([$id, $scopeAccountId]);

        Response::noContent();
    }

    /**
     * @return array{
     *   0:string, 1:?string,
     *   2:array{street:?string,postal_code:?string,city:?string}, 3:?string
     * }
     */
    private static function readBody(Request $req): array
    {
        $name    = $req->jsonString('name');
        $ico     = $req->jsonString('ico');
        $address = $req->jsonString('address');
        $contact = $req->jsonString('contact');

        if ($name === null || $name === '') {
            Response::error('Field required: name', 422);
        }
        if ($ico !== null) {
            $ico = preg_replace('/\s+/', '', $ico);
            if ($ico === '') {
                $ico = null;
            } elseif (!preg_match('/^\d{1,12}$/', $ico)) {
                Response::error('IČO must be numeric', 422);
            }
        }

        // The edit form sends the structured parts; offline/import clients may
        // still send a single combined "Adresa" string.
        $addr = Address::resolve(
            $req->jsonString('street'),
            $req->jsonString('postal_code'),
            $req->jsonString('city'),
            $address,
        );
        return [$name, $ico, $addr, $contact];
    }

    /** @return array<string, mixed> */
    private static function findOrFail(?int $accountId, int $id): array
    {
        if ($accountId === null) {
            $stmt = Db::pdo()->prepare(
                'SELECT id, account_id, name, ico, street, postal_code, city, contact, created_at
                 FROM   companies
                 WHERE  id = ? AND archived_at IS NULL'
            );
            $stmt->execute([$id]);
        } else {
            $stmt = Db::pdo()->prepare(
                'SELECT id, account_id, name, ico, street, postal_code, city, contact, created_at
                 FROM   companies
                 WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $stmt->execute([$id, $accountId]);
        }
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Company not found', 404);
        }
        return $row;
    }

    /**
     * Normalises a raw PDO row for the API. Coerces facilities_count to an
     * int when present (PDO returns it as a string for COUNT()).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        if (isset($row['facilities_count'])) {
            $row['facilities_count'] = (int) $row['facilities_count'];
        }
        if (isset($row['inspections_count'])) {
            $row['inspections_count'] = (int) $row['inspections_count'];
        }
        // Expose both the combined `address` (for read-only display) and the
        // structured parts (so the edit form can prefill them individually).
        if (array_key_exists('street', $row) || array_key_exists('postal_code', $row) || array_key_exists('city', $row)) {
            $row['address'] = Address::format($row['street'] ?? null, $row['postal_code'] ?? null, $row['city'] ?? null);
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }
}
