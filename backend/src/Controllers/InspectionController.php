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
use Firol\Support\CarryOver;
use Firol\Support\Periodicity;

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
     * Every inspection type the app knows. Which periodicity each one
     * SUGGESTS lives in {@see Periodicity::RECOMMENDED_MONTHS} — the suggestion
     * is the only thing fixed per type. Since block 1 (chapter 5) the value
     * itself is the technician's to set, in days, weeks or months, or to drop
     * entirely ("bez opakovania"); nothing here constrains it.
     *
     * @var list<string>
     */
    private const TYPES = [
        'php', 'hydranty', 'oprava_ts_php', 'poziarna_kniha',
        'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie', 'ts_hadic',
        // Pokyn — žatevné práce is not here: it is a document issued to the
        // client's employees, so it lives in the training tree (see
        // TrainingController), not among the inspection types.
        // One-off document — a disposal has no recurrence (change request 2.1).
        'vyradenie',
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

        $sql = 'SELECT i.id, i.type, i.executed_on,
                       i.periodicity_value, i.periodicity_unit, i.periodicity_is_custom,
                       i.is_preventive_inspection, i.source_inspection_id,
                       i.carried_over_from_id, i.visit_id,
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

        // Chapter 12 — a draft with nothing in it yet can still be filled
        // from last time's devices, so the offer travels with the detail as
        // well as with the freshly created draft.
        $carryOver = ($row['status'] === 'draft' && $items === [])
            ? self::carryOverOffer(
                (int) $row['account_id'],
                (int) $row['facility_id'],
                (string) $row['type'],
                $id,
            )
            : null;

        Response::json([
            'inspection' => self::shapeRow($row),
            'items' => $items,
            'follow_ups' => $followUps,
            'carry_over' => $carryOver,
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

        // Follow-up drafts start from the target type's recommended period;
        // the technician revisits it in Step 1 like any other úkon.
        $recommended = Periodicity::RECOMMENDED_MONTHS[$targetType] ?? [];
        $fuValue = $recommended[0] ?? null;
        $fuUnit  = $fuValue === null ? null : 'mesiac';

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, source_inspection_id, type,
                     periodicity_value, periodicity_unit, executed_on, inspector_user_id,
                     status, notes, is_preventive_inspection)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, "draft", NULL, ?)'
            )->execute([
                $accountId,
                $source['company_id'],
                $source['facility_id'],
                $sourceId,
                $targetType,
                $fuValue,
                $fuUnit,
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
        $executedOn = $req->jsonString('executed_on');
        $companyId = $req->jsonInt('company_id');
        $facilityId = $req->jsonInt('facility_id');
        $inspectorUserId = $req->jsonInt('inspector_user_id') ?? $userId;
        $notes = $req->jsonString('notes');
        $visitId = $req->jsonInt('visit_id');

        if ($type === null || !in_array($type, self::TYPES, true)) {
            Response::error('Invalid inspection type', 422);
        }
        // Any value in days/weeks/months, or none at all. The type only
        // decides what the app SUGGESTS (chapter 5) — it never limits what the
        // technician may choose.
        [$periodicityValue, $periodicityUnit] = self::readPeriodicity($req);
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

        // A visit (chapter 9) collects several úkony under one trip; the
        // inspection must sit in the same account and on the same prevádzka,
        // or the visit summary would list a protocol from somewhere else.
        if ($visitId !== null) {
            $visitCheck = Db::pdo()->prepare(
                'SELECT 1 FROM visits
                 WHERE  id = ? AND account_id = ? AND facility_id = ? AND archived_at IS NULL'
            );
            $visitCheck->execute([$visitId, $accountId, $facilityId]);
            if ($visitCheck->fetchColumn() === false) {
                Response::error('Návšteva sa nenašla.', 404);
            }
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO inspections
                (account_id, company_id, facility_id, visit_id, type,
                 periodicity_value, periodicity_unit, periodicity_is_custom,
                 executed_on, inspector_user_id, status, notes,
                 is_preventive_inspection)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $companyId,
            $facilityId,
            $visitId,
            $type,
            $periodicityValue,
            $periodicityUnit,
            Periodicity::isCustom($type, $periodicityValue, $periodicityUnit) ? 1 : 0,
            $executedOn,
            $inspectorUserId,
            $notes,
            in_array($type, self::NON_CYCLIC_TYPES, true) ? 0 : 1,
        ]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json([
            'inspection' => self::shapeRow(self::loadOrFail($accountId, $id)),
            'items' => [],
            // Chapter 12 — offer to carry the devices over from last time
            // instead of typing them again.
            'carry_over' => self::carryOverOffer($accountId, $facilityId, $type, $id),
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

        if ($executedOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
            Response::error('Invalid executed_on (expected YYYY-MM-DD)', 422);
        }

        // Periodicity is edited as a whole: "bez opakovania" has to be
        // expressible, and a COALESCE over two columns cannot tell "leave it
        // alone" from "clear it". Sending either key means the technician
        // touched the field.
        $body = $req->json();
        $touchesPeriodicity = array_key_exists('periodicity_value', $body)
            || array_key_exists('periodicity_unit', $body);
        if ($touchesPeriodicity) {
            [$periodicityValue, $periodicityUnit] = self::readPeriodicity($req);
            Db::pdo()->prepare(
                'UPDATE inspections
                 SET    periodicity_value     = ?,
                        periodicity_unit      = ?,
                        periodicity_is_custom = ?
                 WHERE  id = ? AND account_id = ?'
            )->execute([
                $periodicityValue,
                $periodicityUnit,
                Periodicity::isCustom((string) $row['type'], $periodicityValue, $periodicityUnit) ? 1 : 0,
                $id,
                $scopeAccountId,
            ]);
        }

        // COALESCE keeps existing values when the field is not in the body.
        Db::pdo()->prepare(
            'UPDATE inspections
             SET    executed_on = COALESCE(?, executed_on),
                    notes       = COALESCE(?, notes)
             WHERE  id = ? AND account_id = ?'
        )->execute([$executedOn, $notes, $id, $scopeAccountId]);

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

        // A protocol signed on the screen was re-rendered into further
        // versions (chapter 13); every one of those files has to go too, or
        // the discarded protocol stays readable under its old version path.
        $versionsStmt = $pdo->prepare(
            'SELECT v.file_path FROM document_versions v
             JOIN   documents d ON d.id = v.document_id
             WHERE  d.account_id = ? AND d.parent_type = "inspection" AND d.parent_id = ?'
        );
        $versionsStmt->execute([$accountId, $id]);
        $versionFiles = $versionsStmt->fetchAll(\PDO::FETCH_COLUMN);

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
        $paths = array_unique(array_merge(
            array_map(static fn (array $d): string => (string) $d['file_path'], $docs),
            array_map(static fn ($p): string => (string) $p, $versionFiles),
        ));
        foreach ($paths as $rel) {
            $abs = Storage::documentAbsolute($rel);
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
     * "Opakovať" — start this year's inspection from last year's, block 1 /
     * chapter 12.
     *
     * The devices come across; the verdict on them does not. Stav, výsledok,
     * poznámka, photos and nedostatky all start empty, and a device disposed
     * of last time is left behind entirely (the response says how many, so the
     * technician can see it was a decision). Each carried item remembers what
     * it scored last time in `previous_status`, shown beside it while entering
     * results but printed on nothing.
     *
     * The source inspection and its protocol are untouched.
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

        $type = (string) $source['type'];
        $sourceItems = self::loadItemFields($sourceId);
        $carried = self::carryItems($type, $sourceItems);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // executed_on is intentionally NULL — Step 3 forces the
            // technician to enter a fresh date before generating PDF.
            // The periodicity travels: it was the technician's decision for
            // this prevádzka and there is no reason to make them repeat it.
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     is_preventive_inspection, executed_on, inspector_user_id,
                     status, notes, carried_over_from_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, "draft", ?, ?)'
            )->execute([
                $accountId,
                $source['company_id'],
                $source['facility_id'],
                $type,
                $source['periodicity_value'],
                $source['periodicity_unit'],
                !empty($source['periodicity_is_custom']) ? 1 : 0,
                !empty($source['is_preventive_inspection']) ? 1 : 0,
                $source['inspector_user_id'],
                $source['notes'],
                $sourceId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
            );
            foreach ($carried['items'] as $pos => $fields) {
                $insertItem->execute([
                    $newId,
                    $pos + 1,
                    json_encode($fields, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json([
            'inspection' => self::shapeRow(self::loadOrFail($accountId, $newId)),
            'items' => self::loadItemsForResponse($newId),
            'source_id' => $sourceId,
            'disposed_skipped' => $carried['disposed'],
        ], 201);
    }

    /**
     * Pull the previous inspection's devices into a draft that was just
     * created in Step 1 — the same carry-over as "Opakovať", offered at the
     * other end of the flow (chapter 12).
     *
     * Only ever fills an EMPTY draft: a technician who already typed three
     * devices must not have them silently joined by forty from last year.
     */
    public static function carryOver(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $row = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $accountId = (int) $row['account_id'];
        self::assertUnlocked($row);

        $type = (string) $row['type'];
        $existing = Db::pdo()->prepare('SELECT COUNT(*) FROM inspection_items WHERE inspection_id = ?');
        $existing->execute([$id]);
        if ((int) $existing->fetchColumn() > 0) {
            Response::error(
                'Kontrola už obsahuje položky — prevzatie z minulej kontroly sa dá spustiť len na prázdnej kontrole.',
                409,
            );
        }

        $sourceId = $req->jsonInt('source_id')
            ?? self::lastFinalizedId($accountId, (int) $row['facility_id'], $type, $id);
        if ($sourceId === null) {
            Response::error('Pre túto prevádzku a typ kontroly zatiaľ nie je z čoho prevziať položky.', 404);
        }
        $source = self::loadOrFail($accountId, $sourceId);
        if ((string) $source['type'] !== $type || (int) $source['facility_id'] !== (int) $row['facility_id']) {
            Response::error('Prevziať položky možno len z kontroly rovnakého typu na tej istej prevádzke.', 422);
        }

        $carried = self::carryItems($type, self::loadItemFields($sourceId));
        if ($carried['items'] === []) {
            Response::error('Minulá kontrola neobsahuje položky, ktoré by sa dali prevziať.', 422);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $insertItem = $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
            );
            foreach ($carried['items'] as $pos => $fields) {
                $insertItem->execute([$id, $pos + 1, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->prepare(
                'UPDATE inspections SET carried_over_from_id = ? WHERE id = ? AND account_id = ?'
            )->execute([$sourceId, $id, $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json([
            'inspection' => self::shapeRow(self::loadOrFail($accountId, $id)),
            'items' => self::loadItemsForResponse($id),
            'source_id' => $sourceId,
            'disposed_skipped' => $carried['disposed'],
        ]);
    }

    /**
     * Map a source inspection's items onto carried-over ones.
     *
     * @param list<array<string, mixed>> $sourceFields
     * @return array{items: list<array<string, mixed>>, disposed: int}
     */
    private static function carryItems(string $type, array $sourceFields): array
    {
        if (!CarryOver::supports($type)) {
            // An unknown type carries nothing rather than guessing which of
            // its fields are identification and which are a verdict.
            return ['items' => [], 'disposed' => 0];
        }
        $items = [];
        $disposed = 0;
        foreach ($sourceFields as $fields) {
            $mapped = CarryOver::mapItem($type, $fields);
            if ($mapped === null) {
                $disposed++;
                continue;
            }
            $items[] = $mapped;
        }
        return ['items' => $items, 'disposed' => $disposed];
    }

    /**
     * The offer shown right after Step 1: "Prevziať položky z poslednej
     * kontroly (12. 8. 2026)". Null when there is nothing to carry over.
     *
     * @return array{source_id: int, executed_on: string|null, item_count: int, disposed: int}|null
     */
    private static function carryOverOffer(
        int $accountId,
        int $facilityId,
        string $type,
        int $exceptId,
    ): ?array {
        if (!CarryOver::supports($type)) {
            return null;
        }
        $sourceId = self::lastFinalizedId($accountId, $facilityId, $type, $exceptId);
        if ($sourceId === null) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT executed_on FROM inspections WHERE id = ?');
        $stmt->execute([$sourceId]);
        $executedOn = $stmt->fetchColumn();

        $carried = self::carryItems($type, self::loadItemFields($sourceId));
        if ($carried['items'] === []) {
            return null;
        }
        return [
            'source_id'   => $sourceId,
            'executed_on' => $executedOn !== false ? (string) $executedOn : null,
            'item_count'  => count($carried['items']),
            'disposed'    => $carried['disposed'],
        ];
    }

    /** Most recent finalized inspection of this type on this prevádzka. */
    private static function lastFinalizedId(
        int $accountId,
        int $facilityId,
        string $type,
        int $exceptId,
    ): ?int {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
                AND status = "finalized" AND archived_at IS NULL AND id <> ?
             ORDER  BY COALESCE(executed_on, "1000-01-01") DESC, id DESC
             LIMIT  1'
        );
        $stmt->execute([$accountId, $facilityId, $type, $exceptId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Just the decoded `fields` of an inspection's items, in order.
     *
     * @return list<array<string, mixed>>
     */
    private static function loadItemFields(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT fields FROM inspection_items
             WHERE  inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);
        return array_map(
            static fn (array $r): array => json_decode((string) $r['fields'], true) ?: [],
            $stmt->fetchAll(),
        );
    }

    /**
     * Items shaped the way show() returns them, so a client can swap its state
     * for the response of repeat()/carryOver() without a second round trip.
     *
     * @return list<array<string, mixed>>
     */
    private static function loadItemsForResponse(int $inspectionId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, position, fields, created_at, updated_at
             FROM   inspection_items
             WHERE  inspection_id = ?
             ORDER  BY position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);
        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'position' => (int) $r['position'],
                'fields' => json_decode((string) $r['fields'], true) ?? [],
                'photos' => [],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Read and validate the periodicity pair off the request body, turning a
     * bad pair into a 422 with a Slovak message.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function readPeriodicity(Request $req): array
    {
        $body = $req->json();
        try {
            return Periodicity::normalize(
                $body['periodicity_value'] ?? null,
                $body['periodicity_unit'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
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
        $sql = 'SELECT i.id, i.account_id, i.type,
                       i.periodicity_value, i.periodicity_unit, i.periodicity_is_custom,
                       i.is_preventive_inspection, i.source_inspection_id,
                       i.carried_over_from_id, i.visit_id,
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
        $row['periodicity_value'] = isset($row['periodicity_value'])
            ? (int) $row['periodicity_value']
            : null;
        $row['periodicity_unit'] = $row['periodicity_unit'] ?? null;
        $row['periodicity_is_custom'] = (bool) ($row['periodicity_is_custom'] ?? false);
        // Derived, never stored: the same arithmetic would otherwise be
        // repeated in the list view, the calendar and the detail badge.
        $row['valid_until'] = Periodicity::validUntil(
            $row['executed_on'] ?? null,
            $row['periodicity_value'],
            $row['periodicity_unit'],
        );
        $row['visit_id'] = isset($row['visit_id']) ? (int) $row['visit_id'] : null;
        $row['carried_over_from_id'] = isset($row['carried_over_from_id'])
            ? (int) $row['carried_over_from_id']
            : null;
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
