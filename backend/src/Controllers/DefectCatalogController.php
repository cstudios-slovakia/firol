<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditLog;
use Firol\Auth\Csrf;
use Firol\Auth\MemberRights;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Číselník nedostatkov (spec 3.4, 6.3, Phase 4).
 *
 * Two kinds of row share the `defect_catalog` table (migration 058):
 *
 *   - shipped items (account_id NULL) — the 27 items FIROL delivers; offered to
 *     every account, read-only;
 *   - own items (account_id = the active account) — a technician's „vlastný
 *     nedostatok". Spec: global for the account, i.e. offered at every firm of
 *     it; editing or removing one changes it for all of them.
 *
 * Removing an own item is an archive (archived_at): it leaves every list, but a
 * defect already written keeps the texts copied into it. Archiving follows the
 * members' switch (MemberRights) like other deletes, except that whoever wrote
 * the item may always remove it themselves — the same exception a member's own
 * draft úkon has.
 *
 * Always scoped to the session's active account — admins included — because
 * the catalogue is part of a technician's working set, not an admin record.
 */
final class DefectCatalogController
{
    private const SCOPES = ['po', 'bo', 'ob'];
    private const DEFAULT_DEADLINE = 'do 30 dní';
    private const DEFAULT_AREA = 'Vlastné';

    private const MAX_TITLE = 255;
    private const MAX_REGULATION = 500;
    private const MAX_DEADLINE = 40;
    private const MAX_TEXT = 4000;

    /** Rows returned by the usage endpoint; the picker narrows them to its scope and shows 5. */
    private const USAGE_LIMIT = 40;

    private const COLUMNS = 'id, account_id, title, scope, area, default_deadline, description,
                             regulation, justification, measure, created_by_user_id, created_at';

