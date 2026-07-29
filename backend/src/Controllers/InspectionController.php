<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;
use PDO;

final class InspectionController
{
    /**
     * Computes whether an inspection has been "superseded" — i.e. a newer
     * inspection exists for the same facility + type (within the same
     * account). The validity status (platná / blíži sa / po termíne) is
     * derived on the frontend; a superseded record drops out of the overdue
     * bucket so a renewed control no longer flags "po termíne". This covers
     * both the "Opakovať" flow and a manually re-created inspection.
     * Correlates on the outer alias `i`.
     *
     * Three conditions a row must meet to supersede an older one:
     *   - it is finalized. A draft carries no validity of its own, so it must
     *     never invalidate a finished inspection — otherwise merely starting
     *     a repeat would mark the still-valid protocol "Nahradená".
     *   - it is newer *by execution date*, not by id. Inspection dates are
     *     always entered manually, so a backdated record created later has a
     *     higher id while covering an earlier cycle; `id` only breaks ties
     *     between two inspections executed on the same day.
     *   - it is itself a cycle-advancing inspection
     *     (`is_preventive_inspection = 1`). A plain požiarna kniha entry
     *     (is_preventive_inspection = 0) must never supersede the previous
     *     preventive inspection — otherwise a routine note would mask a
     *     missed statutory inspection (change request 1.7).
     *
     * Drafts are never superseded themselves (`i.status = 'finalized'`) — a
     * concept is judged by its own status, not against the finalized record.
     *
     * `executed_on` is nullable, so both sides are coalesced to a floor date
     * to keep the row comparison from collapsing to NULL.
     */
    private const SUPERSEDED_EXPR = "EXISTS(
                           SELECT 1 FROM inspections s
                           WHERE  i.status      = 'finalized'
                             AND  s.account_id  = i.account_id
                             AND  s.facility_id = i.facility_id
                             AND  s.type        = i.type
                             AND  s.archived_at IS NULL
                             AND  s.status      = 'finalized'
                             AND  s.is_preventive_inspection = 1
                             AND  (COALESCE(s.executed_on, '1000-01-01'), s.id)
                                > (COALESCE(i.executed_on, '1000-01-01'), i.id)
                       ) AS is_superseded";

    /**
     * Allowed periodicities per inspection type. Source of truth: locked
     * decisions in docs/Firol base document and docs/development-roadmap.md.
     * Step 1 must reject anything outside this map.
     *
     * @var array<string, list<int>>
     */
    private const TYPE_PERIODICITIES = [
        'php' => [12, 24],
        'hydranty' => [12],
        'oprava_ts_php' => [60],
        'poziarna_kniha' => [3, 6, 12],
        'pu_akcieschopnost' => [3],
        'pu_udrzba' => [12],
        'nudzove_osvetlenie' => [12],
        'ts_hadic' => [12],
        // Pokyn — žatevné práce is not here: it is a document issued to the
        // client's employees, so it lives in the training tree (see
        // TrainingController), not among the inspection types.
        // One-off document — a disposal has no recurrence (change request 2.1).
        'vyradenie' => [0],
    ];

    /**
     * Types that record a one-off event rather than advancing a statutory
     * cycle. Stored with `is_preventive_inspection = 0`, which is the same
     * mechanism a plain požiarna kniha entry uses (change request 1.7): it
     * keeps them out of the calendar's deadline computation and stops them
     * superseding anything.
     *
     * @var list<string>
     */
    private const NON_CYCLIC_TYPES = ['vyradenie'];

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        $companyId = self::queryInt($req, 'company_id');
        $facilityId = self::queryInt($req, 'facility_id');
        $type = $req->query('type');

        $sql = 'SELECT i.id, i.type, i.periodicity_months, i.executed_on,
                       i.is_preventive_inspection, i.source_inspection_id,
                       i.status, i.notes, i.created_at,
                       i.company_id, c.name AS company_name,
                       i.facility_id, f.name AS facility_name,
                       i.inspector_user_id, u.fullname AS inspector_name,
                       i.effective_inspector_user_id,
                       eu.fullname AS effective_inspector_name,
                       i.effective_cert_number,
                       ' . self::SUPERSEDED_EXPR . '
                FROM   inspections i
                JOIN   companies   c ON c.id = i.company_id
                JOIN   facilities  f ON f.id = i.facility_id
                JOIN   users       u ON u.id = i.inspector_user_id
                LEFT JOIN users    eu ON eu.id = i.effective_inspector_user_id
                WHERE  i.archived_at IS NULL';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND i.account_id = :account_id';
            $params['account_id'] = $accountId;
        }

        if ($companyId !== null) {
            $sql .= ' AND i.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($facilityId !== null) {
            $sql .= ' AND i.facility_id = :facility_id';
            $params['facility_id'] = $facilityId;
        }
        if ($type !== null) {
            $sql .= ' AND i.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY COALESCE(i.executed_on, i.created_at) DESC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'shapeRow'], $stmt->fetchAll());

        Response::json(['items' => $items]);
    }

    /**
     * Autocomplete suggestions for repetitive item fields (manufacturer / type
     * / location) drawn from the account's own history — so a technician
     * entering ten prístroje of the same make types it once, not ten times
     * (change request 2.4.1). Distinct values are ranked by how often they were
     * used; for `location`, values from the given facility float to the top.
     */
    public static function suggestions(Request $req): void
    {
        $accountId = Tenant::currentAccountId();

        // Whitelist the JSON key so it can be safely interpolated into the
        // JSON path (never user-controlled beyond this fixed set — so no SQL
        // injection is possible). Kept out of a bound param on purpose: the
        // same expression appears several times and reusing a named
        // placeholder is not portable across PDO emulation settings.
        $field = (string) $req->query('field');
        if (!in_array($field, ['manufacturer', 'type', 'location'], true)) {
            Response::error('Invalid field', 422);
        }
        $val = "JSON_UNQUOTE(JSON_EXTRACT(ji.fields, '\$.$field'))";
        // A JSON column is stored with the binary collation, which would make
        // the prefix match case- and accent-sensitive ("pe" missing "Peter",
        // "gloria" missing "Glória"). Compare through utf8mb4_unicode_ci so
        // both are ignored — technicians type without accents on mobile.
        $valCi = "CONVERT($val USING utf8mb4) COLLATE utf8mb4_unicode_ci";

        $q = trim((string) ($req->query('q') ?? ''));
        $facilityId = self::queryInt($req, 'facility_id');

        // LIKE-escape the prefix so %/_ typed by the user match literally.
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        $preferFacility = $field === 'location' && $facilityId !== null;

        $sql = "SELECT $val AS val"
             . ($preferFacility ? ', MAX(i.facility_id = :fid) AS same_fac' : '')
             . ", COUNT(*) AS uses
                FROM   inspection_items ji
                JOIN   inspections i ON i.id = ji.inspection_id
                WHERE  i.account_id = :acct
                  AND  i.archived_at IS NULL
                  AND  $val IS NOT NULL
                  AND  $val <> ''
                  AND  $valCi LIKE :like
                GROUP  BY val
                ORDER  BY " . ($preferFacility ? 'same_fac DESC, ' : '') . "uses DESC, val ASC
                LIMIT  20";

        $params = ['acct' => $accountId, 'like' => $like];
        if ($preferFacility) {
            $params['fid'] = $facilityId;
        }

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $values = array_values(array_filter(array_map(
            static fn ($r): string => (string) $r['val'],
            $stmt->fetchAll(),
        ), static fn (string $v): bool => $v !== ''));

        Response::json(['suggestions' => $values]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);

        $itemsStmt = Db::pdo()->prepare(
            'SELECT id, position, fields, created_at, updated_at
             FROM   inspection_items
             WHERE  inspection_id = ?
             ORDER  BY position ASC, id ASC'
        );
        $itemsStmt->execute([$id]);
        $rawItems = $itemsStmt->fetchAll();
        // Photo documentation (change request 2.2) — fetched in one query for
        // the whole inspection and zipped onto the items below, so a protocol
        // with 40 items still costs a single extra round trip.
        $photosByItem = InspectionPhotoController::byItemForInspection($id);
        $items = array_map(static function (array $r) use ($photosByItem): array {
            return [
                'id' => (int) $r['id'],
                'position' => (int) $r['position'],
                'fields' => json_decode((string) $r['fields'], true) ?? [],
                'photos' => $photosByItem[(int) $r['id']] ?? [],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $rawItems);

        // Follow-up drafts spawned from this inspection (change request 2.1),
        // so the detail view can show "nadväzujúci koncept existuje" and link.
        $fuStmt = Db::pdo()->prepare(
            'SELECT id, type, status FROM inspections
             WHERE  source_inspection_id = ? AND archived_at IS NULL
             ORDER  BY id ASC'
        );
        $fuStmt->execute([$id]);
        $followUps = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'type' => (string) $r['type'],
                'status' => (string) $r['status'],
            ];
        }, $fuStmt->fetchAll());

        Response::json([
            'inspection' => self::shapeRow($row),
            'items' => $items,
            'follow_ups' => $followUps,
        ]);
    }

    /**
     * Create a pre-filled DRAFT of a follow-up control off the back of this
     * inspection (change request 2.1):
     *   php      → oprava_ts_php   (prístroje with status "TS")
     *   php      → vyradenie       (prístroje with status "V")
     *   hydranty → ts_hadic        (hoses from the checked hydrants)
     * The draft is never finalized — the technician opens it later, enters the
     * real results and date, and generates the protocol. Re-triggering the
     * offer for the same source returns the existing draft (no duplicate).
     */
    public static function followUp(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $sourceId = (int) $params['id'];

        $source = self::loadOrFail($isAdmin ? null : $accountId, $sourceId);
        $accountId = (int) $source['account_id'];
        $sourceType = (string) $source['type'];

        $targetType = $req->jsonString('target_type');
        $allowed = [
            'php'      => ['oprava_ts_php', 'vyradenie'],
            'hydranty' => ['ts_hadic'],
        ];
        if (
            !isset($allowed[$sourceType])
            || $targetType === null
            || !in_array($targetType, $allowed[$sourceType], true)
        ) {
            Response::error('Pre tento typ kontroly nie je dostupný nadväzujúci koncept.', 422);
        }

        // Pull source items and map them to the target type's field shape.
        $itemsStmt = Db::pdo()->prepare(
            'SELECT fields FROM inspection_items
             WHERE  inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $itemsStmt->execute([$sourceId]);
        $sourceItems = array_map(
            static fn (array $r): array => json_decode((string) $r['fields'], true) ?: [],
            $itemsStmt->fetchAll(),
        );

        $mapped = match ($targetType) {
            'oprava_ts_php' => self::mapPhpToOprava($sourceItems),
            'vyradenie'     => self::mapPhpToVyradenie($sourceItems),
            default         => self::mapHydrantyToTsHadic($sourceItems),
        };

        if ($mapped === []) {
            Response::error('Zdrojová kontrola neobsahuje položky pre nadväzujúci koncept.', 422);
        }

        $pdo = Db::pdo();

        // Idempotency: if a draft already grew from THIS source, return it
        // instead of creating a second one.
        $existing = $pdo->prepare(
            'SELECT id FROM inspections
             WHERE  source_inspection_id = ? AND type = ? AND status = "draft"
                AND archived_at IS NULL
             ORDER  BY id DESC LIMIT 1'
        );
        $existing->execute([$sourceId, $targetType]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            Response::json(['inspection_id' => (int) $existingId, 'created' => false], 200);
        }

        $periodicity = self::TYPE_PERIODICITIES[$targetType][0];

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, source_inspection_id, type,
                     periodicity_months, executed_on, inspector_user_id, status, notes,
                     is_preventive_inspection)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "draft", NULL, ?)'
            )->execute([
                $accountId,
                $source['company_id'],
                $source['facility_id'],
                $sourceId,
                $targetType,
                $periodicity,
                $source['inspector_user_id'],
                in_array($targetType, self::NON_CYCLIC_TYPES, true) ? 0 : 1,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
            );
            foreach ($mapped as $pos => $fields) {
                $ins->execute([$newId, $pos + 1, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['inspection_id' => $newId, 'created' => true], 201);
    }

    /**
     * PHP items with status "TS" (na tlakovú skúšku) → Oprava/plnenie/TS PHP
     * draft items. Carries identification only; the standard scope of work is
     * fixed on that protocol and the note starts blank.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function mapPhpToOprava(array $items): array
    {
        $out = [];
        foreach ($items as $f) {
            if (($f['status'] ?? null) !== 'TS') {
                continue;
            }
            $out[] = [
                'manufacturer' => (string) ($f['manufacturer'] ?? ''),
                'type'         => (string) ($f['type'] ?? ''),
                'serial'       => (string) ($f['serial'] ?? ''),
                'year'         => (int) ($f['year'] ?? 0),
                'location'     => (string) ($f['location'] ?? ''),
                'notes'        => null,
            ];
        }
        return $out;
    }

    /**
     * PHP items with status "V" (vyradený) → vyraďovací protokol draft items
     * (change request 2.1). The technician's note from the inspection becomes
     * the starting reason for disposal — it is usually already the reason
     * ("neúspešná tlaková skúška", "korózia") — and stays editable.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function mapPhpToVyradenie(array $items): array
    {
        $out = [];
        foreach ($items as $f) {
            if (($f['status'] ?? null) !== 'V') {
                continue;
            }
            $note = trim((string) ($f['notes'] ?? ''));
            $out[] = [
                'manufacturer' => (string) ($f['manufacturer'] ?? ''),
                'type'         => (string) ($f['type'] ?? ''),
                'serial'       => (string) ($f['serial'] ?? ''),
                'year'         => (int) ($f['year'] ?? 0),
                'location'     => (string) ($f['location'] ?? ''),
                'reason'       => $note !== '' ? $note : 'Neopraviteľná porucha',
            ];
        }
        return $out;
    }

    /**
     * Hydrant items → Tlaková skúška hadíc draft items. One hose row per hose
     * on the hydrant (hose_count), carrying the hydrant's type/DN as the hose
     * type and the hydrant location. Measured pressures/length are left blank
     * for the technician to fill when the test is actually performed.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function mapHydrantyToTsHadic(array $items): array
    {
        $out = [];
        foreach ($items as $f) {
            $type = (string) ($f['type'] ?? '');
            if ($type === 'other') {
                $type = (string) ($f['type_other'] ?? '');
            }
            $location = (string) ($f['location'] ?? '');
            $count = (int) ($f['hose_count'] ?? 0);
            // A hydrant with no hoses still yields one row so the technician can
            // record the test; otherwise one row per hose.
            $rows = max(1, $count);
            for ($n = 0; $n < $rows; $n++) {
                $out[] = [
                    'hose_type'           => $type,
                    'location'            => $location,
                    'manufacturer'        => '',
                    'working_pressure'    => null,
                    'test_pressure'       => null,
                    'length'              => null,
                    'year_of_manufacture' => null,
                    'result'              => 'vyhovuje',
                    'notes'               => null,
                ];
            }
        }
        return $out;
    }

    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $isAdmin = Admin::isAdmin($userId);

        $type = $req->jsonString('type');
        $periodicityMonths = $req->jsonInt('periodicity_months');
        $executedOn = $req->jsonString('executed_on');
        $companyId = $req->jsonInt('company_id');
        $facilityId = $req->jsonInt('facility_id');
        $inspectorUserId = $req->jsonInt('inspector_user_id') ?? $userId;
        $notes = $req->jsonString('notes');

        if ($type === null || !isset(self::TYPE_PERIODICITIES[$type])) {
            Response::error('Invalid inspection type', 422);
        }
        if (
            $periodicityMonths === null
            || !in_array($periodicityMonths, self::TYPE_PERIODICITIES[$type], true)
        ) {
            Response::error('Invalid periodicity for this type', 422);
        }
        if ($companyId === null || $facilityId === null) {
            Response::error('Field required: company_id, facility_id', 422);
        }
        if ($executedOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
            Response::error('Invalid executed_on (expected YYYY-MM-DD)', 422);
        }

        // Verify the facility exists, belongs to the company, and (for
        // non-admins) sits inside the active account. Admins may create
        // inspections under any account — the inspection inherits the
        // facility's account_id.
        if ($isAdmin) {
            $check = Db::pdo()->prepare(
                'SELECT f.account_id
                 FROM   facilities f
                 JOIN   companies  c ON c.id = f.company_id
                 WHERE  f.id = ? AND f.company_id = ?
                    AND f.archived_at IS NULL AND c.archived_at IS NULL'
            );
            $check->execute([$facilityId, $companyId]);
            $facAccountId = $check->fetchColumn();
            if ($facAccountId === false) {
                Response::error('Company or facility not found', 404);
            }
            $accountId = (int) $facAccountId;
        } else {
            $check = Db::pdo()->prepare(
                'SELECT 1
                 FROM   facilities f
                 JOIN   companies  c ON c.id = f.company_id
                 WHERE  f.id = ? AND f.company_id = ? AND f.account_id = ?
                    AND f.archived_at IS NULL AND c.archived_at IS NULL'
            );
            $check->execute([$facilityId, $companyId, $accountId]);
            if ($check->fetchColumn() === false) {
                Response::error('Company or facility not found', 404);
            }
        }

        // Inspector must be a user attached to the inspection's account.
        // Sys-admins are exempt — they act across accounts and may assign
        // themselves as inspector even without an account_users row.
        if (!$isAdmin) {
            $auCheck = Db::pdo()->prepare(
                'SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?'
            );
            $auCheck->execute([$accountId, $inspectorUserId]);
            if ($auCheck->fetchColumn() === false) {
                Response::error('Inspector is not a member of this account', 422);
            }
        }

        // Block creation when the executing technician has no own cert AND
        // no usable fallback. Admins are exempt (they manage other accounts'
        // data without their own profile).
        if (!$isAdmin) {
            self::assertCanInspect($accountId, $inspectorUserId, $type);
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, type, periodicity_months,
                 executed_on, inspector_user_id, status, notes,
                 is_preventive_inspection)
             VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $companyId,
            $facilityId,
            $type,
            $periodicityMonths,
            $executedOn,
            $inspectorUserId,
            $notes,
            in_array($type, self::NON_CYCLIC_TYPES, true) ? 0 : 1,
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json([
            'inspection' => self::shapeRow(self::loadOrFail($accountId, $id)),
            'items' => [],
        ], 201);
    }

    public static function updateBasic(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $row['account_id'] : $accountId;

        // A finalized inspection is frozen: its date, periodicity and notes
        // are printed on an issued protocol, so changing them here would make
        // the record contradict the PDF. Unlock (which discards the protocol)
        // is the only way back in.
        self::assertUnlocked($row);

        $executedOn = $req->jsonString('executed_on');
        $notes = $req->jsonString('notes');
        $periodicityMonths = $req->jsonInt('periodicity_months');

        if ($executedOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
            Response::error('Invalid executed_on (expected YYYY-MM-DD)', 422);
        }
        if ($periodicityMonths !== null) {
            $allowed = self::TYPE_PERIODICITIES[$row['type']] ?? [];
            if (!in_array($periodicityMonths, $allowed, true)) {
                Response::error('Invalid periodicity for this type', 422);
            }
        }

        // COALESCE keeps existing values when the field is not in the body.
        Db::pdo()->prepare(
            'UPDATE inspections
             SET    executed_on        = COALESCE(?, executed_on),
                    notes              = COALESCE(?, notes),
                    periodicity_months = COALESCE(?, periodicity_months)
             WHERE  id = ? AND account_id = ?'
        )->execute([$executedOn, $notes, $periodicityMonths, $id, $scopeAccountId]);

        $fresh = self::loadOrFail($scopeAccountId, $id);
        Response::json(['inspection' => self::shapeRow($fresh)]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $existing = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;

        Db::pdo()->prepare(
            'UPDATE inspections SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $scopeAccountId]);

        Response::noContent();
    }

    /**
     * "Upraviť" — reopen a finalized inspection for editing.
     *
     * An inspection locks the moment its PDF is issued, because the protocol
     * and the record it was rendered from must keep saying the same thing.
     * Unlocking therefore *discards* the protocol: its documents rows and the
     * PDF files are deleted, the frozen inspector/cert snapshot is cleared and
     * the inspection drops back to `draft`.
     *
     * The discarded number is never handed out again — generating afresh takes
     * the next one from the sequence and leaves a gap. That is deliberate: a
     * copy of the old protocol may already sit in the customer's inbox, and two
     * different documents sharing one number is far worse than a gap.
     *
     * Opakovať stays the right tool for re-issuing the same control on a new
     * date; unlock is for fixing a protocol that was issued wrong.
     */
    public static function unlock(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        // The inspection's own account, so an admin acting on a foreign-account
        // record deletes that account's documents, not their session account's.
        $accountId = (int) $row['account_id'];

        if ($row['status'] !== 'finalized') {
            Response::error('Kontrola nie je uzamknutá.', 422);
        }

        $pdo = Db::pdo();
        $docsStmt = $pdo->prepare(
            'SELECT number, file_path FROM documents
             WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?'
        );
        $docsStmt->execute([$accountId, $id]);
        $docs = $docsStmt->fetchAll();

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM documents
                 WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?'
            )->execute([$accountId, $id]);

            $pdo->prepare(
                'UPDATE inspections
                 SET    status = "draft",
                        effective_inspector_user_id = NULL,
                        effective_cert_number       = NULL
                 WHERE  id = ? AND account_id = ?'
            )->execute([$id, $accountId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Files are unlinked only after the commit: an orphaned PDF nobody
        // links to is harmless, a missing file behind a live row is not.
        foreach ($docs as $doc) {
            $abs = Storage::documentAbsolute((string) $doc['file_path']);
            if (is_file($abs) && !@unlink($abs)) {
                error_log('[unlock-inspection] failed to delete PDF: ' . $abs);
            }
        }

        // Destroying an issued, signed protocol is worth a trail.
        AuditLog::record(
            'inspection.unlock',
            'inspections',
            $id,
            ['status' => 'finalized', 'documents' => array_column($docs, 'number')],
            ['status' => 'draft'],
        );

        Response::json(['inspection' => self::shapeRow(self::loadOrFail($accountId, $id))]);
    }

    /**
     * Guard for writes that must not touch an issued protocol. Mirrors the
     * same check on items (InspectionItemController) and photos
     * (InspectionPhotoController) — the UI hides these affordances once the
     * inspection is locked, this is the server-side half of it.
     *
     * @param array<string, mixed> $row inspection row with `status`
     */
    private static function assertUnlocked(array $row): void
    {
        if (($row['status'] ?? '') === 'finalized') {
            Response::error(
                'Kontrola je uzamknutá — najprv ju odomkni tlačidlom „Upraviť".',
                409,
            );
        }
    }

    /**
     * "Opakovať" — clone a finalized inspection into a fresh draft so the
     * technician can re-issue the protocol with a new date and minor edits
     * (items copied verbatim). The source inspection and its document
     * remain untouched.
     */
    public static function repeat(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $sourceId = (int) $params['id'];

        $source = self::loadOrFail($isAdmin ? null : $accountId, $sourceId);
        // Repeat lives in the same account as the source — admins repeating
        // a foreign-account inspection keep that account context.
        $accountId = (int) $source['account_id'];

        if ($source['status'] !== 'finalized') {
            Response::error('Opakovať možno len ukončenú kontrolu (s vystaveným PDF).', 422);
        }

        // Same cert guard as fresh creation — if the original inspector
        // lost their cert and no fallback is set, refuse to clone now
        // rather than at PDF time.
        if (!$isAdmin) {
            self::assertCanInspect(
                $accountId,
                (int) $source['inspector_user_id'],
                (string) $source['type'],
            );
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        /** @var list<string> $copiedFiles absolute paths written by clonePhotos() */
        $copiedFiles = [];

        try {
            // executed_on is intentionally NULL — Step 3 forces the
            // technician to enter a fresh date before generating PDF.
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type, periodicity_months,
                     is_preventive_inspection, executed_on, inspector_user_id, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "draft", ?)'
            )->execute([
                        $accountId,
                        $source['company_id'],
                        $source['facility_id'],
                        $source['type'],
                        $source['periodicity_months'],
                        !empty($source['is_preventive_inspection']) ? 1 : 0,
                        $source['inspector_user_id'],
                        $source['notes'],
                    ]);
            $newId = (int) $pdo->lastInsertId();

            // Row by row rather than INSERT … SELECT: cloning the photo
            // documentation below needs to know which new item each source
            // item became.
            $srcItems = $pdo->prepare(
                'SELECT id, position, fields
                 FROM   inspection_items
                 WHERE  inspection_id = ?
                 ORDER  BY position ASC, id ASC'
            );
            $srcItems->execute([$sourceId]);
            $insertItem = $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
            );
            /** @var array<int, int> $itemIdMap source item id => cloned item id */
            $itemIdMap = [];
            foreach ($srcItems->fetchAll() as $srcItem) {
                $insertItem->execute([$newId, $srcItem['position'], $srcItem['fields']]);
                $itemIdMap[(int) $srcItem['id']] = (int) $pdo->lastInsertId();
            }

            $copiedFiles = self::clonePhotos($pdo, $sourceId, $newId, $accountId, $itemIdMap);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            // The image files are outside the transaction, so undo them by hand.
            foreach ($copiedFiles as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }

        // Re-use show()'s loader so the response shape matches what the
        // frontend expects when navigating directly to the new inspection.
        $fresh = self::loadOrFail($accountId, $newId);
        $itemsStmt = Db::pdo()->prepare(
            'SELECT id, position, fields, created_at, updated_at
             FROM   inspection_items
             WHERE  inspection_id = ?
             ORDER  BY position ASC, id ASC'
        );
        $itemsStmt->execute([$newId]);
        $items = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'position' => (int) $r['position'],
                'fields' => json_decode((string) $r['fields'], true) ?? [],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $itemsStmt->fetchAll());

        Response::json([
            'inspection' => self::shapeRow($fresh),
            'items' => $items,
            'source_id' => $sourceId,
        ], 201);
    }

    /**
     * Clone the photo documentation of a repeated inspection, keeping each
     * photo on the same item (and, for Požiarna kniha, the same nedostatok —
     * `defect_key` travels with the item's `fields`, so the cloned defects
     * still match their photos).
     *
     * The image files are copied, not shared: each protocol owns its photos,
     * so deleting one in the repeat — or archiving the source — must never
     * take the other protocol's image with it.
     *
     * @param array<int, int> $itemIdMap source item id => cloned item id
     * @return list<string> absolute paths written, so a failed transaction can clean up
     */
    private static function clonePhotos(
        PDO $pdo,
        int $sourceId,
        int $newId,
        int $accountId,
        array $itemIdMap,
    ): array {
        if (!$itemIdMap) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT item_id, defect_key, position, file_path, thumb_path, byte_size, width, height
             FROM   inspection_item_photos
             WHERE  inspection_id = ?
             ORDER  BY item_id ASC, position ASC, id ASC'
        );
        $stmt->execute([$sourceId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return [];
        }

        Storage::ensureDir(Storage::photoDir($accountId, $newId));

        $insert = $pdo->prepare(
            'INSERT INTO inspection_item_photos
                (account_id, inspection_id, item_id, defect_key, position,
                 file_path, thumb_path, byte_size, width, height)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $written = [];
        foreach ($rows as $row) {
            $newItemId = $itemIdMap[(int) $row['item_id']] ?? null;
            if ($newItemId === null) {
                continue;
            }

            $srcFull  = Storage::absolute((string) $row['file_path']);
            $srcThumb = Storage::absolute((string) $row['thumb_path']);
            if (!is_file($srcFull)) {
                // A photo whose file went missing must not block the repeat —
                // skip it the same way PDF generation does.
                error_log('[repeat-photos] file missing: ' . $srcFull);
                continue;
            }

            // Fresh unguessable token per copy, same as a real upload.
            $token    = bin2hex(random_bytes(16));
            $relFull  = Storage::photoRelative($accountId, $newId, $token);
            $relThumb = Storage::photoThumbRelative($accountId, $newId, $token);
            $absFull  = Storage::absolute($relFull);
            $absThumb = Storage::absolute($relThumb);

            if (!copy($srcFull, $absFull)) {
                throw new \RuntimeException('Failed to copy photo: ' . $srcFull);
            }
            $written[] = $absFull;
            // A missing thumbnail falls back to the full size rather than
            // failing — re-encoding needs GD-JPEG, which isn't guaranteed.
            if (!copy(is_file($srcThumb) ? $srcThumb : $srcFull, $absThumb)) {
                throw new \RuntimeException('Failed to copy photo thumbnail: ' . $srcThumb);
            }
            $written[] = $absThumb;

            $insert->execute([
                $accountId,
                $newId,
                $newItemId,
                $row['defect_key'],
                (int) $row['position'],
                $relFull,
                $relThumb,
                (int) $row['byte_size'],
                (int) $row['width'],
                (int) $row['height'],
            ]);
        }

        return $written;
    }

    /**
     * Refuse to create an inspection the executor can't legitimately
     * sign. Technik PO types (everything except php and oprava_ts_php)
     * require the executor's own cert_general — no borrowing. For PHP /
     * Oprava the executor may borrow the account-default technician's
     * cert; only when neither own nor default cert is available do we
     * block. Errors carry a `code` so the UI can show a useful hint.
     */
    private static function assertCanInspect(int $accountId, int $inspectorUserId, string $type): void
    {
        $stmt = Db::pdo()->prepare(
            'SELECT cert_php, cert_oprava, cert_general
             FROM   inspector_profiles
             WHERE  user_id = ? AND account_id = ?'
        );
        $stmt->execute([$inspectorUserId, $accountId]);
        $own = $stmt->fetch() ?: ['cert_php' => null, 'cert_oprava' => null, 'cert_general' => null];

        $has = static fn($v) => is_string($v) && trim($v) !== '';

        if ($type === 'php' || $type === 'oprava_ts_php') {
            $certKey = $type === 'php' ? 'cert_php' : 'cert_oprava';
            $defaultCol = $type === 'php' ? 'default_php_user_id' : 'default_oprava_user_id';
            if ($has($own[$certKey] ?? null)) {
                return;
            }
            $defaultStmt = Db::pdo()->prepare(
                'SELECT ip.' . $certKey . '
                 FROM   accounts a
                 LEFT JOIN inspector_profiles ip
                        ON ip.user_id = a.' . $defaultCol . ' AND ip.account_id = a.id
                 WHERE  a.id = ?'
            );
            $defaultStmt->execute([$accountId]);
            $defaultCert = $defaultStmt->fetchColumn();
            if ($has($defaultCert)) {
                return;
            }
            $label = $type === 'php' ? 'Kontrola PHP' : 'Oprava / plnenie / TS PHP';
            Response::error(
                'Pre ' . $label . ' potrebuješ vlastné číslo oprávnenia, alebo nech správca účtu nastaví predvoleného technika so zadaným číslom.',
                422,
                ['code' => 'cert_missing', 'cert' => $certKey],
            );
        }

        // All other types print cert_general (Technik PO). No fallback.
        if (!$has($own['cert_general'] ?? null)) {
            Response::error(
                'Tento typ kontroly vyžaduje platné číslo oprávnenia Technik PO. Doplň ho v Profile revízneho technika.',
                422,
                ['code' => 'cert_missing', 'cert' => 'cert_general'],
            );
        }
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT i.id, i.account_id, i.type, i.periodicity_months,
                       i.is_preventive_inspection, i.source_inspection_id,
                       i.executed_on, i.status, i.notes,
                       i.created_at, i.updated_at,
                       i.company_id, c.name AS company_name, c.ico AS company_ico,
                       i.facility_id, f.name AS facility_name,
                       i.inspector_user_id, u.fullname AS inspector_name,
                       i.effective_inspector_user_id,
                       eu.fullname AS effective_inspector_name,
                       i.effective_cert_number,
                       ' . self::SUPERSEDED_EXPR . '
                FROM   inspections i
                JOIN   companies   c ON c.id = i.company_id
                JOIN   facilities  f ON f.id = i.facility_id
                JOIN   users       u ON u.id = i.inspector_user_id
                LEFT JOIN users    eu ON eu.id = i.effective_inspector_user_id
                WHERE  i.id = ? AND i.archived_at IS NULL';
        $params = [$id];
        if ($accountId !== null) {
            $sql .= ' AND i.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Inspection not found', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        $row['facility_id'] = (int) $row['facility_id'];
        $row['inspector_user_id'] = (int) $row['inspector_user_id'];
        $row['periodicity_months'] = (int) $row['periodicity_months'];
        $row['effective_inspector_user_id'] = isset($row['effective_inspector_user_id'])
            ? (int) $row['effective_inspector_user_id']
            : null;
        $row['effective_inspector_name'] = $row['effective_inspector_name'] ?? null;
        $row['effective_cert_number'] = $row['effective_cert_number'] ?? null;
        $row['is_superseded'] = (bool) ($row['is_superseded'] ?? false);
        $row['is_preventive_inspection'] = (bool) ($row['is_preventive_inspection'] ?? true);
        $row['source_inspection_id'] = isset($row['source_inspection_id'])
            ? (int) $row['source_inspection_id']
            : null;
        unset($row['account_id']);
        return $row;
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
