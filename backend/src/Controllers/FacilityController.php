<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

final class FacilityController
{
    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        $stmt = Db::pdo()->prepare(
            'SELECT f.id, f.name, f.address, f.contact_person, f.notes,
                    f.company_id, c.name AS company_name
             FROM   facilities f
             JOIN   companies  c ON c.id = f.company_id
             WHERE  f.id = ? AND f.account_id = ? AND f.archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Facility not found', 404);
        }
        $row['id']         = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];

        Response::json(['facility' => $row]);
    }

    public static function storeUnderCompany(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $companyId = (int) $params['id'];

        // Make sure the company belongs to the active account before
        // attaching a facility — guards against cross-tenant inserts.
        $check = Db::pdo()->prepare(
            'SELECT 1 FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $check->execute([$companyId, $accountId]);
        if ($check->fetchColumn() === false) {
            Response::error('Company not found', 404);
        }

        [$name, $address, $contactPerson, $notes] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO facilities (account_id, company_id, name, address, contact_person, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $companyId, $name, $address, $contactPerson, $notes]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['facility' => self::loadOrFail($accountId, $id)], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        self::loadOrFail($accountId, $id);
        [$name, $address, $contactPerson, $notes] = self::readBody($req);

        Db::pdo()->prepare(
            'UPDATE facilities
             SET    name = ?, address = ?, contact_person = ?, notes = ?
             WHERE  id = ? AND account_id = ?'
        )->execute([$name, $address, $contactPerson, $notes, $id, $accountId]);

        Response::json(['facility' => self::loadOrFail($accountId, $id)]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id        = (int) $params['id'];

        self::loadOrFail($accountId, $id);

        Db::pdo()->prepare(
            'UPDATE facilities SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $accountId]);

        Response::noContent();
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} */
    private static function readBody(Request $req): array
    {
        $name          = $req->jsonString('name');
        $address       = $req->jsonString('address');
        $contactPerson = $req->jsonString('contact_person');
        $notes         = $req->jsonString('notes');

        if ($name === null || $name === '') {
            Response::error('Field required: name', 422);
        }
        return [$name, $address, $contactPerson, $notes];
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(int $accountId, int $id): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT f.id, f.name, f.address, f.contact_person, f.notes,
                    f.company_id, c.name AS company_name
             FROM   facilities f
             JOIN   companies  c ON c.id = f.company_id
             WHERE  f.id = ? AND f.account_id = ? AND f.archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Facility not found', 404);
        }
        $row['id']         = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        return $row;
    }
}
