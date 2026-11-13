<?php

declare(strict_types=1);

/**
 * POapp 7. 11. 2026 — a požiarna kniha entry covers one or more prevádzky.
 *
 *   inspection_facilities  one row per prevádzka a new-format entry covers,
 *                          with that prevádzka's own periodicity (spec 3, K1).
 *                          Old entries have no rows; every reader falls back to
 *                          inspections.facility_id / periodicity_*.
 *   calendar_plans,        gain facility_id: a planned date and a client notice
 *   deadline_notices       belong to one (entry, prevádzka) pair, so the unique
 *                          key becomes (inspection_id, facility_id). Existing
 *                          inspection rows take the inspection's prevádzka;
 *                          training rows keep NULL (they are keyed by training_id).
 *
 * A PHP migration because the foreign keys and CHECK constraints have no
 * IF NOT EXISTS form: each one is guarded by information_schema, so the file
 * is safe to run again on a database that already has part of it.
 */

return function (PDO $pdo): void {
    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    $has = static function (string $sql, array $args) use ($pdo): bool {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn() !== false;
    };
    $hasConstraint = static fn (string $table, string $name): bool => $has(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
        [$db, $table, $name],
    );

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS inspection_facilities (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id        INT UNSIGNED NOT NULL,
    inspection_id     INT UNSIGNED NOT NULL,
    facility_id       INT UNSIGNED NOT NULL,
    periodicity_value SMALLINT UNSIGNED NULL DEFAULT NULL,
    periodicity_unit  ENUM('den','tyzden','mesiac') NULL DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inspection_facilities (inspection_id, facility_id),
    INDEX idx_inspection_facilities_facility (facility_id, inspection_id),
    INDEX idx_inspection_facilities_account (account_id),
    CONSTRAINT fk_inspection_facilities_account    FOREIGN KEY (account_id)    REFERENCES accounts(id)    ON DELETE CASCADE,
    CONSTRAINT fk_inspection_facilities_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    CONSTRAINT fk_inspection_facilities_facility   FOREIGN KEY (facility_id)   REFERENCES facilities(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $tables = [
        'calendar_plans'   => ['old' => 'uq_calendar_plan_inspection',    'new' => 'uq_calendar_plan_inspection_facility'],
        'deadline_notices' => ['old' => 'uq_deadline_notices_inspection', 'new' => 'uq_deadline_notices_inspection_facility'],
    ];
    foreach ($tables as $table => $keys) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN IF NOT EXISTS facility_id INT UNSIGNED NULL DEFAULT NULL AFTER inspection_id");
        $pdo->exec(
            "UPDATE $table t JOIN inspections i ON i.id = t.inspection_id
             SET t.facility_id = i.facility_id
             WHERE t.facility_id IS NULL AND t.inspection_id IS NOT NULL"
        );
        // The new key starts with inspection_id, so the inspection foreign key
        // keeps an index once the old single-column key is gone.
        $pdo->exec("ALTER TABLE $table ADD UNIQUE KEY IF NOT EXISTS {$keys['new']} (inspection_id, facility_id)");
        $pdo->exec("ALTER TABLE $table DROP INDEX IF EXISTS {$keys['old']}");

        $fk = "fk_{$table}_facility";
        if (!$hasConstraint($table, $fk)) {
            $pdo->exec(
                "ALTER TABLE $table ADD CONSTRAINT $fk
                 FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE"
            );
        }
        $chk = "chk_{$table}_inspection_facility";
        if (!$hasConstraint($table, $chk)) {
            $pdo->exec(
                "ALTER TABLE $table ADD CONSTRAINT $chk
                 CHECK (inspection_id IS NULL OR facility_id IS NOT NULL)"
            );
        }
    }
};
