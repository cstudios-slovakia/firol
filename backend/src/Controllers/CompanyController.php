<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use PDO;

final class CompanyController
{
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $search    = $req->query('search');

        $sql = 'SELECT c.id, c.name, c.ico, c.address, c.contact,
                       (SELECT COUNT(*) FROM facilities f
                         WHERE f.company_id = c.id AND f.archived_at IS NULL) AS facilities_count
                FROM   companies c
                WHERE  c.account_id = :account_id AND c.archived_at IS NULL';
        $params = ['account_id' => $accountId];

        if ($search !== null) {
            // Distinct placeholders: PDO with emulate_prepares=false rejects
            // reusing the same named param across multiple positions.
            $sql .= ' AND (c.name LIKE :search_name OR c.ico LIKE :search_ico)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_ico']  = '%' . $search . '%';
        }
        $sql .= ' ORDER BY c.name ASC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shape'], $stmt->fetchAll());

        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        $row = self::findOrFail($accountId, $id);

        $facStmt = Db::pdo()->prepare(
            'SELECT id, name, address, contact_person, notes
             FROM   facilities
             WHERE  company_id = ? AND archived_at IS NULL
             ORDER  BY name ASC'
        );
        $facStmt->execute([$id]);
        $facilities = $facStmt->fetchAll();

        Response::json([
            'company'    => self::shape($row),
            'facilities' => $facilities,
        ]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        [$name, $ico, $address, $contact] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO companies (account_id, name, ico, address, contact)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $name, $ico, $address, $contact]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['company' => self::shape(self::findOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        self::findOrFail($accountId, $id);

        [$name, $ico, $address, $contact] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'UPDATE companies SET name = ?, ico = ?, address = ?, contact = ?
             WHERE  id = ? AND account_id = ?'
        );
        $stmt->execute([$name, $ico, $address, $contact, $id, $accountId]);

        Response::json(['company' => self::shape(self::findOrFail($accountId, $id))]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        self::findOrFail($accountId, $id);

        Db::pdo()->prepare('UPDATE companies SET archived_at = NOW() WHERE id = ? AND account_id = ?')
            ->execute([$id, $accountId]);

        Response::noContent();
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} */
    private static function readBody(Request $req): array
    {
        $name    = $req->jsonString('name');
        $ico     = $req->jsonString('ico');
        $address = $req->jsonString('address');
        $contact = $req->jsonString('contact');

        if ($name === null || $name === '') {
            Response::error('Field required: name', 422);
        }
        if ($ico !== null && !preg_match('/^\d{1,12}$/', $ico)) {
            Response::error('IČO must be numeric', 422);
        }

        return [$name, $ico, $address, $contact];
    }

    /** @return array<string, mixed> */
    private static function findOrFail(int $accountId, int $id): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, ico, address, contact, created_at
             FROM   companies
             WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
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
        $row['id'] = (int) $row['id'];
        return $row;
    }
}
