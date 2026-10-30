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
use Firol\Support\ClientEmail;
use Firol\Support\ClientKind;
use Firol\Support\FacilityDevices;

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
        // and the new facility inherits the company's account_id. The client
        // type decides whether the floor fields are stored.
        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT account_id, client_type FROM companies WHERE id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId]);
            $company = $check->fetch();
            if ($company === false) {
                Response::error('Firma sa nenašla.', 404);
            }
            $accountId = (int) $company['account_id'];
            $clientType = (string) $company['client_type'];
        } else {
            $check = Db::pdo()->prepare(
                'SELECT client_type FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $check->execute([$companyId, $accountId]);
            $clientType = $check->fetchColumn();
            if ($clientType === false) {
                Response::error('Firma sa nenašla.', 404);
            }
            $clientType = (string) $clientType;
        }

        $fields = self::readBody($req, $clientType, true);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO facilities
                (account_id, company_id, name, street, postal_code, city, email, contact_person, notes,
                 main_power_location, main_gas_location, main_water_location, description,
                 floors_above, floors_below, entrances, places)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId, $companyId, $fields['name'],
            $fields['addr']['street'], $fields['addr']['postal_code'], $fields['addr']['city'],
            $fields['email'], $fields['contact_person'], $fields['notes'],
            $fields['main_power_location'], $fields['main_gas_location'], $fields['main_water_location'],
            $fields['description'], $fields['floors_above'], $fields['floors_below'],
            '[]', '[]',
        ]);
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

        $clientType = (string) $existing['company_client_type'];
        $fields = self::readBody($req, $clientType, false);
        // A replay from before these columns existed omits the keys. Leaving
        // them out keeps what is stored; sending them (including empty) writes.
        $body = $req->json();
        foreach ([
            'email', 'main_power_location', 'main_gas_location', 'main_water_location', 'description',
        ] as $key) {
            if (!array_key_exists($key, $body)) {
                $fields[$key] = $existing[$key] !== null && $existing[$key] !== '' ? (string) $existing[$key] : null;
            }
        }
        if (ClientKind::isBytovyDom($clientType)) {
            if (!array_key_exists('floors_above', $body)) {
                $fields['floors_above'] = $existing['floors_above'] !== null ? (int) $existing['floors_above'] : null;
            }
            if (!array_key_exists('floors_below', $body)) {
                $fields['floors_below'] = $existing['floors_below'] !== null ? (int) $existing['floors_below'] : null;
            }
        }

        Db::pdo()->prepare(
            'UPDATE facilities
             SET    name = ?, street = ?, postal_code = ?, city = ?, email = ?, contact_person = ?, notes = ?,
                    main_power_location = ?, main_gas_location = ?, main_water_location = ?, description = ?,
                    floors_above = ?, floors_below = ?
             WHERE  id = ? AND account_id = ?'
        )->execute([
            $fields['name'], $fields['addr']['street'], $fields['addr']['postal_code'], $fields['addr']['city'],
            $fields['email'], $fields['contact_person'], $fields['notes'],
            $fields['main_power_location'], $fields['main_gas_location'], $fields['main_water_location'],
            $fields['description'], $fields['floors_above'], $fields['floors_below'],
            $id, $scopeAccountId,
        ]);

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
     * GET /api/facilities/{id}/devices — the derived device list (spec 3.3).
     * A read; the same tenant rules as show(), including an archived prevádzka.
     */
    public static function devices(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id, true);
        $scopeAccountId = (int) $row['account_id'];

        Response::json(['groups' => FacilityDevices::forFacility($scopeAccountId, $id)]);
    }

    /**
     * @return array{
     *   name: string,
     *   addr: array{street:?string,postal_code:?string,city:?string},
     *   contact_person: ?string,
     *   notes: ?string,
     *   email: ?string,
     *   main_power_location: ?string,
     *   main_gas_location: ?string,
     *   main_water_location: ?string,
     *   description: ?string,
     *   floors_above: ?int,
     *   floors_below: ?int
     * }
     */
    private static function readBody(Request $req, string $clientType, bool $creating): array
    {
        $name          = $req->jsonString('name');
        $address       = $req->jsonString('address');
        $contactPerson = $req->jsonString('contact_person');
        $notes         = $req->jsonString('notes');
        $email         = self::readEmail($req);
        $power         = self::blankToNull($req->jsonString('main_power_location'));
        $gas           = self::blankToNull($req->jsonString('main_gas_location'));
        $water         = self::blankToNull($req->jsonString('main_water_location'));
        $description   = self::blankToNull($req->jsonString('description'));

        if ($name === null || $name === '') {
            Response::error(ClientKind::nameRequired($clientType), 422);
        }

        $floorsAbove = null;
        $floorsBelow = null;
        if (ClientKind::isBytovyDom($clientType)) {
            $floorsAbove = self::readFloor($req, 'floors_above', 1, 40, 'Počet nadzemných podlaží');
            $floorsBelow = self::readFloor($req, 'floors_below', 0, 5, 'Počet podzemných podlaží');
            if ($creating && ($floorsAbove === null || $floorsBelow === null)) {
                Response::error('Pri bytovom dome zadaj počet nadzemných (1–40) a podzemných (0–5) podlaží.', 422);
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
        return [
            'name'                 => $name,
            'addr'                 => $addr,
            'contact_person'       => $contactPerson,
            'notes'                => $notes,
            'email'                => $email,
            'main_power_location'  => $power,
            'main_gas_location'    => $gas,
            'main_water_location'  => $water,
            'description'          => $description,
            'floors_above'         => $floorsAbove,
            'floors_below'         => $floorsBelow,
        ];
    }

    private static function readEmail(Request $req): ?string
    {
        $email = $req->jsonString('email');
        if ($email === null || $email === '') {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Zadaj platný e-mail prevádzky.', 422, ['field' => 'email']);
        }
        return $email;
    }

    /**
     * A floor count. Null when the body leaves it out (an edit may then keep
     * the stored value). Out of range is a 422.
     */
    private static function readFloor(Request $req, string $key, int $min, int $max, string $label): ?int
    {
        $raw = $req->json()[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = $req->jsonInt($key);
        if ($n === null || $n < $min || $n > $max) {
            Response::error("$label musí byť číslo od $min do $max.", 422, ['field' => $key]);
        }
        return $n;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * A prevádzka that is archived, or whose firma is, counts as not found
     * unless `$includeArchived` — it can be opened and restored, not edited.
     *
     * @return array<string, mixed>
     */
    private static function loadOrFail(?int $accountId, int $id, bool $includeArchived = false): array
    {
        $sql = 'SELECT f.id, f.account_id, f.name, f.street, f.postal_code, f.city, f.email,
                       f.contact_person, f.notes,
                       f.main_power_location, f.main_gas_location, f.main_water_location, f.description,
                       f.floors_above, f.floors_below, f.entrances, f.places,
                       f.archived_at, f.archived_reason,
                       f.company_id, c.name AS company_name, c.client_type AS company_client_type,
                       c.contact_email AS company_contact_email, c.contact AS company_contact,
                       c.archived_at AS company_archived_at
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
        $row['floors_above'] = $row['floors_above'] !== null ? (int) $row['floors_above'] : null;
        $row['floors_below'] = $row['floors_below'] !== null ? (int) $row['floors_below'] : null;
        $row['entrances'] = self::jsonList($row['entrances'] ?? null);
        $row['places'] = self::jsonList($row['places'] ?? null);
        $row['recipient_email'] = ClientEmail::resolve(
            isset($row['email']) ? (string) $row['email'] : null,
            isset($row['company_contact_email']) ? (string) $row['company_contact_email'] : null,
            isset($row['company_contact']) ? (string) $row['company_contact'] : null,
        );
        return $row;
    }

    /** @return list<mixed> */
    private static function jsonList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
