<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Csrf;
use Firol\Auth\MemberRights;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\BookLedger;
use Firol\Support\BookMapping;
use Firol\Support\BookSentences;
use Firol\Support\ClientKind;
use Firol\Support\InspectionDetails;
use Firol\Support\PrehliadkaActivities;
use Firol\Support\PrehliadkaConcepts;
use Firol\Support\PrehliadkaCloser;
use Firol\Support\PrehliadkaDefects;
use Firol\Support\PrehliadkaDetails;
use Firol\Support\PrehliadkaPrevious;
use Firol\Support\PrehliadkaRecord;
use Firol\Support\PrehliadkaTerms;

/**
 * What steps 2 and 3 of a preventívna prehliadka read and write beyond the
 * generic inspection API (spec 6.4–6.6, plan phase 7b).
 *
 *   GET    /api/facilities/{id}/prehliadka-previous[?exclude=<inspection id>]
 *   GET    /api/facilities/{id}/prehliadka-terms?as_of=Y-m-d
 *   GET    /api/companies/{id}/activities
 *   POST   /api/companies/{id}/activities                 { kind, label }
 *   DELETE /api/companies/{id}/activities/{activity_id}   (archives)
 *   POST   /api/inspections/{id}/confirm-concept          { confirmed? }
 *
 * Offline: the reads are cached like any GET; adding or removing a firm's own
 * activity is online-only (it changes a list shared by the firm's later
 * prehliadky). What the technician ticks and decides during the visit is saved
 * in the prehliadka itself and queues like the rest of it.
 */
final class PrehliadkaController
{
    public static function previous(Request $req, array $params): void
    {
        $facility = self::facility((int) $params['id']);
        $exclude = $req->query('exclude');

        Response::json(PrehliadkaPrevious::forFacility(
            (int) $facility['account_id'],
            (int) $facility['id'],
            $exclude !== null && ctype_digit($exclude) ? (int) $exclude : null,
        ));
    }

    public static function terms(Request $req, array $params): void
    {
        $facility = self::facility((int) $params['id']);
        $asOf = $req->query('as_of');
        if ($asOf === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            $asOf = date('Y-m-d');
        }

        Response::json(PrehliadkaTerms::forFacility((int) $facility['account_id'], (int) $facility['id'], $asOf));
    }

    public static function activities(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $company = self::company($accountId, (int) $params['id']);

        Response::json(PrehliadkaActivities::forCompany($accountId, $company['id']));
    }

    public static function addActivity(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $company = self::writableCompany($accountId, (int) $params['id']);

        try {
            $activity = PrehliadkaActivities::add(
                $accountId,
                $company['id'],
                (string) ($req->jsonString('kind') ?? ''),
                (string) ($req->jsonString('label') ?? ''),
                Tenant::currentUserId(),
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }

        AuditLog::record('company_activity.create', 'company', $company['id'], null, ['activity_id' => $activity['id']]);
        Response::json(['activity' => $activity], 201);
    }

    public static function removeActivity(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        MemberRights::requireDelete($accountId);
        $company = self::writableCompany($accountId, (int) $params['id']);

        if (!PrehliadkaActivities::archive($accountId, $company['id'], (int) $params['activity_id'])) {
            Response::error('Činnosť sa nenašla.', 404);
        }
        AuditLog::record('company_activity.archive', 'company', $company['id'], null, ['activity_id' => (int) $params['activity_id']]);
        Response::noContent();
    }

    /**
     * POST /api/inspections/{id}/prehliadka-close   { include_photos? }
     *
     * Closes the prehliadka: one transaction issues the PP record, the BOZP
     * record and the book entries ({@see PrehliadkaCloser}). Online-only — it
     * takes numbers — and the answer is the result screen's data.
     */
    public static function close(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];

        $result = PrehliadkaCloser::close(
            $accountId,
            Tenant::currentUserId(),
            $id,
            $req->jsonBool('include_photos') ?? true,
        );
        if (isset($result['error'])) {
            Response::error((string) $result['error'], (int) $result['status']);
        }

        AuditLog::record(
            'prehliadka.close',
            'inspections',
            $id,
            ['status' => 'draft'],
            ['status' => 'finalized', 'po_document_id' => $result['po_document_id'], 'bozp_inspection_id' => $result['bozp_inspection_id']],
        );
        Response::json(self::resultFor($accountId, $id), 201);
    }

