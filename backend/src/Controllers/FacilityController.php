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

final class FacilityController
{
    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);

        Response::json(['facility' => self::shapePublic($row)]);
    }

    public static function storeUnderCompany(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $companyId = (int) $params['id'];

        // Make sure the company exists; for non-admins, also that it belongs
        // to the active account. Admins may attach facilities to any company,
        // and the new facility inherits the company's account_id.
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

        [$name, $addr, $contactPerson, $notes] = self::readBody($req);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO facilities (account_id, company_id, name, street, postal_code, city, contact_person, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $companyId, $name, $addr['street'], $addr['postal_code'], $addr['city'], $contactPerson, $notes]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['facility' => self::shapePublic(self::loadOrFail($accountId, $id))], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        [$name, $addr, $contactPerson, $notes] = self::readBody($req);

        Db::pdo()->prepare(
            'UPDATE facilities
             SET    name = ?, street = ?, postal_code = ?, city = ?, contact_person = ?, notes = ?
             WHERE  id = ? AND account_id = ?'
        )->execute([$name, $addr['street'], $addr['postal_code'], $addr['city'], $contactPerson, $notes, $id, $scopeAccountId]);

        Response::json(['facility' => self::shapePublic(self::loadOrFail($scopeAccountId, $id))]);
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
            'UPDATE facilities SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $scopeAccountId]);

        Response::noContent();
    }

    /**
     * @return array{
     *   0:string,
     *   1:array{street:?string,postal_code:?string,city:?string}, 2:?string, 3:?string
     * }
     */
    private static function readBody(Request $req): array
    {
        $name          = $req->jsonString('name');
        $address       = $req->jsonString('address');
        $contactPerson = $req->jsonString('contact_person');
        $notes         = $req->jsonString('notes');

        if ($name === null || $name === '') {
            Response::error('Field required: name', 422);
        }
        // The edit form sends the structured parts; offline/import clients may
        // still send a single combined "Adresa" string.
        $addr = Address::resolve(
            $req->jsonString('street'),
            $req->jsonString('postal_code'),
            $req->jsonString('city'),
            $address,
        );
        return [$name, $addr, $contactPerson, $notes];
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT f.id, f.account_id, f.name, f.street, f.postal_code, f.city, f.contact_person, f.notes,
                       f.company_id, c.name AS company_name
                FROM   facilities f
                JOIN   companies  c ON c.id = f.company_id
                WHERE  f.id = ? AND f.archived_at IS NULL';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND f.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Facility not found', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapePublic(array $row): array
    {
        unset($row['account_id']);
        // Expose both the combined `address` (for read-only display) and the
        // structured parts (so the edit form can prefill them individually).
        $row['address'] = Address::format($row['street'] ?? null, $row['postal_code'] ?? null, $row['city'] ?? null);
        $row['id']         = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        return $row;
    }
}
