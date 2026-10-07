<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;
use PDO;

/**
 * Termíny (chapter 4.7, 11) — computed, never stored.
 *
 * A deadline is defined by the latest finalized preventive úkon of a type at a
 * prevádzka (chapter 1.6.1a: latest `executed_on`, on a tie the latest id), and
 * falls on its executed_on + the periodicity chosen *on that úkon*. Computing
 * it on the fly means a corrected or repeated úkon moves the deadline without
 * anyone marking anything, and there is always exactly one open deadline per
 * type and prevádzka.
 *
 * Trainings (change request 6) define deadlines the same way: the latest
 * finalized, non-archived training of a chain at a firma / prevádzka, on its
 * `date` plus the periodicity stored with it. A chain is the training's kind
 * ({@see Periodicity::trainingChain()} — Vstupné and Opakované share one) per
 * prevádzka, or per whole firma when the training has no prevádzka. Same
 * states, same lookback, same-day rule; a training without a period still
 * supersedes the previous term but defines none of its own.
 *
 * Stav:
 *   planovany   the deadline has not passed yet;
 *   po_termine  its date has passed and no newer úkon has been done;
 *   splneny     a newer úkon of the same type was done at the prevádzka on a
 *               later day — kept for a year back so a month in the calendar
 *               still shows what was due there and that it got done. A
 *               same-day correction (1.6.1) does not count: it replaces the
 *               faulty úkon, it does not fulfil its deadline.
 *
 * Zdroj is always `kontrola` here ("Predpripravený termín"); vlastné udalosti
 * and the technician's own certificate dates are added by the calendar.
 *
 * Archived companies, prevádzky and úkony produce no deadlines (chapter 25:
 * after archiving "termíny prestanú chodiť").
 *
 * One deadline shape serves both sources. An inspection term has
 * `inspection_id` and `done_inspection_id`; a training term has `training_id`
 * and `done_training_id` instead (the others null), `type` `skolenie_po` so it
 * resolves to the OPP section, and `training_type` with the subtype. A term of
 * a training without a prevádzka has `facility_id` / `facility_name` null —
 * the whole firma.
 */
final class Deadlines
{
    /** How far back fulfilled deadlines are still reported. */
    public const DONE_LOOKBACK = '-12 months';

    /** `type` of every training term — the section and colour resolve from it. */
    public const TRAINING_DEADLINE_TYPE = 'skolenie_po';