    /**
     * POST /api/inspections/{id}/confirm-concept   { confirmed?: bool = true }
     *
     * The technician confirms the results a prehliadka prepared in a concept
     * (spec 7.3) — until then the protocol cannot be generated. `confirmed:
     * false` takes the confirmation back. Online-only, like generating.
     */
    public static function confirmConcept(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];

        $inspection = DocumentController::loadInspectionForGenerate($accountId, $id);
        $details = InspectionDetails::decode($inspection['details'] ?? null);
        if (!PrehliadkaConcepts::isConcept($details)) {
            Response::error('Toto nie je koncept z preventívnej prehliadky.', 422);
        }
        if ($inspection['status'] !== 'draft') {
            Response::error('Kontrola už je uzamknutá a má vystavený protokol.', 409);
        }
        $confirmed = $req->jsonBool('confirmed') ?? true;
        if ($confirmed) {
            $items = DocumentController::loadItems($id);
            if ($items === []) {
                Response::error('Koncept nemá žiadne zariadenie — pridaj aspoň jedno.', 422);
            }
            // A device the walk-through wrote down starts with the fields it did not
            // ask for blank; a protocol must not carry them (spec 7.3). Taking the
            // confirmation back is never restricted.
            $incomplete = PrehliadkaConcepts::incompleteItems((string) $inspection['type'], $items);
            if ($incomplete !== []) {
                Response::error(PrehliadkaConcepts::incompleteMessage($incomplete), 422, ['incomplete' => $incomplete]);
            }
        }

        $details[PrehliadkaConcepts::CONFIRMED_KEY] = $confirmed ? date('Y-m-d H:i:s') : null;
        Db::pdo()->prepare('UPDATE inspections SET details = ? WHERE id = ? AND account_id = ?')
            ->execute([InspectionDetails::encode($details), $id, $accountId]);
        AuditLog::record(
            $confirmed ? 'concept.confirm' : 'concept.unconfirm',
            'inspections',
            $id,
            null,
            ['source_inspection_id' => $details[PrehliadkaConcepts::KEY]],
        );

