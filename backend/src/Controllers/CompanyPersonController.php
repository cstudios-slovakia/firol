<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Handover;

/**
 * People at a client company entitled to sign a protocol — block 1 /
 * chapter 13.2.
 *
 * A client is rarely one signature. A larger one has a konateľ at the
 * registered seat and a vedúci zamestnanec at each prevádzka, and which of
 * them signs depends on the document: a požiarna kniha entry is approved by
 * the vedúci zamestnanec, not by the konateľ. So a person may be pinned to one
 * prevádzka (facility_id) or valid for the whole company (facility_id NULL),
 * and one of them can be marked as the default the app offers first.
 */
final class CompanyPersonController
{
    /**
     * People on this company. `facility_id` narrows the list to those valid at
     * that prevádzka plus the company-wide ones — which is exactly what the
     * signature picker needs when a protocol is being handed over.
     */
    public static function index(Request $req, array $params): void
    {
        $companyId = (int) $params['id'];
        $accountId = self::assertCompany($companyId);
        $facilityId = self::queryInt($req, 'facility_id');

        $sql = 'SELECT id, facility_id, fullname, role_title, email, is_default
                FROM   company_persons
                WHERE  company_id = ? AND account_id = ? AND archived_at IS NULL';
        $args = [$companyId, $accountId];
        if ($facilityId !== null) {
            $sql .= ' AND (facility_id IS NULL OR facility_id = ?)';
            $args[] = $facilityId;
        }
        // Default first, then the prevádzka-specific ones before the
        // company-wide ones: the more specific person is the likelier signer.
        $sql .= ' ORDER BY is_default DESC, facility_id IS NULL ASC, fullname ASC';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);

        Response::json([
            'items' => array_map([self::class, 'shape'], $stmt->fetchAll()),
            'role_suggestions' => Handover::ROLE_SUGGESTIONS,
        ]);
    }

    public static function store(Request $req, array $params): void
    {
        Csrf::require($req);
        $companyId = (int) $params['id'];
        $accountId = self::assertCompany($companyId);

        $fullname = $req->jsonString('fullname');
        $roleTitle = $req->jsonString('role_title');
        if ($fullname === null || $fullname === '') {
            Response::error('Zadaj meno osoby.', 422);
        }
        if ($roleTitle === null || $roleTitle === '') {
            Response::error('Zadaj funkciu osoby (napr. konateľ).', 422);
        }
        $facilityId = self::readFacilityId($req, $companyId);
        $email = self::readEmail($req);
        $isDefault = $req->jsonBool('is_default') ?? false;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($isDefault) {
                self::clearDefault($companyId, $accountId);
            }
            $pdo->prepare(
                'INSERT INTO company_persons
                    (account_id, company_id, facility_id, fullname, role_title, email, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$accountId, $companyId, $facilityId, $fullname, $roleTitle, $email, $isDefault ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['person' => self::load($id, $accountId)], 201);
    }

    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $id = (int) $params['person_id'];
        $companyId = (int) $params['id'];
        $accountId = self::assertCompany($companyId);
        if (self::load($id, $accountId) === null) {
            Response::error('Osoba sa nenašla.', 404);
        }

        $body = $req->json();
        $fullname = $req->jsonString('fullname');
        $roleTitle = $req->jsonString('role_title');
        if (array_key_exists('fullname', $body) && ($fullname === null || $fullname === '')) {
            Response::error('Zadaj meno osoby.', 422);
        }
        if (array_key_exists('role_title', $body) && ($roleTitle === null || $roleTitle === '')) {
            Response::error('Zadaj funkciu osoby (napr. konateľ).', 422);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if (($req->jsonBool('is_default') ?? false) === true) {
                self::clearDefault($companyId, $accountId);
            }
            // Each field is updated only when the body mentions it, so a
            // partial edit from the signature picker cannot blank the rest.
            if (array_key_exists('fullname', $body)) {
                self::set($id, 'fullname', $fullname);
            }
            if (array_key_exists('role_title', $body)) {
                self::set($id, 'role_title', $roleTitle);
            }
            if (array_key_exists('email', $body)) {
                self::set($id, 'email', self::readEmail($req));
            }
            if (array_key_exists('facility_id', $body)) {
                self::set($id, 'facility_id', self::readFacilityId($req, $companyId));
            }
            if (array_key_exists('is_default', $body)) {
                self::set($id, 'is_default', ($req->jsonBool('is_default') ?? false) ? 1 : 0);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['person' => self::load($id, $accountId)]);
    }

    /**
     * Archive rather than delete: a protocol signed last year records who
     * signed it, and that reference must survive the person leaving the
     * company.
     */
    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $id = (int) $params['person_id'];
        $accountId = self::assertCompany((int) $params['id']);
        if (self::load($id, $accountId) === null) {
            Response::error('Osoba sa nenašla.', 404);
        }
        Db::pdo()->prepare(
            'UPDATE company_persons SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $accountId]);

        Response::noContent();
    }

    /**
     * Resolve the company, returning the account it belongs to. Admins act
     * across accounts, so the company's own account_id is what scopes every
     * query below — never the admin's session account.
     */
    private static function assertCompany(int $companyId): int
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        $sql = 'SELECT account_id FROM companies WHERE id = ? AND archived_at IS NULL';
        $args = [$companyId];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            Response::error('Firma sa nenašla.', 404);
        }
        return (int) $found;
    }

    private static function readFacilityId(Request $req, int $companyId): ?int
    {
        $facilityId = $req->jsonInt('facility_id');
        if ($facilityId === null) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM facilities WHERE id = ? AND company_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$facilityId, $companyId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Prevádzka sa nenašla.', 404);
        }
        return $facilityId;
    }

    private static function readEmail(Request $req): ?string
    {
        $email = $req->jsonString('email');
        if ($email === null || $email === '') {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Zadaj platnú e-mailovú adresu.', 422);
        }
        return $email;
    }

    private static function clearDefault(int $companyId, int $accountId): void
    {
        Db::pdo()->prepare(
            'UPDATE company_persons SET is_default = 0
             WHERE  company_id = ? AND account_id = ?'
        )->execute([$companyId, $accountId]);
    }

    private static function set(int $id, string $column, mixed $value): void
    {
        // $column comes from this class's own whitelist of body keys above,
        // never from the request — safe to interpolate.
        Db::pdo()->prepare("UPDATE company_persons SET $column = ? WHERE id = ?")
            ->execute([$value, $id]);
    }

    /** @return array<string, mixed>|null */
    private static function load(int $id, int $accountId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, facility_id, fullname, role_title, email, is_default
             FROM   company_persons
             WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        return $row ? self::shape($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'facility_id' => $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
            'fullname'    => (string) $row['fullname'],
            'role_title'  => (string) $row['role_title'],
            'email'       => $row['email'] !== null ? (string) $row['email'] : null,
            'is_default'  => (int) $row['is_default'] === 1,
        ];
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
