<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\Periodicity;

/**
 * Calendar (change request 2.5).
 *
 * Statutory deadlines are computed on the fly from each inspection's
 * executed_on + periodicity — never stored — so they always reflect the latest
 * inspection for a facility+type. Only the two user-editable layers are
 * persisted: a planned visit date per deadline (calendar_plans) and free
 * standing custom events (calendar_events).
 */
final class CalendarController
{
    /**
     * Statutory deadlines (with any planned date) plus custom events for the
     * active account. The frontend groups deadlines per facility and buckets
     * them by nearness; nothing here is date-range filtered — the volume per
     * account is small and the calendar needs the full picture.
     *
     * Admins get deadlines and events across all accounts (no tenant filter),
     * the same rule the inspections list follows — otherwise an inspection an
     * admin can see in "Kontroly" as po termíne would be missing from both the
     * calendar and the dashboard "Termíny" block.
     */
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());

        // Latest non-superseded finalized preventive inspection per facility+type
        // defines the current deadline. Plain fire-book entries and drafts are
        // excluded (they carry no statutory cycle).
        $sql = 'SELECT i.id, i.type, i.executed_on,
                       i.periodicity_value, i.periodicity_unit,
                       i.company_id, c.name AS company_name, c.contact_email AS company_email,
                       i.facility_id, f.name AS facility_name,
                       p.planned_date
                FROM   inspections i
                JOIN   companies  c ON c.id = i.company_id
                JOIN   facilities f ON f.id = i.facility_id
                LEFT   JOIN calendar_plans p ON p.inspection_id = i.id
                WHERE  ' . ($isAdmin ? '1 = 1' : 'i.account_id = :acct') . '
                  AND  i.status = "finalized"
                  AND  i.archived_at IS NULL
                  AND  i.executed_on IS NOT NULL
                  AND  i.periodicity_value IS NOT NULL
                  AND  i.is_preventive_inspection = 1
                  AND  NOT EXISTS (
                         SELECT 1 FROM inspections s
                         WHERE  s.account_id  = i.account_id
                           AND  s.facility_id = i.facility_id
                           AND  s.type        = i.type
                           AND  s.archived_at IS NULL
                           AND  s.status      = "finalized"
                           AND  s.is_preventive_inspection = 1
                           AND  (COALESCE(s.executed_on, "1000-01-01"), s.id)
                              > (COALESCE(i.executed_on, "1000-01-01"), i.id)
                       )';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($isAdmin ? [] : ['acct' => $accountId]);

        $deadlines = array_map(static function (array $r): array {
            // Named `statutory_date` for historical reasons only — the app
            // never claims a period is statutory (chapter 5). It is simply
            // the date this úkon's own periodicity runs out on.
            $due = Periodicity::validUntil(
                (string) $r['executed_on'],
                (int) $r['periodicity_value'],
                (string) $r['periodicity_unit'],
            );
            return [
                'inspection_id' => (int) $r['id'],
                'type'          => (string) $r['type'],
                'company_id'    => (int) $r['company_id'],
                'company_name'  => (string) $r['company_name'],
                // Recipient for the "Oznámiť klientovi e-mailom" button (2.5.4).
                'company_email' => $r['company_email'] !== null && $r['company_email'] !== ''
                    ? (string) $r['company_email']
                    : null,
                'facility_id'   => (int) $r['facility_id'],
                'facility_name' => (string) $r['facility_name'],
                'statutory_date' => $due,
                'planned_date'  => $r['planned_date'] !== null ? (string) $r['planned_date'] : null,
            ];
        }, $stmt->fetchAll());

        $evStmt = Db::pdo()->prepare(
            'SELECT e.id, e.title, e.event_date, e.note,
                    e.company_id, c.name AS company_name,
                    e.facility_id, f.name AS facility_name
             FROM   calendar_events e
             LEFT   JOIN companies  c ON c.id = e.company_id
             LEFT   JOIN facilities f ON f.id = e.facility_id
             WHERE  ' . ($isAdmin ? '1 = 1' : 'e.account_id = :acct') . '
             ORDER  BY e.event_date ASC, e.id ASC'
        );
        $evStmt->execute($isAdmin ? [] : ['acct' => $accountId]);
        $events = array_map(static fn (array $r): array => self::shapeEvent($r), $evStmt->fetchAll());

        Response::json(['deadlines' => $deadlines, 'events' => $events]);
    }

    /** Set or update the planned visit date for a deadline's inspection. */
    public static function setPlan(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) ($params['inspection_id'] ?? 0);

        // The plan row belongs to the inspection's account, not to whoever is
        // planning it — an admin planning another tenant's control must not
        // file the plan under their own account.
        $scopeAccountId = self::assertInspectionInAccount(
            $inspectionId,
            $isAdmin ? null : $accountId,
        );

        $date = $req->jsonString('planned_date');
        if ($date === null || !self::isDate($date)) {
            Response::error('Invalid planned_date (expected YYYY-MM-DD)', 422);
        }

        // Upsert: one plan per inspection (unique key).
        Db::pdo()->prepare(
            'INSERT INTO calendar_plans (account_id, inspection_id, planned_date)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE planned_date = VALUES(planned_date)'
        )->execute([$scopeAccountId, $inspectionId, $date]);

        Response::json(['ok' => true]);
    }

    /** Clear the planned visit date, falling the deadline back to statutory. */
    public static function deletePlan(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $inspectionId = (int) ($params['inspection_id'] ?? 0);

        $sql = 'DELETE FROM calendar_plans WHERE inspection_id = ?';
        $args = [$inspectionId];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        Db::pdo()->prepare($sql)->execute($args);

        Response::noContent();
    }

    /** Create a free-standing custom event. */
    public static function createEvent(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        [$title, $date, $note, $companyId, $facilityId] = self::validateEventBody($req, $accountId);

        Db::pdo()->prepare(
            'INSERT INTO calendar_events (account_id, title, event_date, note, company_id, facility_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$accountId, $title, $date, $note, $companyId, $facilityId]);

        $id = (int) Db::pdo()->lastInsertId();
        Response::json(['event' => self::loadEvent($id, $accountId)], 201);
    }

    public static function updateEvent(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) ($params['id'] ?? 0);
        // Stays in its owner account when an admin edits another tenant's event.
        $scopeAccountId = self::assertEventInAccount($id, $isAdmin ? null : $accountId);

        [$title, $date, $note, $companyId, $facilityId] = self::validateEventBody($req, $scopeAccountId);

        Db::pdo()->prepare(
            'UPDATE calendar_events
                SET title = ?, event_date = ?, note = ?, company_id = ?, facility_id = ?
              WHERE id = ? AND account_id = ?'
        )->execute([$title, $date, $note, $companyId, $facilityId, $id, $scopeAccountId]);

        Response::json(['event' => self::loadEvent($id, $scopeAccountId)]);
    }

    public static function deleteEvent(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $id = (int) ($params['id'] ?? 0);

        $sql = 'DELETE FROM calendar_events WHERE id = ?';
        $args = [$id];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        Db::pdo()->prepare($sql)->execute($args);

        Response::noContent();
    }

    /**
     * `$accountId` is the account the event belongs to — the active one on
     * create, the event's own on update — so an optional company/facility is
     * always validated against the tenant that will own the row.
     *
     * @return array{0:string,1:string,2:?string,3:?int,4:?int}
     */
    private static function validateEventBody(Request $req, int $accountId): array
    {
        $title = $req->jsonString('title');
        if ($title === null || trim($title) === '') {
            Response::error('Title required', 422);
        }
        $title = mb_substr(trim($title), 0, 191);

        $date = $req->jsonString('event_date');
        if ($date === null || !self::isDate($date)) {
            Response::error('Invalid event_date (expected YYYY-MM-DD)', 422);
        }

        $note = $req->jsonString('note');
        if ($note !== null) {
            $note = trim($note);
            if ($note === '') {
                $note = null;
            } elseif (mb_strlen($note) > 2000) {
                $note = mb_substr($note, 0, 2000);
            }
        }

        // Optional company/facility must belong to the account when given.
        $companyId = $req->jsonInt('company_id');
        $facilityId = $req->jsonInt('facility_id');
        if ($companyId !== null) {
            $c = Db::pdo()->prepare('SELECT 1 FROM companies WHERE id = ? AND account_id = ?');
            $c->execute([$companyId, $accountId]);
            if ($c->fetchColumn() === false) {
                Response::error('Company not found', 422);
            }
        }
        if ($facilityId !== null) {
            $f = Db::pdo()->prepare('SELECT 1 FROM facilities WHERE id = ? AND account_id = ?');
            $f->execute([$facilityId, $accountId]);
            if ($f->fetchColumn() === false) {
                Response::error('Facility not found', 422);
            }
        }

        return [$title, $date, $note, $companyId, $facilityId];
    }

    /**
     * @param int|null $accountId Tenant to scope to; null = any account (admin).
     * @return int The inspection's own account id, to scope the write with.
     */
    private static function assertInspectionInAccount(int $inspectionId, ?int $accountId): int
    {
        $sql = 'SELECT account_id FROM inspections WHERE id = ? AND archived_at IS NULL';
        $args = [$inspectionId];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $owner = $stmt->fetchColumn();
        if ($owner === false) {
            Response::error('Inspection not found', 404);
        }
        return (int) $owner;
    }

    /**
     * @param int|null $accountId Tenant to scope to; null = any account (admin).
     * @return int The event's own account id, to scope the write with.
     */
    private static function assertEventInAccount(int $id, ?int $accountId): int
    {
        $sql = 'SELECT account_id FROM calendar_events WHERE id = ?';
        $args = [$id];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $owner = $stmt->fetchColumn();
        if ($owner === false) {
            Response::error('Event not found', 404);
        }
        return (int) $owner;
    }

    private static function loadEvent(int $id, int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT e.id, e.title, e.event_date, e.note,
                    e.company_id, c.name AS company_name,
                    e.facility_id, f.name AS facility_name
             FROM   calendar_events e
             LEFT   JOIN companies  c ON c.id = e.company_id
             LEFT   JOIN facilities f ON f.id = e.facility_id
             WHERE  e.id = ? AND e.account_id = ?'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        return $row ? self::shapeEvent($row) : [];
    }

    /** @param array<string, mixed> $r */
    private static function shapeEvent(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'title'         => (string) $r['title'],
            'event_date'    => (string) $r['event_date'],
            'note'          => $r['note'] !== null ? (string) $r['note'] : null,
            'company_id'    => $r['company_id'] !== null ? (int) $r['company_id'] : null,
            'company_name'  => $r['company_name'] !== null ? (string) $r['company_name'] : null,
            'facility_id'   => $r['facility_id'] !== null ? (int) $r['facility_id'] : null,
            'facility_name' => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
        ];
    }

    private static function isDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    }
}
