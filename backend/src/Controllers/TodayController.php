<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Deadlines;
use Firol\Support\Defects;
use Firol\Support\Invoicing;
use Firol\Support\PkDefects;
use Firol\Support\TeamIdentity;
use PDO;

/**
 * Obrazovka „Dnes" — block 4 / chapter 18.
 *
 * GET /api/today?scope=mine|team
 *
 * One read for the cards that have no list endpoint of their own, computed
 * with the same rules as the rest of the app. The card „Úlohy do 7 dní" is
 * not here — it reads GET /api/tasks/upcoming.
 *
 * Always scoped to the ACTIVE account, also for a platform admin: Dnes is
 * "what do I have to do today in this firm", never a cross-tenant overview.
 * Nothing of an archived company or prevádzka appears (chapter 25: "Obrazovka
 * Dnes — firma sa nikde nezobrazuje").
 *
 * `scope=mine` (default) — the signed-in technician's own rows: úkony they
 * performed (inspector) or trained (trainer), visits they lead, vlastné
 * udalosti they created, deadlines whose defining úkon they performed.
 * `scope=team` — every row of the account (the main user's „Celý tím").
 *
 * Every list is exactly the rows its card shows, so a card's header count is
 * simply the length of its list (acceptance criterion of chapter 18).
 *
 * Response:
 *   date        today, YYYY-MM-DD (server time)
 *   field       „Dnes v teréne": visits dated today, deadlines planned for
 *               today and vlastné udalosti dated today. There is no
 *               time-of-day anywhere in the data model, so the order is:
 *               visits (in the order they were created), then planned
 *               deadlines (company, prevádzka), then events (creation order).
 *   overdue     „Po termíne": open deadlines whose date has passed (state
 *               po_termine, {@see Deadlines}), most overdue first.
 *   drafts      „Rozrobené koncepty": úkony (inspections and trainings) still
 *               in draft, most recently started first.
 *   defects     „Otvorené nedostatky" — see {@see self::openDefects()}.
 *   uninvoiced  „Nevyfakturované": úkony with režim na faktúru not checked
 *               off ({@see Invoicing::uninvoicedCondition()}, chapter 22).
 *   own_terms   „Tvoje termíny": the signed-in user's certificate validity
 *               dates (plus the firm's for the main user) that have run out
 *               or run out within {@see self::OWN_TERM_DAYS} days.
 */
final class TodayController
{
    /**
     * How far ahead „Tvoje termíny" looks. The spec gives no number; the app
     * has no certificate-expiry warning to reuse either. 120 days is the
     * smallest round window that still holds the spec's own mockup (a
     * certificate expiring in 95 days is shown) and leaves time to arrange the
     * renewal training before a certificate lapses and blocks protocols.
     */
    public const OWN_TERM_DAYS = 120;

    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $scope = $req->query('scope') ?? 'mine';
        if (!in_array($scope, ['mine', 'team'], true)) {
            Response::error('Neznámy rozsah.', 422);
        }
        $mine = $scope === 'mine';
        $today = date('Y-m-d');

        // Members who joined through a path that assigned no avatar get one
        // before anything is drawn with it (same as the calendar).
        TeamIdentity::ensureAll(Db::pdo(), $accountId);

        // Open deadlines only — the fulfilled ones are of no use here.
        $deadlines = Deadlines::compute($accountId, false, $today);
        if ($mine) {
            $deadlines = array_values(array_filter(
                $deadlines,
                static fn (array $d): bool => ($d['technician']['id'] ?? null) === $userId,
            ));
        }

        $overdue = array_values(array_filter(
            $deadlines,
            static fn (array $d): bool => $d['state'] === 'po_termine',
        ));
        usort($overdue, static fn (array $a, array $b): int =>
            strcmp($a['due_date'], $b['due_date']) ?: strcmp($a['key'], $b['key']));

        $horizon = (new \DateTimeImmutable($today))->modify('+' . self::OWN_TERM_DAYS . ' days')->format('Y-m-d');
        $ownTerms = array_values(array_filter(
            CalendarController::ownTerms($accountId, $userId, $today),
            static fn (array $t): bool => $t['date'] <= $horizon,
        ));

