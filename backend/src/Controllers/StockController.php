<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Auth\Csrf;
use Firol\Auth\MemberRights;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Documents\NumberAllocator;
use Firol\Http\Request;
use Firol\Http\Response;
use Firol\Pdf\ActLabels;
use Firol\Pdf\PdfRenderer;
use Firol\Storage\Storage;
use Firol\Support\Address;
use Firol\Support\Contractor;
use Firol\Support\Stock;
use Firol\Support\StockException;
use PDO;

/**
 * Sklad — materiál a značenie, and the výdajka (block 4 / chapter 21).
 *
 * Positions (Položky: a column for Sklad, one per technician, and Spolu), the
 * movements journal (Pohyby), and the výdajka issued from a použitie at a
 * client. The balance rules themselves live in {@see Stock}.
 *
 * The journal is read-only by design: there is no route that edits or
 * deletes a movement. `billing()` only flips the invoice flags of a použitie
 * („Pridať na faktúru" and its vyfakturované check-off) — what the technician
 * did about the invoice, not what happened in the sklad.
 *
 * Every write needs the server to check the holder's balance against every
 * other move in flight, so none of these requests are queued offline; the
 * app says so plainly instead.
 */
final class StockController
{
    // ── Položky ──────────────────────────────────────────────────────────────

