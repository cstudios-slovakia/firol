<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Address;
use Firol\Support\BookEntry;
use Firol\Support\BookRecords;

/**
 * What a new or edited požiarna kniha entry needs to know about its company
 * (POapp 7. 11. 2026, spec 4 step 1 and spec 7) — one read, cached offline by
 * the client so an entry can be started without a connection.
 *
 * GET /api/companies/{id}/kniha
 *
 *   company               id, name, ico, client_type
 *   schvalil_default      „Meno, funkcia" of the client's person for the book, or null
 *   activities            the activity list of the client type (spec 6), in order
 *   company_group_heading heading of the group of the organisation's own
 *                         trainings, or null when they are not offered
 *   facilities            active prevádzky by name, each with the number of
 *                         newest records it has to attach and its last term
 *                         (`last_prehliadka`, BookEntry::lastTerm) or null
 *   records               every attachable record (BookRecords::forCompany)
 */
final class KnihaController
{
    public static function context(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $companyId = (int) $params['id'];

        $sql = 'SELECT id, account_id, name, ico, client_type FROM companies WHERE id = ? AND archived_at IS NULL';
        $args = [$companyId];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $company = $stmt->fetch();
        if (!$company) {
            Response::error('Firma sa nenašla.', 404);
        }
        $accountId = (int) $company['account_id'];

        $records = BookRecords::forCompany($accountId, $companyId);
        $attach = [];
        foreach ($records as $rec) {
            if ($rec['facility_id'] !== null && $rec['is_latest']) {
                $attach[$rec['facility_id']] = ($attach[$rec['facility_id']] ?? 0) + 1;
            }
        }

        $fStmt = Db::pdo()->prepare(
            'SELECT id, name, street, postal_code, city FROM facilities
             WHERE  company_id = ? AND account_id = ? AND archived_at IS NULL
             ORDER  BY name ASC, id ASC'
        );
        $fStmt->execute([$companyId, $accountId]);
        $facilities = [];
        foreach ($fStmt->fetchAll() as $f) {
            $fid = (int) $f['id'];
            $last = BookEntry::lastTerm($accountId, $fid);
            $facilities[] = [
                'id'              => $fid,
                'name'            => (string) $f['name'],
                'address'         => Address::format($f['street'], $f['postal_code'], $f['city']),
                'attach_count'    => $attach[$fid] ?? 0,
                'last_prehliadka' => $last,
            ];
        }

        Response::json([
            'company' => [
                'id'          => (int) $company['id'],
                'name'        => (string) $company['name'],
                'ico'         => $company['ico'] !== null ? (string) $company['ico'] : null,
                'client_type' => (string) $company['client_type'],
            ],
            'schvalil_default'      => BookEntry::schvalilDefault($accountId, $companyId),
            'activities'            => BookEntry::activitiesFor((string) $company['client_type']),
            'company_group_heading' => BookRecords::OFFER_COMPANY_TRAININGS ? BookRecords::COMPANY_GROUP_HEADING : null,
            'facilities'            => $facilities,
            'records'               => array_map([BookRecords::class, 'publicShape'], $records),
        ]);
    }
}
