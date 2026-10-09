<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Úlohy — block 4 / chapter 20. A simple list, not a project tool.
 *
 * A task has a text, optionally a firma + prevádzka (none = „všeobecná"), an
 * optional assignee, an optional termín and a done flag. Done tasks drop out of
 * the open list but stay findable under „Splnené". A task can come from a
 * nedostatok (source_inspection_id + source_defect_key, see migration 044) —
 * it then shows the úkon's protocol number and links back to it.
 *
 * Everything is scoped to the session's active account — except for admins,
 * who see and edit tasks across all accounts, the same rule the inspections
 * list and the calendar follow. A task always lives in the account of the
 * úkon or firma it belongs to, so an admin's task on another tenant's úkon
 * lands with that tenant, not under the admin's own account.
 *
 * Archiving a firma or prevádzka closes its open tasks as „zrušené"
 * (cancel_reason, see ClientArchive — spec 25), so they leave every open list,
 * count and the Dnes card, and stay findable under „Splnené". Such a task can't
 * be reopened while its firma / prevádzka is archived.
 *
 * API shape for the Dnes screen (chapter 18, card „Úlohy do 7 dní"):
 *   GET /api/tasks/upcoming?scope=mine|team
 * → { items: Task[], until: 'YYYY-MM-DD' } — open tasks with a termín up to
 * today + 7 days, overdue ones included, earliest first.
 */
final class TaskController
{
    private const MAX_TEXT = 1000;

    /** Days ahead the „Úlohy do 7 dní" card looks. */
    private const UPCOMING_DAYS = 7;

    /**
     * GET /api/tasks?state=open|done&assignee=me|{userId}&source_inspection_id={id}
     *
     * `state` defaults to open. `source_inspection_id` lists the tasks that
     * came from that úkon's nedostatky (both states) — the defect-task offer
     * uses it to skip nedostatky that already have a task.
     */
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();

        $where = [];
        $args = [];

        $source = self::queryInt($req, 'source_inspection_id');
        if ($source !== null) {
            $where[] = 't.source_inspection_id = ?';
            $args[] = $source;
        } else {
            $state = $req->query('state') ?? 'open';
            if (!in_array($state, ['open', 'done'], true)) {
                Response::error('Neznámy stav úlohy.', 422);
            }
            $where[] = $state === 'done' ? 't.done = 1' : 't.done = 0';
        }

        $assignee = $req->query('assignee');
        if ($assignee !== null) {
            if ($assignee === 'me') {
                $where[] = 't.assignee_user_id = ?';
                $args[] = $userId;
            } elseif (ctype_digit($assignee)) {
                $where[] = 't.assignee_user_id = ?';
                $args[] = (int) $assignee;
            } else {
                Response::error('Neznámy technik.', 422);
            }
        }

        $order = ($req->query('state') ?? 'open') === 'done'
            ? 't.done_at DESC, t.id DESC'
            // Open: nearest termín first, tasks without one at the end.
            : 't.due_date IS NULL, t.due_date ASC, t.created_at ASC, t.id ASC';

        Response::json(['items' => self::select(self::scope($accountId, $userId), $where, $args, $order)]);
    }

    /**
     * GET /api/tasks/count → { open: int }
     *
     * The badge on the „Úlohy" menu item: every open task of the account, i.e.
     * exactly what the list shows with its default filter (Otvorené, všetci).
     */
    public static function count(Request $req): void
    {
        $scope = self::scope(Tenant::currentAccountId(), Tenant::currentUserId());
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*)
             FROM   tasks t
             LEFT   JOIN companies  c ON c.id = t.company_id
             LEFT   JOIN facilities f ON f.id = t.facility_id
             WHERE  ' . ($scope === null ? '1 = 1' : 't.account_id = ?') . '
               AND  t.done = 0 AND c.archived_at IS NULL AND f.archived_at IS NULL'
        );
        $stmt->execute($scope === null ? [] : [$scope]);
        Response::json(['open' => (int) $stmt->fetchColumn()]);
    }

    /**
     * GET /api/tasks/upcoming?scope=mine|team — the Dnes card (chapter 18).
     *
     * `mine` (default): assigned to the signed-in user, plus unassigned tasks
     * they created themselves (nobody else will pick those up). `team`: every
     * open task of the account.
     */
    public static function upcoming(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $scope = $req->query('scope') ?? 'mine';
        if (!in_array($scope, ['mine', 'team'], true)) {
            Response::error('Neznámy rozsah.', 422);
        }

        $until = (new \DateTimeImmutable('today'))
            ->modify('+' . self::UPCOMING_DAYS . ' days')
            ->format('Y-m-d');

        $where = ['t.done = 0', 't.due_date IS NOT NULL', 't.due_date <= ?'];
        $args = [$until];
        if ($scope === 'mine') {
            $where[] = '(t.assignee_user_id = ? OR (t.assignee_user_id IS NULL AND t.created_by_user_id = ?))';
            $args[] = $userId;
            $args[] = $userId;
        }

        Response::json([
            'items' => self::select(self::scope($accountId, $userId), $where, $args, 't.due_date ASC, t.created_at ASC, t.id ASC'),
            'until' => $until,
        ]);
    }

    /**
     * POST /api/tasks
     *
     * With source_inspection_id + source_defect_key the task comes from a
     * nedostatok: firma and prevádzka are taken from the úkon, and a second
     * create for the same nedostatok (another device, a replayed offline
     * queue) returns the existing task instead of a duplicate.
     */
    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $scope = self::scope($accountId, $userId);
        $body = $req->json();

        $text = self::readText($req);
        $dueDate = self::readDate($body['due_date'] ?? null);

        $sourceInspectionId = $req->jsonInt('source_inspection_id');
        $sourceKey = $req->jsonString('source_defect_key');
        if (($sourceInspectionId === null) !== ($sourceKey === null || $sourceKey === '')) {
            Response::error('Zdroj úlohy je neúplný.', 422);
        }

        // The task is filed under the account of its úkon / firma — for an
        // admin that may be another tenant's (see the class comment).
        if ($sourceInspectionId !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $sourceKey)) {
                Response::error('Neplatný nedostatok.', 422);
            }
            $inspection = self::loadInspection($sourceInspectionId, $scope);
            if ($inspection === null) {
                Response::error('Kontrola sa nenašla.', 404);
            }
            $accountId = (int) $inspection['account_id'];
            $existing = self::findBySource($accountId, $sourceInspectionId, (string) $sourceKey);
            if ($existing !== null) {
                Response::json(['task' => $existing]);
            }
            $companyId = (int) $inspection['company_id'];
            $facilityId = $inspection['facility_id'] !== null ? (int) $inspection['facility_id'] : null;
        } else {
            [$companyId, $facilityId, $placeAccountId] = self::readPlace($body, $scope);
            $accountId = $placeAccountId ?? $accountId;
        }

        $assignee = self::readAssignee($body['assignee_user_id'] ?? null, $accountId, $scope === null ? $userId : null);

        $pdo = Db::pdo();
        try {
            $pdo->prepare(
                'INSERT INTO tasks
                    (account_id, text, company_id, facility_id, assignee_user_id, due_date,
                     source_inspection_id, source_defect_key, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId, $text, $companyId, $facilityId, $assignee, $dueDate,
                $sourceInspectionId, $sourceInspectionId !== null ? $sourceKey : null, $userId,
            ]);
        } catch (\PDOException $e) {
            // Two devices racing on the same nedostatok: the unique key lets
            // exactly one insert through, and the other gets that task back.
            if ($e->getCode() === '23000' && $sourceInspectionId !== null) {
                $existing = self::findBySource($accountId, $sourceInspectionId, (string) $sourceKey);
                if ($existing !== null) {
                    Response::json(['task' => $existing]);
                }
            }
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();

        Response::json(['task' => self::load($id, $accountId)], 201);
    }

    /**
     * PATCH /api/tasks/{id} — any subset of text, company_id, facility_id,
     * assignee_user_id, due_date, done. Only the keys present in the body are
     * touched, so ticking a task done cannot blank its other fields.
     */
    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $userId = Tenant::currentUserId();
        $scope = self::scope(Tenant::currentAccountId(), $userId);
        $id = (int) $params['id'];
        $current = self::load($id, $scope);
        if ($current === null) {
            Response::error('Úloha sa nenašla.', 404);
        }
        // An admin editing another tenant's task keeps it in that account.
        $accountId = $current['account_id'];
        $body = $req->json();

        $set = [];
        $args = [];
        if (array_key_exists('text', $body)) {
            $set[] = 'text = ?';
            $args[] = self::readText($req);
        }
        if (array_key_exists('due_date', $body)) {
            $set[] = 'due_date = ?';
            $args[] = self::readDate($body['due_date']);
        }
        if (array_key_exists('assignee_user_id', $body)) {
            $raw = $body['assignee_user_id'];
            // Keeping an assignee who has since been deactivated is fine;
            // only a change has to name an active member.
            $unchanged = $raw !== null && (int) $raw === $current['assignee_user_id'];
            $set[] = 'assignee_user_id = ?';
            $args[] = $unchanged
                ? $current['assignee_user_id']
                : self::readAssignee($raw, $accountId, $scope === null ? $userId : null);
        }
        if (array_key_exists('company_id', $body) || array_key_exists('facility_id', $body)) {
            [$companyId, $facilityId] = self::readPlace($body + [
                'company_id'  => $current['company_id'],
                'facility_id' => $current['facility_id'],
            ], $accountId);
            $set[] = 'company_id = ?';
            $args[] = $companyId;
            $set[] = 'facility_id = ?';
            $args[] = $facilityId;
        }
        if (array_key_exists('done', $body)) {
            $done = $req->jsonBool('done');
            if ($done === null) {
                Response::error('Neplatný stav úlohy.', 422);
            }
            if ($done !== $current['done']) {
                if (!$done && $current['place_archived']) {
                    Response::error('Firma alebo prevádzka úlohy je archivovaná — úlohu nemožno znovu otvoriť.', 409);
                }
                $set[] = 'done = ?';
                $args[] = $done ? 1 : 0;
                $set[] = $done ? 'done_at = NOW()' : 'done_at = NULL';
                // Reopened or ticked by hand: no longer „zrušená".
                $set[] = 'cancel_reason = NULL';
            }
        }

        if ($set !== []) {
            $args[] = $id;
            $args[] = $accountId;
            Db::pdo()->prepare(
                'UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = ? AND account_id = ?'
            )->execute($args);
        }

        Response::json(['task' => self::load($id, $accountId)]);
    }

    /** DELETE /api/tasks/{id} */
    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $scope = self::scope(Tenant::currentAccountId(), Tenant::currentUserId());
        $id = (int) $params['id'];
        $current = self::load($id, $scope);
        if ($current === null) {
            Response::error('Úloha sa nenašla.', 404);
        }
        Db::pdo()->prepare('DELETE FROM tasks WHERE id = ? AND account_id = ?')
            ->execute([$id, $current['account_id']]);
        Response::noContent();
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * The account filter for the signed-in user: their active account, or
     * null for an admin, who works across all of them.
     */
    private static function scope(int $accountId, int $userId): ?int
    {
        return Admin::isAdmin($userId) ? null : $accountId;
    }

    /**
     * @param ?int $accountId null = every account (admin scope)
     * @param list<string> $where extra conditions, ANDed
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private static function select(?int $accountId, array $where, array $args, string $order): array
    {
        // Source details only while the úkon still exists in the app: an
        // archived one can't be opened, so there is nothing to link to.
        $sql = 'SELECT t.id, t.account_id, t.text, t.company_id, c.name AS company_name,
                       t.facility_id, f.name AS facility_name,
                       t.assignee_user_id, au.fullname AS assignee_name,
                       t.due_date, t.done, t.done_at, t.cancel_reason,
                       (c.archived_at IS NOT NULL OR f.archived_at IS NOT NULL) AS place_archived,
                       t.source_inspection_id, t.source_defect_key,
                       si.type AS source_type, si.executed_on AS source_executed_on,
                       (SELECT d.number FROM documents d
                        WHERE  d.parent_type = "inspection" AND d.parent_id = si.id
                        ORDER  BY d.id ASC LIMIT 1) AS source_document_number,
                       t.created_by_user_id, cu.fullname AS created_by_name,
                       t.created_at
                FROM   tasks t
                LEFT   JOIN companies   c  ON c.id  = t.company_id
                LEFT   JOIN facilities  f  ON f.id  = t.facility_id
                LEFT   JOIN users       au ON au.id = t.assignee_user_id
                LEFT   JOIN users       cu ON cu.id = t.created_by_user_id
                LEFT   JOIN inspections si ON si.id = t.source_inspection_id
                                          AND si.account_id = t.account_id
                                          AND si.archived_at IS NULL
                WHERE  ' . ($accountId === null ? '1 = 1' : 't.account_id = ?') . '
                  AND  (t.done = 1 OR (c.archived_at IS NULL AND f.archived_at IS NULL))';
        foreach ($where as $condition) {
            $sql .= ' AND ' . $condition;
        }
        $sql .= ' ORDER BY ' . $order;

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($accountId === null ? $args : [$accountId, ...$args]);
        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    private static function load(int $id, ?int $accountId): ?array
    {
        $rows = self::select($accountId, ['t.id = ?'], [$id], 't.id');
        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private static function findBySource(int $accountId, int $inspectionId, string $key): ?array
    {
        $rows = self::select(
            $accountId,
            ['t.source_inspection_id = ?', 't.source_defect_key = ?'],
            [$inspectionId, $key],
            't.id',
        );
        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $sourceId = $row['source_inspection_id'] !== null ? (int) $row['source_inspection_id'] : null;
        return [
            'id'               => (int) $row['id'],
            'account_id'       => (int) $row['account_id'],
            'text'           => (string) $row['text'],
            'company_id'       => $row['company_id'] !== null ? (int) $row['company_id'] : null,
            'company_name'     => $row['company_name'],
            'facility_id'      => $row['facility_id'] !== null ? (int) $row['facility_id'] : null,
            'facility_name'    => $row['facility_name'],
            'assignee_user_id' => $row['assignee_user_id'] !== null ? (int) $row['assignee_user_id'] : null,
            'assignee_name'    => $row['assignee_name'],
            'due_date'         => $row['due_date'],
            'done'             => (int) $row['done'] === 1,
            'done_at'          => $row['done_at'],
            // Spec 25 — closed by archiving its firma / prevádzka, not ticked.
            'cancel_reason'    => $row['cancel_reason'],
            'place_archived'   => (int) $row['place_archived'] === 1,
            // Present only while the source úkon exists (see select()).
            'source'           => $sourceId !== null && $row['source_type'] !== null ? [
                'inspection_id'   => $sourceId,
                'defect_key'      => $row['source_defect_key'],
                'type'            => $row['source_type'],
                'executed_on'     => $row['source_executed_on'],
                'document_number' => $row['source_document_number'],
            ] : null,
            // Kept even when the úkon is gone, so the defect offer still knows
            // this nedostatok was already turned into a task.
            'source_defect_key' => $row['source_defect_key'],
            'created_by_user_id' => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            'created_by_name'  => $row['created_by_name'],
            'created_at'       => $row['created_at'],
        ];
    }

    /**
     * @param ?int $accountId null = any account (admin scope)
     * @return array{account_id: int, company_id: int, facility_id: ?int}|null
     */
    private static function loadInspection(int $id, ?int $accountId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT account_id, company_id, facility_id FROM inspections
             WHERE  id = ? AND archived_at IS NULL'
            . ($accountId === null ? '' : ' AND account_id = ?')
        );
        $stmt->execute($accountId === null ? [$id] : [$id, $accountId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── Input ────────────────────────────────────────────────────────────────

    private static function readText(Request $req): string
    {
        $text = $req->jsonString('text');
        if ($text === null || $text === '') {
            Response::error('Zadaj text úlohy.', 422);
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            Response::error('Text úlohy je príliš dlhý (najviac ' . self::MAX_TEXT . ' znakov).', 422);
        }
        return $text;
    }

    private static function readDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            Response::error('Termín nie je platný dátum.', 422);
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        if (!checkdate($m, $d, $y)) {
            Response::error('Termín nie je platný dátum.', 422);
        }
        return $raw;
    }

    /**
     * The assignee must be an active member of the task's account. An admin
     * may still assign themselves on another tenant's task — the same
     * exemption the úkon's inspector check gives them.
     */
    private static function readAssignee(mixed $raw, int $accountId, ?int $adminUserId = null): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            Response::error('Neplatný technik.', 422);
        }
        $userId = (int) $raw;
        if ($adminUserId !== null && $userId === $adminUserId) {
            return $userId;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ? AND is_active = 1'
        );
        $stmt->execute([$accountId, $userId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Technik nie je členom tímu.', 422);
        }
        return $userId;
    }

    /**
     * Firma and prevádzka, both optional. A prevádzka needs its firma. The
     * third value is the firma's account (null without one) — with a null
     * $accountId (admin scope) the firma may sit in any account.
     *
     * @param array<string, mixed> $body
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private static function readPlace(array $body, ?int $accountId): array
    {
        $companyId = self::intOrNull($body['company_id'] ?? null);
        $facilityId = self::intOrNull($body['facility_id'] ?? null);
        if ($companyId === null) {
            if ($facilityId !== null) {
                Response::error('Prevádzka sa dá vybrať len spolu s firmou.', 422);
            }
            return [null, null, null];
        }

        $stmt = Db::pdo()->prepare(
            'SELECT account_id FROM companies WHERE id = ? AND archived_at IS NULL'
            . ($accountId === null ? '' : ' AND account_id = ?')
        );
        $stmt->execute($accountId === null ? [$companyId] : [$companyId, $accountId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false) {
            Response::error('Firma sa nenašla.', 404);
        }
        $accountId = (int) $owner;
        if ($facilityId !== null) {
            $stmt = Db::pdo()->prepare(
                'SELECT 1 FROM facilities
                 WHERE  id = ? AND company_id = ? AND account_id = ? AND archived_at IS NULL'
            );
            $stmt->execute([$facilityId, $companyId, $accountId]);
            if ($stmt->fetchColumn() === false) {
                Response::error('Prevádzka sa nenašla.', 404);
            }
        }
        return [$companyId, $facilityId, $accountId];
    }

    private static function intOrNull(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        Response::error('Neplatný údaj.', 422);
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
