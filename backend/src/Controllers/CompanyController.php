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
use Firol\Support\Periodicity;
use PDO;

final class CompanyController
{
    public static function index(Request $req): void
    {
        $userId    = Tenant::currentUserId();
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin($userId);
        $search    = $req->query('search');
        $withArchived = $req->query('archived') === '1';

        // Pull facilities count + a quick "last finalized inspection"
        // summary so the dashboard can render status without an extra
        // round-trip per company. Subqueries are scoped to the same
        // tenant via the account_id condition on the parent query.
        // Admins get all companies across all accounts (no tenant filter).
        if ($isAdmin) {
            $sql = 'SELECT c.id, c.name, c.ico, c.street, c.postal_code, c.city, c.contact, c.contact_email, c.approver, c.billing_mode,
                           c.archived_at, c.archived_reason,
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
                    WHERE  1 = 1';
            $params = [];
        } else {
            $sql = 'SELECT c.id, c.name, c.ico, c.street, c.postal_code, c.city, c.contact, c.contact_email, c.approver, c.billing_mode,
                           c.archived_at, c.archived_reason,
                           (SELECT COUNT(*) FROM facilities f
                             WHERE f.company_id = c.id AND f.archived_at IS NULL) AS facilities_count,
                           (SELECT MAX(i.executed_on) FROM inspections i
                             WHERE i.company_id = c.id
                               AND i.status = "finalized" AND i.archived_at IS NULL) AS last_inspection_at,
                           (SELECT COUNT(*) FROM inspections i
                             WHERE i.company_id = c.id
                               AND i.status = "finalized" AND i.archived_at IS NULL) AS inspections_count
                    FROM   companies c
                    WHERE  c.account_id = :account_id';
            $params = ['account_id' => $accountId];
        }
        // Spec 25 — archived firms only behind the „aj archivované" filter,
        // listed after the active ones.
        if (!$withArchived) {
            $sql .= ' AND c.archived_at IS NULL';
        }

        if ($search !== null) {
            // Distinct placeholders: PDO with emulate_prepares=false rejects
            // reusing the same named param across multiple positions.
            $sql .= ' AND (c.name LIKE :search_name OR c.ico LIKE :search_ico)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_ico']  = '%' . $search . '%';
        }
        $sql .= ' ORDER BY c.archived_at IS NOT NULL, c.name ASC LIMIT 500';

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

        // Spec 25 — an archived firm still opens: its protocols stay readable
        // and sendable, and this is where it is restored from.
        $row = self::findOrFail($isAdmin ? null : $accountId, $id, true);

        $facStmt = Db::pdo()->prepare(
            'SELECT id, name, street, postal_code, city, contact_person, notes, archived_at, archived_reason
             FROM   facilities
             WHERE  company_id = ?
             ORDER  BY name ASC'
        );
        $facStmt->execute([$id]);
        // Archived prevádzky go in their own list, so every caller that offers
        // `facilities` for a new úkon keeps getting only the active ones.
        $facilities = [];
        $archivedFacilities = [];
        foreach ($facStmt->fetchAll() as $f) {
            if ($f['archived_at'] !== null) {
                $archivedFacilities[] = [
                    'id'              => (int) $f['id'],
                    'name'            => $f['name'],
                    'address'         => Address::format($f['street'], $f['postal_code'], $f['city']),
                    'archived_at'     => $f['archived_at'],
                    'archived_reason' => $f['archived_reason'],
                ];
                continue;
            }
            unset($f['archived_at'], $f['archived_reason']);
            $facilities[] = $f;
        }

