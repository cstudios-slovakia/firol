<?php

declare(strict_types=1);

/**
 * Migration runner. Run with:
 *
 *     docker compose exec php php /var/www/backend/db/migrate.php
 *
 * Picks up *.sql and *.php files in db/migrations/ in alphabetical order and
 * applies any not yet recorded in the `migrations` table. A .sql file may
 * contain multiple semicolon-separated statements. A .php migration returns
 * a `callable(PDO): void` and is used when a step needs real logic (e.g.
 * back-filling derived columns) that plain SQL cannot express cleanly.
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

$files = array_merge(
    glob(__DIR__ . '/migrations/*.sql') ?: [],
    glob(__DIR__ . '/migrations/*.php') ?: []
);
// Order by basename so 023_*.php sorts after 022_*.sql regardless of glob order.
usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

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

    try {
        if (str_ends_with($name, '.php')) {
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("$name must return a callable(PDO)");
            }
            $migration($pdo);
        } else {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("could not read $file");
            }

            // Strip line comments first (otherwise a leading `-- header` block
            // makes its statement look like a comment and gets filtered out).
            // Then split on `;\n` so we can run multi-statement migrations under
            // a PDO connection that disables MULTI_STATEMENTS. Naïve split is
            // fine for our DDL — there are no `;` chars inside string literals.
            $sqlWithoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            $statements = array_filter(
                array_map('trim', preg_split('/;\s*\n/', $sqlWithoutComments) ?: []),
                static fn (string $s): bool => $s !== ''
            );
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }
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
