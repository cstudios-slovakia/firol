<?php

declare(strict_types=1);

namespace Firol\Audit;

use Firol\Auth\Tenant;
use Firol\Db;

/**
 * Append-only sink for admin-panel writes. Stores a JSON before/after
 * snapshot so we can answer "who changed this and to what" after the
 * fact. Never throws — auditing failure must not block the actual action.
 */
final class AuditLog
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        string $action,
        string $targetType,
        ?int $targetId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        try {
            $actor = Tenant::currentUserId();
        } catch (\Throwable) {
            $actor = null;
        }

        try {
            Db::pdo()->prepare(
                'INSERT INTO audit_log
                    (actor_user_id, action, target_type, target_id, before_json, after_json)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $actor,
                $action,
                $targetType,
                $targetId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('[audit-log] failed: ' . $e->getMessage());
        }
    }
}
