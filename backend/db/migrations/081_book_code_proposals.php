<?php

declare(strict_types=1);

/**
 * Round 6 of the fix list of 16. 11. 2026 — every firma gets its požiarna
 * kniha code stored once.
 *
 * Firms created before migration 080 have no `book_code`; the firma's detail
 * showed what the first issue would propose, computed on each read. Two such
 * firms starting with the same letters both showed the same proposal
 * („VER (navrhnutý)") and the second got another code at its first issue, so
 * the screen promised a number the entry did not get. The proposal is now
 * written onto every firma without a code (bytové družstvo too, archived ones
 * included), account by account in the order of the firma's id, by the rule a
 * firma created in the app gets ({@see \Firol\Documents\BookNumber::candidates()}):
 * the first variant no other firma of the account holds and no PK-<KÓD>-n
 * number of the account uses. A name with fewer than two letters keeps no
 * code; its detail asks the user for one, as before.
 *
 * Re-runnable: only firms without a code are touched.
 */

use Firol\Documents\BookNumber;

return function (PDO $pdo): void {
    $firms = $pdo->query(
        'SELECT id, account_id, name FROM companies WHERE book_code IS NULL ORDER BY account_id, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($firms === []) {
        return;
    }

    $taken = [];
    $codes = $pdo->query('SELECT account_id, book_code FROM companies WHERE book_code IS NOT NULL');
    foreach ($codes->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $taken[(int) $r['account_id']][(string) $r['book_code']] = true;
    }
    $numbers = $pdo->query(
        "SELECT account_id, book_number AS n FROM inspections WHERE book_number LIKE 'PK-%'
         UNION
         SELECT account_id, number AS n FROM documents WHERE number LIKE 'PK-%'"
    );
    foreach ($numbers->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (preg_match('/^PK-([A-Z0-9]{2,4})-\d+$/', (string) $r['n'], $m) === 1 && preg_match('/[A-Z]/', $m[1]) === 1) {
            $taken[(int) $r['account_id']][$m[1]] = true;
        }
    }

    $update = $pdo->prepare('UPDATE companies SET book_code = ? WHERE id = ? AND book_code IS NULL');
    foreach ($firms as $f) {
        $accountId = (int) $f['account_id'];
        foreach (BookNumber::candidates((string) $f['name']) as $code) {
            if (isset($taken[$accountId][$code])) {
                continue;
            }
            $update->execute([$code, (int) $f['id']]);
            $taken[$accountId][$code] = true;
            break;
        }
    }
};
