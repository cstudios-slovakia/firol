<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Support\PersonList;

/**
 * The person list of a dychová skúška, kontrola omamných látok or
 * oboznámenie BOZP — chapters 7, 8 and 8.1.
 *
 *   GET  /api/inspections/{id}/person-sources   where names could be taken from
 *   POST /api/inspections/{id}/persons/take-over take them
 *   POST /api/inspections/{id}/persons/bulk      add many at once (oboznámenie BOZP)
 *   POST /api/inspections/{id}/fill-results      reopen a test printed blank
 *
 * „Zoznam osôb sa dá prevziať z predchádzajúcej skúšky alebo zo školenia tej
 * istej firmy." The source is the COMPANY, not the prevádzka: people move
 * between a firm's sites, and the test at the warehouse is taken by the same
 * shift that was trained at the head office. Only names and pracovné
 * zaradenie travel (and the osobné číslo of an oboznámenie) — never a result,
 * a time, a value or a signature.
 */
final class PersonListController
{
    /** How many alternative sources the picker offers. */
    private const SOURCE_LIMIT = 8;

    /** Spec 8.3 — a shift of twenty is the case; this only stops a runaway paste. */
    private const BULK_LIMIT = 500;

    public static function sources(Request $req, array $params): void
    {
        $inspection = self::loadPersonInspection((int) $params['id']);
        Response::json(['sources' => self::findSources($inspection)]);
    }

    /**
     * Append the people of a source to this draft. People already on the list
     * (same name, compared case-insensitively) are skipped, so taking over
     * twice, or after
     * typing two newcomers first, never duplicates anyone.
     */
    public static function takeOver(Request $req, array $params): void
    {
        Csrf::require($req);
        $inspection = self::loadPersonInspection((int) $params['id']);
        if ($inspection['status'] !== 'draft') {
            Response::error('Kontrola je uzamknutá — zoznam osôb sa už nedá meniť.', 409);
        }

        $kind = $req->jsonString('source_kind');
        $sourceId = $req->jsonInt('source_id');
        if (!in_array($kind, ['inspection', 'training'], true) || $sourceId === null) {
            Response::error('Vyber, odkiaľ sa majú osoby prevziať.', 422);
        }

        $people = $kind === 'inspection'
            ? self::peopleFromInspection($inspection, $sourceId)
            : self::peopleFromTraining($inspection, $sourceId);
        if ($people === null) {
            Response::error('Zdroj sa nenašiel.', 404);
        }

        $type = (string) $inspection['type'];
        $pdo = Db::pdo();
        $existing = $pdo->prepare('SELECT fields FROM inspection_items WHERE inspection_id = ?');
        $existing->execute([(int) $inspection['id']]);
        $known = [];
        foreach ($existing->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $f = json_decode((string) $raw, true) ?: [];
            $known[self::nameKey((string) ($f['name'] ?? ''))] = true;
        }

        $added = 0;
        $pdo->beginTransaction();
        try {
            $max = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM inspection_items WHERE inspection_id = ?');
            $max->execute([(int) $inspection['id']]);
            $position = (int) $max->fetchColumn();
            $insert = $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
            );
            foreach ($people as $person) {
                $row = PersonList::carryRow($type, $person);
                if ($row === null) {
                    continue;
                }
                $key = self::nameKey((string) $row['name']);
                if (isset($known[$key])) {
                    continue;
                }
                $known[$key] = true;
                $insert->execute([
                    (int) $inspection['id'],
                    ++$position,
                    json_encode($row, JSON_UNESCAPED_UNICODE),
                ]);
                $added++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['added' => $added, 'skipped' => count($people) - $added], 201);
    }

