<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * Who a document is prefilled with as the zodpovedná osoba (spec 6.6).
 *
 * A požiarna kniha entry takes the company-level person: the default
 * company-wide `company_persons` row, otherwise `companies.approver`.
 * Every other document takes the prevádzka-level person: a `company_persons`
 * row pinned to that prevádzka, otherwise `facilities.contact_person`,
 * otherwise the company-level one.
 *
 * Always a prefill. Whatever screen uses this keeps the field editable.
 * The same rules are in frontend/src/lib/responsiblePerson.ts.
 */
final class ResponsiblePerson
{
    /**
     * @return array{fullname: string, role_title: ?string}|null
     */
    public static function forFireBook(int $accountId, int $companyId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT fullname, role_title
             FROM   company_persons
             WHERE  account_id = ? AND company_id = ? AND facility_id IS NULL
               AND  is_default = 1 AND archived_at IS NULL
             LIMIT  1'
        );
        $stmt->execute([$accountId, $companyId]);
        $row = $stmt->fetch();
        if ($row) {
            return self::person($row);
        }

        $approver = Db::pdo()->prepare(
            'SELECT approver FROM companies WHERE id = ? AND account_id = ?'
        );
        $approver->execute([$companyId, $accountId]);
        $name = $approver->fetchColumn();
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        return ['fullname' => trim($name), 'role_title' => null];
    }

    /**
     * @return array{fullname: string, role_title: ?string}|null
     */
    public static function forFacility(int $accountId, int $companyId, int $facilityId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT fullname, role_title
             FROM   company_persons
             WHERE  account_id = ? AND company_id = ? AND facility_id = ?
               AND  archived_at IS NULL
             ORDER  BY is_default DESC, fullname ASC
             LIMIT  1'
        );
        $stmt->execute([$accountId, $companyId, $facilityId]);
        $row = $stmt->fetch();
        if ($row) {
            return self::person($row);
        }

        $contact = Db::pdo()->prepare(
            'SELECT contact_person FROM facilities
             WHERE  id = ? AND company_id = ? AND account_id = ?'
        );
        $contact->execute([$facilityId, $companyId, $accountId]);
        $name = $contact->fetchColumn();
        if (is_string($name) && trim($name) !== '') {
            return ['fullname' => trim($name), 'role_title' => null];
        }

        return self::forFireBook($accountId, $companyId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{fullname: string, role_title: ?string}
     */
    private static function person(array $row): array
    {
        $role = $row['role_title'] ?? null;
        $role = is_string($role) && trim($role) !== '' ? trim($role) : null;
        return ['fullname' => trim((string) $row['fullname']), 'role_title' => $role];
    }
}
