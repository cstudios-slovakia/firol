<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\AccountCertificates;
use Firol\Support\Deadlines;
use Firol\Support\Sections;
use Firol\Support\TeamIdentity;

/**
 * Calendar (change request 2.5, BOZP extension chapter 11).
 *
 * Deadlines are computed on the fly ({@see Deadlines}) — never stored — so
 * they always follow the latest úkon of a type at a prevádzka. Persisted are
 * only the user-editable layers: a planned visit date per deadline
 * (calendar_plans), free-standing vlastné udalosti (calendar_events), the
 * automatic-notice settings and the record of notices sent (deadline_notices).
 *
 * Every termín carries its `zdroj` (11.2): `kontrola` for a deadline computed
 * from an úkon, `vlastny` for a vlastná udalosť, `technik` for the signed-in
 * technician's own certificate dates.
 */
final class CalendarController
{
    /** The day counts the automatic notice offers (11.3). */
    public const NOTICE_DAYS = [7, 14, 30];

    /** Personal certificates on inspector_profiles, and the odbor each serves. */
    private const PERSONAL_CERTS = [
        'po_technik'   => ['column' => 'valid_to_general', 'label' => 'Technik požiarnej ochrany',            'section' => Sections::OPP],
        'php_kontrola' => ['column' => 'valid_to_php',     'label' => 'Kontrola hasiacich prístrojov',        'section' => Sections::REVIZIE],
        'php_oprava'   => ['column' => 'valid_to_oprava',  'label' => 'Oprava, plnenie a tlaková skúška PHP', 'section' => Sections::REVIZIE],
        'bt'           => ['column' => 'valid_to_bt',      'label' => 'Bezpečnostný technik',                 'section' => Sections::BOZP],
    ];

    /**
     * Everything the calendar and the timeline show, for the active account:
     *
     *   deadlines  computed from úkony — the open ones and the fulfilled ones
     *              of the last year ({@see Deadlines} for shape and states);
     *   events     vlastné udalosti;
     *   own_terms  the signed-in technician's certificate validity dates
     *              („Tvoje termíny"), plus the firm's certificates for the main
     *              user, who is the one warned about those (1.3.1).
     *
     * Admins get deadlines and events across all accounts (no tenant filter),
     * the same rule the inspections list follows — otherwise an inspection an
     * admin can see as po termíne would be missing from the calendar.
     */
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $isAdmin = Admin::isAdmin($userId);
        $today = date('Y-m-d');

        // Members who joined through a path that assigned no avatar get one
        // before anything is drawn with it.
        TeamIdentity::ensureAll(Db::pdo(), $accountId);

        $deadlines = Deadlines::compute($isAdmin ? null : $accountId, true, $today);

        $evStmt = Db::pdo()->prepare(
            self::eventSelect() . '
             WHERE  ' . ($isAdmin ? '1 = 1' : 'e.account_id = :acct') . '
             ORDER  BY e.event_date ASC, e.time_from IS NOT NULL, e.time_from ASC, e.id ASC'
        );
        $evStmt->execute($isAdmin ? [] : ['acct' => $accountId]);
        $events = array_map(static fn (array $r): array => self::shapeEvent($r), $evStmt->fetchAll());

