<?php

declare(strict_types=1);

/**
 * Phase 4 — seeds the shipped číselník nedostatkov (27 items, spec 3.4/11.17).
 *
 * A PHP migration (like 023/047) because the texts live in
 * src/Support/data/defect_catalog.json and must be copied verbatim rather
 * than retyped into SQL. Each row keeps the JSON's id and has account_id NULL.
 *
 * Idempotent: a row whose id is already there as a shipped item is left
 * alone; an id taken by an account's own row is a hard error (cannot happen
 * when 058 and 059 run together).
 */

use Firol\Support\DefectCatalogSeed;

return function (PDO $pdo): void {
    $exists = $pdo->prepare('SELECT account_id FROM defect_catalog WHERE id = ?');
    $insert = $pdo->prepare(
        'INSERT INTO defect_catalog
            (id, account_id, title, scope, area, default_deadline, description, regulation, justification, measure)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach (DefectCatalogSeed::rows() as $row) {
        $exists->execute([$row['id']]);
        $found = $exists->fetch(PDO::FETCH_NUM);
        if ($found !== false) {
            if ($found[0] !== null) {
                throw new RuntimeException("defect_catalog id {$row['id']} is taken by an account's own item.");
            }
            continue;
        }
        $insert->execute([
            $row['id'],
            $row['title'],
            $row['scope'],
            $row['area'],
            $row['default_deadline'],
            $row['description'],
            $row['regulation'],
            $row['justification'],
            $row['measure'],
        ]);
    }
};
