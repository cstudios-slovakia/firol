<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Auth\Admin;
use Firol\Db;

/**
 * The Profil technika that signs an úkon in a given account.
 *
 * A profile is kept per (user, account): a technician working for two
 * employers holds a different oprávnenie and signature in each. A platform
 * admin is the exception. They work inside client accounts they are not a
 * member of (InspectionController::store files the inspection under the
 * facility's account), where they never kept a profile, yet the oprávnenie is
 * personal and the same wherever they use it. So for an admin outside their
 * own accounts, every part the client account's profile leaves blank is taken
 * from the admin's home profile: each certificate together with its own
 * validity, and the signature separately. Whatever the admin did fill in
 * inside the client account still wins.
 *
 * One home for this, so the refusal on create (CertGuard) and the identity
 * printed on the protocol (DocumentController) can never disagree.
 */
final class InspectorProfiles
{
    private const COLUMNS = [
        'signature_path', 'cert_php', 'cert_oprava', 'cert_general', 'cert_bt',
        'certification_number',
        'valid_from_php', 'valid_to_php',
        'valid_from_oprava', 'valid_to_oprava',
        'valid_from_general', 'valid_to_general',
        'valid_from_bt', 'valid_to_bt',
        'valid_from', 'valid_to',
    ];

    /**
     * A certificate number and the validity that belongs to it. They move as
     * one: a number from one profile never travels with dates from another.
     */
    private const CERT_GROUPS = [
        ['cert_php', 'valid_from_php', 'valid_to_php'],
        ['cert_oprava', 'valid_from_oprava', 'valid_to_oprava'],
        ['cert_general', 'valid_from_general', 'valid_to_general'],
        ['cert_bt', 'valid_from_bt', 'valid_to_bt'],
        // Pre-split single number, still read as a last resort for PDFs.
        ['certification_number', 'valid_from', 'valid_to'],
    ];

    /**
     * The profile row (COLUMNS) of $userId for an úkon in
     * $accountId; an empty array when there is none at all.
     *
     * @return array<string, mixed>
     */
    public static function forAccount(int $userId, int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::select() . '
             FROM   inspector_profiles ip
             WHERE  ip.user_id = ? AND ip.account_id = ?'
        );
        $stmt->execute([$userId, $accountId]);
        $row = $stmt->fetch() ?: [];

        if (!self::actsAsOutsider($userId, $accountId)) {
            return $row;
        }
        $home = self::homeProfile($userId, $accountId);
        if ($home === []) {
            return $row;
        }

        foreach (self::CERT_GROUPS as $group) {
            if (!self::filled($row[$group[0]] ?? null) && self::filled($home[$group[0]] ?? null)) {
                foreach ($group as $col) {
                    $row[$col] = $home[$col] ?? null;
                }
            }
        }
        if (!self::filled($row['signature_path'] ?? null) && self::filled($home['signature_path'] ?? null)) {
            $row['signature_path'] = $home['signature_path'];
        }
        return $row;
    }

    /** A platform admin working in an account they are not a member of. */
    private static function actsAsOutsider(int $userId, int $accountId): bool
    {
        if (!Admin::isAdmin($userId)) {
            return false;
        }
        $stmt = Db::pdo()->prepare('SELECT 1 FROM account_users WHERE account_id = ? AND user_id = ?');
        $stmt->execute([$accountId, $userId]);
        return $stmt->fetchColumn() === false;
    }

    /**
     * The admin's own profile outside $accountId: the one in an account they
     * are the main user of first, else the one they kept up to date last.
     *
     * @return array<string, mixed>
     */
    private static function homeProfile(int $userId, int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::select() . '
             FROM   inspector_profiles ip
             JOIN   accounts a ON a.id = ip.account_id
             WHERE  ip.user_id = ? AND ip.account_id <> ?
             ORDER  BY (a.main_user_id = ip.user_id) DESC, ip.updated_at DESC, ip.account_id
             LIMIT  1'
        );
        $stmt->execute([$userId, $accountId]);
        return $stmt->fetch() ?: [];
    }

    private static function select(): string
    {
        return implode(', ', array_map(static fn (string $c): string => 'ip.' . $c, self::COLUMNS));
    }

    private static function filled(mixed $v): bool
    {
        return is_string($v) && trim($v) !== '';
    }
}
