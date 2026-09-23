<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;
use PDO;

/**
 * Sklad — materiál a značenie (block 4 / chapter 21).
 *
 * A count of what the firm has and who has it: „Sklad" plus every technician.
 * Not a warehouse system — no prices, batches or low-stock alerts, on purpose.
 *
 * Every change of a balance goes through {@see record()}, which updates both
 * sides of the move and appends the journal row in ONE transaction, holding
 * the item row lock (SELECT … FOR UPDATE) for its whole length. Two
 * technicians moving the same item at the same moment are therefore
 * serialised: the second one sees the balance the first one left, and a move
 * that would overdraw a holder is refused instead of going negative. That is
 * what keeps „súčet stavov všetkých držiteľov = celkové množstvo" true.
 *
 * The journal (stock_movements) is append-only. Nothing in the app edits or
 * deletes a row; the only columns that change afterwards are the billing
 * flags of a použitie („Pridať na faktúru" / vyfakturované), which describe
 * what the technician did with the invoice, not what happened in the sklad.
 */
final class Stock
{
    public const UNITS = ['ks', 'bal', 'm'];
    public const ACTIONS = ['nakup', 'presun', 'pouzite'];

    /** Holder label for the firm's own stock (texty_ui.json → sklad.sklad). */
    public const WAREHOUSE_LABEL = 'Sklad';

    /** Upper bound of one movement — a typo guard, not a business rule. */
    public const MAX_QTY = 1000000;

    /**
     * Records one movement and applies it to the balances.
     *
     * `$from` / `$to` are holders: ['holder' => 'sklad'] or
     * ['holder' => 'technik', 'user_id' => int], or null for „no side".
     *
     * @param array{holder: string, user_id?: int|null}|null $from
     * @param array{holder: string, user_id?: int|null}|null $to
     * @return int id of the new movement
     * @throws StockException with a Slovak, user-facing message
     */
    public static function record(
        int $accountId,
        int $actorUserId,
        int $itemId,
        string $action,
        ?array $from,
        ?array $to,
        int $qty,
        ?int $companyId = null,
        ?int $inspectionId = null,
        ?string $note = null,
    ): int {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new StockException('Neznámy druh pohybu.');
        }
        if ($qty < 1) {
            throw new StockException('Zadaj počet väčší ako 0.');
        }
        if ($qty > self::MAX_QTY) {
            throw new StockException('Počet je príliš veľký.');
        }

        // Which sides a movement has is fixed by its kind (pohyb_skladu: z /
        // komu). A nákup comes from nowhere, a použitie goes nowhere.
        switch ($action) {
            case 'nakup':
                $from = null;
                $to ??= ['holder' => 'sklad'];
                break;
            case 'presun':
                if ($from === null || $to === null) {
                    throw new StockException('Vyber, odkiaľ a kam sa materiál presúva.');
                }
                if (self::sameHolder($from, $to)) {
                    throw new StockException('Presun musí ísť k inému držiteľovi.');
                }
                break;
            case 'pouzite':
                if ($from === null) {
                    throw new StockException('Vyber, kto materiál vydáva.');
                }
                $to = null;
                break;
        }

        // The firm and úkon only belong to a použitie (pohyb_skladu.firma_id
        // „pri akcii pouzite").
        if ($action !== 'pouzite') {
            $companyId = null;
            $inspectionId = null;
        }
        if ($inspectionId !== null && $companyId === null) {
            throw new StockException('Úkon sa dá priradiť len spolu s firmou.');
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new StockException('Poznámka môže mať najviac 500 znakov.');
        }