        Response::json([
            'date'       => $today,
            'scope'      => $scope,
            'field'      => self::field($accountId, $mine ? $userId : null, $today, $deadlines),
            'overdue'    => $overdue,
            'drafts'     => self::drafts($accountId, $mine ? $userId : null),
            'defects'    => self::openDefects($accountId, $mine ? $userId : null),
            'uninvoiced' => self::uninvoiced($accountId, $mine ? $userId : null),
            'own_terms'  => $ownTerms,
        ]);
    }

    /**
     * „Dnes v teréne".
     *
     * @param list<array<string, mixed>> $deadlines open deadlines, already scoped
     * @return list<array<string, mixed>>
     */
    private static function field(int $accountId, ?int $userId, string $today, array $deadlines): array
    {
        $out = [];

        // Visits dated today — both still running and already finished: the
        // card is the day's programme, and a finished one says so.
        $sql = 'SELECT v.id, v.company_id, c.name AS company_name,
                       v.facility_id, f.name AS facility_name, f.city AS facility_city,
                       v.planned_types, v.status,
                       v.technician_user_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   visits v
                JOIN   companies  c ON c.id = v.company_id  AND c.archived_at IS NULL
                JOIN   facilities f ON f.id = v.facility_id AND f.archived_at IS NULL
                LEFT   JOIN users u ON u.id = v.technician_user_id
                LEFT   JOIN account_users au ON au.account_id = v.account_id AND au.user_id = v.technician_user_id
                WHERE  v.account_id = ? AND v.archived_at IS NULL AND v.visit_date = ?';
        $args = [$accountId, $today];
        if ($userId !== null) {
            $sql .= ' AND v.technician_user_id = ?';
            $args[] = $userId;
        }
        $sql .= ' ORDER BY v.created_at ASC, v.id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $types = json_decode((string) ($r['planned_types'] ?? '[]'), true);
            $out[] = [
                'kind'          => 'navsteva',
                'key'           => 'navsteva-' . (int) $r['id'],
                'id'            => (int) $r['id'],
                'company_id'    => (int) $r['company_id'],
                'company_name'  => (string) $r['company_name'],
                'facility_name' => (string) $r['facility_name'],
                'city'          => self::city($r['facility_city']),
                'types'         => is_array($types) ? array_values(array_filter($types, 'is_string')) : [],
                'title'         => null,
                'status'        => (string) $r['status'],
                'technician'    => self::technician($r),
            ];
        }

        // Deadlines planned for today (calendar_plans) — the open ones only;
        // a plan left on a deadline that has since been fulfilled is stale.
        $planned = array_values(array_filter(
            $deadlines,
            static fn (array $d): bool => $d['planned_date'] === $today,
        ));
        usort($planned, static fn (array $a, array $b): int =>
            strcmp($a['company_name'], $b['company_name'])
            ?: strcmp((string) $a['facility_name'], (string) $b['facility_name'])
            ?: strcmp($a['key'], $b['key']));
        foreach ($planned as $d) {
            // A training term (no inspection_id) is told apart by `training_id`;
            // `id` is the úkon the row opens, whichever kind it is.
            $out[] = [
                'kind'          => 'plan',
                'key'           => 'plan-' . $d['key'],
                'id'            => $d['inspection_id'] ?? $d['training_id'],
                'training_id'   => $d['training_id'],
                'company_id'    => $d['company_id'],
                'company_name'  => $d['company_name'],
                // Null for a training of the whole firma.
                'facility_name' => $d['facility_name'],
                'city'          => $d['facility_city'],
                'types'         => [$d['type']],
                'title'         => null,
                'status'        => $d['state'],
                'technician'    => $d['technician'],
            ];
        }

        // Vlastné udalosti dated today. One without a firm stays; one whose
        // firm or prevádzka is archived goes (chapter 25).
        $sql = 'SELECT e.id, e.title, e.time_from, e.time_to, e.company_id, c.name AS company_name,
                       f.name AS facility_name, f.city AS facility_city,
                       e.user_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   calendar_events e
                LEFT   JOIN companies  c ON c.id = e.company_id
                LEFT   JOIN facilities f ON f.id = e.facility_id
                LEFT   JOIN users u ON u.id = e.user_id
                LEFT   JOIN account_users au ON au.account_id = e.account_id AND au.user_id = e.user_id
                WHERE  e.account_id = ? AND e.event_date = ?
                  AND  c.archived_at IS NULL AND f.archived_at IS NULL';
        $args = [$accountId, $today];
        if ($userId !== null) {
            $sql .= ' AND e.user_id = ?';
            $args[] = $userId;
        }
        $sql .= ' ORDER BY e.time_from IS NOT NULL, e.time_from ASC, e.created_at ASC, e.id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'kind'          => 'udalost',
                'key'           => 'udalost-' . (int) $r['id'],
                'id'            => (int) $r['id'],
                'company_id'    => $r['company_id'] !== null ? (int) $r['company_id'] : null,
                'company_name'  => $r['company_name'] !== null ? (string) $r['company_name'] : null,
                'facility_name' => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
                'city'          => self::city($r['facility_city']),
                'types'         => [],
                'title'         => (string) $r['title'],
                'time_from'     => $r['time_from'] !== null ? substr((string) $r['time_from'], 0, 5) : null,
                'time_to'       => $r['time_to'] !== null ? substr((string) $r['time_to'], 0, 5) : null,
                'status'        => null,
                'technician'    => self::technician($r),
            ];
        }

        return $out;
    }

    /**
     * „Rozrobené koncepty" — inspections and trainings still in draft.
     *
     * @return list<array<string, mixed>>
     */
    private static function drafts(int $accountId, ?int $userId): array
    {
        $rows = [];

        $sql = 'SELECT i.id, i.type, i.created_at, i.executed_on AS done_on,
                       c.name AS company_name, f.name AS facility_name,
                       i.inspector_user_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   inspections i
                JOIN   companies  c ON c.id = i.company_id  AND c.archived_at IS NULL
                JOIN   facilities f ON f.id = i.facility_id AND f.archived_at IS NULL
                LEFT   JOIN users u ON u.id = i.inspector_user_id
                LEFT   JOIN account_users au ON au.account_id = i.account_id AND au.user_id = i.inspector_user_id
                WHERE  i.account_id = ? AND i.archived_at IS NULL AND i.status = "draft"';
        $args = [$accountId];
        if ($userId !== null) {
            $sql .= ' AND i.inspector_user_id = ?';
            $args[] = $userId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = self::ukonRow('inspection', $r);
        }

        $sql = 'SELECT t.id, t.type, t.created_at, t.date AS done_on,
                       c.name AS company_name, f.name AS facility_name,
                       t.trainer_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   trainings t
                JOIN   companies  c ON c.id = t.company_id AND c.archived_at IS NULL
                LEFT   JOIN facilities f ON f.id = t.facility_id
                LEFT   JOIN users u ON u.id = t.trainer_id
                LEFT   JOIN account_users au ON au.account_id = t.account_id AND au.user_id = t.trainer_id
                WHERE  t.account_id = ? AND t.archived_at IS NULL AND t.status = "draft"
                  AND  f.archived_at IS NULL';
        $args = [$accountId];
        if ($userId !== null) {
            $sql .= ' AND t.trainer_id = ?';
            $args[] = $userId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = self::ukonRow('training', $r);
        }

        // Most recently started first — that is the one being worked on.
        usort($rows, static fn (array $a, array $b): int =>
            strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);
        return $rows;
    }

    /**
     * „Otvorené nedostatky" — nedostatky waiting to be removed, with a termín.
     *
     * The data model has no "removed" flag on a nedostatok, so "open" is read
     * from what it does record, conservatively:
     *
     *   1. it carries a termín odstránenia (the card lists them "s termínom";
     *      one without a termín is a finding, not a removal to wait for);
     *   2. its úkon is finalized — the protocol was issued to the client; a
     *      draft's nedostatky belong to the unfinished úkon (card Koncepty);
     *   3. its úkon is still the latest of its type at that prevádzka — the
     *      same "superseded" rule the úkon list uses: a later úkon of the same
     *      type re-inspected the place, and its own nedostatky are the current
     *      ones;
     *   4. it has not been verified as removed: when the task „Overiť
     *      odstránenie nedostatku" created from it (chapter 20) is done and no
     *      such task is still open, the nedostatok is closed.
     *
     * Archived companies, prevádzky and úkony are left out. `mine` = the úkon
     * was performed by the signed-in technician. Earliest termín first.
     *
     * @return list<array<string, mixed>>
     */
    private static function openDefects(int $accountId, ?int $userId): array
    {
        $sql = "SELECT i.id AS inspection_id, i.type, i.executed_on,
                       c.id AS company_id, c.name AS company_name, f.name AS facility_name,
                       ii.id AS item_id, ii.fields,
                       i.inspector_user_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   inspections i
                JOIN   companies  c ON c.id = i.company_id  AND c.archived_at IS NULL
                JOIN   facilities f ON f.id = i.facility_id AND f.archived_at IS NULL
                JOIN   inspection_items ii ON ii.inspection_id = i.id
                LEFT   JOIN users u ON u.id = i.inspector_user_id
                LEFT   JOIN account_users au ON au.account_id = i.account_id AND au.user_id = i.inspector_user_id
                WHERE  i.account_id = ? AND i.archived_at IS NULL AND i.status = 'finalized'
                  AND  (JSON_CONTAINS_PATH(ii.fields, 'one', '$.defects', '$.defect_deadline'))
                  AND  NOT EXISTS (
                           SELECT 1 FROM inspections s
                           WHERE  s.account_id  = i.account_id
                             AND  s.facility_id = i.facility_id
                             AND  s.type        = i.type
                             AND  s.archived_at IS NULL
                             AND  s.status      = 'finalized'
                             AND  s.is_preventive_inspection = 1
                             AND  (COALESCE(s.executed_on, '1000-01-01'), s.id)
                                > (COALESCE(i.executed_on, '1000-01-01'), i.id)
                       )";
        $args = [$accountId];
        if ($userId !== null) {
            $sql .= ' AND i.inspector_user_id = ?';
            $args[] = $userId;
        }
        $sql .= ' ORDER BY i.id ASC, ii.position ASC, ii.id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        // Verification tasks per nedostatok: [inspection_id][defect_key] => [open, done].
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['inspection_id'], $rows)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tStmt = Db::pdo()->prepare(
            "SELECT source_inspection_id, source_defect_key,
                    SUM(done = 0) AS open_count, SUM(done = 1) AS done_count
             FROM   tasks
             WHERE  account_id = ? AND source_defect_key IS NOT NULL
               AND  source_inspection_id IN ($placeholders)
             GROUP  BY source_inspection_id, source_defect_key"
        );
        $tStmt->execute([$accountId, ...$ids]);
        $verified = [];
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if ((int) $t['done_count'] > 0 && (int) $t['open_count'] === 0) {
                $verified[(int) $t['source_inspection_id']][(string) $t['source_defect_key']] = true;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $fields = json_decode((string) $r['fields'], true);
            if (!is_array($fields)) {
                continue;
            }
            $inspectionId = (int) $r['inspection_id'];
            // Požiarna kniha still reads records saved before the generic
            // block (a single defect_deadline + notes); PkDefects is the one
            // place that knows both shapes. Everything else is Defects.
            $list = $r['type'] === 'poziarna_kniha'
                ? PkDefects::rows($fields)['rows']
                : Defects::fromFields($fields);
            foreach ($list as $idx => $defect) {
                $deadline = $defect['deadline'] ?? null;
                if (!is_string($deadline) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
                    continue;
                }
                $key = $defect['key'] ?? null;
                if ($key !== null && isset($verified[$inspectionId][$key])) {
                    continue;
                }
                $out[] = [
                    'key'           => 'nedostatok-' . $inspectionId . '-' . ($key ?? ((int) $r['item_id'] . '-' . $idx)),
                    'inspection_id' => $inspectionId,
                    'defect_key'    => $key,
                    'type'          => (string) $r['type'],
                    'executed_on'   => $r['executed_on'] !== null ? (string) $r['executed_on'] : null,
                    'description'   => (string) $defect['description'],
                    'deadline'      => $deadline,
                    'company_id'    => (int) $r['company_id'],
                    'company_name'  => (string) $r['company_name'],
                    'facility_name' => (string) $r['facility_name'],
                    'technician'    => self::technician($r),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int =>
            strcmp($a['deadline'], $b['deadline']) ?: $a['inspection_id'] <=> $b['inspection_id']);
        return $out;
    }

    /**
     * „Nevyfakturované" — the rule of chapter 22, shared with the list filter
     * `?uninvoiced=1` and the summary, minus archived firms (chapter 25).
     *
     * @return list<array<string, mixed>>
     */
    private static function uninvoiced(int $accountId, ?int $userId): array
    {
        $rows = [];

        $sql = 'SELECT i.id, i.type, i.created_at, i.executed_on AS done_on,
                       c.name AS company_name, f.name AS facility_name,
                       i.inspector_user_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   inspections i
                JOIN   companies  c ON c.id = i.company_id  AND c.archived_at IS NULL
                JOIN   facilities f ON f.id = i.facility_id AND f.archived_at IS NULL
                LEFT   JOIN users u ON u.id = i.inspector_user_id
                LEFT   JOIN account_users au ON au.account_id = i.account_id AND au.user_id = i.inspector_user_id
                WHERE  i.account_id = ? AND i.archived_at IS NULL
                  AND  ' . Invoicing::uninvoicedCondition('i');
        $args = [$accountId];
        if ($userId !== null) {
            $sql .= ' AND i.inspector_user_id = ?';
            $args[] = $userId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = self::ukonRow('inspection', $r);
        }

        $sql = 'SELECT t.id, t.type, t.created_at, t.date AS done_on,
                       c.name AS company_name, f.name AS facility_name,
                       t.trainer_id AS tech_id, u.fullname AS tech_name,
                       au.initials AS tech_initials, au.avatar_color AS tech_color
                FROM   trainings t
                JOIN   companies  c ON c.id = t.company_id AND c.archived_at IS NULL
                LEFT   JOIN facilities f ON f.id = t.facility_id
                LEFT   JOIN users u ON u.id = t.trainer_id
                LEFT   JOIN account_users au ON au.account_id = t.account_id AND au.user_id = t.trainer_id
                WHERE  t.account_id = ? AND t.archived_at IS NULL
                  AND  f.archived_at IS NULL
                  AND  ' . Invoicing::uninvoicedCondition('t');
        $args = [$accountId];
        if ($userId !== null) {
            $sql .= ' AND t.trainer_id = ?';
            $args[] = $userId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = self::ukonRow('training', $r);
        }

        // Oldest first — the one waiting longest for its invoice leads.
        usort($rows, static fn (array $a, array $b): int =>
            strcmp($a['done_on'] ?? $a['created_at'], $b['done_on'] ?? $b['created_at']) ?: $a['id'] <=> $b['id']);
        return $rows;
    }

    /**
     * @param 'inspection'|'training' $kind
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function ukonRow(string $kind, array $r): array
    {
        return [
            'kind'          => $kind,
            'key'           => $kind . '-' . (int) $r['id'],
            'id'            => (int) $r['id'],
            'type'          => (string) $r['type'],
            'company_name'  => (string) $r['company_name'],
            'facility_name' => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
            'done_on'       => $r['done_on'] !== null ? (string) $r['done_on'] : null,
            'created_at'    => (string) $r['created_at'],
            'technician'    => self::technician($r),
        ];
    }

    /**
     * The avatar identity of a row's technician (11.5) — same fallback as the
     * calendar: someone no longer on the team keeps derived initials in grey.
     *
     * @param array<string, mixed> $r with tech_id, tech_name, tech_initials, tech_color
     * @return array{id: int, fullname: string, initials: string, avatar_color: string}|null
     */
    private static function technician(array $r): ?array
    {
        if (($r['tech_id'] ?? null) === null) {
            return null;
        }
        $name = (string) ($r['tech_name'] ?? '');
        return [
            'id'           => (int) $r['tech_id'],
            'fullname'     => $name,
            'initials'     => ($r['tech_initials'] ?? null) !== null && $r['tech_initials'] !== ''
                ? (string) $r['tech_initials']
                : TeamIdentity::deriveInitials($name),
            'avatar_color' => ($r['tech_color'] ?? null) !== null && $r['tech_color'] !== ''
                ? (string) $r['tech_color']
                : TeamIdentity::FORMER_MEMBER_COLOR,
        ];
    }

    private static function city(mixed $city): ?string
    {
        return $city !== null && trim((string) $city) !== '' ? trim((string) $city) : null;
    }
}
