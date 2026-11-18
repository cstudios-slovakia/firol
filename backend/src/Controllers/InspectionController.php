<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\MemberRights;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Storage\Storage;
use Firol\Support\BookEntry;
use Firol\Support\CarryOver;
use Firol\Support\CertGuard;
use Firol\Support\InspectionCoverage;
use Firol\Support\InspectionDetails;
use Firol\Support\Periodicity;
use Firol\Support\PrehliadkaChecklist;
use Firol\Support\PrehliadkaConcepts;
use Firol\Support\PrehliadkaDefects;
use Firol\Support\PrehliadkaDetails;
use Firol\Support\UkonDiscard;
use Firol\Support\UkonDiscardRefused;

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
     *
     * `$facilityExpr` is the prevádzka the row is seen from: a newer record
     * covering it supersedes ({@see InspectionCoverage::covers()} — a požiarna
     * kniha entry covers every prevádzka it was written for). The facility view
     * of the list passes the covered prevádzka; everything else the úkon's own.
     */
    private static function supersededExpr(string $facilityExpr = 'i.facility_id'): string
    {
        return "EXISTS(
                           SELECT 1 FROM inspections s
                           WHERE  i.status      = 'finalized'
                             AND  s.account_id  = i.account_id
                             AND  " . InspectionCoverage::covers('s', $facilityExpr) . "
                             AND  (s.type = i.type
                                   OR (s.type IN ('poziarna_kniha', 'preventivna_prehliadka')
                                       AND i.type IN ('poziarna_kniha', 'preventivna_prehliadka')))
                             AND  s.archived_at IS NULL
                             AND  s.status      = 'finalized'
                             AND  s.is_preventive_inspection = 1
                             AND  (COALESCE(s.executed_on, '1000-01-01'), s.id)
                                > (COALESCE(i.executed_on, '1000-01-01'), i.id)
                       ) AS is_superseded";
    }

    /**
     * Every inspection type the app knows. Which periodicity each one
     * SUGGESTS lives in {@see Periodicity::RECOMMENDED_MONTHS} — the suggestion
     * is the only thing fixed per type. Since block 1 (chapter 5) the value
     * itself is the technician's to set, in days, weeks or months, or to drop
     * entirely ("bez opakovania"); nothing here constrains it.
     *
     * @var list<string>
     */
    public const TYPES = [
        'php', 'hydranty', 'oprava_ts_php', 'poziarna_kniha',
        'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie', 'ts_hadic',
        // Pokyn — žatevné práce is not here: it is a document issued to the
        // client's employees, so it lives in the training tree (see
        // TrainingController), not among the inspection types.
        // One-off document — a disposal has no recurrence (change request 2.1).
        'vyradenie',
        // Block 2 — single-record BOZP úkony (Firol\Support\BozpRecords).
        'kniha_bozp', 'pracovisko', 'osamele_pracovisko', 'fajcenie',
        // Block 2 — BOZP úkony whose rows are items (Firol\Support\BozpItems).
        'oopp', 'pracovne_prostriedky', 'rebriky', 'regale', 'oznacenie',
        // Block 2 — the „osoby" úkony: a list of people under a header
        // (Firol\Support\PersonList). Oboznámenie BOZP is one of them rather
        // than a training-tree record: it carries a periodicity, joins a visit,
        // is signed „Za organizáciu" and shares its person list with the tests.
        'dychova_skuska', 'omamne_latky', 'skolenie_bozp',
        // POapp 26. 10. 2026 — the walk-through that yields several records
        // (Firol\Support\PrehliadkaDetails / PrehliadkaDefects).
        'preventivna_prehliadka',
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

    /**
     * PHP statuses that feed an Oprava/plnenie/TS PHP follow-up (spec 7.3, D3).
     * The one place to change if FIROL drops `O` — keep in step with
     * PHP_OPRAVA_FOLLOW_UP_STATUSES in frontend/src/api/inspections.ts.
     *
     * @var list<string>
     */
    private const OPRAVA_FOLLOW_UP_STATUSES = ['TS', 'O'];

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        $companyId = self::queryInt($req, 'company_id');
        $facilityId = self::queryInt($req, 'facility_id');
        $type = $req->query('type');

        // The facility view lists what covers that prevádzka — a požiarna kniha
        // entry written for several prevádzky appears under each of them, with
        // that prevádzka's periodicity (InspectionCoverage). At most one row of
        // inspection_facilities joins per entry, so no row is duplicated.
        $fac = $facilityId !== null;
        $sql = 'SELECT i.id, i.type, i.executed_on,
                       ' . ($fac ? InspectionCoverage::periodicityValue() : 'i.periodicity_value') . ' AS periodicity_value,
                       ' . ($fac ? InspectionCoverage::periodicityUnit() : 'i.periodicity_unit') . ' AS periodicity_unit,
                       i.periodicity_is_custom,
                       i.is_preventive_inspection, i.source_inspection_id,
                       i.carried_over_from_id, i.visit_id,
                       i.status, i.issued_before, i.notes, i.created_at, i.account_id,
                       i.company_id, c.name AS company_name, c.client_type AS company_client_type,
                       ' . ($fac ? InspectionCoverage::facility() : 'i.facility_id') . ' AS facility_id,
                       f.name AS facility_name,
                       i.inspector_user_id, u.fullname AS inspector_name,
                       i.created_by_user_id,
                       i.effective_inspector_user_id,
                       eu.fullname AS effective_inspector_name,
                       i.effective_cert_number,
                       i.billing_mode, i.invoiced, i.invoiced_at, i.billing_note,
                       CASE WHEN JSON_VALUE(i.details, "$.concept_from_prehliadka") IS NULL THEN NULL
                            WHEN JSON_VALUE(i.details, "$.concept_confirmed_at") IS NULL THEN "pending"
                            ELSE "confirmed" END AS concept_state,
                       i.details AS scope_details,
                       ' . self::supersededExpr($fac ? InspectionCoverage::facility() : 'i.facility_id') . '
                FROM   inspections i
                JOIN   companies   c ON c.id = i.company_id
                ' . ($fac ? 'LEFT JOIN inspection_facilities ix ON ix.inspection_id = i.id AND ix.facility_id = :fid_x' : '') . '
                JOIN   facilities  f ON f.id = ' . ($fac ? InspectionCoverage::facility() : 'i.facility_id') . '
                JOIN   users       u ON u.id = i.inspector_user_id
                LEFT JOIN users    eu ON eu.id = i.effective_inspector_user_id
                WHERE  i.archived_at IS NULL';
        $params = $fac ? ['fid_x' => $facilityId] : [];
        // Chapter 22 — „Nevyfakturované": filtered here rather than on the
        // client, so the 200-row cap below never hides an older úkon that is
        // still waiting to be invoiced.
        if ($req->query('uninvoiced') === '1') {
            $sql .= ' AND ' . \Firol\Support\Invoicing::uninvoicedCondition('i');
        }
        if (!$isAdmin) {
            $sql .= ' AND i.account_id = :account_id';
            $params['account_id'] = $accountId;
        }

        if ($companyId !== null) {
            $sql .= ' AND i.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($facilityId !== null) {
            $sql .= ' AND (ix.id IS NOT NULL OR i.facility_id = :facility_id)';
            $params['facility_id'] = $facilityId;
        }
        if ($type !== null) {
            $sql .= ' AND i.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY COALESCE(i.executed_on, i.created_at) DESC LIMIT 200';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // New-format book entries carry their prevádzky. In the facility view the
        // row already speaks for that prevádzka; elsewhere one representative
        // prevádzka sets the periodicity and term of the row.
        $bookIds = [];
        foreach ($rows as $r) {
            if ($r['type'] === BookEntry::TYPE && BookEntry::isNew(InspectionDetails::decode($r['scope_details'] ?? null))) {
                $bookIds[] = (int) $r['id'];
            }
        }
        $coverage = InspectionCoverage::forInspections($isAdmin ? null : $accountId, $bookIds);
        // What the trash icon does to each row (UkonDiscard): delete / archive / none.
        $discard = UkonDiscard::decideMany($isAdmin ? null : $accountId, $rows);
        $items = array_map(
            static fn (array $r): array => self::withFacilities(self::shapeRow($r), $coverage, !$fac)
                + ['discard' => $discard[(int) $r['id']]['mode'] ?? null],
            $rows,
        );

        Response::json(['items' => $items]);
    }

    /**
     * Adds `facilities` to a shaped new-format book entry and, unless the row is
     * already seen from one prevádzka, takes the periodicity, term and
     * superseded state from the representative prevádzka
     * ({@see InspectionCoverage::representative()}).
     *
     * @param array<string, mixed> $row shaped
     * @param array<int, list<array<string, mixed>>> $coverage {@see InspectionCoverage::forInspections()}
     * @return array<string, mixed>
     */
    private static function withFacilities(array $row, array $coverage, bool $derive): array
    {
        $facilities = $coverage[(int) $row['id']] ?? null;
        if ($facilities === null) {
            return $row;
        }
        $row['facilities'] = $facilities;
        if ($derive && count($facilities) > 1) {
            $rep = InspectionCoverage::representative($facilities, (bool) $row['is_preventive_inspection']);
            if ($rep !== null) {
                $row['periodicity_value'] = $rep['periodicity_value'];
                $row['periodicity_unit'] = $rep['periodicity_unit'];
                $row['valid_until'] = $rep['valid_until'];
                $row['is_superseded'] = $rep['is_superseded'];
            }
        }
        return $row;
    }

    /**
     * One inspection as the detail endpoints return it, with the prevádzky of a
     * new-format book entry.
     *
     * @param array<string, mixed> $row loadOrFail() row
     * @return array<string, mixed>
     */
    private static function shapeDetail(array $row): array
    {
        $isBook = $row['type'] === BookEntry::TYPE && BookEntry::isNew(InspectionDetails::decode($row['details'] ?? null));
        $shaped = self::shapeRow($row);
        if (!$isBook) {
            return $shaped;
        }
        $coverage = InspectionCoverage::forInspections((int) $row['account_id'], [(int) $row['id']]);
        $shaped = self::withFacilities($shaped, $coverage, true);
        $shaped['facilities'] ??= [];
        return $shaped;
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
            Response::error('Neplatné pole.', 422);
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
        // Block 2 — „výber z prevádzky" for the workplaces of a BOZP record:
        // only places already recorded on THIS prevádzka are offered.
        $facilityOnly = $preferFacility && $req->query('facility_only') === '1';

        $sql = "SELECT $val AS val"
             . ($preferFacility ? ', MAX(i.facility_id = :fid) AS same_fac' : '')
             . ", COUNT(*) AS uses
                FROM   inspection_items ji
                JOIN   inspections i ON i.id = ji.inspection_id
                WHERE  i.account_id = :acct
                  AND  i.archived_at IS NULL
                  AND  $val IS NOT NULL
                  AND  $val <> ''
                  AND  $valCi LIKE :like"
             . ($facilityOnly ? ' AND i.facility_id = :fid_only' : '') . "
                GROUP  BY val
                ORDER  BY " . ($preferFacility ? 'same_fac DESC, ' : '') . "uses DESC, val ASC
                LIMIT  20";

        $params = ['acct' => $accountId, 'like' => $like];
        if ($preferFacility) {
            $params['fid'] = $facilityId;
        }
        if ($facilityOnly) {
            $params['fid_only'] = $facilityId;
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

        // A concept from a prehliadka: the items that still lack a required field
        // (what keeps it from being confirmed), so the list can mark them.
        $rowDetails = InspectionDetails::decode($row['details'] ?? null);
        $isConcept = PrehliadkaConcepts::isConcept($rowDetails);
        $conceptIncomplete = null;
        if ($row['status'] === 'draft' && $isConcept) {
            $conceptIncomplete = PrehliadkaConcepts::incompleteItems((string) $row['type'], $items);
        }
        // A draft with carried items: the same check holds its protocol back
        // (CarryOver::checksCompleteness()), so the summary marks the items.
        // Any other draft gets null — nothing is held back.
        $incomplete = $conceptIncomplete;
        if ($row['status'] === 'draft' && !$isConcept && CarryOver::checksCompleteness(['details' => $rowDetails] + $row)) {
            $incomplete = PrehliadkaConcepts::incompleteItems((string) $row['type'], $items);
        }

        $shapedDetail = self::shapeDetail($row);
        // A prehliadka says whether it was ever closed: a re-opened one is not a
        // draft that may be discarded (see archive()).
        if ($row['type'] === PrehliadkaDetails::TYPE) {
            $shapedDetail['closed_before'] = self::prehliadkaClosedBefore($row);
        }
        // What „zahodiť" does to it: 'delete' (a never-issued draft, for good),
        // 'archive' (an issued úkon) or null (refused — the screen offers nothing).
        $shapedDetail['discard'] = UkonDiscard::decide((int) $row['account_id'], $row)['mode'];

        Response::json([
            'inspection' => $shapedDetail,
            'items' => $items,
            'follow_ups' => $followUps,
            'carry_over' => $carryOver,
            'concept_incomplete' => $conceptIncomplete,
            'incomplete_items' => $incomplete,
        ]);
    }

    /**
     * Create a pre-filled DRAFT of a follow-up control off the back of this
     * inspection (change request 2.1):
     *   php      → oprava_ts_php   (prístroje with status "TS" or "O")
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

        // A tlaková skúška hadíc takes each hose's values and result from the last
        // ts_hadic protocol here — the checklist's own matching, with its origin
        // recorded the same way (fix list of 16. 11. 2026, decision 4).
        $carriedDetails = null;
        if ($targetType === 'ts_hadic') {
            $build = PrehliadkaChecklist::tsHadicRows($accountId, (int) $source['facility_id'], $sourceItems);
            $mapped = $build['rows'];
            $carriedDetails = [
                PrehliadkaChecklist::CARRIED_FROM_KEY      => $build['origin'],
                PrehliadkaChecklist::CARRIED_POSITIONS_KEY => array_map(static fn (int $i): int => $i + 1, $build['carried']),
            ];
        } else {
            $mapped = $targetType === 'oprava_ts_php'
                ? self::mapPhpToOprava($sourceItems)
                : self::mapPhpToVyradenie($sourceItems);
            // Oprava and vyradenie take today's devices as they are; the origin is
            // recorded as the checklist after a prehliadka records it, so the
            // completeness check holds the protocol back while a value the source
            // lacked is still empty (round 3 of the fix list of 16. 11. 2026).
            if ($mapped !== []) {
                $carriedDetails = [
                    PrehliadkaChecklist::CARRIED_FROM_KEY      => PrehliadkaChecklist::originOf($accountId, $sourceId),
                    PrehliadkaChecklist::CARRIED_POSITIONS_KEY => range(1, count($mapped)),
                ];
            }
        }

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

        // The follow-up is a new úkon signed by the same technician — refuse
        // it now, as on creation, rather than when its protocol is issued.
        self::assertCanInspect($accountId, (int) $source['inspector_user_id'], $targetType);

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
                     status, notes, is_preventive_inspection, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", NULL, ?, ?)'
            )->execute([
                $accountId,
                $source['company_id'],
                $source['facility_id'],
                $sourceId,
                $targetType,
                $fuValue,
                $fuUnit,
                // Prefilled with today and editable, as for Opakovať (project rule;
                // POapp spec ch. 6) — „Generovať" is not held back by an empty date.
                date('Y-m-d'),
                $source['inspector_user_id'],
                in_array($targetType, self::NON_CYCLIC_TYPES, true) ? 0 : 1,
                Tenant::currentUserId(),
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Chapter 22 — a follow-up is a new úkon; its režim comes from
            // the firm, not from the source úkon.
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([\Firol\Support\Invoicing::companyMode((int) $source['company_id']), $newId]);
            if ($carriedDetails !== null) {
                $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?')
                    ->execute([InspectionDetails::encode($carriedDetails), $newId]);
            }

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
     * PHP items with status "TS" (na tlakovú skúšku) or "O" (vyžaduje opravu,
     * spec 7.3) → Oprava/plnenie/TS PHP draft items. Carries identification only; the standard scope of work is
     * fixed on that protocol and the note starts blank.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function mapPhpToOprava(array $items): array
    {
        $out = [];
        foreach ($items as $f) {
            if (!in_array($f['status'] ?? null, self::OPRAVA_FOLLOW_UP_STATUSES, true)) {
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
    public static function mapPhpToVyradenie(array $items): array
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
     * type and the hydrant location. Everything else — výrobca, pressures,
     * length, year and the result — is blank here; the values of the last
     * ts_hadic protocol are laid over it by
     * {@see PrehliadkaChecklist::tsHadicRows()}, and a hose with no match there
     * stays blank for manual entry, its result not presumed.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function mapHydrantyToTsHadic(array $items): array
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
                    'result'              => '',
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
        // Header data (inspections.details) — only for types that have one.
        $details = self::readDetails($req, (string) $type);

        if ($type === null || !in_array($type, self::TYPES, true)) {
            Response::error('Neplatný typ kontroly.', 422);
        }
        // A prehliadka is nothing without its setup: which records it yields
        // decides whose oprávnenie signs it and which defects are offered.
        if ($type === PrehliadkaDetails::TYPE && $details === null) {
            Response::error('Chýba nastavenie prehliadky.', 422);
        }
        // Inside a visit (chapter 9) the company, prevádzka and date were chosen
        // once, when the visit started. They are the visit's, not the request's:
        // overriding them is friendlier offline than rejecting a draft that
        // was queued with a stale value.
        if ($visitId !== null) {
            $visit = VisitController::findForUkon($visitId, $isAdmin, $accountId);
            if ($visit === null) {
                Response::error('Návšteva sa nenašla.', 404);
            }
            $companyId = $visit['company_id'];
            $facilityId = $visit['facility_id'];
            $executedOn = $visit['visit_date'];
        }
        // A požiarna kniha entry is nothing without its header (spec 3); an
        // old-format entry can no longer be started.
        if ($type === BookEntry::TYPE && $details === null) {
            Response::error('Chýba nastavenie zápisu.', 422);
        }
        // Any value in days/weeks/months, or none at all. The type only
        // decides what the app SUGGESTS (chapter 5) — it never limits what the
        // technician may choose. A book entry keeps its periodicity per
        // prevádzka; the columns are derived from them (BookEntry::syncHeader).
        [$periodicityValue, $periodicityUnit] = $type === BookEntry::TYPE
            ? [null, null]
            : self::readPeriodicity($req);
        if ($companyId === null || $facilityId === null) {
            Response::error('Vyber firmu aj prevádzku.', 422);
        }
        if ($executedOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executedOn)) {
            Response::error('Neplatný dátum vykonania (očakáva sa formát YYYY-MM-DD).', 422);
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
                Response::error('Firma alebo prevádzka sa nenašla.', 404);
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
                Response::error('Firma alebo prevádzka sa nenašla.', 404);
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
                Response::error('Technik nie je členom tohto účtu.', 422);
            }
        }

        // Block creation when the executing technician has no own cert AND
        // no usable fallback. Admins included: the check is about who signs
        // the protocol, not who creates the draft — and the close would refuse
        // it anyway, only after the whole walk-through.
        if ($type === PrehliadkaDetails::TYPE) {
            self::assertCanDoPrehliadka($accountId, $inspectorUserId, (array) $details);
        } else {
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

        $bookRows = null;
        if ($type === BookEntry::TYPE) {
            [$bookRows, $details] = self::bookCreate($req, $accountId, $companyId, $facilityId, $visitId !== null, (array) $details);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, visit_id, type,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     executed_on, inspector_user_id, status, notes,
                     is_preventive_inspection, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?)'
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
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();

            // Chapter 22 — the režim starts from the firm's setting.
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([\Firol\Support\Invoicing::companyMode($companyId), $id]);

            if ($details !== null) {
                $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?')
                    ->execute([InspectionDetails::encode($details), $id]);
            }
            // A book entry: one row per selected prevádzka, then the columns the
            // term chain reads (spôsob, primary periodicity) follow from them.
            if ($bookRows !== null) {
                BookEntry::writeFacilities($pdo, $accountId, $id, $bookRows);
                BookEntry::syncHeader($pdo, $id);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json([
            'inspection' => self::shapeDetail(self::loadOrFail($accountId, $id)),
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
            Response::error('Neplatný dátum vykonania (očakáva sa formát YYYY-MM-DD).', 422);
        }
        // The date of an úkon in a visit is the visit's (chapter 9) — ignored
        // rather than rejected, so an edit queued offline still saves the rest.
        if ($row['visit_id'] !== null) {
            $executedOn = null;
        }

        $body = $req->json();

        // A new-format požiarna kniha entry: its prevádzky, then its header,
        // then (below) the date. An old entry has neither.
        $storedDetails = InspectionDetails::decode($row['details'] ?? null);
        $isNewBook = $row['type'] === BookEntry::TYPE && BookEntry::isNew($storedDetails);
        if (array_key_exists('facilities', $body) && !$isNewBook) {
            Response::error('Tento úkon má jednu prevádzku.', 422);
        }
        if ($row['type'] === BookEntry::TYPE && !$isNewBook && ($body['details'] ?? null) !== null) {
            Response::error('Tento typ úkonu nemá údaje v hlavičke.', 422);
        }
        if ($isNewBook) {
            self::bookUpdate($req, $row, $scopeAccountId, (array) $storedDetails);
        }

        // Periodicity is edited as a whole: "bez opakovania" has to be
        // expressible, and a COALESCE over two columns cannot tell "leave it
        // alone" from "clear it". Sending either key means the technician
        // touched the field. A book entry keeps it per prevádzka — the
        // top-level pair is ignored, so a replayed request does not fail.
        $touchesPeriodicity = !$isNewBook && (array_key_exists('periodicity_value', $body)
            || array_key_exists('periodicity_unit', $body));
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

        // A new prehliadka (or book entry) date moves every computed defect
        // deadline with it.
        if ($executedOn !== null && ($row['type'] === PrehliadkaDetails::TYPE || $isNewBook)) {
            PrehliadkaDefects::recomputeDeadlines($id, $executedOn);
        }

        // Header data replaces the whole object — a cleared field has to be
        // expressible, so there is no field-by-field COALESCE here.
        if (array_key_exists('details', $body) && !$isNewBook) {
            $details = self::readDetails($req, (string) $row['type']);
            // Switching on PO or BOZP scope asks for that oprávnenie now, as
            // on creation — not only when the prehliadka is closed.
            if ($row['type'] === PrehliadkaDetails::TYPE && is_array($details)) {
                self::assertCanDoPrehliadka(
                    $scopeAccountId,
                    (int) $row['inspector_user_id'],
                    $details,
                    InspectionDetails::decode($row['details'] ?? null) ?? [],
                );
            }
            if ($row['type'] === PrehliadkaDetails::TYPE && is_array($details) && !empty($details['previous'])) {
                // A book entry written for several prevádzky is a valid source
                // at each of them.
                $validSourceIds = Db::pdo()->prepare(
                    'SELECT s.id FROM inspections s WHERE s.account_id = ? AND ' . InspectionCoverage::covers('s', '?') . ' AND s.archived_at IS NULL'
                );
                $validSourceIds->execute([$scopeAccountId, (int) $row['facility_id'], (int) $row['facility_id']]);
                $allowed = array_fill_keys($validSourceIds->fetchAll(\PDO::FETCH_COLUMN), true);
                $details['previous'] = array_values(array_filter(
                    $details['previous'],
                    static fn (array $p): bool => isset($allowed[(int) ($p['source_inspection_id'] ?? 0)]),
                ));
            }
            Db::pdo()->prepare(
                'UPDATE inspections SET details = ? WHERE id = ? AND account_id = ?'
            )->execute([InspectionDetails::encode($details), $id, $scopeAccountId]);
        }

        $fresh = self::loadOrFail($scopeAccountId, $id);
        Response::json(['inspection' => self::shapeDetail($fresh)]);
    }

    public static function archive(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) $params['id'];

        $existing = self::loadOrFail($isAdmin ? null : $accountId, $id);
        $scopeAccountId = $isAdmin ? (int) $existing['account_id'] : $accountId;
        MemberRights::requireDeleteUkon($existing, (int) $existing['inspector_user_id']);

        // Owner decision of 17. 11. 2026: an issued úkon is archived (hidden,
        // nothing removed); a draft that was never issued and that nothing was
        // made from is deleted for good; any other draft is refused
        // (UkonDiscard).
        $decision = UkonDiscard::decide($scopeAccountId, $existing);
        if ($decision['mode'] === null) {
            Response::error((string) $decision['reason'], 409);
        }
        if ($decision['mode'] === UkonDiscard::DELETE) {
            try {
                UkonDiscard::delete($scopeAccountId, $existing);
            } catch (UkonDiscardRefused $e) {
                // Issued, reopened, followed up or archived between the check
                // and the lock: the reason decided under the lock. A database
                // error is not caught here and answers 500.
                Response::error($e->reason(), 409);
            }
            // The mode actually applied, so the client's toast never trusts a
            // cached `discard`.
            Response::json(['mode' => UkonDiscard::DELETE]);
        }

        Db::pdo()->prepare(
            'UPDATE inspections SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $scopeAccountId]);

        Response::json(['mode' => UkonDiscard::ARCHIVE]);
    }

    /**
     * Whether a prehliadka was ever closed. „Upraviť" turns it back into a draft
     * and deletes its own documents, so the status alone cannot tell; what stays
     * is the audit trail of its closing and of the unlock, and anything that was
     * issued from it (the kniha BOZP record, concepts — archived ones included).
     *
     * @param array<string, mixed> $row loadOrFail() row of a prehliadka
     */
    private static function prehliadkaClosedBefore(array $row): bool
    {
        return UkonDiscard::prehliadkaClosedBefore($row);
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
        // Unlocking discards the issued protocol, so it is a delete under the
        // account's práva členov (chapter 1.6).
        MemberRights::requireDelete($accountId);

        $pdo = Db::pdo();

        // The kniha BOZP record of a prehliadka (phase 8a) is issued with it and
        // opened with it — on its own it would drift from the walk-through.
        if ($row['type'] === 'kniha_bozp' && $row['source_inspection_id'] !== null) {
            $srcType = $pdo->prepare('SELECT type FROM inspections WHERE id = ? AND account_id = ?');
            $srcType->execute([(int) $row['source_inspection_id'], $accountId]);
            if ($srcType->fetchColumn() === PrehliadkaDetails::TYPE) {
                Response::error(
                    'Tento zápis vznikol z preventívnej prehliadky — otvor prehliadku a použi „Upraviť".',
                    422,
                );
            }
        }

        // Unlocking a prehliadka discards everything it issued: its own PP
        // record and the BOZP record that grew out of it.
        $ids = [$id];
        if ($row['type'] === PrehliadkaDetails::TYPE) {
            $kids = $pdo->prepare(
                'SELECT id FROM inspections
                 WHERE  account_id = ? AND source_inspection_id = ? AND type = "kniha_bozp" AND archived_at IS NULL'
            );
            $kids->execute([$accountId, $id]);
            foreach ($kids->fetchAll(\PDO::FETCH_COLUMN) as $kid) {
                $ids[] = (int) $kid;
            }
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $docsStmt = $pdo->prepare(
            "SELECT number, file_path FROM documents
             WHERE  account_id = ? AND parent_type = 'inspection' AND parent_id IN ($in)"
        );
        $docsStmt->execute([$accountId, ...$ids]);
        $docs = $docsStmt->fetchAll();

        // A protocol signed on the screen was re-rendered into further
        // versions (chapter 13); every one of those files has to go too, or
        // the discarded protocol stays readable under its old version path.
        $versionsStmt = $pdo->prepare(
            "SELECT v.file_path FROM document_versions v
             JOIN   documents d ON d.id = v.document_id
             WHERE  d.account_id = ? AND d.parent_type = 'inspection' AND d.parent_id IN ($in)"
        );
        $versionsStmt->execute([$accountId, ...$ids]);
        $versionFiles = $versionsStmt->fetchAll(\PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "DELETE FROM documents
                 WHERE  account_id = ? AND parent_type = 'inspection' AND parent_id IN ($in)"
            )->execute([$accountId, ...$ids]);

            $pdo->prepare(
                "UPDATE inspections
                 SET    status = 'draft',
                        issued_before               = 1,
                        protocol_deferred_at        = NULL,
                        effective_inspector_user_id = NULL,
                        effective_cert_number       = NULL,
                        effective_cert_valid_from   = NULL,
                        effective_cert_valid_to     = NULL
                 WHERE  id IN ($in) AND account_id = ?"
            )->execute([...$ids, $accountId]);

            // A book entry forgets what it printed; the next issue composes afresh.
            $pdo->prepare(
                "UPDATE inspections SET details = JSON_REMOVE(details, '$.issued')
                 WHERE  id = ? AND account_id = ? AND " . BookEntry::isNewSql('inspections') . "
                   AND  JSON_EXISTS(details, '$.issued')"
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

        Response::json(['inspection' => self::shapeDetail(self::loadOrFail($accountId, $id))]);
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
     * The devices come across as {@see CarryOver} maps them: a fire-protection
     * type with its values and result (the draft records where they came from,
     * `carried_from` / `carried_positions`, shown on its summary), any other
     * type with its identification only and last time's verdict in
     * `previous_status`. Photos never travel, and a device disposed of last
     * time is left behind entirely (the response says how many, so the
     * technician can see it was a decision).
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
        // A prehliadka is started afresh; its setup is offered as „Ako naposledy".
        if ($source['type'] === PrehliadkaDetails::TYPE) {
            Response::error('Preventívnu prehliadku nemožno opakovať — začni novú, nastavenie sa predvyplní.', 422);
        }

        // A požiarna kniha entry — old or new — repeats as a new-format draft:
        // the same prevádzky, spôsob back to its default, periodicities from
        // each prevádzka's last term, nothing ticked (BookEntry::repeat).
        if ($source['type'] === BookEntry::TYPE) {
            self::assertCanInspect($accountId, (int) $source['inspector_user_id'], BookEntry::TYPE);
            try {
                $newId = BookEntry::repeat($accountId, $source, Tenant::currentUserId());
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            Response::json([
                'inspection'       => self::shapeDetail(self::loadOrFail($accountId, $newId)),
                'items'            => [],
                'source_id'        => $sourceId,
                'disposed_skipped' => 0,
            ], 201);
        }

        // Same cert guard as fresh creation — if the original inspector
        // lost their cert and no fallback is set, refuse to clone now
        // rather than at PDF time. Admins included, as on creation.
        self::assertCanInspect(
            $accountId,
            (int) $source['inspector_user_id'],
            (string) $source['type'],
        );

        $type = (string) $source['type'];
        $sourceItems = self::loadItemFields($sourceId);
        $carried = self::carryItems($type, $sourceItems);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // The date is prefilled with today and stays editable on the
            // summary (CLAUDE.md, user decision 23. 9. 2026 — as for a new
            // úkon and a repeated book entry); the protocol carries whatever
            // date is saved there. The periodicity travels: it was the technician's decision for
            // this prevádzka and there is no reason to make them repeat it.
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, type,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     is_preventive_inspection, executed_on, inspector_user_id,
                     status, notes, carried_over_from_id, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?)'
            )->execute([
                $accountId,
                $source['company_id'],
                $source['facility_id'],
                $type,
                $source['periodicity_value'],
                $source['periodicity_unit'],
                !empty($source['periodicity_is_custom']) ? 1 : 0,
                !empty($source['is_preventive_inspection']) ? 1 : 0,
                date('Y-m-d'),
                $source['inspector_user_id'],
                $source['notes'],
                $sourceId,
                Tenant::currentUserId(),
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Chapter 22 — a repeat is a new úkon to invoice: režim from the
            // firm's current setting, check-off and note start empty.
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([\Firol\Support\Invoicing::companyMode((int) $source['company_id']), $newId]);

            // The header travels in the same spirit as the items: what
            // identifies (the device, the kind of oboznámenie) comes along,
            // what was observed last time does not.
            $carriedDetails = InspectionDetails::carry($type, InspectionDetails::decode($source['details'] ?? null))
                ?? self::carriedOrigin($accountId, $type, $sourceId, count($carried['items']));
            if ($carriedDetails !== null) {
                $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ?')
                    ->execute([InspectionDetails::encode($carriedDetails), $newId]);
            }

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
            'inspection' => self::shapeDetail(self::loadOrFail($accountId, $newId)),
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
        // Values and results travel with a fire-protection item: only an issued
        // protocol is a source, never another draft.
        if ((string) $source['status'] !== 'finalized') {
            Response::error('Prevziať položky možno len z ukončenej kontroly (s vystaveným PDF).', 422);
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
            $origin = self::carriedOrigin($accountId, $type, $sourceId, count($carried['items']));
            if ($origin !== null) {
                $details = array_merge(InspectionDetails::decode($row['details'] ?? null) ?? [], $origin);
                $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ? AND account_id = ?')
                    ->execute([InspectionDetails::encode($details), $id, $accountId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // The summary marks the carried items that lack a required value at once,
        // with the same rule as show() — not only after a reload.
        $fresh = self::loadOrFail($accountId, $id);
        $incomplete = CarryOver::checksCompleteness($fresh)
            ? PrehliadkaConcepts::incompleteItems($type, DocumentController::loadItems($id))
            : null;

        Response::json([
            'inspection' => self::shapeRow($fresh),
            'items' => self::loadItemsForResponse($id),
            'source_id' => $sourceId,
            'disposed_skipped' => $carried['disposed'],
            'incomplete_items' => $incomplete,
        ]);
    }

    /**
     * Where the values of a fire-protection draft came from, in the shape the
     * checklist after a prehliadka records it ({@see PrehliadkaChecklist}), so
     * the summary says „prevzaté z protokolu č. … zo dňa …" (fix list of
     * 16. 11. 2026, decision 5). Null for a type that carries identification
     * only, or when nothing was carried.
     *
     * @return array<string, mixed>|null
     */
    private static function carriedOrigin(int $accountId, string $type, int $sourceId, int $count): ?array
    {
        if (!CarryOver::carriesValues($type) || $count === 0) {
            return null;
        }
        return [
            PrehliadkaChecklist::CARRIED_FROM_KEY      => PrehliadkaChecklist::originOf($accountId, $sourceId),
            PrehliadkaChecklist::CARRIED_POSITIONS_KEY => range(1, $count),
        ];
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
        return CarryOver::latestSourceId($accountId, $facilityId, $type, $exceptId);
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
     * Read and validate the header data (`details`) off the request body.
     * Null when absent; a 422 with a Slovak message when invalid or sent for
     * a type that has no header.
     *
     * @return array<string, mixed>|null
     */
    private static function readDetails(Request $req, string $type): ?array
    {
        $body = $req->json();
        if (!array_key_exists('details', $body) || $body['details'] === null) {
            return null;
        }
        try {
            return InspectionDetails::validate($type, $body['details']);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * The prevádzky and the completed header of a new book entry. Inside a
     * visit the visit's prevádzka is always one of them (added without a
     * periodicity when the request left it out); otherwise the request's
     * `facility_id` — the primary prevádzka — must be among them.
     *
     * @param array<string, mixed> $details from BookEntry::validateShape()
     * @return array{0: list<array{facility_id: int, periodicity_value: ?int, periodicity_unit: ?string}>, 1: array<string, mixed>}
     */
    private static function bookCreate(Request $req, int $accountId, int $companyId, int $facilityId, bool $inVisit, array $details): array
    {
        $body = $req->json();
        try {
            $rows = BookEntry::readFacilities($body['facilities'] ?? null);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        $ids = array_map(static fn (array $r): int => $r['facility_id'], $rows);
        if (!in_array($facilityId, $ids, true)) {
            if (!$inVisit) {
                Response::error('Prevádzka zápisu musí byť medzi vybranými.', 422);
            }
            $rows[] = ['facility_id' => $facilityId, 'periodicity_value' => null, 'periodicity_unit' => null];
            $ids[] = $facilityId;
        }
        if (BookEntry::facilityNames($accountId, $companyId, $ids) === null) {
            Response::error('Firma alebo prevádzka sa nenašla.', 404);
        }
        try {
            $details = BookEntry::applyContext($details, [
                'account_id'   => $accountId,
                'company_id'   => $companyId,
                'client_type'  => self::clientType($companyId),
                'facility_ids' => $ids,
                'stored'       => null,
                'creating'     => true,
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        return [$rows, $details];
    }

    /**
     * PATCH of a new book entry: `facilities` (the whole set) and `details`
     * (the whole header), both checked before anything is written, then
     * written together with the derived columns (BookEntry::syncHeader).
     *
     * @param array<string, mixed> $row loadOrFail() row
     * @param array<string, mixed> $stored the stored header
     */
    private static function bookUpdate(Request $req, array $row, int $accountId, array $stored): void
    {
        $body = $req->json();
        $id = (int) $row['id'];
        $companyId = (int) $row['company_id'];
        $current = BookEntry::facilityRows($id);
        $currentIds = array_map(static fn (array $r): int => $r['facility_id'], $current);

        $rows = null;
        $ids = $currentIds;
        if (array_key_exists('facilities', $body)) {
            try {
                $rows = BookEntry::readFacilities($body['facilities']);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            $ids = array_map(static fn (array $r): int => $r['facility_id'], $rows);
            if (BookEntry::facilityNames($accountId, $companyId, $ids) === null) {
                Response::error('Firma alebo prevádzka sa nenašla.', 404);
            }
            if ($row['visit_id'] !== null) {
                $visit = Db::pdo()->prepare('SELECT facility_id FROM visits WHERE id = ? AND account_id = ?');
                $visit->execute([(int) $row['visit_id'], $accountId]);
                $visitFacility = $visit->fetchColumn();
                if ($visitFacility !== false && !in_array((int) $visitFacility, $ids, true)) {
                    Response::error('Prevádzka návštevy musí zostať vybraná.', 422);
                }
            }
            // A defect belongs to one prevádzka; that prevádzka cannot leave the
            // entry while the defect is there.
            $withDefects = Db::pdo()->prepare(
                "SELECT 1 FROM inspection_items WHERE inspection_id = ? AND JSON_VALUE(fields, '$.facility_id') = ? LIMIT 1"
            );
            foreach ($current as $r) {
                if (in_array($r['facility_id'], $ids, true)) {
                    continue;
                }
                $withDefects->execute([$id, $r['facility_id']]);
                if ($withDefects->fetchColumn() !== false) {
                    Response::error('Z prevádzky ' . $r['name'] . ' sú zapísané nedostatky — najprv ich zmaž.', 409);
                }
            }
        }

        $details = null;
        if (($body['details'] ?? null) !== null) {
            $details = self::readDetails($req, BookEntry::TYPE);
            try {
                $details = BookEntry::applyContext((array) $details, [
                    'account_id'   => $accountId,
                    'company_id'   => $companyId,
                    'client_type'  => self::clientType($companyId),
                    'facility_ids' => $ids,
                    'stored'       => $stored,
                    'creating'     => false,
                ]);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
        } elseif ($rows !== null) {
            // Records of a prevádzka that left the entry are no longer attached.
            $details = $stored;
            $details['zaznamy'] = BookEntry::filterRecords((array) ($stored['zaznamy'] ?? []), $accountId, $companyId, $ids);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($rows !== null) {
                BookEntry::writeFacilities($pdo, $accountId, $id, $rows);
            }
            if ($details !== null) {
                $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ? AND account_id = ?')
                    ->execute([InspectionDetails::encode($details), $id, $accountId]);
            }
            BookEntry::syncHeader($pdo, $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function clientType(int $companyId): ?string
    {
        $stmt = Db::pdo()->prepare('SELECT client_type FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $type = $stmt->fetchColumn();
        return is_string($type) ? $type : null;
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
     * Refuse a prehliadka whose scope the technician cannot sign — the rules
     * live in CertGuard. `$onlyAdded` limits the check to scope switched on
     * by this request, so a draft that predates a lost number stays editable
     * (the close refuses it, as before).
     *
     * @param array<string, mixed> $details validated PrehliadkaDetails
     * @param array<string, mixed>|null $before details as stored, for an edit
     */
    private static function assertCanDoPrehliadka(
        int $accountId,
        int $inspectorUserId,
        array $details,
        ?array $before = null,
    ): void {
        $scope = (array) ($details['scope'] ?? []);
        $was = (array) ($before['scope'] ?? []);
        $po = ($scope['po'] ?? false) === true && ($was['po'] ?? false) !== true;
        $bozp = ($scope['bozp'] ?? false) === true && ($was['bozp'] ?? false) !== true;
        self::refuseMissing(CertGuard::missingForPrehliadka($accountId, $inspectorUserId, $po, $bozp));
    }

    /**
     * Refuse to create an inspection the executor can't legitimately sign —
     * the rules live in CertGuard. The refusal carries `code` / `cert` so the
     * UI can open the right field.
     */
    private static function assertCanInspect(int $accountId, int $inspectorUserId, string $type): void
    {
        self::refuseMissing(CertGuard::missingForType($accountId, $inspectorUserId, $type));
    }

    /** @param array{error: string, cert: string}|null $missing */
    private static function refuseMissing(?array $missing): void
    {
        if ($missing !== null) {
            Response::error($missing['error'], 422, ['code' => 'cert_missing', 'cert' => $missing['cert']]);
        }
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT i.id, i.account_id, i.type,
                       i.periodicity_value, i.periodicity_unit, i.periodicity_is_custom,
                       i.is_preventive_inspection, i.source_inspection_id,
                       i.carried_over_from_id, i.visit_id,
                       i.executed_on, i.status, i.issued_before, i.notes, i.details,
                       i.created_at, i.updated_at,
                       i.company_id, c.name AS company_name, c.ico AS company_ico,
                       c.client_type AS company_client_type,
                       i.facility_id, f.name AS facility_name,
                       i.inspector_user_id, u.fullname AS inspector_name,
                       i.created_by_user_id,
                       i.effective_inspector_user_id,
                       eu.fullname AS effective_inspector_name,
                       i.effective_cert_number,
                       i.billing_mode, i.invoiced, i.invoiced_at, i.billing_note,
                       ' . self::supersededExpr() . '
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
            Response::error('Kontrola sa nenašla.', 404);
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
        $row['created_by_user_id'] = isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null;
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
        // Plan 8b — the scope chip of a prehliadka and the state of a concept. The
        // list selects them without the whole header; the detail endpoint derives
        // them from the header it returns anyway.
        $rawScope = $row['scope_details'] ?? ($row['details'] ?? null);
        unset($row['scope_details']);
        $row['prehliadka_scope'] = ($row['type'] ?? null) === PrehliadkaDetails::TYPE
            ? PrehliadkaDetails::scopeLabel(InspectionDetails::decode($rawScope))
            : null;
        if (!array_key_exists('concept_state', $row)) {
            $conceptDetails = InspectionDetails::decode($row['details'] ?? null);
            $row['concept_state'] = !PrehliadkaConcepts::isConcept($conceptDetails)
                ? null
                : (PrehliadkaConcepts::isPending($conceptDetails) ? 'pending' : 'confirmed');
        }
        // Header data (block 2). Only the detail endpoints select it; the list
        // leaves it out, and the key is then absent rather than null.
        if (array_key_exists('details', $row)) {
            // issued_contractor is written by the server at issue time. The
            // form edits the header, not that snapshot, so it is not returned.
            $row['details'] = \Firol\Support\Contractor::visibleDetails(
                InspectionDetails::decode($row['details']),
            );
        }
        // Chapter 22 — fakturácia úkonu (billing_mode null = recorded before
        // the app tracked invoicing).
        $row = \Firol\Support\Invoicing::shape($row);
        unset($row['account_id']);
        return $row;
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
