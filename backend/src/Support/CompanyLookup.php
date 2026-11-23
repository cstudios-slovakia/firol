<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * One firm row of the active account, for the screens that only need to know
 * that it exists, what kind of client it is and whether it is archived
 * (external terms, the prehliadka's activities, closing a prehliadka).
 */
final class CompanyLookup
{
    /**
     * The firm, or null when it is not this account's. Archived firms are
     * returned too — callers decide whether they are read-only.
     *
     * @return array{id: int, name: string, client_type: string, archived_at: ?string}|null
     */
    public static function find(int $accountId, int $companyId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, client_type, archived_at
             FROM   companies WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([$companyId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'client_type' => (string) $row['client_type'],
            'archived_at' => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
        ];
    }
}
