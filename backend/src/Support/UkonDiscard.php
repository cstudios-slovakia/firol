<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Audit\AuditLog;
use Firol\Db;
use Firol\Storage\Storage;

/**
 * What „zahodiť" / the trash icon does to an úkon (owner decision of
 * 17. 11. 2026, round 3 of the fix list of 16. 11. 2026).
 *
 *  - An issued (finalized) úkon is archived: hidden from the lists, its row and
 *    its protocol stay.
 *  - A draft that was never issued and that nothing was made from is deleted
 *    for good: the row (items, photo rows, prevádzky of a book entry, calendar
 *    plans and deadline notices go with it by CASCADE) and its photo folder.
 *    It holds no document number.
 *  - Any other draft is refused: one issued before (opened again with
 *    „Upraviť", or a test printed blank and reopened with „Doplniť výsledky",
 *    which still owns a numbered document), one something was made from
 *    (tasks, concepts, checklist drafts, follow-ups, a material issue or a
 *    potvrdenie listing it), a prehliadka closed before, and the kniha BOZP
 *    record of a prehliadka on its own.
 *
 * „Issued before" is read from `issued_before` (migration 078, set on every
 * issue and reopen and backfilled from the audit trail), the contractor
 * snapshot written at issue time, and a documents row — not from the audit
 * log alone, which is pruned after 365 days.
 */
final class UkonDiscard
{
    public const DELETE = 'delete';
    public const ARCHIVE = 'archive';

    public const REFUSE_ISSUED = 'Protokol už bol vystavený, zahodiť sa nedá. Dokonči ho a vystav znova.';
    public const REFUSE_PREHLIADKA_CLOSED = 'Prehliadka už bola uzavretá, zahodiť sa nedá. Dokonči ju a uzavri znova.';
    public const REFUSE_MADE_FROM = 'Z tohto úkonu už vznikli ďalšie záznamy (úlohy, koncepty alebo nadväzujúce úkony), zahodiť sa nedá.';
    public const REFUSE_BOZP_CHILD = 'Zápis do knihy BOZP patrí k preventívnej prehliadke — samostatne sa zahodiť nedá.';

    /**
     * The discard of each úkon: DELETE, ARCHIVE, or null with the reason it is
     * refused.
     *
     * @param list<array<string, mixed>> $rows each with `id`, `account_id` (or
     *        $accountId given), `type`, `status`, `details`, `issued_before`,
     *        `source_inspection_id`
     * @return array<int, array{mode: ?string, reason: ?string}> by id
     */
    public static function decideMany(?int $accountId, array $rows): array
    {
        $out = [];
        $draftIds = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (($r['status'] ?? '') === 'finalized') {
                $out[$id] = ['mode' => self::ARCHIVE, 'reason' => null];
                continue;
            }
            $details = $r['details'] ?? ($r['scope_details'] ?? null);
            $details = is_array($details) ? $details : InspectionDetails::decode($details);
            if (CarryOver::wasIssued($r, $details)) {
                $out[$id] = ['mode' => null, 'reason' => self::issuedReason((string) $r['type'])];
                continue;
            }
            $draftIds[] = $id;
        }
        if ($draftIds === []) {
            return $out;
        }