        // Last-used periodicity per (facility, inspection type) — Step 1
        // prefills it, because what this prevádzka was on last time is a far
        // better guess than the catalogue's recommendation. Carries the unit
        // too: since block 1 a period can be days or weeks, not only months.
        // The window function picks the most recent inspection per
        // facility+type.
        $defStmt = Db::pdo()->prepare(
            'SELECT facility_id, type, periodicity_value, periodicity_unit
             FROM (
                 SELECT i.facility_id, i.type, i.periodicity_value, i.periodicity_unit,
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
            $defaultsByFacility[$fid][(string) $r['type']] = [
                'value' => $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null,
                'unit'  => $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null,
            ];
        }

        // The same for trainings, per term chain (Vstupné and Opakované share
        // one — Periodicity::trainingChain). Newest first, so the first row
        // seen for a (prevádzka, chain) is the latest. Trainings without a
        // prevádzka are the whole firm's and go under their own key.
        $trStmt = Db::pdo()->prepare(
            'SELECT t.facility_id, t.type, t.periodicity_value, t.periodicity_unit
             FROM   trainings t
             WHERE  t.company_id = ? AND t.account_id = ? AND t.archived_at IS NULL
               AND  t.date IS NOT NULL
             ORDER  BY t.date DESC, t.id DESC'
        );
        $trStmt->execute([$id, (int) $row['account_id']]);
        $trainingDefaults = [];
        foreach ($trStmt->fetchAll() as $r) {
            $scope = $r['facility_id'] !== null ? (int) $r['facility_id'] : 'company';
            $chain = Periodicity::trainingChain((string) $r['type']);
            $trainingDefaults[$scope][$chain] ??= [
                'value' => $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null,
                'unit'  => $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null,
            ];
        }

        foreach ($facilities as &$fac) {
            $fac['id'] = (int) $fac['id'];
            $fac['address'] = Address::format($fac['street'], $fac['postal_code'], $fac['city']);
            $fac['last_periodicities'] = $defaultsByFacility[$fac['id']] ?? new \stdClass();
            $fac['last_training_periodicities'] = $trainingDefaults[$fac['id']] ?? new \stdClass();
        }
        unset($fac);

        Response::json([
            'company'    => self::shape($row),
            'facilities' => $facilities,
            'archived_facilities' => $archivedFacilities,
            'company_last_training_periodicities' => $trainingDefaults['company'] ?? new \stdClass(),
        ]);
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        [$name, $ico, $addr, $contact, $contactEmail, $approver] = self::readBody($req);
        // Chapter 22 — validated up front so a bad value never half-saves the firm.
        $billingMode = self::readBillingMode($req);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO companies (account_id, name, ico, street, postal_code, city, contact, contact_email, approver)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $name, $ico, $addr['street'], $addr['postal_code'], $addr['city'], $contact, $contactEmail, $approver]);
        $id = (int) Db::pdo()->lastInsertId();

        // Chapter 22 — absent means the column default (na faktúru).
        if ($billingMode !== null) {
            Db::pdo()->prepare('UPDATE companies SET billing_mode = ? WHERE id = ?')
                ->execute([$billingMode, $id]);
        }

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

        [$name, $ico, $addr, $contact, $contactEmail, $approver] = self::readBody($req);
        // Chapter 22 — validated up front so a bad value never half-saves the firm.
        $billingMode = self::readBillingMode($req);

        $stmt = Db::pdo()->prepare(
            'UPDATE companies SET name = ?, ico = ?, street = ?, postal_code = ?, city = ?, contact = ?, contact_email = ?, approver = ?
             WHERE  id = ? AND account_id = ?'
        );
        $stmt->execute([$name, $ico, $addr['street'], $addr['postal_code'], $addr['city'], $contact, $contactEmail, $approver, $id, $scopeAccountId]);

        // Chapter 22 — absent keeps the current setting (older offline
        // clients replay the edit without it). Changing it affects only úkony
        // created from now on; existing ones keep their own režim.
        if ($billingMode !== null) {
            Db::pdo()->prepare('UPDATE companies SET billing_mode = ? WHERE id = ? AND account_id = ?')
                ->execute([$billingMode, $id, $scopeAccountId]);
        }

        Response::json(['company' => self::shape(self::findOrFail($isAdmin ? null : $accountId, $id))]);
    }

    /**
     * POST /api/companies/{id}/archive  { reason?: string }
     *
     * Spec 25 — a firma is archived, never deleted: its protocols must be
     * kept. See ClientArchive for what archiving does.
     */
    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::findOrFail($isAdmin ? null : $accountId, $id, true);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;
        // Archiving sits under the members' switch (chapter 1.6, spec 25).
        MemberRights::requireDelete($scopeAccountId, MemberRights::ARCHIVE_DENIED);
        if ($existing['archived_at'] !== null) {
            Response::error('Firma je už archivovaná.', 409);
        }

        ClientArchive::archiveCompany($scopeAccountId, $id, ClientArchive::readReason($req));

        Response::noContent();
    }

    /**
     * POST /api/companies/{id}/restore — spec 25: archiving can be undone at
     * any time. The firm's termíny are computed afresh from the last control.
     */
    public static function restore(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin   = Admin::isAdmin(Tenant::currentUserId());
        $id        = (int) $params['id'];

        $existing = self::findOrFail($isAdmin ? null : $accountId, $id, true);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;
        MemberRights::requireDelete($scopeAccountId, MemberRights::ARCHIVE_DENIED);
        if ($existing['archived_at'] === null) {
            Response::error('Firma nie je archivovaná.', 409);
        }

        ClientArchive::restoreCompany($scopeAccountId, $id);

        Response::noContent();
    }

    /**
     * @return array{
     *   0:string, 1:?string,
     *   2:array{street:?string,postal_code:?string,city:?string}, 3:?string, 4:?string, 5:?string
     * }
     */
    private static function readBody(Request $req): array
    {
        $name    = $req->jsonString('name');
        $ico     = $req->jsonString('ico');
        $address = $req->jsonString('address');
        $contact = $req->jsonString('contact');
        // Recipient of the calendar's client notice (change request 2.5.4) —
        // a single address, unlike the free-text `contact` above.
        $contactEmail = $req->jsonString('contact_email');
        // Schvaľujúca osoba — name and role in one field, printed on the
        // Pokyn and the vyraďovací protokol (change request 2.3 / 2.1).
        $approver = $req->jsonString('approver');

        if ($name === null || $name === '') {
            Response::error('Zadaj názov firmy.', 422);
        }
        if ($ico !== null) {
            $ico = preg_replace('/\s+/', '', $ico);
            if ($ico === '') {
                $ico = null;
            } elseif (!preg_match('/^\d{1,12}$/', $ico)) {
                Response::error('IČO môže obsahovať len číslice.', 422);
            }
        }

        if ($contactEmail !== null) {
            $contactEmail = trim($contactEmail);
            if ($contactEmail === '') {
                $contactEmail = null;
            } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                Response::error('Zadaj platný kontaktný e-mail.', 422);
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
        return [$name, $ico, $addr, $contact, $contactEmail, $approver !== '' ? $approver : null];
    }

    /**
     * The firm's default režim fakturácie (chapter 22) — paušál or na faktúru;
     * null when the body does not carry it.
     */
    private static function readBillingMode(Request $req): ?string
    {
        $mode = $req->jsonString('billing_mode');
        if ($mode === null) {
            return null;
        }
        if (!in_array($mode, \Firol\Support\Invoicing::COMPANY_MODES, true)) {
            Response::error('Neplatný režim fakturácie firmy.', 422);
        }
        return $mode;
    }

    /**
     * An archived firm counts as not found unless `$includeArchived` — it can
     * be opened and restored, but not edited.
     *
     * @return array<string, mixed>
     */
    private static function findOrFail(?int $accountId, int $id, bool $includeArchived = false): array
    {
        $sql = 'SELECT id, account_id, name, ico, street, postal_code, city, contact, contact_email, approver, billing_mode,
                       archived_at, archived_reason, created_at
                FROM   companies
                WHERE  id = ?';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Firma sa nenašla.', 404);
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
