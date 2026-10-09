<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Audit\AuditLog;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Spec 25 — archiving and restoring a firma or a prevádzka.
 *
 * A firma is never deleted from the app: its protocols have to be kept after
 * the client leaves. Archiving only sets `archived_at` (+ an optional reason);
 * every list, the calendar, the časová os, Dnes, client notices and the
 * new-úkon pickers already leave archived rows out (Deadlines, TodayController,
 * …), while the protocols stay readable, downloadable and sendable.
 *
 * On top of the flag:
 *   - archiving closes the open úlohy of that firma / prevádzka as „zrušené"
 *     (tasks.cancel_reason, migration 055);
 *   - restoring drops the stale calendar plans of its úkony, so a termín is
 *     computed afresh from the last control and its periodicity — never from
 *     the plan made before archiving. A lehota that ran out meanwhile shows
 *     as po termíne;
 *   - both are written to the audit log („história zmien").
 *
 * Archiving a firma leaves its prevádzky as they are: the firma's flag hides
 * them, and a restore brings back exactly those that were not archived on
 * their own.
 *
 * Callers check the tenant and MemberRights first.
 */
final class ClientArchive
{
    private const MAX_REASON = 500;

    /** The optional `reason` of an archive request — trimmed, '' = none. */
    public static function readReason(Request $req): ?string
    {
        $reason = $req->jsonString('reason');
        if ($reason === null || $reason === '') {
            return null;
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            Response::error('Dôvod archivácie je príliš dlhý (najviac ' . self::MAX_REASON . ' znakov).', 422);
        }
        return $reason;
    }

    public static function archiveCompany(int $accountId, int $companyId, ?string $reason): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE companies SET archived_at = NOW(), archived_reason = ?
                 WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
            )->execute([$reason, $companyId, $accountId]);

            $tasks = $pdo->prepare(
                'UPDATE tasks SET done = 1, done_at = NOW(), cancel_reason = "company_archived"
                 WHERE  company_id = ? AND account_id = ? AND done = 0'
            );
            $tasks->execute([$companyId, $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLog::record(
            'company.archive',
            'companies',
            $companyId,
            ['archived' => false],
            ['archived' => true, 'reason' => $reason, 'tasks_cancelled' => $tasks->rowCount()],
        );
    }

    public static function restoreCompany(int $accountId, int $companyId): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE companies SET archived_at = NULL, archived_reason = NULL
                 WHERE  id = ? AND account_id = ?'
            )->execute([$companyId, $accountId]);
            $plans = self::dropPlans($accountId, 'company_id', $companyId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLog::record(
            'company.restore',
            'companies',
            $companyId,
            ['archived' => true],
            ['archived' => false, 'plans_dropped' => $plans],
        );
    }

    public static function archiveFacility(int $accountId, int $facilityId, ?string $reason): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE facilities SET archived_at = NOW(), archived_reason = ?
                 WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
            )->execute([$reason, $facilityId, $accountId]);

            $tasks = $pdo->prepare(
                'UPDATE tasks SET done = 1, done_at = NOW(), cancel_reason = "facility_archived"
                 WHERE  facility_id = ? AND account_id = ? AND done = 0'
            );
            $tasks->execute([$facilityId, $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLog::record(
            'facility.archive',
            'facilities',
            $facilityId,
            ['archived' => false],
            ['archived' => true, 'reason' => $reason, 'tasks_cancelled' => $tasks->rowCount()],
        );
    }

    public static function restoreFacility(int $accountId, int $facilityId): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE facilities SET archived_at = NULL, archived_reason = NULL
                 WHERE  id = ? AND account_id = ?'
            )->execute([$facilityId, $accountId]);
            $plans = self::dropPlans($accountId, 'facility_id', $facilityId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLog::record(
            'facility.restore',
            'facilities',
            $facilityId,
            ['archived' => true],
            ['archived' => false, 'plans_dropped' => $plans],
        );
    }

    /**
     * Deletes the calendar plans of every inspection and training under the
     * firma / prevádzka. `$column` is a fixed name from this class, never input.
     *
     * @param 'company_id'|'facility_id' $column
     */
    private static function dropPlans(int $accountId, string $column, int $id): int
    {
        $pdo = Db::pdo();
        $inspections = $pdo->prepare(
            "DELETE p FROM calendar_plans p
             JOIN   inspections i ON i.id = p.inspection_id
             WHERE  p.account_id = ? AND i.{$column} = ?"
        );
        $inspections->execute([$accountId, $id]);

        $trainings = $pdo->prepare(
            "DELETE p FROM calendar_plans p
             JOIN   trainings t ON t.id = p.training_id
             WHERE  p.account_id = ? AND t.{$column} = ?"
        );
        $trainings->execute([$accountId, $id]);

        return $inspections->rowCount() + $trainings->rowCount();
    }
}