        $facts = self::facts($accountId, $draftIds);
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (isset($out[$id])) {
                continue;
            }
            $f = $facts[$id] ?? null;
            if ($f === null) {
                $out[$id] = ['mode' => null, 'reason' => self::REFUSE_ISSUED];
                continue;
            }
            $type = (string) $r['type'];
            if ($type === 'kniha_bozp' && $f['source_type'] === PrehliadkaDetails::TYPE) {
                $out[$id] = ['mode' => null, 'reason' => self::REFUSE_BOZP_CHILD];
            } elseif ($type === PrehliadkaDetails::TYPE && ($f['closed_audit'] || $f['children'])) {
                // Anything issued from a prehliadka (its BOZP record, a concept —
                // archived ones included) is proof it was closed before.
                $out[$id] = ['mode' => null, 'reason' => self::REFUSE_PREHLIADKA_CLOSED];
            } elseif ($f['document'] || $f['reopen_audit']) {
                $out[$id] = ['mode' => null, 'reason' => self::issuedReason($type)];
            } elseif ($f['children'] || $f['tasks'] || $f['stock'] || $f['confirmation']) {
                $out[$id] = ['mode' => null, 'reason' => self::REFUSE_MADE_FROM];
            } else {
                $out[$id] = ['mode' => self::DELETE, 'reason' => null];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{mode: ?string, reason: ?string}
     */
    public static function decide(int $accountId, array $row): array
    {
        return self::decideMany($accountId, [$row])[(int) $row['id']];
    }

    /**
     * Delete a never-issued draft for good. The caller has checked
     * {@see self::decide()} and the member's rights.
     *
     * @param array<string, mixed> $row the úkon (`id`, `type`, `facility_id`, …)
     */
    public static function delete(int $accountId, array $row): void
    {
        $id = (int) $row['id'];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Re-checked under a row lock: an issue, a reopen or a follow-up
            // arriving meanwhile must not lose its úkon.
            $lock = $pdo->prepare(
                'SELECT id, account_id, type, status, details, issued_before, source_inspection_id
                 FROM   inspections WHERE id = ? AND account_id = ? FOR UPDATE'
            );
            $lock->execute([$id, $accountId]);
            $current = $lock->fetch();
            if (!$current || self::decide($accountId, $current)['mode'] !== self::DELETE) {
                $pdo->rollBack();
                throw new \RuntimeException('not deletable');
            }
            // Items, their photo rows, the prevádzky of a book entry, calendar
            // plans and deadline notices go by CASCADE (fk_* ON DELETE CASCADE).
            $pdo->prepare('DELETE FROM inspections WHERE id = ? AND account_id = ? AND status = "draft"')
                ->execute([$id, $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Files go only after the commit: an orphaned photo nobody links to is
        // harmless, a missing file behind a live row is not.
        Storage::purgeInspectionPhotos($accountId, $id);

        AuditLog::record(
            'inspection.delete',
            'inspections',
            $id,
            [
                'status'      => 'draft',
                'type'        => (string) $row['type'],
                'company_id'  => isset($row['company_id']) ? (int) $row['company_id'] : null,
                'facility_id' => isset($row['facility_id']) ? (int) $row['facility_id'] : null,
                'facility'    => $row['facility_name'] ?? null,
                'executed_on' => $row['executed_on'] ?? null,
            ],
            null,
        );
    }

    /**
     * Whether a prehliadka was ever closed. „Upraviť" turns it back into a draft
     * and deletes its own documents, so the status alone cannot tell; what stays
     * is `issued_before`, the audit trail of its closing and of the unlock, and
     * anything that was issued from it (the kniha BOZP record, concepts —
     * archived ones included).
     *
     * @param array<string, mixed> $row the prehliadka (`id`, `account_id`, `status`, …)
     */
    public static function prehliadkaClosedBefore(array $row): bool
    {
        if (($row['status'] ?? '') === 'finalized' || !empty($row['issued_before'])) {
            return true;
        }
        $f = self::facts((int) $row['account_id'], [(int) $row['id']])[(int) $row['id']] ?? null;
        return $f === null || $f['closed_audit'] || $f['children'];
    }

    private static function issuedReason(string $type): string
    {
        return $type === PrehliadkaDetails::TYPE ? self::REFUSE_PREHLIADKA_CLOSED : self::REFUSE_ISSUED;
    }

    /**
     * Everything that keeps a draft from being deleted, for several úkony in
     * one query.
     *
     * @param list<int> $ids
     * @return array<int, array{source_type: ?string, document: bool, reopen_audit: bool, closed_audit: bool,
     *                          children: bool, tasks: bool, stock: bool, confirmation: bool}>
     */
    private static function facts(?int $accountId, array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT i.id, src.type AS source_type,
                       EXISTS (SELECT 1 FROM documents d
                               WHERE d.account_id = i.account_id AND d.parent_type = 'inspection'
                                 AND d.parent_id = i.id) AS document,
                       EXISTS (SELECT 1 FROM audit_log a
                               WHERE a.target_type = 'inspections' AND a.target_id = i.id
                                 AND a.action IN ('inspection.unlock', 'inspection.fill_results')) AS reopen_audit,
                       EXISTS (SELECT 1 FROM audit_log a
                               WHERE a.target_type = 'inspections' AND a.target_id = i.id
                                 AND a.action IN ('prehliadka.close', 'inspection.unlock')) AS closed_audit,
                       EXISTS (SELECT 1 FROM inspections ch
                               WHERE ch.account_id = i.account_id AND ch.source_inspection_id = i.id) AS children,
                       EXISTS (SELECT 1 FROM tasks t
                               WHERE t.account_id = i.account_id AND t.source_inspection_id = i.id) AS tasks,
                       (EXISTS (SELECT 1 FROM stock_issues s WHERE s.account_id = i.account_id AND s.inspection_id = i.id)
                        OR EXISTS (SELECT 1 FROM stock_movements m WHERE m.account_id = i.account_id AND m.inspection_id = i.id)) AS stock,
                       EXISTS (SELECT 1 FROM work_confirmations w
                               WHERE w.account_id = i.account_id
                                 AND JSON_CONTAINS(w.inspection_ids, CAST(i.id AS CHAR))) AS confirmation
                FROM   inspections i
                LEFT JOIN inspections src ON src.id = i.source_inspection_id AND src.account_id = i.account_id
                WHERE  i.id IN ($in)";
        $params = $ids;
        if ($accountId !== null) {
            $sql .= ' AND i.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = [
                'source_type'  => $r['source_type'] !== null ? (string) $r['source_type'] : null,
                'document'     => (bool) $r['document'],
                'reopen_audit' => (bool) $r['reopen_audit'],
                'closed_audit' => (bool) $r['closed_audit'],
                'children'     => (bool) $r['children'],
                'tasks'        => (bool) $r['tasks'],
                'stock'        => (bool) $r['stock'],
                'confirmation' => (bool) $r['confirmation'],
            ];
        }
        return $out;
    }
}
