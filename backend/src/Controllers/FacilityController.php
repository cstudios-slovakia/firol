<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\MemberRights;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Address;
use Firol\Support\ClientArchive;

final class FacilityController
{
    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        // Spec 25 — an archived prevádzka (or one of an archived firm) still
        // opens, so its protocols stay reachable and it can be restored.
        $row = self::loadOrFail($isAdmin ? null : $accountId, $id, true);

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
                Response::error('Firma sa nenašla.', 404);
            }
            $accountId = (int) $companyAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1 FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Firma sa nenašla.', 404);
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

    /**
     * POST /api/facilities/{id}/archive  { reason?: string }
     *
     * Spec 25 — a prevádzka the client closed is archived on its own while the
     * firma stays active. See ClientArchive.
     */
    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::loadOrFail($isAdmin ? null : $accountId, $id, true);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;
        // Archiving sits under the members' switch (chapter 1.6, spec 25).
        MemberRights::requireDelete($scopeAccountId, MemberRights::ARCHIVE_DENIED);
        if ($existing['archived_at'] !== null) {
            Response::error('Prevádzka je už archivovaná.', 409);
        }
        if ($existing['company_archived_at'] !== null) {
            Response::error('Firma tejto prevádzky je archivovaná.', 409);
        }

        ClientArchive::archiveFacility($scopeAccountId, $id, ClientArchive::readReason($req));

        Response::noContent();
    }

    /** POST /api/facilities/{id}/restore — spec 25, undoes the archiving. */
    public static function restore(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::loadOrFail($isAdmin ? null : $accountId, $id, true);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;
        MemberRights::requireDelete($scopeAccountId, MemberRights::ARCHIVE_DENIED);
        if ($existing['archived_at'] === null) {
            Response::error('Prevádzka nie je archivovaná.', 409);
        }
        // A prevádzka of an archived firm would stay hidden anyway.
        if ($existing['company_archived_at'] !== null) {
            Response::error('Najprv obnov firmu tejto prevádzky.', 409);
        }

        ClientArchive::restoreFacility($scopeAccountId, $id);

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
            Response::error('Zadaj názov prevádzky.', 422);
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

    /**
     * A prevádzka that is archived, or whose firma is, counts as not found
     * unless `$includeArchived` — it can be opened and restored, not edited.
     *
     * @return array<string, mixed>
     */
    private static function loadOrFail(?int $accountId, int $id, bool $includeArchived = false): array
    {
        $sql = 'SELECT f.id, f.account_id, f.name, f.street, f.postal_code, f.city, f.contact_person, f.notes,
                       f.archived_at, f.archived_reason,
                       f.company_id, c.name AS company_name, c.archived_at AS company_archived_at
                FROM   facilities f
                JOIN   companies  c ON c.id = f.company_id
                WHERE  f.id = ?';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND f.account_id = ?';
            $params[] = $accountId;
        }
        if (!$includeArchived) {
            $sql .= ' AND f.archived_at IS NULL AND c.archived_at IS NULL';
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Prevádzka sa nenašla.', 404);
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