    /**
     * GET /api/defect-catalog → { items: DefectCatalogItem[] }
     *
     * Shipped items first (in the delivered order), then the account's own
     * ones, oldest first. Archived items are not listed.
     */
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM   defect_catalog
             WHERE  archived_at IS NULL AND (account_id IS NULL OR account_id = ?)
             ORDER  BY account_id IS NOT NULL, id'
        );
        $stmt->execute([$accountId]);
        Response::json(['items' => array_map([self::class, 'shape'], $stmt->fetchAll())]);
    }

    /**
     * GET /api/defect-catalog/usage → { items: [{ catalog_id, count }] }
     *
     * „Najčastejšie" (spec 6.3 point 2): how often the signed-in technician
     * wrote each catalogue item into a preventívna prehliadka they created,
     * most used first. Counted from the defect items' `fields.catalog_id`
     * (Phase 7a); until such defects exist the list is simply empty. Archived
     * items and other accounts' own items never show. Scope filtering and the
     * top 5 cut are the picker's job — it knows the prehliadka's scope.
     */
    public static function usage(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();

        $stmt = Db::pdo()->prepare(
            'SELECT dc.id AS catalog_id, COUNT(*) AS cnt
             FROM   inspection_items ii
             JOIN   inspections i ON i.id = ii.inspection_id
             JOIN   defect_catalog dc
                    ON dc.id = CAST(JSON_VALUE(ii.fields, "$.catalog_id") AS UNSIGNED)
                   AND dc.archived_at IS NULL
                   AND (dc.account_id IS NULL OR dc.account_id = i.account_id)
             WHERE  i.account_id = ?
               AND  i.type = "preventivna_prehliadka"
               AND  i.archived_at IS NULL
               AND  COALESCE(i.created_by_user_id, i.inspector_user_id) = ?
               AND  JSON_VALUE(ii.fields, "$.catalog_id") REGEXP "^[0-9]+$"
             GROUP  BY dc.id
             ORDER  BY cnt DESC, dc.id
             LIMIT  ' . self::USAGE_LIMIT
        );
        $stmt->execute([$accountId, $userId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = ['catalog_id' => (int) $row['catalog_id'], 'count' => (int) $row['cnt']];
        }
        Response::json(['items' => $items]);
    }

    /** POST /api/defect-catalog — a new own item. */
    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $body = $req->json();

        $title = self::readTitle($req);
        $scope = self::readScope($body['scope'] ?? 'po');
        $area = array_key_exists('area', $body)
            ? self::readLine($req, 'area', 'Oblasť', self::MAX_TITLE)
            : self::DEFAULT_AREA;
        $deadline = array_key_exists('default_deadline', $body)
            ? self::readLine($req, 'default_deadline', 'Lehota', self::MAX_DEADLINE)
            : self::DEFAULT_DEADLINE;

        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO defect_catalog
                (account_id, title, scope, area, default_deadline, description, regulation,
                 justification, measure, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $accountId,
            $title,
            $scope,
            $area !== '' ? $area : self::DEFAULT_AREA,
            $deadline !== '' ? $deadline : self::DEFAULT_DEADLINE,
            self::readText($req, 'description', 'Popis'),
            self::readLine($req, 'regulation', 'Predpis', self::MAX_REGULATION),
            self::readText($req, 'justification', 'Odôvodnenie'),
            self::readText($req, 'measure', 'Opatrenie'),
            $userId,
        ]);

        Response::json(['item' => self::load((int) $pdo->lastInsertId(), $accountId)], 201);
    }

    /**
     * PATCH /api/defect-catalog/{id} — any subset of title, scope, description,
     * regulation, justification, measure. Only an own item can change.
     *
     * @param array<string, string> $params
     */
    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) ($params['id'] ?? 0);
        self::loadOwnOrFail($id, $accountId);
        $body = $req->json();

        $set = [];
        $args = [];
        if (array_key_exists('title', $body)) {
            $set[] = 'title = ?';
            $args[] = self::readTitle($req);
        }
        if (array_key_exists('scope', $body)) {
            $set[] = 'scope = ?';
            $args[] = self::readScope($body['scope']);
        }
        foreach (['description' => 'Popis', 'justification' => 'Odôvodnenie', 'measure' => 'Opatrenie'] as $key => $label) {
            if (array_key_exists($key, $body)) {
                $set[] = "$key = ?";
                $args[] = self::readText($req, $key, $label);
            }
        }
        if (array_key_exists('regulation', $body)) {
            $set[] = 'regulation = ?';
            $args[] = self::readLine($req, 'regulation', 'Predpis', self::MAX_REGULATION);
        }

        if ($set !== []) {
            $args[] = $id;
            $args[] = $accountId;
            Db::pdo()->prepare(
                'UPDATE defect_catalog SET ' . implode(', ', $set) . ' WHERE id = ? AND account_id = ?'
            )->execute($args);
        }

        Response::json(['item' => self::load($id, $accountId)]);
    }

    /**
     * DELETE /api/defect-catalog/{id} — archives an own item (no hard delete).
     *
     * @param array<string, string> $params
     */
    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $id = (int) ($params['id'] ?? 0);
        $row = self::loadOwnOrFail($id, $accountId);

        // Whoever wrote the item may always remove it; anyone else's follows
        // the members' switch (chapter 1.6).
        if ((int) ($row['created_by_user_id'] ?? 0) !== $userId) {
            MemberRights::requireDelete($accountId);
        }

        Db::pdo()->prepare(
            'UPDATE defect_catalog SET archived_at = NOW() WHERE id = ? AND account_id = ?'
        )->execute([$id, $accountId]);

        AuditLog::record('defect_catalog.archive', 'defect_catalog', $id, self::shape($row), null);

        Response::noContent();
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * An own, not archived item of this account. A shipped item answers 403
     * (it exists, but nobody may change it); anything else — another account's
     * item, an archived or missing one — is a plain 404.
     *
     * @return array<string, mixed>
     */
    private static function loadOwnOrFail(int $id, int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM   defect_catalog
             WHERE  id = ? AND archived_at IS NULL AND (account_id IS NULL OR account_id = ?)'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Response::error('Položka číselníka sa nenašla.', 404);
        }
        if ($row['account_id'] === null) {
            Response::error('Položku z dodaného číselníka nemožno upraviť ani odstrániť.', 403);
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    private static function load(int $id, int $accountId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM defect_catalog WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        return $row ? self::shape($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'title'            => (string) $row['title'],
            'scope'            => (string) $row['scope'],
            'area'             => (string) $row['area'],
            'default_deadline' => (string) $row['default_deadline'],
            'description'      => (string) $row['description'],
            'regulation'       => (string) $row['regulation'],
            'justification'    => (string) $row['justification'],
            'measure'          => (string) $row['measure'],
            // Own = editable / removable (a vlastný nedostatok).
            'own'              => $row['account_id'] !== null,
        ];
    }

    // ── Input ────────────────────────────────────────────────────────────────

    private static function readTitle(Request $req): string
    {
        $title = $req->jsonString('title');
        if ($title === null || $title === '') {
            Response::error('Zadajte názov nedostatku.', 422);
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            Response::error('Názov nedostatku je príliš dlhý (najviac ' . self::MAX_TITLE . ' znakov).', 422);
        }
        return $title;
    }

    private static function readScope(mixed $raw): string
    {
        if (!is_string($raw) || !in_array($raw, self::SCOPES, true)) {
            Response::error('Neplatné zaradenie nedostatku (požiarny / BOZP / oboje).', 422);
        }
        return $raw;
    }

    /** An optional single-line text; absent or empty is ''. */
    private static function readLine(Request $req, string $key, string $label, int $max): string
    {
        $value = $req->jsonString($key) ?? '';
        if (mb_strlen($value) > $max) {
            Response::error("$label: najviac $max znakov.", 422);
        }
        return $value;
    }

    /** An optional multi-line text; absent or empty is ''. */
    private static function readText(Request $req, string $key, string $label): string
    {
        $value = $req->jsonString($key) ?? '';
        if (mb_strlen($value) > self::MAX_TEXT) {
            Response::error("$label: najviac " . self::MAX_TEXT . ' znakov.', 422);
        }
        return $value;
    }
}