        Response::json(['confirmed' => $confirmed, 'details' => $details]);
    }

    /** GET /api/inspections/{id}/prehliadka-result — what a closed prehliadka produced. */
    public static function result(Request $req, array $params): void
    {
        Response::json(self::resultFor(Tenant::currentAccountId(), (int) $params['id']));
    }

    /**
     * The documents, book entries and the offer of a closed prehliadka (prototype
     * `scrVysledok`).
     *
     * @return array<string, mixed>
     */
    private static function resultFor(int $accountId, int $id): array
    {
        $inspection = DocumentController::loadInspectionForGenerate($accountId, $id);
        if ((string) $inspection['type'] !== PrehliadkaDetails::TYPE) {
            Response::error('Toto nie je preventívna prehliadka.', 422);
        }
        $details = InspectionDetails::decode($inspection['details'] ?? null) ?? [];
        $scope = is_array($details['scope'] ?? null) ? $details['scope'] : [];
        $po = ($scope['po'] ?? false) === true;
        $bozp = ($scope['bozp'] ?? false) === true;
        $companyId = (int) $inspection['company_id'];

        $items = DocumentController::loadItems($id);
        $poPoints = PrehliadkaRecord::points($items, PrehliadkaRecord::PO);
        $bozpPoints = PrehliadkaRecord::points($items, PrehliadkaRecord::BOZP);

        $poDocs = DocumentController::listDocuments($accountId, 'inspection', $id);

        $childId = null;
        $bozpDocs = [];
        if ($bozp) {
            $find = Db::pdo()->prepare(
                'SELECT id FROM inspections
                 WHERE  account_id = ? AND source_inspection_id = ? AND type = "kniha_bozp" AND archived_at IS NULL
                 ORDER  BY id DESC LIMIT 1'
            );
            $find->execute([$accountId, $id]);
            $found = $find->fetchColumn();
            if ($found !== false) {
                $childId = (int) $found;
                $bozpDocs = DocumentController::listDocuments($accountId, 'inspection', $childId);
            }
        }

        $entry = static function (string $book, ?int $recordId) use ($accountId, $companyId): ?array {
            if ($recordId === null) {
                return null;
            }
            $e = BookLedger::entryOfInspection($accountId, $companyId, $book, $recordId);
            return $e === null ? null : [
                'id' => $e['id'], 'seq' => $e['seq'], 'signed_manager' => $e['signed_manager'], 'facility_id' => $e['facility_id'],
            ];
        };
        $poEntry = $entry(BookMapping::PO, $po ? $id : null);

        // The fire book was not ticked on close: the app offers the entry with its sentence ready.
        $offer = null;
        if ($po && $poEntry === null && $poDocs !== [] && $inspection['status'] === 'finalized') {
            $offer = [
                'text'             => BookSentences::prehliadka(
                    BookMapping::PO,
                    (string) $inspection['executed_on'],
                    (string) $inspection['facility_name'],
                    (string) $poDocs[0]['number'],
                    count($poPoints),
                ),
                'protocol_numbers' => [(string) $poDocs[0]['number']],
                'has_defects'      => $poPoints !== [],
                'legal_sentence'   => BookSentences::LEGAL,
            ];
        }

        return [
            'inspection' => [
                'id'            => $id,
                'status'        => (string) $inspection['status'],
                'executed_on'   => $inspection['executed_on'],
                'company_id'    => $companyId,
                'facility_id'   => (int) $inspection['facility_id'],
                'facility_name' => (string) $inspection['facility_name'],
                'facility_city' => $inspection['facility_city'] ?? null,
                'scope'         => ['po' => $po, 'bozp' => $bozp],
            ],
            'po'    => $po ? ['documents' => $poDocs, 'defects' => count($poPoints)] : null,
            'bozp'  => $bozp ? ['inspection_id' => $childId, 'documents' => $bozpDocs, 'defects' => count($bozpPoints)] : null,
            'entries' => [
                'po'   => $poEntry,
                'bozp' => null,
            ],
            'offer' => $offer,
            // Koncepty riadnych kontrol (spec 7.3) — one draft úkon per ticked type.
            'concepts' => PrehliadkaConcepts::summary($accountId, $id),
            // Defects the „Overiť odstránenie" task can be offered for (spec 20) — after closing only.
            'task_defects' => PrehliadkaDefects::taskCandidates($accountId, $id, $inspection, $items),
        ];
    }

    /**
     * A prevádzka the caller may read — the same tenant rule as the device list.
     *
     * @return array{id: int, account_id: int}
     */
    private static function facility(int $facilityId): array
    {
        $accountId = Tenant::currentAccountId();
        $stmt = Db::pdo()->prepare('SELECT id, account_id FROM facilities WHERE id = ? AND account_id = ?');
        $stmt->execute([$facilityId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Prevádzka sa nenašla.', 404);
        }
        return ['id' => (int) $row['id'], 'account_id' => (int) $row['account_id']];
    }

    /** @return array{id: int, archived_at: ?string, ...} */
    private static function company(int $accountId, int $companyId): array
    {
        $company = BookLedger::company($accountId, $companyId);
        if ($company === null) {
            Response::error('Firma sa nenašla.', 404);
        }
        return $company;
    }

    /** An archived firm is read-only (spec 25). */
    private static function writableCompany(int $accountId, int $companyId): array
    {
        $company = self::company($accountId, $companyId);
        if ($company['archived_at'] !== null) {
            Response::error(ClientKind::clientArchivedReadOnly((string) $company['client_type'], 'činnosti sa už nemenia.'), 409);
        }
        return $company;
    }
}
