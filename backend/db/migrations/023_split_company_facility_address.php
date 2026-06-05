<?php

declare(strict_types=1);

/**
 * Splits the free-text `address` column on companies and facilities into
 * structured `street` / `postal_code` / `city` columns.
 *
 * Why a PHP migration: the back-fill re-uses Firol\Support\Address::parse so
 * existing rows are split with exactly the same logic the API now applies on
 * every write — no risk of a hand-rolled SQL regex diverging from runtime
 * behaviour. The UI still sends/receives a single combined "Adresa" string;
 * the controllers parse on write and re-assemble on read.
 *
 * Idempotent: ADD/DROP use IF [NOT] EXISTS and the back-fill is skipped once
 * `address` is gone, so a partial failure can be re-run safely.
 */

use Firol\Support\Address;

return function (PDO $pdo): void {
    $tables = ['companies', 'facilities'];

    // 1. Add the structured columns (nullable, like the old free-text field).
    foreach ($tables as $table) {
        $pdo->exec(
            "ALTER TABLE $table
                 ADD COLUMN IF NOT EXISTS street      VARCHAR(191) NULL AFTER name,
                 ADD COLUMN IF NOT EXISTS postal_code VARCHAR(16)  NULL AFTER street,
                 ADD COLUMN IF NOT EXISTS city        VARCHAR(128) NULL AFTER postal_code"
        );
    }

    // 2. Back-fill from the existing combined address, if it is still present.
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach ($tables as $table) {
        $colStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $colStmt->execute([$dbName, $table, 'address']);
        if ((int) $colStmt->fetchColumn() === 0) {
            continue; // already migrated
        }

        $rows = $pdo->query(
            "SELECT id, address FROM $table WHERE address IS NOT NULL AND address <> ''"
        );
        $update = $pdo->prepare(
            "UPDATE $table SET street = ?, postal_code = ?, city = ? WHERE id = ?"
        );
        foreach ($rows as $row) {
            $parts = Address::parse($row['address']);
            $update->execute([
                $parts['street'],
                $parts['postal_code'],
                $parts['city'],
                $row['id'],
            ]);
        }
    }

    // 3. Drop the now-redundant free-text column.
    foreach ($tables as $table) {
        $pdo->exec("ALTER TABLE $table DROP COLUMN IF EXISTS address");
    }
};
