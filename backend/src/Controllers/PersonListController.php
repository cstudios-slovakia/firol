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
 *   POST /api/inspections/{id}/fill-results      reopen a test printed blank
 *
 * „Zoznam osôb sa dá prevziať z predchádzajúcej skúšky alebo zo školenia tej
 * istej firmy." The source is the COMPANY, not the prevádzka: people move
 * between a firm's sites, and the test at the warehouse is taken by the same
 * shift that was trained at the head office. Only names and pracovné
 * zaradenie travel — never a result, a time, a value or a signature.
 */
final class PersonListController
{
    /** How many alternative sources the picker offers. */
    private const SOURCE_LIMIT = 8;

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
            'SELECT fullname AS name, position FROM trainees WHERE training_id = ? ORDER BY id ASC'
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