        Response::json([
            'deadlines' => $deadlines,
            'events'    => $events,
            'own_terms' => self::ownTerms($accountId, $userId, $today),
        ]);
    }

    /** Automatic client notice settings (11.3). */
    public static function showNoticeSettings(Request $req): void
    {
        Response::json(['settings' => self::noticeSettings(Tenant::currentAccountId())]);
    }

    /**
     * Only the main user switches the automatic notice — it sends mail to the
     * firm's clients on the whole team's behalf.
     */
    public static function updateNoticeSettings(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        if (!self::isMainUser($accountId, Tenant::currentUserId())) {
            Response::error('Automatické oznámenia môže nastaviť len hlavný používateľ.', 403);
        }

        $current = self::noticeSettings($accountId);
        $enabled = $req->jsonBool('enabled') ?? $current['enabled'];
        $days = $req->jsonInt('days_ahead') ?? $current['days_ahead'];
        if (!in_array($days, self::NOTICE_DAYS, true)) {
            Response::error('Počet dní vopred musí byť 7, 14 alebo 30.', 422);
        }

        Db::pdo()->prepare(
            'UPDATE accounts SET client_notice_auto = ?, client_notice_days = ? WHERE id = ?'
        )->execute([$enabled ? 1 : 0, $days, $accountId]);

        Response::json(['settings' => self::noticeSettings($accountId)]);
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
            Response::error('Neplatný plánovaný dátum (očakáva sa formát YYYY-MM-DD).', 422);
        }

        // Upsert: one plan per inspection (unique key).
        Db::pdo()->prepare(
            'INSERT INTO calendar_plans (account_id, inspection_id, planned_date)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE planned_date = VALUES(planned_date)'
        )->execute([$scopeAccountId, $inspectionId, $date]);

        Response::json(['ok' => true]);
    }

    /** Clear the planned visit date, falling the deadline back to its due date. */
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

    /** Create a free-standing vlastná udalosť, owned by whoever creates it. */
    public static function createEvent(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        [$title, $date, $timeFrom, $timeTo, $note, $companyId, $facilityId] = self::validateEventBody($req, $accountId);

        Db::pdo()->prepare(
            'INSERT INTO calendar_events (account_id, user_id, title, event_date, time_from, time_to, note, company_id, facility_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$accountId, Tenant::currentUserId(), $title, $date, $timeFrom, $timeTo, $note, $companyId, $facilityId]);

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

        [$title, $date, $timeFrom, $timeTo, $note, $companyId, $facilityId] = self::validateEventBody($req, $scopeAccountId);

        Db::pdo()->prepare(
            'UPDATE calendar_events
                SET title = ?, event_date = ?, time_from = ?, time_to = ?, note = ?, company_id = ?, facility_id = ?
              WHERE id = ? AND account_id = ?'
        )->execute([$title, $date, $timeFrom, $timeTo, $note, $companyId, $facilityId, $id, $scopeAccountId]);

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

    /** @return array{enabled: bool, days_ahead: int} */
    private static function noticeSettings(int $accountId): array
    {
        $stmt = Db::pdo()->prepare('SELECT client_notice_auto, client_notice_days FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch() ?: ['client_notice_auto' => 0, 'client_notice_days' => 14];
        return [
            'enabled'    => (bool) $row['client_notice_auto'],
            'days_ahead' => (int) $row['client_notice_days'],
        ];
    }

    private static function isMainUser(int $accountId, int $userId): bool
    {
        $stmt = Db::pdo()->prepare('SELECT main_user_id FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn() === $userId;
    }

    /**
     * „Tvoje termíny" — the dates the signed-in technician's own certificates
     * run out on, and for the main user also the firm's certificates. Shown in
     * the calendar apart from client deadlines (11.1).
     *
     * @return list<array<string, mixed>>
     */
    public static function ownTerms(int $accountId, int $userId, string $today): array
    {
        $out = [];

        $stmt = Db::pdo()->prepare(
            'SELECT valid_to_general, valid_to_php, valid_to_oprava, valid_to_bt
             FROM   inspector_profiles WHERE user_id = ? AND account_id = ?'
        );
        $stmt->execute([$userId, $accountId]);
        $profile = $stmt->fetch() ?: [];
        foreach (self::PERSONAL_CERTS as $code => $meta) {
            $date = $profile[$meta['column']] ?? null;
            if ($date === null || $date === '') {
                continue;
            }
            $out[] = self::ownTerm($code, (string) $date, $meta['label'], $meta['section'], 'osobne', $today);
        }

        if (self::isMainUser($accountId, $userId)) {
            foreach (AccountCertificates::forAccount($accountId) as $type => $cert) {
                if ($cert['valid_to'] === null) {
                    continue;
                }
                $out[] = self::ownTerm(
                    $type,
                    $cert['valid_to'],
                    AccountCertificates::LABELS[$type] ?? $type,
                    Sections::BOZP,
                    'firemne',
                    $today,
                );
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        return $out;
    }

    /** @return array<string, mixed> */
    private static function ownTerm(string $code, string $date, string $label, string $section, string $level, string $today): array
    {
        return [
            'key'     => 'technik-' . $code,
            'zdroj'   => 'technik',
            'code'    => $code,
            'level'   => $level,
            'title'   => 'Platnosť oprávnenia — ' . $label,
            'section' => $section,
            'date'    => $date,
            'state'   => $date < $today ? 'po_termine' : 'planovany',
        ];
    }

    /**
     * `$accountId` is the account the event belongs to — the active one on
     * create, the event's own on update — so an optional company/facility is
     * always validated against the tenant that will own the row.
     *
     * @return array{0:string,1:string,2:?string,3:?string,4:?string,5:?int,6:?int}
     */
    private static function validateEventBody(Request $req, int $accountId): array
    {
        $title = $req->jsonString('title');
        if ($title === null || trim($title) === '') {
            Response::error('Zadaj názov udalosti.', 422);
        }
        $title = mb_substr(trim($title), 0, 191);

        $date = $req->jsonString('event_date');
        if ($date === null || !self::isDate($date)) {
            Response::error('Neplatný dátum udalosti (očakáva sa formát YYYY-MM-DD).', 422);
        }

        $timeFrom = self::readTime($req, 'time_from');
        $timeTo = self::readTime($req, 'time_to');
        if ($timeTo !== null && $timeFrom === null) {
            Response::error('Zadaj čas začiatku, ak je zadaný čas konca.', 422);
        }
        if ($timeTo !== null && $timeTo < $timeFrom) {
            Response::error('Čas konca nesmie byť skôr ako čas začiatku.', 422);
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
                Response::error('Firma sa nenašla.', 422);
            }
        }
        if ($facilityId !== null) {
            $f = Db::pdo()->prepare('SELECT 1 FROM facilities WHERE id = ? AND account_id = ?');
            $f->execute([$facilityId, $accountId]);
            if ($f->fetchColumn() === false) {
                Response::error('Prevádzka sa nenašla.', 422);
            }
        }

        return [$title, $date, $timeFrom, $timeTo, $note, $companyId, $facilityId];
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
            Response::error('Kontrola sa nenašla.', 404);
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
            Response::error('Udalosť sa nenašla.', 404);
        }
        return (int) $owner;
    }

    private static function eventSelect(): string
    {
        return 'SELECT e.id, e.title, e.event_date, e.time_from, e.time_to, e.note,
                       e.company_id, c.name AS company_name,
                       e.facility_id, f.name AS facility_name, f.city AS facility_city,
                       e.user_id, u.fullname AS user_name,
                       au.initials AS user_initials, au.avatar_color AS user_color
                FROM   calendar_events e
                LEFT   JOIN companies  c ON c.id = e.company_id
                LEFT   JOIN facilities f ON f.id = e.facility_id
                LEFT   JOIN users      u ON u.id = e.user_id
                LEFT   JOIN account_users au ON au.account_id = e.account_id AND au.user_id = e.user_id';
    }

    private static function loadEvent(int $id, int $accountId): array
    {
        $stmt = Db::pdo()->prepare(self::eventSelect() . ' WHERE e.id = ? AND e.account_id = ?');
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        return $row ? self::shapeEvent($row) : [];
    }

    /** @param array<string, mixed> $r */
    private static function shapeEvent(array $r): array
    {
        $technician = null;
        if ($r['user_id'] !== null) {
            $name = (string) ($r['user_name'] ?? '');
            $technician = [
                'id'           => (int) $r['user_id'],
                'fullname'     => $name,
                'initials'     => $r['user_initials'] !== null && $r['user_initials'] !== ''
                    ? (string) $r['user_initials']
                    : TeamIdentity::deriveInitials($name),
                'avatar_color' => $r['user_color'] !== null && $r['user_color'] !== ''
                    ? (string) $r['user_color']
                    : TeamIdentity::FORMER_MEMBER_COLOR,
            ];
        }
        $city = $r['facility_city'] ?? null;

        return [
            'id'            => (int) $r['id'],
            'key'           => 'vlastny-' . (int) $r['id'],
            'zdroj'         => 'vlastny',
            'title'         => (string) $r['title'],
            'event_date'    => (string) $r['event_date'],
            'time_from'     => self::shapeTime($r['time_from'] ?? null),
            'time_to'       => self::shapeTime($r['time_to'] ?? null),
            'note'          => $r['note'] !== null ? (string) $r['note'] : null,
            'company_id'    => $r['company_id'] !== null ? (int) $r['company_id'] : null,
            'company_name'  => $r['company_name'] !== null ? (string) $r['company_name'] : null,
            'facility_id'   => $r['facility_id'] !== null ? (int) $r['facility_id'] : null,
            'facility_name' => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
            'facility_city' => $city !== null && trim((string) $city) !== '' ? trim((string) $city) : null,
            // Who created it; null for events recorded before chapter 11.
            'technician'    => $technician,
        ];
    }

    /** Optional `HH:MM` field; empty / absent = null, anything else 422. */
    private static function readTime(Request $req, string $key): ?string
    {
        $v = $req->jsonString($key);
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            Response::error('Zadaj čas vo formáte HH:MM.', 422);
        }
        return $v;
    }

    /** TIME comes back as `HH:MM:SS`; the API speaks `HH:MM`. */
    private static function shapeTime(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? substr((string) $v, 0, 5) : null;
    }

    private static function isDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    }
}