    /** Items with the balance of every holder, plus the technician columns. */
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        Response::json([
            'holders' => Stock::technicianHolders($accountId),
            'items'   => self::items($accountId),
        ]);
    }

    public static function storeItem(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $name = $req->jsonString('name');
        if ($name === null || $name === '') {
            Response::error('Zadaj názov položky.', 422);
        }
        if (mb_strlen($name) > 191) {
            Response::error('Názov položky je príliš dlhý.', 422);
        }
        $unit = $req->jsonString('unit') ?? 'ks';
        if (!in_array($unit, Stock::UNITS, true)) {
            Response::error('Jednotka musí byť ks, bal alebo m.', 422);
        }

        $dup = Db::pdo()->prepare('SELECT 1 FROM stock_items WHERE account_id = ? AND name = ?');
        $dup->execute([$accountId, $name]);
        if ($dup->fetchColumn() !== false) {
            Response::error('Položka s týmto názvom už v sklade je.', 409);
        }

        Db::pdo()->prepare(
            'INSERT INTO stock_items (account_id, name, unit, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$accountId, $name, $unit, Tenant::currentUserId(), date('Y-m-d H:i:s')]);
        $id = (int) Db::pdo()->lastInsertId();

        Response::json(['item' => self::items($accountId, $id)[0] ?? null], 201);
    }

    /**
     * Rename a položka and/or change its unit. Balances and journal rows hang
     * on the item id, so nothing else moves.
     *
     * @param array<string, string> $params
     */
    public static function updateItem(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) ($params['id'] ?? 0);

        $name = $req->jsonString('name');
        if ($name === null || $name === '') {
            Response::error('Zadaj názov položky.', 422);
        }
        if (mb_strlen($name) > 191) {
            Response::error('Názov položky je príliš dlhý.', 422);
        }
        $unit = $req->jsonString('unit') ?? 'ks';
        if (!in_array($unit, Stock::UNITS, true)) {
            Response::error('Jednotka musí byť ks, bal alebo m.', 422);
        }

        $pdo = Db::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM stock_items WHERE id = ? AND account_id = ? AND retired_at IS NULL');
        $exists->execute([$id, $accountId]);
        if ($exists->fetchColumn() === false) {
            Response::error('Položka sa nenašla.', 404);
        }

        $dup = $pdo->prepare('SELECT 1 FROM stock_items WHERE account_id = ? AND name = ? AND id <> ?');
        $dup->execute([$accountId, $name, $id]);
        if ($dup->fetchColumn() !== false) {
            Response::error('Položka s týmto názvom už v sklade je.', 409);
        }

        $pdo->prepare('UPDATE stock_items SET name = ?, unit = ? WHERE id = ? AND account_id = ?')
            ->execute([$name, $unit, $id, $accountId]);

        Response::json(['item' => self::items($accountId, $id)[0] ?? null]);
    }

    /**
     * Plain delete of a položka. Refused (409) once any of its movements sits
     * on a výdajka — the issued document lists that material and must keep
     * saying so. Otherwise the item goes together with its balances and its
     * journal rows (the schema cascades both); the journal of an item that
     * never reached a client has nothing else pointing at it.
     *
     * Deleting follows the members' switch (chapter 1.6) like every other
     * delete. The refusal carries `code: stock_item_used` so the app can offer
     * {@see retireItem} instead.
     *
     * @param array<string, string> $params
     */
    public static function deleteItem(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        MemberRights::requireDelete($accountId);
        $id = (int) ($params['id'] ?? 0);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Row lock: a použitie being issued right now can't slip in
            // between the check below and the delete.
            $item = $pdo->prepare('SELECT id FROM stock_items WHERE id = ? AND account_id = ? FOR UPDATE');
            $item->execute([$id, $accountId]);
            if ($item->fetchColumn() === false) {
                $pdo->rollBack();
                Response::error('Položka sa nenašla.', 404);
            }

            $used = $pdo->prepare(
                'SELECT 1 FROM stock_movements WHERE item_id = ? AND account_id = ? AND issue_id IS NOT NULL LIMIT 1'
            );
            $used->execute([$id, $accountId]);
            if ($used->fetchColumn() !== false) {
                $pdo->rollBack();
                Response::error(
                    'Položku nemožno vymazať — je už uvedená na výdajke, ktorá by inak zostala bez materiálu.',
                    409,
                    ['code' => 'stock_item_used'],
                );
            }

            $pdo->prepare('DELETE FROM stock_items WHERE id = ? AND account_id = ?')->execute([$id, $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Response::json(['deleted' => true]);
    }

    /**
     * Vyradenie zo skladu — what is offered when a položka can't be deleted
     * because a výdajka lists it. The item leaves the sklad (no longer in the
     * Položky list, no new nákup / presun / použitie) but its movements and
     * the issued documents keep naming it. Same right as deleting.
     *
     * @param array<string, string> $params
     */
    public static function retireItem(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        MemberRights::requireDelete($accountId);
        $id = (int) ($params['id'] ?? 0);

        $stmt = Db::pdo()->prepare(
            'UPDATE stock_items SET retired_at = ? WHERE id = ? AND account_id = ? AND retired_at IS NULL'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id, $accountId]);
        if ($stmt->rowCount() === 0) {
            Response::error('Položka sa nenašla.', 404);
        }

        Response::json(['retired' => true]);
    }

    // ── Pohyby ───────────────────────────────────────────────────────────────

    /**
     * The journal, newest first. Filters: `item_id`, `company_id`,
     * `na_fakturu=1` (použitia added to an invoice and not yet checked off).
     */
    public static function movements(Request $req): void
    {
        $accountId = Tenant::currentAccountId();

        $where = ['m.account_id = ?'];
        $args = [$accountId];
        if (($itemId = self::queryInt($req, 'item_id')) !== null) {
            $where[] = 'm.item_id = ?';
            $args[] = $itemId;
        }
        if (($companyId = self::queryInt($req, 'company_id')) !== null) {
            $where[] = 'm.company_id = ?';
            $args[] = $companyId;
        }
        if ($req->query('na_fakturu') === '1') {
            $where[] = 'm.to_invoice = 1 AND m.invoiced = 0';
        }
        $limit = min(500, max(1, self::queryInt($req, 'limit') ?? 200));

        Response::json(['items' => self::loadMovements($where, $args, $limit)]);
    }

    /**
     * Nákup · Presun · Použité. Body: item_id, action, from, to (each "sklad"
     * or a technician's user id), qty, and for a použitie optionally
     * company_id, inspection_id, note.
     */
    public static function storeMovement(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $body = $req->json();

        $itemId = $req->jsonInt('item_id');
        $qty = $req->jsonInt('qty');
        if ($itemId === null) {
            Response::error('Vyber položku.', 422);
        }
        if ($qty === null) {
            Response::error('Zadaj počet väčší ako 0.', 422);
        }

        $retired = Db::pdo()->prepare('SELECT 1 FROM stock_items WHERE id = ? AND account_id = ? AND retired_at IS NOT NULL');
        $retired->execute([$itemId, $accountId]);
        if ($retired->fetchColumn() !== false) {
            Response::error('Položka je vyradená zo skladu.', 409);
        }

        try {
            $movementId = Stock::record(
                $accountId,
                $userId,
                $itemId,
                (string) ($body['action'] ?? ''),
                Stock::parseHolder($body['from'] ?? null),
                Stock::parseHolder($body['to'] ?? null),
                $qty,
                $req->jsonInt('company_id'),
                $req->jsonInt('inspection_id'),
                $req->jsonString('note'),
            );
        } catch (StockException $e) {
            Response::error($e->getMessage(), $e->status());
        }

        $movement = self::loadMovements(['m.account_id = ?', 'm.id = ?'], [$accountId, $movementId], 1)[0];
        Response::json([
            'movement' => $movement,
            'item'     => self::items($accountId, $itemId)[0] ?? null,
        ], 201);
    }

    /**
     * „Pridať na faktúru" and the vyfakturované check-off of one použitie.
     * Body: to_invoice?: bool, invoiced?: bool. Nothing else about the
     * movement can change.
     */
    public static function billing(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];

        $stmt = Db::pdo()->prepare(
            'SELECT action, company_id, to_invoice, invoiced FROM stock_movements WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Pohyb sa nenašiel.', 404);
        }
        if ($row['action'] !== 'pouzite' || $row['company_id'] === null) {
            Response::error('Na faktúru sa dá pridať len použitý materiál pri firme.', 422);
        }

        $toInvoice = $req->jsonBool('to_invoice') ?? ((int) $row['to_invoice'] === 1);
        $invoiced = $req->jsonBool('invoiced') ?? ((int) $row['invoiced'] === 1);
        if (!$toInvoice) {
            // Off the invoice list means there is nothing to check off either.
            $invoiced = false;
        }
        $wasInvoiced = (int) $row['invoiced'] === 1;

        Db::pdo()->prepare(
            'UPDATE stock_movements
             SET    to_invoice = ?, invoiced = ?,
                    invoiced_at = CASE WHEN ? = 0 THEN NULL WHEN ? = 1 THEN invoiced_at ELSE ? END
             WHERE  id = ? AND account_id = ?'
        )->execute([
            $toInvoice ? 1 : 0,
            $invoiced ? 1 : 0,
            $invoiced ? 1 : 0,
            $wasInvoiced ? 1 : 0,
            date('Y-m-d'),
            $id,
            $accountId,
        ]);

        Response::json([
            'movement' => self::loadMovements(['m.account_id = ?', 'm.id = ?'], [$accountId, $id], 1)[0],
        ]);
    }

    // ── Výdajka ──────────────────────────────────────────────────────────────

    /**
     * Použitia that can go on one výdajka together with movement {id}: same
     * client, same day, not on a výdajka yet. The one asked about is first.
     */
    public static function issuable(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $id = (int) $params['id'];

        $stmt = Db::pdo()->prepare(
            'SELECT company_id, DATE(created_at) AS day, action, issue_id
             FROM   stock_movements WHERE id = ? AND account_id = ?'
        );
        $stmt->execute([$id, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Pohyb sa nenašiel.', 404);
        }
        if ($row['action'] !== 'pouzite' || $row['company_id'] === null) {
            Response::error('Výdajka sa vystavuje len na použitý materiál pri firme.', 422);
        }
        if ($row['issue_id'] !== null) {
            Response::error('Tento materiál už na výdajke je.', 409);
        }

        $items = self::loadMovements(
            [
                'm.account_id = ?', 'm.action = "pouzite"', 'm.company_id = ?',
                'm.issue_id IS NULL', 'm.created_at >= ?', 'm.created_at < ? + INTERVAL 1 DAY',
            ],
            [$accountId, (int) $row['company_id'], $row['day'] . ' 00:00:00', $row['day']],
            100,
        );
        usort($items, static fn (array $a, array $b): int => ($b['id'] === $id) <=> ($a['id'] === $id) ?: $a['id'] <=> $b['id']);

        Response::json(['items' => $items]);
    }

    /**
     * Issue a výdajka (VYD-RRRR-NNN) for the given použitia and render its PDF.
     * They must all be for one client and from one day — that day is the date
     * printed on the document.
     */
    public static function storeIssue(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $userId = Tenant::currentUserId();
        $ids = self::readIds($req->json()['movement_ids'] ?? null);
        if ($ids === []) {
            Response::error('Vyber materiál, ktorý má byť na výdajke.', 422);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, action, company_id, inspection_id, issue_id, from_holder, from_user_id,
                        DATE(created_at) AS day
                 FROM   stock_movements
                 WHERE  account_id = ? AND id IN ($placeholders)
                 ORDER  BY id ASC
                 FOR UPDATE"
            );
            $stmt->execute(array_merge([$accountId], $ids));
            $rows = $stmt->fetchAll();
            if (count($rows) !== count($ids)) {
                throw new StockException('Niektorý z pohybov sa nenašiel.', 404);
            }

            $companyId = null;
            $day = null;
            $inspectionIds = [];
            foreach ($rows as $r) {
                if ($r['action'] !== 'pouzite' || $r['company_id'] === null) {
                    throw new StockException('Výdajka sa vystavuje len na použitý materiál pri firme.');
                }
                if ($r['issue_id'] !== null) {
                    throw new StockException('Časť materiálu už na inej výdajke je.', 409);
                }
                $companyId ??= (int) $r['company_id'];
                $day ??= (string) $r['day'];
                if ((int) $r['company_id'] !== $companyId || (string) $r['day'] !== $day) {
                    throw new StockException('Na jednej výdajke môže byť len materiál pre jednu firmu z jedného dňa.');
                }
                if ($r['inspection_id'] !== null) {
                    $inspectionIds[(int) $r['inspection_id']] = true;
                }
            }

            // One úkon behind all of it → the výdajka is tied to it and its
            // prevádzka. Several (or none) → no single prevádzka to name.
            $inspectionId = count($inspectionIds) === 1 ? (int) array_key_first($inspectionIds) : null;
            $facilityId = null;
            if ($inspectionId !== null) {
                $f = $pdo->prepare('SELECT facility_id FROM inspections WHERE id = ? AND account_id = ?');
                $f->execute([$inspectionId, $accountId]);
                $facilityId = ($v = $f->fetchColumn()) !== false ? (int) $v : null;
            }

            // Vydal: the technician the material came from; from Sklad, the
            // person issuing the výdajka. Frozen with their certificate.
            $first = $rows[0];
            $issuerId = $first['from_holder'] === 'technik' && $first['from_user_id'] !== null
                ? (int) $first['from_user_id']
                : $userId;
            [$issuerName, $issuerCert] = self::issuer($accountId, $issuerId);

            $pdo->prepare(
                'INSERT INTO stock_issues
                    (account_id, company_id, facility_id, inspection_id, issued_on,
                     issuer_user_id, issuer_name, issuer_cert, contractor,
                     created_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId, $companyId, $facilityId, $inspectionId, $day,
                $issuerId, $issuerName, $issuerCert,
                json_encode(Contractor::live($accountId, 'vydajka'), JSON_UNESCAPED_UNICODE),
                $userId, date('Y-m-d H:i:s'),
            ]);
            $issueId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "UPDATE stock_movements SET issue_id = ? WHERE account_id = ? AND id IN ($placeholders)"
            )->execute(array_merge([$issueId, $accountId], $ids));

            $year = (int) substr((string) $day, 0, 4);
            $allocated = NumberAllocator::allocate($accountId, 'vydajka', $year);

            $payload = self::issuePayload($accountId, $issueId);
            $payload['number'] = $allocated['number'];
            $bytes = PdfRenderer::renderStockIssue($payload);

            $relPath = Storage::documentRelative($accountId, $year, $allocated['number']);
            $absPath = Storage::documentAbsolute($relPath);
            Storage::ensureDir(dirname($absPath));
            if (file_put_contents($absPath, $bytes) === false) {
                throw new \RuntimeException('Failed to write PDF to storage.');
            }

            $pdo->prepare(
                'INSERT INTO documents
                    (account_id, parent_type, parent_id, type, number, include_photos,
                     file_path, signed, signed_at)
                 VALUES (?, "stock_issue", ?, "vydajka", ?, 0, ?, 1, NOW())'
            )->execute([$accountId, $issueId, $allocated['number'], $relPath]);
            $documentId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO document_versions (document_id, version, file_path) VALUES (?, 1, ?)')
                ->execute([$documentId, $relPath]);

            $pdo->commit();
        } catch (StockException $e) {
            $pdo->rollBack();
            Response::error($e->getMessage(), $e->status());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($absPath) && is_file($absPath) && !@unlink($absPath)) {
                error_log('[stock-issue] orphan PDF cleanup failed: ' . $absPath);
            }
            error_log('[stock-issue] ' . $e::class . ': ' . $e->getMessage());
            Response::error('Výdajku sa nepodarilo vystaviť.', 500);
        }

        Response::json([
            'issue'    => self::loadIssues($accountId, ['si.id = ?'], [$issueId])[0] ?? null,
            'document' => [
                'id'           => $documentId,
                'number'       => $allocated['number'],
                'download_url' => '/api/documents/' . $documentId . '/download',
            ],
        ], 201);
    }

    /** Výdajky, newest first; `company_id` narrows to one client. */
    public static function issues(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $where = [];
        $args = [];
        if (($companyId = self::queryInt($req, 'company_id')) !== null) {
            $where[] = 'si.company_id = ?';
            $args[] = $companyId;
        }
        Response::json(['items' => self::loadIssues($accountId, $where, $args)]);
    }

    /**
     * Renderer payload of one výdajka. Public because the document is
     * re-rendered from it when the client signs on the screen (chapter 13).
     * Everything that was printed at issue — the lines, the issuer and the
     * Zhotoviteľ — comes from what was stored then.
     *
     * @return array<string, mixed>
     */
    public static function issuePayload(int $accountId, int $issueId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT si.*, c.name AS company_name, c.ico AS company_ico, c.street AS company_street,
                    c.postal_code AS company_postal_code, c.city AS company_city,
                    f.name AS facility_name, f.street AS facility_street,
                    f.postal_code AS facility_postal_code, f.city AS facility_city,
                    ip.signature_path
             FROM   stock_issues si
             JOIN   companies c ON c.id = si.company_id
             LEFT   JOIN facilities f ON f.id = si.facility_id
             LEFT   JOIN inspector_profiles ip
                    ON ip.user_id = si.issuer_user_id AND ip.account_id = si.account_id
             WHERE  si.id = ? AND si.account_id = ?'
        );
        $stmt->execute([$issueId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Výdajka sa nenašla.');
        }

        $lineStmt = $pdo->prepare(
            'SELECT item_name, qty, unit, note, inspection_id
             FROM   stock_movements WHERE issue_id = ? AND account_id = ? ORDER BY id ASC'
        );
        $lineStmt->execute([$issueId, $accountId]);
        $lines = $lineStmt->fetchAll();

        $inspectionIds = array_values(array_unique(array_filter(
            array_map(static fn (array $l): ?int => $l['inspection_id'] !== null ? (int) $l['inspection_id'] : null, $lines),
        )));
        $acts = [];
        if ($inspectionIds !== []) {
            $ph = implode(',', array_fill(0, count($inspectionIds), '?'));
            $a = $pdo->prepare(
                "SELECT i.type, d.number
                 FROM   inspections i
                 LEFT   JOIN documents d ON d.parent_type = 'inspection' AND d.parent_id = i.id
                 WHERE  i.account_id = ? AND i.id IN ($ph)
                 ORDER  BY i.id ASC"
            );
            $a->execute(array_merge([$accountId], $inspectionIds));
            foreach ($a->fetchAll() as $r) {
                $acts[] = ['label' => ActLabels::for((string) $r['type']), 'document_number' => $r['number']];
            }
        }

        $acc = $pdo->prepare('SELECT logo_path FROM accounts WHERE id = ?');
        $acc->execute([$accountId]);

        $contractor = json_decode((string) ($row['contractor'] ?? ''), true);

        return [
            'brand'   => ['logo_data_uri' => self::imageDataUri($acc->fetchColumn() ?: null)],
            'company' => [
                'name'    => (string) $row['company_name'],
                'ico'     => $row['company_ico'],
                'address' => Address::format($row['company_street'], $row['company_postal_code'], $row['company_city']),
                'city'    => $row['company_city'],
            ],
            'facility' => [
                'name'    => $row['facility_name'],
                'address' => Address::format($row['facility_street'], $row['facility_postal_code'], $row['facility_city']),
                'city'    => $row['facility_city'],
            ],
            'issue'  => ['issued_on' => (string) $row['issued_on']],
            'issuer' => [
                'fullname'             => (string) $row['issuer_name'],
                'certification_number' => $row['issuer_cert'],
                'signature_data_uri'   => self::imageDataUri($row['signature_path'] ?? null),
            ],
            'contractor' => Contractor::forDocument($accountId, 'vydajka', is_array($contractor) ? $contractor : null),
            'lines' => array_map(static fn (array $l): array => [
                'name'     => (string) $l['item_name'],
                'quantity' => (int) $l['qty'] . ' ' . (string) $l['unit'],
                'note'     => $l['note'],
            ], $lines),
            'acts'     => $acts,
            'handover' => null,
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(int $accountId, ?int $onlyId = null): array
    {
        $sql = 'SELECT id, name, unit, warehouse_qty FROM stock_items WHERE account_id = ? AND retired_at IS NULL';
        $args = [$accountId];
        if ($onlyId !== null) {
            $sql .= ' AND id = ?';
            $args[] = $onlyId;
        }
        $stmt = Db::pdo()->prepare($sql . ' ORDER BY name ASC');
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $balSql = 'SELECT item_id, user_id, qty FROM stock_balances WHERE account_id = ? AND qty > 0';
        $balArgs = [$accountId];
        if ($onlyId !== null) {
            $balSql .= ' AND item_id = ?';
            $balArgs[] = $onlyId;
        }
        $bal = Db::pdo()->prepare($balSql);
        $bal->execute($balArgs);
        $byItem = [];
        foreach ($bal->fetchAll() as $b) {
            $byItem[(int) $b['item_id']][(string) (int) $b['user_id']] = (int) $b['qty'];
        }

        return array_map(static function (array $r) use ($byItem): array {
            $balances = $byItem[(int) $r['id']] ?? [];
            $warehouse = (int) $r['warehouse_qty'];
            return [
                'id'        => (int) $r['id'],
                'name'      => (string) $r['name'],
                'unit'      => (string) $r['unit'],
                'warehouse' => $warehouse,
                // user id → count; technicians holding nothing are absent.
                'balances'  => (object) $balances,
                'total'     => $warehouse + array_sum($balances),
            ];
        }, $rows);
    }

    /**
     * @param list<string> $where
     * @param list<mixed>  $args
     * @return list<array<string, mixed>>
     */
    private static function loadMovements(array $where, array $args, int $limit): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT m.*, c.name AS company_name, i.type AS inspection_type, i.executed_on AS inspection_date,
                    idoc.number AS inspection_number,
                    vd.id AS issue_document_id, vd.number AS issue_number
             FROM   stock_movements m
             LEFT   JOIN companies c   ON c.id = m.company_id
             LEFT   JOIN inspections i ON i.id = m.inspection_id
             LEFT   JOIN documents idoc ON idoc.parent_type = "inspection" AND idoc.parent_id = m.inspection_id
             LEFT   JOIN documents vd   ON vd.parent_type = "stock_issue" AND vd.parent_id = m.issue_id
             WHERE  ' . implode(' AND ', $where) . '
             ORDER  BY m.created_at DESC, m.id DESC
             LIMIT  ' . $limit
        );
        $stmt->execute($args);

        $holder = static fn (?string $kind, mixed $uid, ?string $name): ?array => $kind === null ? null : [
            'holder'  => $kind,
            'user_id' => $uid !== null ? (int) $uid : null,
            'name'    => (string) $name,
        ];

        return array_map(static fn (array $r): array => [
            'id'                => (int) $r['id'],
            'item_id'           => (int) $r['item_id'],
            'item_name'         => (string) $r['item_name'],
            'unit'              => (string) $r['unit'],
            'action'            => (string) $r['action'],
            'from'              => $holder($r['from_holder'], $r['from_user_id'], $r['from_name']),
            'to'                => $holder($r['to_holder'], $r['to_user_id'], $r['to_name']),
            'qty'               => (int) $r['qty'],
            'company_id'        => $r['company_id'] !== null ? (int) $r['company_id'] : null,
            'company_name'      => $r['company_name'],
            'inspection_id'     => $r['inspection_id'] !== null ? (int) $r['inspection_id'] : null,
            'inspection_type'   => $r['inspection_type'],
            'inspection_date'   => $r['inspection_date'],
            'inspection_number' => $r['inspection_number'],
            'note'              => $r['note'],
            'issue_id'          => $r['issue_id'] !== null ? (int) $r['issue_id'] : null,
            'issue_document_id' => $r['issue_document_id'] !== null ? (int) $r['issue_document_id'] : null,
            'issue_number'      => $r['issue_number'],
            'to_invoice'        => (int) $r['to_invoice'] === 1,
            'invoiced'          => (int) $r['invoiced'] === 1,
            'invoiced_at'       => $r['invoiced_at'],
            'created_by_name'   => (string) $r['created_by_name'],
            'created_at'        => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /**
     * @param list<string> $where
     * @param list<mixed>  $args
     * @return list<array<string, mixed>>
     */
    private static function loadIssues(int $accountId, array $where, array $args): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT si.id, si.company_id, c.name AS company_name, si.facility_id, f.name AS facility_name,
                    si.issued_on, si.issuer_name,
                    (SELECT COUNT(*) FROM stock_movements m WHERE m.issue_id = si.id) AS line_count,
                    d.id AS document_id, d.number AS document_number, d.version AS document_version,
                    h.fullname AS handover_fullname, h.role_title AS handover_role,
                    h.place AS handover_place, h.signed_on AS handover_signed_on
             FROM   stock_issues si
             JOIN   companies c ON c.id = si.company_id
             LEFT   JOIN facilities f ON f.id = si.facility_id
             LEFT   JOIN documents d ON d.parent_type = "stock_issue" AND d.parent_id = si.id
             LEFT   JOIN document_handovers h ON h.document_id = d.id
             WHERE  ' . implode(' AND ', array_merge(['si.account_id = ?'], $where)) . '
             ORDER  BY si.issued_on DESC, si.id DESC
             LIMIT  200'
        );
        $stmt->execute(array_merge([$accountId], $args));

        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'company_id'       => (int) $r['company_id'],
            'company_name'     => (string) $r['company_name'],
            'facility_id'      => $r['facility_id'] !== null ? (int) $r['facility_id'] : null,
            'facility_name'    => $r['facility_name'],
            'issued_on'        => (string) $r['issued_on'],
            'issuer_name'      => (string) $r['issuer_name'],
            'line_count'       => (int) $r['line_count'],
            'document_id'      => $r['document_id'] !== null ? (int) $r['document_id'] : null,
            'document_number'  => $r['document_number'],
            'document_version' => $r['document_version'] !== null ? (int) $r['document_version'] : null,
            'handover'         => $r['handover_fullname'] === null ? null : [
                'fullname'   => (string) $r['handover_fullname'],
                'role_title' => (string) $r['handover_role'],
                'place'      => (string) $r['handover_place'],
                'signed_on'  => (string) $r['handover_signed_on'],
            ],
        ], $stmt->fetchAll());
    }

    /**
     * Name and certificate number printed as „Vydal". The výdajka is no
     * professional document tied to one úkon type, so it prints the
     * technician's general number (technik PO), falling back to the PHP one
     * and then the BT one — the same order the potvrdenie o práci uses.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function issuer(int $accountId, int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.fullname, ip.cert_general, ip.cert_php, ip.cert_bt
             FROM   users u
             LEFT   JOIN inspector_profiles ip ON ip.user_id = u.id AND ip.account_id = ?
             WHERE  u.id = ?'
        );
        $stmt->execute([$accountId, $userId]);
        $r = $stmt->fetch() ?: [];
        $cert = null;
        foreach (['cert_general', 'cert_php', 'cert_bt'] as $col) {
            $v = trim((string) ($r[$col] ?? ''));
            if ($v !== '') {
                $cert = $v;
                break;
            }
        }
        return [(string) ($r['fullname'] ?? ''), $cert];
    }

    private static function imageDataUri(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }
        $abs = Storage::documentAbsolute($relativePath);
        if (!is_file($abs)) {
            return null;
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false) {
            return null;
        }
        $mime = str_ends_with($relativePath, '.jpg') || str_ends_with($relativePath, '.jpeg') ? 'image/jpeg' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function readIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private static function queryInt(Request $req, string $key): ?int
    {
        $value = $req->query($key);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