    /**
     * @param int|null $accountId Tenant to compute for; null = every account
     *                            (platform admin, like the inspections list).
     * @param bool     $withDone  Include `splneny` deadlines.
     * @return list<array<string, mixed>>
     */
    public static function compute(?int $accountId, bool $withDone = true, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $doneSince = (new \DateTimeImmutable($today))->modify(self::DONE_LOOKBACK)->format('Y-m-d');

        return array_merge(
            self::inspectionDeadlines($accountId, $withDone, $today, $doneSince),
            self::trainingDeadlines($accountId, $withDone, $today, $doneSince),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function inspectionDeadlines(?int $accountId, bool $withDone, string $today, string $doneSince): array
    {
        // Every finalized preventive úkon, in chain order per prevádzka + type.
        // The chain includes úkony without a period: done later, they still
        // supersede the previous deadline (and define none of their own).
        $sql = 'SELECT i.id, i.account_id, i.type, i.executed_on,
                       i.periodicity_value, i.periodicity_unit,
                       i.inspector_user_id,
                       i.company_id, c.name AS company_name, c.contact_email AS company_email,
                       i.facility_id, f.name AS facility_name, f.city AS facility_city,
                       p.planned_date,
                       n.sent_at AS notice_sent_at,
                       u.fullname AS technician_name,
                       au.initials AS technician_initials,
                       au.avatar_color AS technician_color
                FROM   inspections i
                JOIN   companies  c ON c.id = i.company_id  AND c.archived_at IS NULL
                JOIN   facilities f ON f.id = i.facility_id AND f.archived_at IS NULL
                LEFT   JOIN calendar_plans   p ON p.inspection_id = i.id
                LEFT   JOIN deadline_notices n ON n.inspection_id = i.id
                LEFT   JOIN users u ON u.id = i.inspector_user_id
                LEFT   JOIN account_users au ON au.account_id = i.account_id AND au.user_id = i.inspector_user_id
                WHERE  ' . ($accountId === null ? '1 = 1' : 'i.account_id = :acct') . '
                  AND  i.status = "finalized"
                  AND  i.archived_at IS NULL
                  AND  i.executed_on IS NOT NULL
                  AND  i.is_preventive_inspection = 1
                ORDER  BY i.executed_on, i.id';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($accountId === null ? [] : ['acct' => $accountId]);

        $chains = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chains[$row['account_id'] . '|' . $row['facility_id'] . '|' . $row['type']][] = $row;
        }

        $out = [];
        foreach ($chains as $chain) {
            array_push($out, ...self::walkChain(
                $chain,
                $withDone,
                $doneSince,
                'done_inspection_id',
                static fn (array $r): ?array => self::shape($r, $today),
            ));
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function trainingDeadlines(?int $accountId, bool $withDone, string $today, string $doneSince): array
    {
        // `date` is aliased to `executed_on`, and the trainer to the technician,
        // so a training walks the same chain code as an inspection. A training
        // without a prevádzka belongs to the whole firma, hence the LEFT JOIN —
        // but a prevádzka that does exist must not be archived.
        $sql = 'SELECT t.id, t.account_id, t.type, t.date AS executed_on,
                       t.periodicity_value, t.periodicity_unit,
                       t.trainer_id AS inspector_user_id,
                       t.company_id, c.name AS company_name, c.contact_email AS company_email,
                       t.facility_id, f.name AS facility_name, f.city AS facility_city,
                       p.planned_date,
                       n.sent_at AS notice_sent_at,
                       u.fullname AS technician_name,
                       au.initials AS technician_initials,
                       au.avatar_color AS technician_color
                FROM   trainings t
                JOIN   companies  c ON c.id = t.company_id AND c.archived_at IS NULL
                LEFT   JOIN facilities f ON f.id = t.facility_id
                LEFT   JOIN calendar_plans   p ON p.training_id = t.id
                LEFT   JOIN deadline_notices n ON n.training_id = t.id
                LEFT   JOIN users u ON u.id = t.trainer_id
                LEFT   JOIN account_users au ON au.account_id = t.account_id AND au.user_id = t.trainer_id
                WHERE  ' . ($accountId === null ? '1 = 1' : 't.account_id = :acct') . '
                  AND  (t.facility_id IS NULL OR f.archived_at IS NULL)
                  AND  t.status = "finalized"
                  AND  t.archived_at IS NULL
                  AND  t.date IS NOT NULL
                ORDER  BY t.date, t.id';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($accountId === null ? [] : ['acct' => $accountId]);

        $chains = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['account_id'] . '|' . $row['company_id'] . '|' . ($row['facility_id'] ?? 'firma')
                . '|' . Periodicity::trainingChain((string) $row['type']);
            $chains[$key][] = $row;
        }

        $out = [];
        foreach ($chains as $chain) {
            array_push($out, ...self::walkChain(
                $chain,
                $withDone,
                $doneSince,
                'done_training_id',
                static fn (array $r): ?array => self::shapeTraining($r, $today),
            ));
        }
        return $out;
    }

    /**
     * Walk one chain of úkony (already in date, id order) and report its
     * deadlines: each úkon a later-day úkon superseded, as `splneny` (while it
     * is still inside the lookback), and the last one as the open deadline.
     * A same-day correction does not count — it replaces the faulty úkon, it
     * does not fulfil its deadline.
     *
     * @param list<array<string, mixed>>                            $chain
     * @param callable(array<string, mixed>): ?array<string, mixed> $shape
     * @return list<array<string, mixed>>
     */
    private static function walkChain(array $chain, bool $withDone, string $doneSince, string $doneKey, callable $shape): array
    {
        $out = [];
        $prev = null;
        foreach ($chain as $row) {
            if ($prev !== null && $withDone && (string) $row['executed_on'] > (string) $prev['executed_on']) {
                $d = $shape($prev);
                if ($d !== null && $d['due_date'] >= $doneSince) {
                    $d['state']   = 'splneny';
                    $d['done_on'] = (string) $row['executed_on'];
                    $d[$doneKey]  = (int) $row['id'];
                    $out[] = $d;
                }
            }
            $prev = $row;
        }
        if ($prev !== null) {
            $d = $shape($prev);
            if ($d !== null) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null Null when the úkon has no period.
     */
    private static function shape(array $r, string $today): ?array
    {
        $base = self::shapeCommon($r, $today);
        if ($base === null) {
            return null;
        }

        return array_merge($base, [
            'key'                => 'kontrola-' . (int) $r['id'],
            'inspection_id'      => (int) $r['id'],
            'training_id'        => null,
            'type'               => (string) $r['type'],
            'training_type'      => null,
            'section'            => Sections::forInspectionType((string) $r['type']),
            'facility_id'        => (int) $r['facility_id'],
            'facility_name'      => (string) $r['facility_name'],
            'done_inspection_id' => null,
            'done_training_id'   => null,
        ]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null Null when the training has no period.
     */
    private static function shapeTraining(array $r, string $today): ?array
    {
        $base = self::shapeCommon($r, $today);
        if ($base === null) {
            return null;
        }

        return array_merge($base, [
            'key'                => 'skolenie-' . (int) $r['id'],
            'inspection_id'      => null,
            'training_id'        => (int) $r['id'],
            'type'               => self::TRAINING_DEADLINE_TYPE,
            'training_type'      => (string) $r['type'],
            'section'            => Sections::forTrainingType((string) $r['type']),
            // A training without a prevádzka is the whole firma's.
            'facility_id'        => $r['facility_id'] !== null ? (int) $r['facility_id'] : null,
            'facility_name'      => $r['facility_name'] !== null ? (string) $r['facility_name'] : null,
            'done_inspection_id' => null,
            'done_training_id'   => null,
        ]);
    }

    /**
     * The fields an inspection term and a training term share. The technician
     * is the one who performed the úkon: the inspector, or the trainer.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null Null when the úkon has no period.
     */
    private static function shapeCommon(array $r, string $today): ?array
    {
        $value = $r['periodicity_value'] !== null ? (int) $r['periodicity_value'] : null;
        $unit  = $r['periodicity_unit'] !== null ? (string) $r['periodicity_unit'] : null;
        $due = Periodicity::validUntil((string) $r['executed_on'], $value, $unit);
        if ($due === null) {
            return null;
        }

        $technicianId = $r['inspector_user_id'] !== null ? (int) $r['inspector_user_id'] : null;
        $technician = null;
        if ($technicianId !== null) {
            $name = (string) ($r['technician_name'] ?? '');
            $technician = [
                'id'           => $technicianId,
                'fullname'     => $name,
                // A technician removed from the team keeps their úkony (1.6.4)
                // but no longer has a membership: derived initials, grey.
                'initials'     => $r['technician_initials'] !== null && $r['technician_initials'] !== ''
                    ? (string) $r['technician_initials']
                    : TeamIdentity::deriveInitials($name),
                'avatar_color' => $r['technician_color'] !== null && $r['technician_color'] !== ''
                    ? (string) $r['technician_color']
                    : TeamIdentity::FORMER_MEMBER_COLOR,
            ];
        }

        $email = $r['company_email'] ?? null;
        $city  = $r['facility_city'] ?? null;

        return [
            'zdroj'              => 'kontrola',
            'company_id'         => (int) $r['company_id'],
            'company_name'       => (string) $r['company_name'],
            // Recipient of the client notice (11.3); null when not filled in.
            'company_email'      => $email !== null && $email !== '' ? (string) $email : null,
            'facility_city'      => $city !== null && trim((string) $city) !== '' ? trim((string) $city) : null,
            'last_done_on'       => (string) $r['executed_on'],
            'due_date'           => $due,
            'planned_date'       => $r['planned_date'] !== null ? (string) $r['planned_date'] : null,
            'state'              => $due < $today ? 'po_termine' : 'planovany',
            'done_on'            => null,
            'technician'         => $technician,
            'notice_sent_at'     => $r['notice_sent_at'] !== null ? (string) $r['notice_sent_at'] : null,
        ];
    }
}