        $pdo = Db::pdo();
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            $pdo->beginTransaction();
        }

        try {
            // The item row is the lock every movement of this item queues on.
            $itemStmt = $pdo->prepare(
                'SELECT id, name, unit, warehouse_qty FROM stock_items
                 WHERE  id = ? AND account_id = ? FOR UPDATE'
            );
            $itemStmt->execute([$itemId, $accountId]);
            $item = $itemStmt->fetch();
            if (!$item) {
                throw new StockException('Položka sa nenašla.', 404);
            }
            $unit = (string) $item['unit'];

            if ($companyId !== null) {
                self::assertCompany($pdo, $accountId, $companyId);
            }
            if ($inspectionId !== null) {
                self::assertInspection($pdo, $accountId, (int) $companyId, $inspectionId);
            }

            $fromName = null;
            if ($from !== null) {
                $fromName = self::holderName($pdo, $accountId, $from, false);
                $available = self::balance($pdo, $itemId, (int) $item['warehouse_qty'], $from);
                if ($qty > $available) {
                    // texty_ui.json → sklad.nedostatok_zasob. The spec prints
                    // „ks"; the item's own unit is used so a roll of tape
                    // reads „(12 m)" rather than „(12 ks)".
                    throw new StockException(sprintf(
                        'Na %s toľko nie je (%d %s)',
                        $fromName,
                        $available,
                        $unit,
                    ));
                }
                self::apply($pdo, $accountId, $itemId, $from, -$qty);
            }

            $toName = null;
            if ($to !== null) {
                $toName = self::holderName($pdo, $accountId, $to, true);
                self::apply($pdo, $accountId, $itemId, $to, $qty);
            }

            $pdo->prepare(
                'INSERT INTO stock_movements
                    (account_id, item_id, item_name, unit, action,
                     from_holder, from_user_id, from_name,
                     to_holder, to_user_id, to_name,
                     qty, company_id, inspection_id, note,
                     created_by_user_id, created_by_name, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId, $itemId, (string) $item['name'], $unit, $action,
                $from['holder'] ?? null, self::userIdOf($from), $fromName,
                $to['holder'] ?? null, self::userIdOf($to), $toName,
                $qty, $companyId, $inspectionId, $note,
                $actorUserId, self::userName($pdo, $actorUserId),
                // Local wall-clock time, not the database's UTC NOW(): the
                // date of this row is the date printed on a výdajka.
                date('Y-m-d H:i:s'),
            ]);
            $movementId = (int) $pdo->lastInsertId();

            if ($startedHere) {
                $pdo->commit();
            }
            return $movementId;
        } catch (\Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * A technician leaves the team: whatever they hold goes back to Sklad, as
     * an ordinary `presun` in the journal so the history explains where it
     * went, and their column disappears (chapter 21 acceptance criterion).
     *
     * Must run before the account_users row is deleted — the holder name is
     * read through the membership. Joins the caller's transaction if any.
     *
     * @return int number of items returned
     */
    public static function returnToWarehouse(int $accountId, int $userId, int $actorUserId): int
    {
        $pdo = Db::pdo();
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT item_id FROM stock_balances
                 WHERE  account_id = ? AND user_id = ? AND qty > 0
                 ORDER  BY item_id'
            );
            $stmt->execute([$accountId, $userId]);
            $itemIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $returned = 0;
            foreach ($itemIds as $itemId) {
                // Re-read under the item lock taken by record(): the balance
                // may have moved since the list above was read.
                $lock = $pdo->prepare('SELECT id FROM stock_items WHERE id = ? AND account_id = ? FOR UPDATE');
                $lock->execute([$itemId, $accountId]);
                $qtyStmt = $pdo->prepare('SELECT qty FROM stock_balances WHERE item_id = ? AND user_id = ? FOR UPDATE');
                $qtyStmt->execute([$itemId, $userId]);
                $qty = (int) $qtyStmt->fetchColumn();
                if ($qty <= 0) {
                    continue;
                }
                self::record(
                    $accountId,
                    $actorUserId,
                    $itemId,
                    'presun',
                    ['holder' => 'technik', 'user_id' => $userId],
                    ['holder' => 'sklad'],
                    $qty,
                    null,
                    null,
                    'Vrátené na sklad pri odstránení technika z tímu',
                );
                $returned++;
            }

            // Empty rows would keep nothing but the column alive.
            $pdo->prepare('DELETE FROM stock_balances WHERE account_id = ? AND user_id = ?')
                ->execute([$accountId, $userId]);

            if ($startedHere) {
                $pdo->commit();
            }
            return $returned;
        } catch (\Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Technicians who get a column: every active member of the team, plus
     * anyone else still holding something (a deactivated member keeps their
     * column until their stock is moved — deactivation is not removal).
     *
     * @return list<array{user_id: int, name: string, is_active: bool}>
     */
    public static function technicianHolders(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.id, u.fullname, au.is_active
             FROM   account_users au
             JOIN   users u ON u.id = au.user_id
             WHERE  au.account_id = ?
               AND (au.is_active = 1
                    OR EXISTS (SELECT 1 FROM stock_balances b
                               WHERE b.account_id = au.account_id AND b.user_id = u.id AND b.qty > 0))
             ORDER  BY u.fullname ASC, u.id ASC'
        );
        $stmt->execute([$accountId]);
        return array_map(static fn (array $r): array => [
            'user_id'   => (int) $r['id'],
            'name'      => (string) $r['fullname'],
            'is_active' => (int) $r['is_active'] === 1,
        ], $stmt->fetchAll());
    }

    /**
     * Parses a holder from a request body: "sklad" or a technician's user id.
     *
     * @return array{holder: string, user_id?: int}|null
     */
    public static function parseHolder(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw === 'sklad') {
            return ['holder' => 'sklad'];
        }
        if (is_int($raw) && $raw > 0) {
            return ['holder' => 'technik', 'user_id' => $raw];
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return ['holder' => 'technik', 'user_id' => (int) $raw];
        }
        throw new StockException('Neplatný držiteľ.');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @param array{holder: string, user_id?: int|null} $holder */
    private static function balance(PDO $pdo, int $itemId, int $warehouseQty, array $holder): int
    {
        if ($holder['holder'] === 'sklad') {
            return $warehouseQty;
        }
        $stmt = $pdo->prepare(
            'SELECT qty FROM stock_balances WHERE item_id = ? AND user_id = ? FOR UPDATE'
        );
        $stmt->execute([$itemId, (int) $holder['user_id']]);
        $qty = $stmt->fetchColumn();
        return $qty === false ? 0 : (int) $qty;
    }

    /** @param array{holder: string, user_id?: int|null} $holder */
    private static function apply(PDO $pdo, int $accountId, int $itemId, array $holder, int $delta): void
    {
        if ($holder['holder'] === 'sklad') {
            $pdo->prepare('UPDATE stock_items SET warehouse_qty = warehouse_qty + ? WHERE id = ?')
                ->execute([$delta, $itemId]);
            return;
        }
        if ($delta < 0) {
            $pdo->prepare('UPDATE stock_balances SET qty = qty + ? WHERE item_id = ? AND user_id = ?')
                ->execute([$delta, $itemId, (int) $holder['user_id']]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO stock_balances (item_id, user_id, account_id, qty) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
        )->execute([$itemId, (int) $holder['user_id'], $accountId, $delta]);
    }

    /**
     * Name of a holder as the journal and the refusal message print it. A
     * technician must belong to this account; one receiving stock must also
     * be active (a deactivated member can only hand theirs back).
     *
     * @param array{holder: string, user_id?: int|null} $holder
     */
    private static function holderName(PDO $pdo, int $accountId, array $holder, bool $receiving): string
    {
        if ($holder['holder'] === 'sklad') {
            return self::WAREHOUSE_LABEL;
        }
        if ($holder['holder'] !== 'technik' || empty($holder['user_id'])) {
            throw new StockException('Neplatný držiteľ.');
        }
        $stmt = $pdo->prepare(
            'SELECT u.fullname, au.is_active
             FROM   account_users au JOIN users u ON u.id = au.user_id
             WHERE  au.account_id = ? AND au.user_id = ?'
        );
        $stmt->execute([$accountId, (int) $holder['user_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new StockException('Technik nie je členom tímu.');
        }
        if ($receiving && (int) $row['is_active'] !== 1) {
            throw new StockException('Technik je deaktivovaný — materiál mu nemožno odovzdať.');
        }
        return (string) $row['fullname'];
    }

    /** @param array{holder: string, user_id?: int|null}|null $holder */
    private static function userIdOf(?array $holder): ?int
    {
        return $holder !== null && $holder['holder'] === 'technik' ? (int) $holder['user_id'] : null;
    }

    /**
     * @param array{holder: string, user_id?: int|null} $a
     * @param array{holder: string, user_id?: int|null} $b
     */
    private static function sameHolder(array $a, array $b): bool
    {
        return $a['holder'] === $b['holder'] && self::userIdOf($a) === self::userIdOf($b);
    }

    private static function userName(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT fullname FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private static function assertCompany(PDO $pdo, int $accountId, int $companyId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM companies WHERE id = ? AND account_id = ? AND archived_at IS NULL');
        $stmt->execute([$companyId, $accountId]);
        if ($stmt->fetchColumn() === false) {
            throw new StockException('Firma sa nenašla.', 404);
        }
    }

    private static function assertInspection(PDO $pdo, int $accountId, int $companyId, int $inspectionId): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM inspections
             WHERE  id = ? AND account_id = ? AND company_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$inspectionId, $accountId, $companyId]);
        if ($stmt->fetchColumn() === false) {
            throw new StockException('Úkon sa pri tejto firme nenašiel.', 404);
        }
    }
}
