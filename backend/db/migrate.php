<?php

declare(strict_types=1);

/**
 * Migration runner. Run with:
 *
 *     docker compose exec php php /var/www/backend/db/migrate.php
 *
 * Picks up *.sql files in db/migrations/ in alphabetical order and applies
 * any not yet recorded in the `migrations` table. Each file may contain
 * multiple semicolon-separated statements.
 */

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

use Firol\Db;

$pdo = Db::pdo();

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(191) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$applied = array_flip(
    $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN)
);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

if (empty($files)) {
    fwrite(STDERR, "No migration files found in db/migrations/.\n");
    exit(0);
}

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    fwrite(STDOUT, "Applying $name … ");

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "could not read $file\n");
        exit(1);
    }

    // Strip line comments first (otherwise a leading `-- header` block makes
    // its statement look like a comment and gets filtered out). Then split on
    // `;\n` so we can run multi-statement migrations under a PDO connection
    // that disables MULTI_STATEMENTS. Naïve split is fine for our DDL — there
    // are no `;` chars inside string literals.
    $sqlWithoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*\n/', $sqlWithoutComments) ?: []),
        static fn (string $s): bool => $s !== ''
    );

    try {
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED\n  " . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDOUT, "ok\n");
    $count++;
}

fwrite(STDOUT, $count === 0 ? "Up to date.\n" : "Done. $count migration(s) applied.\n");
