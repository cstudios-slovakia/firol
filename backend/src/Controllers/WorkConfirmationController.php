<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Documents\NumberAllocator;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Pdf\PdfRenderer;
use Firol\Storage\Storage;
use Firol\Support\Address;

/**
 * Potvrdenie o vykonaní práce — block 1 / chapter 10.
 *
 * This is NOT a professional document. A protocol is for the client and for
 * the inspection; this one is for the technician's own EMPLOYER, who does not
 * care what was found — only that their technician was there and how much they
 * got through. Today that is a paper timesheet the client signs.
 *
 * Which is why it lists protocol NUMBERS and nothing else: no nedostatky, no
 * stavy, no verdicts. Anyone who wants the findings opens the protocol whose
 * number is on the line. The header names the technician's own company as
 * zhotoviteľ, not the client.
 *
 * It normally comes out of a whole visit — four úkony, one sheet of paper, not
 * four — but it can also be built from a single úkon or, after the fact, from
 * everything done at a company on a given day.
 */
final class WorkConfirmationController
{
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $companyId = self::queryInt($req, 'company_id');

        $sql = 'SELECT w.id, w.company_id, c.name AS company_name,
                       w.facility_id, f.name AS facility_name,
                       w.visit_id, w.confirmed_on, w.time_from, w.time_to,
                       w.technician_user_id, u.fullname AS technician_name,
                       w.inspection_ids, w.training_ids, w.created_at,
                       d.id AS document_id, d.number AS document_number
                FROM   work_confirmations w
                JOIN   companies  c ON c.id = w.company_id
                LEFT   JOIN facilities f ON f.id = w.facility_id
                JOIN   users      u ON u.id = w.technician_user_id
                LEFT   JOIN documents d
                       ON d.parent_type = "work_confirmation" AND d.parent_id = w.id
                WHERE  w.account_id = ? AND w.archived_at IS NULL';
        $args = [$accountId];
        if ($companyId !== null) {
            $sql .= ' AND w.company_id = ?';
            $args[] = $companyId;
        }
        $sql .= ' ORDER BY w.confirmed_on DESC, w.id DESC LIMIT 100';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        Response::json(['items' => array_map([self::class, 'shape'], $stmt->fetchAll())]);
    }

    /**
     * Create the confirmation and issue its PDF in one step — there is nothing
     * to edit on it afterwards, it only restates work that is already recorded.
     *
     * Accepts either an explicit list of úkony, a visit, or a company plus a
     * date (the "spätne z histórie firmy" case).
     */
    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $isAdmin = Admin::isAdmin($userId);

        $visitId = $req->jsonInt('visit_id');
        $companyId = $req->jsonInt('company_id');
        $facilityId = $req->jsonInt('facility_id');
        $confirmedOn = $req->jsonString('confirmed_on');
        $technicianId = $req->jsonInt('technician_user_id') ?? $userId;
        $timeFrom = self::readTime($req, 'time_from');
        $timeTo = self::readTime($req, 'time_to');
        $inspectionIds = self::readIds($req->json()['inspection_ids'] ?? null);
        // The školenie PO of a visit (chapter 9) is an úkon like the rest, so
        // the confirmation of a visit lists it beside the inspections.
        $trainingIds = [];

        if ($visitId !== null) {
            $visit = VisitController::findForUkon($visitId, $isAdmin, $accountId);
            if ($visit === null) {
                Response::error('Návšteva sa nenašla.', 404);
            }
            // An admin works across accounts: the confirmation (and its
            // number) belongs to the account that owns the visit.
            $accountId = $visit['account_id'];
            $companyId ??= $visit['company_id'];
            $facilityId ??= $visit['facility_id'];
            $confirmedOn ??= $visit['visit_date'];
            if ($inspectionIds === []) {
                $inspectionIds = self::inspectionIdsOfVisit($visitId);
            }
            $trainingIds = self::trainingIdsOfVisit($visitId);
        }

        if ($companyId === null) {
            Response::error('Vyber firmu.', 422);
        }
        // Same for a company picked without a visit: the confirmation is filed
        // under the account that owns the company.
        if ($isAdmin && $visitId === null) {
            $owner = Db::pdo()->prepare('SELECT account_id FROM companies WHERE id = ? AND archived_at IS NULL');
            $owner->execute([$companyId]);
            $ownerAccountId = $owner->fetchColumn();
            if ($ownerAccountId === false) {
                Response::error('Firma sa nenašla.', 404);
            }
            $accountId = (int) $ownerAccountId;
        }
        if ($confirmedOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $confirmedOn)) {
            Response::error('Zadaj dátum vykonania práce.', 422);
        }
        if ($timeFrom !== null && $timeTo !== null && $timeTo <= $timeFrom) {
            Response::error('Čas „do" musí byť neskorší ako čas „od".', 422);
        }

        // Nothing named explicitly and no visit: take everything finished at
        // this company on that day, which is what "spätne z histórie" means.
        if ($inspectionIds === [] && $trainingIds === []) {
            $inspectionIds = self::inspectionIdsOfDay($accountId, $companyId, $facilityId, $confirmedOn);
        }
        if ($inspectionIds === [] && $trainingIds === []) {
            Response::error('V ten deň nie je pri tejto firme zaznamenaný žiadny dokončený úkon.', 422);
        }

        $inspections = self::loadInspections($accountId, $companyId, $inspectionIds);
        $trainings = self::loadTrainings($accountId, $companyId, $trainingIds);
        if ($inspections === [] && $trainings === []) {
            Response::error('Vybrané úkony sa nenašli pri tejto firme.', 404);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Chapter 10 `vydajky`: the výdajky issued at this client on that
            // day. Frozen here, so a later re-render for a signature prints
            // the same material even if another výdajka is issued afterwards.
            $stockIssueIds = self::stockIssueIdsOfDay($accountId, $companyId, $facilityId, $confirmedOn);

            $pdo->prepare(
                'INSERT INTO work_confirmations
                    (account_id, company_id, facility_id, visit_id, confirmed_on,
                     time_from, time_to, technician_user_id, inspection_ids, training_ids,
                     stock_issue_ids)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId, $companyId, $facilityId, $visitId, $confirmedOn,
                $timeFrom, $timeTo, $technicianId,
                json_encode(array_column($inspections, 'id')),
                json_encode(array_column($trainings, 'id')),
                json_encode($stockIssueIds),
            ]);
            $id = (int) $pdo->lastInsertId();

            $year = (int) substr($confirmedOn, 0, 4);
            $allocated = NumberAllocator::allocate($accountId, 'potvrdenie_prace', $year);

            $payload = self::payload($accountId, $id);
            $payload['number'] = $allocated['number'];
            $payload['generated_at'] = date('c');

            $bytes = PdfRenderer::renderWorkConfirmation($payload);

            $relPath = Storage::documentRelative($accountId, $year, $allocated['number']);
            $absPath = Storage::documentAbsolute($relPath);
            Storage::ensureDir(dirname($absPath));
            if (file_put_contents($absPath, $bytes) === false) {
                throw new \RuntimeException('Failed to write PDF to storage.');
            }

            $pdo->prepare(
                'INSERT INTO documents
                    (account_id, parent_type, parent_id, type, number, include_photos,
                     file_path, signed, signed_at)
                 VALUES (?, "work_confirmation", ?, "potvrdenie_prace", ?, 0, ?, 1, NOW())'
            )->execute([$accountId, $id, $allocated['number'], $relPath]);
            $documentId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO document_versions (document_id, version, file_path)
                 VALUES (?, 1, ?)'
            )->execute([$documentId, $relPath]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (isset($absPath) && is_file($absPath) && !@unlink($absPath)) {
                error_log('[work-confirmation] orphan PDF cleanup failed: ' . $absPath);
            }
            error_log('[work-confirmation] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Potvrdenie sa nepodarilo vygenerovať.', 500);
        }

        Response::json([
            'confirmation' => self::load($accountId, $id),
            'document'     => [
                'id'           => $documentId,
                'number'       => $allocated['number'],
                'download_url' => '/api/documents/' . $documentId . '/download',
            ],
        ], 201);
    }

    /**
     * Renderer payload for one confirmation. Public because the document is
     * re-rendered from it when the client signs (chapter 13).
     *
     * @return array<string, mixed>
     */
    public static function payload(int $accountId, int $id): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT w.id, w.confirmed_on, w.time_from, w.time_to, w.inspection_ids,
                    w.training_ids, w.stock_issue_ids, w.company_id, c.name AS company_name, c.ico AS company_ico,
                    c.street AS company_street, c.postal_code AS company_postal_code,
                    c.city AS company_city,
                    w.facility_id, f.name AS facility_name,
                    f.street AS facility_street, f.postal_code AS facility_postal_code,
                    f.city AS facility_city,
                    w.technician_user_id, u.fullname AS technician_name,
                    ip.cert_general, ip.cert_php, ip.signature_path
             FROM   work_confirmations w
             JOIN   companies  c ON c.id = w.company_id
             LEFT   JOIN facilities f ON f.id = w.facility_id
             JOIN   users      u ON u.id = w.technician_user_id
             LEFT   JOIN inspector_profiles ip
                    ON ip.user_id = w.technician_user_id AND ip.account_id = w.account_id
             WHERE  w.id = ? AND w.account_id = ?'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Potvrdenie sa nenašlo.', 404);
        }

        $ids = self::readIds(json_decode((string) $row['inspection_ids'], true));
        $inspections = self::loadInspections($accountId, (int) $row['company_id'], $ids);
        // Trainings are listed after the inspections, as úkony of the same
        // visit — no findings, just the protocol number, like the rest.
        $inspections = array_merge($inspections, self::loadTrainings(
            $accountId,
            (int) $row['company_id'],
            self::readIds(json_decode((string) ($row['training_ids'] ?? ''), true)),
        ));

        $accStmt = Db::pdo()->prepare(
            'SELECT invoice_company_name, invoice_ico, invoice_street, invoice_postal_code,
                    invoice_city, theme_color, logo_path
             FROM   accounts WHERE id = ?'
        );
        $accStmt->execute([$accountId]);
        $acc = $accStmt->fetch() ?: [];

        $protocolCount = count(array_filter(
            $inspections,
            static fn (array $i): bool => $i['document_number'] !== null,
        ));

        return [
            // The zhotoviteľ is the technician's own company — this document
            // is addressed to their employer, not issued to the client.
            'contractor' => [
                'name'    => $acc['invoice_company_name'] ?? 'POapp',
                'ico'     => $acc['invoice_ico'] ?? null,
                'address' => Address::format(
                    $acc['invoice_street'] ?? null,
                    $acc['invoice_postal_code'] ?? null,
                    $acc['invoice_city'] ?? null,
                ),
                'logo_data_uri' => self::logoDataUri($acc['logo_path'] ?? null),
            ],
            'confirmation' => [
                'confirmed_on' => (string) $row['confirmed_on'],
                // Optional by design: only an employer tracking hours needs it,
                // and when it is blank the row is left off the document.
                'time_from'    => $row['time_from'] !== null ? substr((string) $row['time_from'], 0, 5) : null,
                'time_to'      => $row['time_to'] !== null ? substr((string) $row['time_to'], 0, 5) : null,
                'duration'     => self::duration($row['time_from'], $row['time_to']),
                'act_count'    => count($inspections),
                'protocol_count' => $protocolCount,
            ],
            'company' => [
                'name'    => (string) $row['company_name'],
                'ico'     => $row['company_ico'],
                'address' => Address::format($row['company_street'], $row['company_postal_code'], $row['company_city']),
                'city'    => $row['company_city'],
            ],
            'facility' => [
                'name'    => $row['facility_name'],
                'address' => Address::format($row['facility_street'], $row['facility_postal_code'], $row['facility_city']),
                'city'    => $row['facility_city'],
            ],
            'technician' => [
                'fullname'             => (string) $row['technician_name'],
                'certification_number' => $row['cert_general'] ?: ($row['cert_php'] ?: null),
                'signature_data_uri'   => self::signatureDataUri($row['signature_path'] ?? null),
            ],
            'acts' => $inspections,
            // Chapter 10: material handed over that day, from the výdajky
            // frozen on the confirmation, each line with the výdajka's number.
            // None (or a potvrdenie issued before the sklad existed) → the
            // section is left out, as every empty section is.
            'materials' => self::materials(
                $accountId,
                self::readIds(json_decode((string) ($row['stock_issue_ids'] ?? ''), true)),
            ),
            'handover' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function load(int $accountId, int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT w.id, w.company_id, c.name AS company_name,
                    w.facility_id, f.name AS facility_name,
                    w.visit_id, w.confirmed_on, w.time_from, w.time_to,
                    w.technician_user_id, u.fullname AS technician_name,
                    w.inspection_ids, w.training_ids, w.created_at,
                    d.id AS document_id, d.number AS document_number
             FROM   work_confirmations w
             JOIN   companies  c ON c.id = w.company_id
             LEFT   JOIN facilities f ON f.id = w.facility_id
             JOIN   users      u ON u.id = w.technician_user_id
             LEFT   JOIN documents d
                    ON d.parent_type = "work_confirmation" AND d.parent_id = w.id
             WHERE  w.id = ? AND w.account_id = ?'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        return $row ? self::shape($row) : null;
    }

    /**
     * Úkony that go on the document. Only finished ones: a draft has no
     * protocol number, and a line with an empty number proves nothing.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function loadInspections(int $accountId, int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT i.id, i.type, i.executed_on,
                    (SELECT COUNT(*) FROM inspection_items it WHERE it.inspection_id = i.id) AS item_count,
                    d.number AS document_number
             FROM   inspections i
             LEFT   JOIN documents d
                    ON d.parent_type = 'inspection' AND d.parent_id = i.id
             WHERE  i.account_id = ? AND i.company_id = ? AND i.archived_at IS NULL
                AND i.id IN ($placeholders)
             ORDER  BY i.id ASC"
        );
        $stmt->execute(array_merge([$accountId, $companyId], $ids));

        return array_map(static function (array $r): array {
            return [
                'id'              => (int) $r['id'],
                'type'            => (string) $r['type'],
                'executed_on'     => $r['executed_on'],
                'item_count'      => (int) $r['item_count'],
                'document_number' => $r['document_number'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Školenia PO of the confirmation, shaped like an úkon line: the type is
     * printed as `skolenie_po` (one name for all kinds, as on the visit) and
     * the „rozsah" is the number of trainees.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function loadTrainings(int $accountId, int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT t.id, t.date,
                    (SELECT COUNT(*) FROM trainees tr WHERE tr.training_id = t.id) AS trainees_count,
                    d.number AS document_number
             FROM   trainings t
             LEFT   JOIN documents d
                    ON d.parent_type = 'training' AND d.parent_id = t.id
             WHERE  t.account_id = ? AND t.company_id = ? AND t.archived_at IS NULL
                AND t.id IN ($placeholders)
             ORDER  BY t.id ASC"
        );
        $stmt->execute(array_merge([$accountId, $companyId], $ids));

        return array_map(static function (array $r): array {
            return [
                'id'              => (int) $r['id'],
                'type'            => VisitController::TYPE_SKOLENIE_PO,
                'executed_on'     => $r['date'],
                'item_count'      => (int) $r['trainees_count'],
                'document_number' => $r['document_number'],
            ];
        }, $stmt->fetchAll());
    }

    /** @return list<int> */
    private static function trainingIdsOfVisit(int $visitId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM trainings
             WHERE  visit_id = ? AND archived_at IS NULL AND status = "finalized"
             ORDER  BY id ASC'
        );
        $stmt->execute([$visitId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private static function inspectionIdsOfVisit(int $visitId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM inspections
             WHERE  visit_id = ? AND archived_at IS NULL AND status = "finalized"
             ORDER  BY id ASC'
        );
        $stmt->execute([$visitId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private static function inspectionIdsOfDay(
        int $accountId,
        int $companyId,
        ?int $facilityId,
        string $date,
    ): array {
        $sql = 'SELECT id FROM inspections
                WHERE  account_id = ? AND company_id = ? AND executed_on = ?
                   AND archived_at IS NULL AND status = "finalized"';
        $args = [$accountId, $companyId, $date];
        if ($facilityId !== null) {
            $sql .= ' AND facility_id = ?';
            $args[] = $facilityId;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Výdajky (chapter 21) issued at this client on that day. With a
     * prevádzka on the confirmation, a výdajka tied to a different prevádzka
     * is left out; one tied to none still counts — it was the same client
     * that day.
     *
     * @return list<int>
     */
    private static function stockIssueIdsOfDay(
        int $accountId,
        int $companyId,
        ?int $facilityId,
        string $date,
    ): array {
        $sql = 'SELECT id FROM stock_issues
                WHERE  account_id = ? AND company_id = ? AND issued_on = ?';
        $args = [$accountId, $companyId, $date];
        if ($facilityId !== null) {
            $sql .= ' AND (facility_id IS NULL OR facility_id = ?)';
            $args[] = $facilityId;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Lines of the „Odovzdaný materiál" section: every item on the given
     * výdajky, with the výdajka's number as the Doklad.
     *
     * @param list<int> $issueIds
     * @return list<array{name: string, quantity: string, document_number: ?string}>
     */
    private static function materials(int $accountId, array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT m.item_name, m.qty, m.unit, d.number AS document_number
             FROM   stock_movements m
             LEFT   JOIN documents d
                    ON d.parent_type = 'stock_issue' AND d.parent_id = m.issue_id
             WHERE  m.account_id = ? AND m.issue_id IN ($placeholders)
             ORDER  BY m.issue_id ASC, m.id ASC"
        );
        $stmt->execute(array_merge([$accountId], $issueIds));
        return array_map(static fn (array $r): array => [
            'name'            => (string) $r['item_name'],
            'quantity'        => (int) $r['qty'] . ' ' . (string) $r['unit'],
            'document_number' => $r['document_number'],
        ], $stmt->fetchAll());
    }

    /** „3 h 15 min", or null when either end of the interval is missing. */
    private static function duration(?string $from, ?string $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }
        $minutes = (int) round((strtotime($to) - strtotime($from)) / 60);
        if ($minutes <= 0) {
            return null;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m . ' min';
        }
        return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
    }

    private static function readTime(Request $req, string $key): ?string
    {
        $value = $req->jsonString($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
            Response::error('Zadaj čas vo formáte HH:MM.', 422);
        }
        return $value;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function readIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private static function signatureDataUri(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }
        $abs = Storage::documentAbsolute($relativePath);
        if (!is_file($abs)) {
            return null;
        }
        $bytes = file_get_contents($abs);
        return $bytes === false ? null : 'data:image/png;base64,' . base64_encode($bytes);
    }

    private static function logoDataUri(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }
        $abs = Storage::documentAbsolute($relativePath);
        if (!is_file($abs)) {
            return null;
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false) {
            return null;
        }
        $mime = str_ends_with($relativePath, '.jpg') ? 'image/jpeg' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $ids = json_decode((string) $row['inspection_ids'], true);
        $trainingIds = json_decode((string) ($row['training_ids'] ?? ''), true);
        return [
            'id'                 => (int) $row['id'],
            'company_id'         => (int) $row['company_id'],
            'company_name'       => (string) $row['company_name'],
            'facility_id'        => $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
            'facility_name'      => $row['facility_name'],
            'visit_id'           => $row['visit_id'] !== null ? (int) $row['visit_id'] : null,
            'confirmed_on'       => (string) $row['confirmed_on'],
            'time_from'          => $row['time_from'] !== null ? substr((string) $row['time_from'], 0, 5) : null,
            'time_to'            => $row['time_to'] !== null ? substr((string) $row['time_to'], 0, 5) : null,
            'technician_user_id' => (int) $row['technician_user_id'],
            'technician_name'    => (string) $row['technician_name'],
            'inspection_ids'     => is_array($ids) ? array_map('intval', $ids) : [],
            'training_ids'       => is_array($trainingIds) ? array_map('intval', $trainingIds) : [],
            'created_at'         => $row['created_at'],
            'document_id'        => $row['document_id'] !== null ? (int) $row['document_id'] : null,
            'document_number'    => $row['document_number'],
        ];
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