    /**
     * Add many people to an oboznámenie BOZP in one request (spec 8.3
     * „Hromadné pridanie" — „aj v oboznámení BOZP"). The client has split the
     * pasted text into rows; they are inserted in one transaction, so a paste
     * is never half-applied. A row without a pracovné zaradenie is accepted —
     * the summary flags it, exactly like a taken-over row without one.
     *
     * Body: {"rows": [{"name", "position"?, "personal_number"?}, ...]}
     */
    public static function bulk(Request $req, array $params): void
    {
        Csrf::require($req);
        $inspection = self::loadPersonInspection((int) $params['id']);
        if ($inspection['type'] !== PersonList::SKOLENIE_BOZP) {
            Response::error('Hromadne sa dajú pridať len účastníci oboznámenia BOZP.', 422);
        }
        if ($inspection['status'] !== 'draft') {
            Response::error('Kontrola je uzamknutá — zoznam osôb sa už nedá meniť.', 409);
        }

        $rows = $req->json()['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            Response::error('Nalep aspoň jedno meno.', 422);
        }
        if (count($rows) > self::BULK_LIMIT) {
            Response::error('Naraz sa dá pridať najviac ' . self::BULK_LIMIT . ' ľudí.', 422);
        }

        $clean = [];
        foreach (array_values($rows) as $idx => $row) {
            $line = $idx + 1;
            if (!is_array($row)) {
                Response::error("Riadok $line: neplatné údaje.", 422);
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                Response::error("Riadok $line: chýba meno.", 422);
            }
            $position = trim((string) ($row['position'] ?? ''));
            $number = trim((string) ($row['personal_number'] ?? ''));
            if (mb_strlen($name) > 191 || mb_strlen($position) > 191) {
                Response::error("Riadok $line: text je príliš dlhý (najviac 191 znakov).", 422);
            }
            if (mb_strlen($number) > PersonList::MAX_PERSONAL_NUMBER) {
                Response::error("Riadok $line: osobné číslo je príliš dlhé (najviac " . PersonList::MAX_PERSONAL_NUMBER . ' znakov).', 422);
            }
            $carried = PersonList::carryRow(PersonList::SKOLENIE_BOZP, [
                'name' => $name, 'position' => $position, 'personal_number' => $number,
            ]);
            if ($carried !== null) {
                $clean[] = $carried;
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $max = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM inspection_items WHERE inspection_id = ?');
            $max->execute([(int) $inspection['id']]);
            $position = (int) $max->fetchColumn();
            $insert = $pdo->prepare(
                'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
            );
            foreach ($clean as $fields) {
                $insert->execute([
                    (int) $inspection['id'],
                    ++$position,
                    json_encode($fields, JSON_UNESCAPED_UNICODE),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['added' => count($clean)], 201);
    }

    /**
     * Chapter 8.1, step 6 of 29.3: after a test printed blank, the technician
     * may type the handwritten results into the app and issue the filled
     * record too. The test was locked when its blank form was issued; this
     * reopens it for that WITHOUT discarding the issued form — unlike
     * „Upraviť", which throws the protocol away. The filled record is then
     * issued under the same number as the next version
     * (DocumentController::generateForInspectionInternal).
     */
    public static function fillResults(Request $req, array $params): void
    {
        Csrf::require($req);
        $inspection = self::loadPersonInspection((int) $params['id']);
        if (!PersonList::isTestType((string) $inspection['type'])) {
            Response::error('Tento úkon nemá prázdny formulár.', 422);
        }
        if ($inspection['status'] !== 'finalized') {
            Response::error('Kontrola nie je uzamknutá.', 422);
        }
        $stmt = Db::pdo()->prepare(
            'SELECT number, form_variant FROM documents
             WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?
             ORDER  BY id DESC LIMIT 1'
        );
        $stmt->execute([(int) $inspection['account_id'], (int) $inspection['id']]);
        $doc = $stmt->fetch();
        if (!$doc || $doc['form_variant'] !== PersonList::VARIANT_BLANK) {
            Response::error('Výsledky sa dajú doplniť len ku skúške vytlačenej ako prázdny formulár.', 422);
        }

        Db::pdo()->prepare(
            'UPDATE inspections SET status = "draft" WHERE id = ? AND account_id = ?'
        )->execute([(int) $inspection['id'], (int) $inspection['account_id']]);

        AuditLog::record(
            'inspection.fill_results',
            'inspections',
            (int) $inspection['id'],
            ['status' => 'finalized', 'document' => $doc['number']],
            ['status' => 'draft'],
        );

        Response::json(['ok' => true, 'number' => (string) $doc['number']]);
    }

    // ------------------------------------------------------------------

    /**
     * Earlier person lists of the same company, newest first: other tests and
     * oboznámenia (any of the three types) and the attendance of trainings
     * PO. The same type sorts first — „z minulej skúšky" is the usual case.
     *
     * @param array<string, mixed> $inspection
     * @return list<array<string, mixed>>
     */
    private static function findSources(array $inspection): array
    {
        $accountId = (int) $inspection['account_id'];
        $companyId = (int) $inspection['company_id'];
        $type = (string) $inspection['type'];

        $in = implode(',', array_fill(0, count(PersonList::TYPES), '?'));
        $stmt = Db::pdo()->prepare(
            "SELECT i.id, i.type, i.executed_on, f.name AS facility_name,
                    (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = i.id) AS people
             FROM   inspections i
             JOIN   facilities f ON f.id = i.facility_id
             WHERE  i.account_id = ? AND i.company_id = ? AND i.id <> ?
               AND  i.archived_at IS NULL AND i.type IN ($in)
             HAVING people > 0
             ORDER  BY (i.type = ?) DESC, COALESCE(i.executed_on, '1000-01-01') DESC, i.id DESC
             LIMIT  " . self::SOURCE_LIMIT
        );
        $stmt->execute([$accountId, $companyId, (int) $inspection['id'], ...PersonList::TYPES, $type]);
        $sources = [];
        foreach ($stmt->fetchAll() as $r) {
            $sources[] = [
                'kind'          => 'inspection',
                'id'            => (int) $r['id'],
                'type'          => (string) $r['type'],
                'date'          => $r['executed_on'] !== null ? (string) $r['executed_on'] : null,
                'facility_name' => (string) $r['facility_name'],
                'people'        => (int) $r['people'],
            ];
        }

        $stmt = Db::pdo()->prepare(
            "SELECT t.id, t.type, t.date,
                    (SELECT COUNT(*) FROM trainees tr WHERE tr.training_id = t.id) AS people
             FROM   trainings t
             WHERE  t.account_id = ? AND t.company_id = ? AND t.archived_at IS NULL
             HAVING people > 0
             ORDER  BY COALESCE(t.date, '1000-01-01') DESC, t.id DESC
             LIMIT  " . self::SOURCE_LIMIT
        );
        $stmt->execute([$accountId, $companyId]);
        foreach ($stmt->fetchAll() as $r) {
            $sources[] = [
                'kind'          => 'training',
                'id'            => (int) $r['id'],
                'type'          => (string) $r['type'],
                'date'          => $r['date'] !== null ? (string) $r['date'] : null,
                'facility_name' => null,
                'people'        => (int) $r['people'],
            ];
        }
        return $sources;
    }

    /**
     * @param array<string, mixed> $inspection
     * @return list<array<string, mixed>>|null
     */
    private static function peopleFromInspection(array $inspection, int $sourceId): ?array
    {
        $in = implode(',', array_fill(0, count(PersonList::TYPES), '?'));
        $check = Db::pdo()->prepare(
            "SELECT 1 FROM inspections
             WHERE  id = ? AND account_id = ? AND company_id = ? AND archived_at IS NULL
               AND  type IN ($in)"
        );
        $check->execute([$sourceId, (int) $inspection['account_id'], (int) $inspection['company_id'], ...PersonList::TYPES]);
        if ($check->fetchColumn() === false) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$sourceId]);
        return array_map(
            static fn ($raw): array => json_decode((string) $raw, true) ?: [],
            $stmt->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    /**
     * @param array<string, mixed> $inspection
     * @return list<array<string, mixed>>|null
     */
    private static function peopleFromTraining(array $inspection, int $sourceId): ?array
    {
        $check = Db::pdo()->prepare(
            'SELECT 1 FROM trainings
             WHERE  id = ? AND account_id = ? AND company_id = ? AND archived_at IS NULL'
        );
        $check->execute([$sourceId, (int) $inspection['account_id'], (int) $inspection['company_id']]);
        if ($check->fetchColumn() === false) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT fullname AS name, position, personal_number FROM trainees WHERE training_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sourceId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    private static function loadPersonInspection(int $id): array
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $sql = 'SELECT id, account_id, company_id, facility_id, type, status
                FROM   inspections WHERE id = ? AND archived_at IS NULL';
        $args = [$id];
        if (!$isAdmin) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Kontrola sa nenašla.', 404);
        }
        if (!PersonList::isPersonType((string) $row['type'])) {
            Response::error('Tento úkon nemá zoznam osôb.', 422);
        }
        return $row;
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }
}
