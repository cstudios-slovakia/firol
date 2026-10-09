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
use Firol\Support\Periodicity;
use Firol\Support\Sections;

/**
 * Návšteva — one trip to a client, several úkony — block 1 / chapter 9.
 *
 * A technician at a client normally does three or four things: the fire book,
 * the uzávery, the extinguishers. Until now each of those meant picking the
 * company and the prevádzka again. A visit asks once, then walks through the
 * úkony one by one, and ends with all the protocols generated and sent in a
 * single e-mail.
 *
 * The visit owns nothing of its own: each úkon is a full inspection with its
 * own number, its own periodicity and its own next term. The visit is the
 * thread between them.
 *
 * The one úkon that is not an inspection is the školenie PO (`skolenie_po`): a
 * training lives in its own table, so the visit lists the trainings recorded
 * under it next to its inspections.
 */
final class VisitController
{
    /** Planned type of the školenie PO — a training, not an inspection type. */
    public const TYPE_SKOLENIE_PO = 'skolenie_po';

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        $sql = 'SELECT v.id, v.company_id, c.name AS company_name,
                       v.facility_id, f.name AS facility_name,
                       v.visit_date, v.technician_user_id, u.fullname AS technician_name,
                       v.planned_types, v.status, v.created_at
                FROM   visits v
                JOIN   companies  c ON c.id = v.company_id
                JOIN   facilities f ON f.id = v.facility_id
                JOIN   users      u ON u.id = v.technician_user_id
                WHERE  v.archived_at IS NULL';
        $args = [];
        if (!$isAdmin) {
            $sql .= ' AND v.account_id = ?';
            $args[] = $accountId;
        }
        $companyId = self::queryInt($req, 'company_id');
        if ($companyId !== null) {
            $sql .= ' AND v.company_id = ?';
            $args[] = $companyId;
        }
        $sql .= ' ORDER BY v.visit_date DESC, v.id DESC LIMIT 100';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::shape($row);
        }
        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        Response::json(['visit' => self::shape($row)]);
    }

    /**
     * Start a visit. The chosen types are only a plan — the technician can
     * skip one on the spot or add another; what counts is the úkony that end
     * up pointing at the visit.
     */
    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();

        $companyId = $req->jsonInt('company_id');
        $facilityId = $req->jsonInt('facility_id');
        $visitDate = $req->jsonString('visit_date');
        $technicianId = $req->jsonInt('technician_user_id') ?? $userId;
        $plannedTypes = $req->json()['planned_types'] ?? [];

        if ($companyId === null || $facilityId === null) {
            Response::error('Vyber firmu aj prevádzku.', 422);
        }
        if ($visitDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
            Response::error('Zadaj dátum návštevy.', 422);
        }
        if (!is_array($plannedTypes) || $plannedTypes === []) {
            Response::error('Vyber aspoň jeden typ úkonu.', 422);
        }
        $known = self::knownTypes();
        $plannedTypes = array_values(array_unique(array_filter(
            array_map(static fn ($t): string => is_string($t) ? $t : '', $plannedTypes),
            static fn (string $t): bool => in_array($t, $known, true),
        )));
        if ($plannedTypes === []) {
            Response::error('Vyber aspoň jeden typ úkonu.', 422);
        }

        // An admin's company list spans every account, so the visit belongs to
        // the account that owns the chosen facility, not the admin's own.
        $isAdmin = Admin::isAdmin($userId);
        $check = Db::pdo()->prepare(
            'SELECT f.account_id
             FROM   facilities f
             JOIN   companies  c ON c.id = f.company_id
             WHERE  f.id = ? AND f.company_id = ? AND (? = 1 OR f.account_id = ?)
                AND f.archived_at IS NULL AND c.archived_at IS NULL'
        );
        $check->execute([$facilityId, $companyId, $isAdmin ? 1 : 0, $accountId]);
        $ownerAccountId = $check->fetchColumn();
        if ($ownerAccountId === false) {
            Response::error('Firma alebo prevádzka sa nenašla.', 404);
        }
        $accountId = (int) $ownerAccountId;

        $auCheck = Db::pdo()->prepare(
            'SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?'
        );
        $auCheck->execute([$accountId, $technicianId]);
        if ($auCheck->fetchColumn() === false && !$isAdmin) {
            Response::error('Zvolený technik nie je členom tohto účtu.', 422);
        }

        Db::pdo()->prepare(
            'INSERT INTO visits
                (account_id, company_id, facility_id, visit_date, technician_user_id, planned_types)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $accountId, $companyId, $facilityId, $visitDate, $technicianId,
            json_encode($plannedTypes, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json([
            'visit' => self::shape(self::loadOrFail($accountId, $id)),
        ], 201);
    }

    /** Change the plan mid-visit, or close the visit once the work is done. */
    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = (int) $row['account_id'];
        $body = $req->json();

        if (array_key_exists('planned_types', $body)) {
            $known = self::knownTypes();
            $types = array_values(array_unique(array_filter(
                array_map(static fn ($t): string => is_string($t) ? $t : '', (array) $body['planned_types']),
                static fn (string $t): bool => in_array($t, $known, true),
            )));
            Db::pdo()->prepare('UPDATE visits SET planned_types = ? WHERE id = ? AND account_id = ?')
                ->execute([json_encode($types, JSON_UNESCAPED_UNICODE), $id, $scopeAccountId]);
        }

        $status = $req->jsonString('status');
        if ($status !== null) {
            if (!in_array($status, ['prebieha', 'dokoncena'], true)) {
                Response::error('Neznámy stav návštevy.', 422);
            }
            Db::pdo()->prepare('UPDATE visits SET status = ? WHERE id = ? AND account_id = ?')
                ->execute([$status, $id, $scopeAccountId]);
        }

        $visitDate = $req->jsonString('visit_date');
        if ($visitDate !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
                Response::error('Zadaj dátum návštevy.', 422);
            }
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE visits SET visit_date = ? WHERE id = ? AND account_id = ?')
                    ->execute([$visitDate, $id, $scopeAccountId]);
                // The date is the visit's, shared by all its úkony (chapter 9),
                // so the ones still being worked on follow it. An issued
                // protocol keeps the date it was printed with.
                $pdo->prepare(
                    'UPDATE inspections SET executed_on = ?
                     WHERE  visit_id = ? AND account_id = ? AND status = "draft" AND archived_at IS NULL'
                )->execute([$visitDate, $id, $scopeAccountId]);
                $pdo->prepare(
                    'UPDATE trainings SET date = ?
                     WHERE  visit_id = ? AND account_id = ? AND status = "draft" AND archived_at IS NULL'
                )->execute([$visitDate, $id, $scopeAccountId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $fresh = self::loadOrFail($scopeAccountId, $id);
        Response::json(['visit' => self::shape($fresh)]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        MemberRights::requireDelete((int) $row['account_id']);
        // The úkony survive the visit: they are protocols in their own right,
        // and dropping the thread between them must not drop them.
        Db::pdo()->prepare('UPDATE visits SET archived_at = NOW() WHERE id = ? AND account_id = ?')
            ->execute([$id, (int) $row['account_id']]);

        Response::noContent();
    }

    /**
     * Generate the protocol of every finished úkon in the visit that does not
     * have one yet — "Generovať všetky protokoly" (chapter 9, step 5).
     *
     * Each protocol is generated on its own and gets its own number from its
     * own series. One failing úkon (a missing date, no items yet) does not
     * stop the rest: the response says which ones were issued and which were
     * skipped, so the technician can fix that one and press the button again.
     */
    public static function generateDocuments(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = (int) $row['account_id'];

        $generated = [];
        $skipped = [];
        foreach (self::loadInspections($id) as $inspection) {
            if ($inspection['status'] === 'finalized') {
                continue;
            }
            $result = DocumentController::generateForInspectionInternal(
                $scopeAccountId,
                Tenant::currentUserId(),
                (int) $inspection['id'],
                true,
            );
            if (isset($result['error'])) {
                $skipped[] = [
                    'inspection_id' => (int) $inspection['id'],
                    'training_id'   => null,
                    'type'          => $inspection['type'],
                    'reason'        => $result['error'],
                ];
                continue;
            }
            $generated[] = $result['document'];
        }

        // The školenie PO goes through the very same generator the training's
        // own screen uses; a training that is not ready (no trainer, no
        // trainees) is reported like an úkon that is not.
        foreach (self::loadTrainings($id) as $training) {
            if ($training['status'] === 'finalized') {
                continue;
            }
            $result = DocumentController::generateForTrainingInternal(
                $scopeAccountId,
                (int) $training['id'],
            );
            if (isset($result['error'])) {
                $skipped[] = [
                    'inspection_id' => null,
                    'training_id'   => (int) $training['id'],
                    'type'          => self::TYPE_SKOLENIE_PO,
                    'reason'        => $result['error'],
                ];
                continue;
            }
            $generated[] = $result['document'];
        }

        Response::json([
            'generated' => $generated,
            'skipped'   => $skipped,
            'visit'     => self::shape(self::loadOrFail($scopeAccountId, $id)),
        ]);
    }

    /**
     * Hold (or release) the protocol of one draft úkon until the end of the
     * visit. Body: inspection_id or training_id, plus deferred (bool).
     */
    public static function deferProtocol(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = (int) $row['account_id'];

        $body = $req->json();
        $inspectionId = isset($body['inspection_id']) ? (int) $body['inspection_id'] : 0;
        $trainingId = isset($body['training_id']) ? (int) $body['training_id'] : 0;
        if (($inspectionId > 0) === ($trainingId > 0) || !is_bool($body['deferred'] ?? null)) {
            Response::error('Neplatná požiadavka.', 422);
        }
        $table = $inspectionId > 0 ? 'inspections' : 'trainings';
        $ukonId = $inspectionId > 0 ? $inspectionId : $trainingId;

        $stmt = Db::pdo()->prepare(
            "SELECT status FROM {$table}
             WHERE  id = ? AND visit_id = ? AND account_id = ? AND archived_at IS NULL"
        );
        $stmt->execute([$ukonId, $id, $scopeAccountId]);
        $ukon = $stmt->fetch();
        if (!$ukon || $ukon['status'] !== 'draft') {
            Response::error('Úkon nepatrí do tejto návštevy alebo už má vygenerovaný protokol.', 422);
        }

        Db::pdo()->prepare(
            "UPDATE {$table}
             SET    protocol_deferred_at = " . ($body['deferred'] ? 'NOW()' : 'NULL') . '
             WHERE  id = ? AND account_id = ?'
        )->execute([$ukonId, $scopeAccountId]);

        Response::json(['visit' => self::shape(self::loadOrFail($scopeAccountId, $id))]);
    }

    /**
     * Úkony recorded under this visit, with their protocol when one exists.
     *
     * @return list<array<string, mixed>>
     */
    private static function loadInspections(int $visitId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.id, i.type, i.status, i.executed_on,
                    i.periodicity_value, i.periodicity_unit, i.protocol_deferred_at,
                    (SELECT COUNT(*) FROM inspection_items it WHERE it.inspection_id = i.id) AS item_count,
                    d.id AS document_id, d.number AS document_number
             FROM   inspections i
             LEFT   JOIN documents d
                    ON d.parent_type = "inspection" AND d.parent_id = i.id
             WHERE  i.visit_id = ? AND i.archived_at IS NULL
             ORDER  BY i.id ASC'
        );
        $stmt->execute([$visitId]);

        return array_map(static function (array $r): array {
            return [
                'id'          => (int) $r['id'],
                'type'        => (string) $r['type'],
                'status'      => (string) $r['status'],
                'executed_on' => $r['executed_on'],
                'item_count'  => (int) $r['item_count'],
                'valid_until' => Periodicity::validUntil(
                    $r['executed_on'],
                    $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null,
                    $r['periodicity_unit'],
                ),
                'document_id'     => $r['document_id'] !== null ? (int) $r['document_id'] : null,
                'document_number' => $r['document_number'],
                'deferred'        => $r['protocol_deferred_at'] !== null,
            ];
        }, $stmt->fetchAll());
    }

    /**
     * The visit an úkon is being recorded under, for the create endpoints of
     * inspections and trainings. They take company, prevádzka and date from it
     * rather than from the request, so a úkon can never drift away from the
     * visit that lists it. Null when the visit does not exist, is archived, or
     * sits in another account (admins see every account).
     *
     * @return array{account_id: int, company_id: int, facility_id: int, visit_date: string}|null
     */
    public static function findForUkon(int $visitId, bool $isAdmin, int $accountId): ?array
    {
        $sql = 'SELECT account_id, company_id, facility_id, visit_date
                FROM   visits
                WHERE  id = ? AND archived_at IS NULL';
        $args = [$visitId];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'account_id'  => (int) $row['account_id'],
            'company_id'  => (int) $row['company_id'],
            'facility_id' => (int) $row['facility_id'],
            'visit_date'  => (string) $row['visit_date'],
        ];
    }

    /**
     * Trainings (školenia PO) recorded under this visit, with their protocol
     * when one exists.
     *
     * @return list<array<string, mixed>>
     */
    private static function loadTrainings(int $visitId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.id, t.type, t.status, t.date, t.protocol_deferred_at,
                    (SELECT COUNT(*) FROM trainees tr WHERE tr.training_id = t.id) AS trainees_count,
                    d.id AS document_id, d.number AS document_number
             FROM   trainings t
             LEFT   JOIN documents d
                    ON d.parent_type = "training" AND d.parent_id = t.id
             WHERE  t.visit_id = ? AND t.archived_at IS NULL
             ORDER  BY t.id ASC'
        );
        $stmt->execute([$visitId]);

        return array_map(static function (array $r): array {
            return [
                'id'              => (int) $r['id'],
                'type'            => (string) $r['type'],
                'status'          => (string) $r['status'],
                'date'            => $r['date'],
                'trainees_count'  => (int) $r['trainees_count'],
                'document_id'     => $r['document_id'] !== null ? (int) $r['document_id'] : null,
                'document_number' => $r['document_number'],
                'deferred'        => $r['protocol_deferred_at'] !== null,
            ];
        }, $stmt->fetchAll());
    }

    /** @return list<string> */
    private static function knownTypes(): array
    {
        // The školenie PO is a planned type like any other, though it is a
        // training rather than an inspection.
        $types = [self::TYPE_SKOLENIE_PO];
        foreach (Sections::INSPECTION_TYPES as $sectionTypes) {
            foreach ($sectionTypes as $t) {
                $types[] = $t;
            }
        }
        return $types;
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT v.id, v.account_id, v.company_id, c.name AS company_name,
                       v.facility_id, f.name AS facility_name,
                       v.visit_date, v.technician_user_id, u.fullname AS technician_name,
                       v.planned_types, v.status, v.created_at
                FROM   visits v
                JOIN   companies  c ON c.id = v.company_id
                JOIN   facilities f ON f.id = v.facility_id
                JOIN   users      u ON u.id = v.technician_user_id
                WHERE  v.id = ? AND v.archived_at IS NULL';
        $args = [$id];
        if ($accountId !== null) {
            $sql .= ' AND v.account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Návšteva sa nenašla.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $planned = json_decode((string) $row['planned_types'], true);
        return [
            'id'                 => (int) $row['id'],
            'company_id'         => (int) $row['company_id'],
            'company_name'       => (string) $row['company_name'],
            'facility_id'        => (int) $row['facility_id'],
            'facility_name'      => (string) $row['facility_name'],
            'visit_date'         => (string) $row['visit_date'],
            'technician_user_id' => (int) $row['technician_user_id'],
            'technician_name'    => (string) $row['technician_name'],
            'planned_types'      => is_array($planned) ? $planned : [],
            'status'             => (string) $row['status'],
            'created_at'         => $row['created_at'],
            'inspections'        => self::loadInspections((int) $row['id']),
            'trainings'          => self::loadTrainings((int) $row['id']),
        ];
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
