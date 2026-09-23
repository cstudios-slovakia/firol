<?php

declare(strict_types=1);

/**
 * Block 4 / chapter 11 — Kalendár.
 *
 *   account_users.initials / avatar_color  (11.5)
 *       Who a termín belongs to, told by a circle with initials rather than by
 *       colour — colour stays reserved for the odbor. Per membership, not per
 *       user: uniqueness is a property of the team, and one person can be in
 *       two accounts. The unique key makes "two technicians never share a
 *       colour" a database guarantee. Existing members are back-filled here in
 *       the order they joined (the earlier one keeps the plain two letters).
 *
 *   accounts.client_notice_auto / client_notice_days  (11.3)
 *       Automatic client notice — OFF after install, days ahead 7 / 14 / 30.
 *
 *   deadline_notices  (11.3, termin.oznamenie_odoslane)
 *       One row per deadline an automatic notice went out for. A deadline is
 *       identified by the úkon that defines it (chapter 1.6.1a), so the
 *       unique key on inspection_id is what makes "never sent twice" hold;
 *       when the next úkon is done it defines a new deadline, which gets its
 *       own notice in due course. Cascades with the inspection and account.
 *
 *   calendar_events.user_id  (11.5 / 11.6)
 *       Who created a vlastná udalosť, so it carries an avatar and follows the
 *       technician filter like every other termín. Existing events stay NULL —
 *       nobody recorded their author.
 *
 * Idempotent: every step checks for its own result first.
 */

use Firol\Support\TeamIdentity;

return function (PDO $pdo): void {
    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $hasColumn = static function (string $table, string $column) use ($pdo, $db): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$db, $table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $hasIndex = static function (string $table, string $index) use ($pdo, $db): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$db, $table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    };

    // ── 11.5 technician identity ────────────────────────────────────────────
    if (!$hasColumn('account_users', 'initials')) {
        $pdo->exec(
            'ALTER TABLE account_users
                 ADD COLUMN initials     VARCHAR(3) NULL DEFAULT NULL AFTER is_active,
                 ADD COLUMN avatar_color CHAR(7)    NULL DEFAULT NULL AFTER initials'
        );
    }
    if (!$hasIndex('account_users', 'uq_account_users_avatar_color')) {
        $pdo->exec(
            'ALTER TABLE account_users
                 ADD UNIQUE KEY uq_account_users_avatar_color (account_id, avatar_color)'
        );
    }

    $accounts = $pdo->query('SELECT DISTINCT account_id FROM account_users')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($accounts as $accountId) {
        TeamIdentity::ensureAll($pdo, (int) $accountId);
    }

    // ── 11.3 automatic client notice ───────────────────────────────────────
    if (!$hasColumn('accounts', 'client_notice_auto')) {
        $pdo->exec(
            'ALTER TABLE accounts
                 ADD COLUMN client_notice_auto TINYINT(1)       NOT NULL DEFAULT 0,
                 ADD COLUMN client_notice_days TINYINT UNSIGNED NOT NULL DEFAULT 14'
        );
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS deadline_notices (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id    INT UNSIGNED NOT NULL,
            inspection_id INT UNSIGNED NOT NULL,
            notice_date   DATE         NOT NULL,
            recipient     VARCHAR(191) NOT NULL,
            sent_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_deadline_notices_inspection (inspection_id),
            INDEX idx_deadline_notices_account (account_id),
            CONSTRAINT fk_deadline_notices_account    FOREIGN KEY (account_id)    REFERENCES accounts(id)    ON DELETE CASCADE,
            CONSTRAINT fk_deadline_notices_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ── 11.5 / 11.6 author of a vlastná udalosť ───────────────────────────────
    if (!$hasColumn('calendar_events', 'user_id')) {
        $pdo->exec(
            'ALTER TABLE calendar_events
                 ADD COLUMN user_id INT UNSIGNED NULL DEFAULT NULL AFTER account_id,
                 ADD CONSTRAINT fk_calendar_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
    }
};
