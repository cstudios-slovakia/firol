<?php

declare(strict_types=1);

namespace Firol\Auth;

use Firol\Db;
use Firol\Http\Response;

/**
 * Práva členov (chapter 1.6) — the one account-wide switch that decides
 * whether members other than the main user may delete finished úkony and
 * protocols. The main user and a platform admin are never restricted.
 *
 * Bulk delete and restore are a separate, stricter rule
 * (Tenant::requireMainUser) that this switch does not loosen.
 */
final class MemberRights
{
    public const FULL       = 'plne';
    public const RESTRICTED = 'obmedzene';

    private const DENIED = 'Mazanie hotových úkonov a protokolov povoľuje hlavný používateľ účtu.';

    /** May the signed-in user delete finished records of this account? */
    public static function canDelete(int $accountId): bool
    {
        $userId = Tenant::currentUserId();
        if (Admin::isAdmin($userId)) {
            return true;
        }
        $stmt = Db::pdo()->prepare('SELECT main_user_id, member_rights FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }
        return (int) $row['main_user_id'] === $userId || $row['member_rights'] !== self::RESTRICTED;
    }

    /** Sends 403 unless canDelete(). */
    public static function requireDelete(int $accountId): void
    {
        if (!self::canDelete($accountId)) {
            Response::error(self::DENIED, 403);
        }
    }

    /**
     * Deleting an úkon: a member's own draft is always theirs to delete
     * (chapter 1.6.1); anything else follows the switch. "Own" is whoever
     * created it — or, for rows from before that was recorded, the assigned
     * technician.
     *
     * @param array<string, mixed> $row needs account_id, status, created_by_user_id
     */
    public static function requireDeleteUkon(array $row, ?int $assigneeId): void
    {
        if ($row['status'] === 'draft') {
            $userId    = Tenant::currentUserId();
            $creatorId = isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null;
            if ($creatorId === $userId || ($creatorId === null && $assigneeId === $userId)) {
                return;
            }
        }
        self::requireDelete((int) $row['account_id']);
    }
}
