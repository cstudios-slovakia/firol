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
 */
final class Deadlines
{
    /** How far back fulfilled deadlines are still reported. */
    public const DONE_LOOKBACK = '-12 months';

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
                ORDER  BY i.account_id, i.facility_id, i.type, i.executed_on, i.id';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($accountId === null ? [] : ['acct' => $accountId]);

        $out = [];
        $prev = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sameChain = $prev !== null
                && $prev['account_id'] === $row['account_id']
                && $prev['facility_id'] === $row['facility_id']
                && $prev['type'] === $row['type'];

            if ($sameChain && $withDone && (string) $row['executed_on'] > (string) $prev['executed_on']) {
                $d = self::shape($prev, $today);
                if ($d !== null && $d['due_date'] >= $doneSince) {
                    $d['state']              = 'splneny';
                    $d['done_on']            = (string) $row['executed_on'];
                    $d['done_inspection_id'] = (int) $row['id'];
                    $out[] = $d;
                }
            }
            if ($prev !== null && !$sameChain) {
                // $prev closed its chain — it is the úkon defining the deadline.
                $d = self::shape($prev, $today);
                if ($d !== null) {
                    $out[] = $d;
                }
            }
            $prev = $row;
        }
        if ($prev !== null) {
            $d = self::shape($prev, $today);
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
            'key'                => 'kontrola-' . (int) $r['id'],
            'zdroj'              => 'kontrola',
            'inspection_id'      => (int) $r['id'],
            'type'               => (string) $r['type'],
            'section'            => Sections::forInspectionType((string) $r['type']),
            'company_id'         => (int) $r['company_id'],
            'company_name'       => (string) $r['company_name'],
            // Recipient of the client notice (11.3); null when not filled in.
            'company_email'      => $email !== null && $email !== '' ? (string) $email : null,
            'facility_id'        => (int) $r['facility_id'],
            'facility_name'      => (string) $r['facility_name'],
            'facility_city'      => $city !== null && trim((string) $city) !== '' ? trim((string) $city) : null,
            'last_done_on'       => (string) $r['executed_on'],
            'due_date'           => $due,
            'planned_date'       => $r['planned_date'] !== null ? (string) $r['planned_date'] : null,
            'state'              => $due < $today ? 'po_termine' : 'planovany',
            'done_on'            => null,
            'done_inspection_id' => null,
            'technician'         => $technician,
            'notice_sent_at'     => $r['notice_sent_at'] !== null ? (string) $r['notice_sent_at'] : null,
        ];
    }
}
