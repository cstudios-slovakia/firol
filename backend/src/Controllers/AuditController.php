<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditCatalog;
use Firol\Audit\AuditItems;
use Firol\Audit\LinkedWork;
use Firol\Auth\Admin;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Filling in an audit — block 3 / chapters 15 and 16.
 *
 * An audit is an `inspections` row of type `audit_bozp` / `audit_opp` whose
 * items are the checklist questions, copied out of a template when the audit
 * was created. Creating one goes through InspectionController like every other
 * úkon; this controller is what happens afterwards, on the screen where the
 * technician walks the building with the phone in one hand.
 *
 * The three things that make 109 items workable:
 *
 *   „Označiť všetko ako vyhovuje"  — and then overwrite the five that are not.
 *     Only UNANSWERED items are touched: an answer already given, especially a
 *     failing one, is never overwritten by a bulk action.
 *   „Označiť sekciu ako neaplikovateľnú" — a company with no chemicals should
 *     not have to answer ten questions about chemicals, and the protocol says
 *     the section was excluded rather than silently dropping it.
 *   Carrying the previous audit over — the exclusions and the technician's own
 *     items come across; not one single result does.
 */
final class AuditController
{
    /**
     * Everything the fill screen needs in one request: the items grouped into
     * sections, the running totals, what the previous audit found, and which
     * items a separate protocol already answers.
     *
     * One call rather than four because the screen is opened in a boiler room
     * on one bar of signal.
     */
    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $audit = self::loadOrFail($isAdmin ? null : $accountId, (int) $params['id']);

        $items = self::loadItems((int) $audit['id'], withPhotos: true);

        Response::json([
            'audit'    => self::shape($audit),
            'items'    => $items,
            'summary'  => AuditItems::summarize($items),
            'linked'   => self::linkedFor($audit, $items),
            'previous' => self::previousFindings((int) $audit['account_id'], $audit, $items),
        ]);
    }

    /**
     * Bulk actions. `mark_all_ok` and `mark_section_ok` answer only the items
     * still unanswered — the technician marks everything as compliant FIRST and
     * then corrects the handful that are not, so overwriting an existing answer
     * would erase the only work they actually did.
     */
    public static function bulk(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $audit = self::loadOrFail($isAdmin ? null : $accountId, (int) $params['id']);
        self::assertEditable($audit);

        $action = $req->jsonString('action');
        $sectionCode = $req->jsonString('section_code');

        $items = self::loadItems((int) $audit['id']);
        $changed = 0;

        switch ($action) {
            case 'mark_all_ok':
            case 'mark_section_ok':
                if ($action === 'mark_section_ok' && ($sectionCode === null || $sectionCode === '')) {
                    Response::error('Vyber sekciu.', 422);
                }
                foreach ($items as $item) {
                    $f = $item['fields'];
                    if ($action === 'mark_section_ok' && ($f['section_code'] ?? null) !== $sectionCode) {
                        continue;
                    }
                    // An excluded section is not "everything" — it was taken
                    // out of the audit and answering it would put it back.
                    if (!empty($f['section_excluded'])) {
                        continue;
                    }
                    if (($f['result'] ?? null) !== null) {
                        continue;
                    }
                    $f['result'] = 'vyhovuje';
                    self::writeFields((int) $item['id'], $f);
                    $changed++;
                }
                break;

            case 'exclude_section':
            case 'include_section':
                if ($sectionCode === null || $sectionCode === '') {
                    Response::error('Vyber sekciu.', 422);
                }
                $excluded = $action === 'exclude_section';
                foreach ($items as $item) {
                    $f = $item['fields'];
                    if (($f['section_code'] ?? null) !== $sectionCode) {
                        continue;
                    }
                    if ((bool) ($f['section_excluded'] ?? false) === $excluded) {
                        continue;
                    }
                    $f['section_excluded'] = $excluded;
                    self::writeFields((int) $item['id'], $f);
                    $changed++;
                }
                break;

            case 'clear_section':
                if ($sectionCode === null || $sectionCode === '') {
                    Response::error('Vyber sekciu.', 422);
                }
                foreach ($items as $item) {
                    $f = $item['fields'];
                    if (($f['section_code'] ?? null) !== $sectionCode) {
                        continue;
                    }
                    if (($f['result'] ?? null) === null) {
                        continue;
                    }
                    $f['result'] = null;
                    $f['defect_description'] = null;
                    $f['measure'] = null;
                    $f['deadline'] = null;
                    self::writeFields((int) $item['id'], $f);
                    $changed++;
                }
                break;

            default:
                Response::error('Neznáma hromadná operácia.', 422);
        }

        $items = self::loadItems((int) $audit['id'], withPhotos: true);
        Response::json([
            'changed' => $changed,
            'items'   => $items,
            'summary' => AuditItems::summarize($items),
        ]);
    }

    /**
     * Chapter 16 — take the answer from a separate protocol.
     *
     * With `item_ids` the technician accepted a specific offer. Without them,
     * only the links whose úkon was done TODAY or in this same visit are
     * applied, and only onto items nobody has answered yet: those are the ones
     * the spec lets the app fill in by itself, because the technician did the
     * work an hour ago and is standing in the same building.
     *
     * Nothing else is ever pre-filled. The technician signs the audit; an
     * answer they did not give is a claim they did not make.
     */
    public static function takeOver(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $audit = self::loadOrFail($isAdmin ? null : $accountId, (int) $params['id']);
        self::assertEditable($audit);

        $body = $req->json();
        $requested = is_array($body['item_ids'] ?? null)
            ? array_map(static fn ($v): int => (int) $v, $body['item_ids'])
            : null;

        $items = self::loadItems((int) $audit['id']);
        $linked = self::linkedFor($audit, $items);
        $applied = [];

        foreach ($items as $item) {
            $f = $item['fields'];
            $type = $f['linked_type'] ?? null;
            if (!is_string($type) || !isset($linked[$type])) {
                continue;
            }
            $state = $linked[$type];
            if ($state['found'] === null) {
                continue;
            }

            if ($requested === null) {
                // Automatic pass: same day / same visit, untouched items only.
                if ($state['state'] !== 'same_day' || ($f['result'] ?? null) !== null) {
                    continue;
                }
            } else {
                if (!in_array((int) $item['id'], $requested, true)) {
                    continue;
                }
                // An expired or missing úkon proves nothing — there is nothing
                // to take over from.
                if (!in_array($state['state'], ['same_day', 'offer'], true)) {
                    continue;
                }
            }

            $note = LinkedWork::takenFromNote($state['found']);
            $existing = trim((string) ($f['note'] ?? ''));

            $f['result'] = 'vyhovuje';
            $f['note'] = $existing === '' ? $note : $existing . "\n" . $note;
            $f['defect_description'] = null;
            $f['measure'] = null;
            $f['deadline'] = null;
            $f['taken_from'] = [
                'type'            => $type,
                'document_number' => $state['found']['document_number'] ?? null,
                'executed_on'     => $state['found']['executed_on'] ?? null,
            ];
            self::writeFields((int) $item['id'], $f);
            $applied[] = (int) $item['id'];
        }

        $items = self::loadItems((int) $audit['id'], withPhotos: true);
        Response::json([
            'applied' => $applied,
            'items'   => $items,
            'summary' => AuditItems::summarize($items),
        ]);
    }

    /**
     * Chapter 15.4 — carry the previous audit's shape over.
     *
     * Crosses over: which items were marked neaplikovateľné, which sections
     * were excluded, and the items and sections the technician had added
     * themselves. Those are decisions about this client that do not change from
     * year to year.
     *
     * Does NOT cross over: a single result, note, photo or finding. Those are
     * observations, and an observation copied from last year is a fabrication.
     */
    public static function carryOver(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $isAdmin = Admin::isAdmin(Tenant::currentUserId());
        $audit = self::loadOrFail($isAdmin ? null : $accountId, (int) $params['id']);
        self::assertEditable($audit);

        $previousId = $req->jsonInt('previous_id') ?? self::previousAuditId((int) $audit['account_id'], $audit);
        if ($previousId === null) {
            Response::error('Predchádzajúci audit sa nenašiel.', 404);
        }

        $previous = self::loadItems($previousId);
        if ($previous === []) {
            Response::error('Predchádzajúci audit neobsahuje žiadne položky.', 422);
        }

        $excludedSections = [];
        $naByKey = [];
        foreach ($previous as $item) {
            $f = $item['fields'];
            $code = (string) ($f['section_code'] ?? '');
            if (!empty($f['section_excluded'])) {
                $excludedSections[$code] = true;
            }
            if (($f['result'] ?? null) === 'neaplikovatelne') {
                $naByKey[self::itemKey($f)] = true;
            }
        }

        $items = self::loadItems((int) $audit['id']);
        $present = [];
        $carried = 0;
        foreach ($items as $item) {
            $f = $item['fields'];
            $present[self::itemKey($f)] = true;
            $code = (string) ($f['section_code'] ?? '');
            $dirty = false;

            if (isset($excludedSections[$code]) && empty($f['section_excluded'])) {
                $f['section_excluded'] = true;
                $dirty = true;
            }
            if (isset($naByKey[self::itemKey($f)]) && ($f['result'] ?? null) === null) {
                $f['result'] = 'neaplikovatelne';
                $dirty = true;
            }
            if ($dirty) {
                self::writeFields((int) $item['id'], $f);
                $carried++;
            }
        }

        // The technician's own items from last time, including any that belong
        // to a section the current checklist does not have at all.
        $added = 0;
        $pdo = Db::pdo();
        $posStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(position), 0) FROM inspection_items WHERE inspection_id = ?'
        );
        $posStmt->execute([$audit['id']]);
        $position = (int) $posStmt->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
        );
        foreach ($previous as $item) {
            $f = $item['fields'];
            if (empty($f['is_custom']) || isset($present[self::itemKey($f)])) {
                continue;
            }
            $fresh = AuditItems::fromTemplate(
                [
                    'text'        => $f['text'] ?? '',
                    'legal_basis' => $f['legal_basis'] ?? null,
                    'scope'       => $f['scope'] ?? 'VR',
                    'linked_type' => $f['linked_type'] ?? null,
                    'is_custom'   => true,
                ],
                (string) ($f['section_code'] ?? ''),
                (string) ($f['section_name'] ?? ''),
                (int) ($f['section_position'] ?? 999),
            );
            $fresh['section_excluded'] = isset($excludedSections[(string) ($f['section_code'] ?? '')]);
            if (($f['result'] ?? null) === 'neaplikovatelne') {
                $fresh['result'] = 'neaplikovatelne';
            }
            $insert->execute([
                $audit['id'],
                ++$position,
                json_encode($fresh, JSON_UNESCAPED_UNICODE),
            ]);
            $added++;
        }

        $items = self::loadItems((int) $audit['id'], withPhotos: true);
        Response::json([
            'carried' => $carried,
            'added'   => $added,
            'items'   => $items,
            'summary' => AuditItems::summarize($items),
            'previous' => self::previousFindings((int) $audit['account_id'], $audit, $items),
        ]);
    }

    // --- used by InspectionController and DocumentController -----------------

    /**
     * Copy a checklist into a freshly created audit. Only the items whose
     * scope matches the chosen rozsah are copied: an entry audit asks about
     * the building permit, a yearly review asks whether last year's findings
     * were dealt with, and neither should have to scroll past the other's
     * questions.
     *
     * Runs inside the caller's transaction.
     */
    public static function snapshotTemplate(int $inspectionId, int $templateId, string $scope): int
    {
        $sections = AuditCatalog::loadSections($templateId);
        $pdo = Db::pdo();
        $insert = $pdo->prepare(
            'INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)'
        );

        $position = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if (!AuditItems::scopeIncludes($scope, (string) $item['scope'])) {
                    continue;
                }
                $fields = AuditItems::fromTemplate(
                    $item,
                    (string) $section['code'],
                    (string) $section['name'],
                    (int) $section['position'],
                );
                $insert->execute([
                    $inspectionId,
                    ++$position,
                    json_encode($fields, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        return $position;
    }

    /**
     * Items of an audit with their fields decoded, in checklist order.
     *
     * `$withPhotos` is for the fill screen and stays off everywhere else: the
     * bulk actions and the PDF builder walk the same list and have no use for
     * the URLs, and an audit is 109 rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function loadItems(int $inspectionId, bool $withPhotos = false): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, position, fields FROM inspection_items
             WHERE  inspection_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$inspectionId]);

        $photosByItem = $withPhotos
            ? InspectionPhotoController::byItemForInspection($inspectionId)
            : [];

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['fields'], true);
            $item = [
                'id'       => (int) $row['id'],
                'position' => (int) $row['position'],
                'fields'   => is_array($decoded) ? $decoded : [],
            ];
            if ($withPhotos) {
                $item['photos'] = $photosByItem[(int) $row['id']] ?? [];
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Chapter 16 state per linked type present in the audit.
     *
     * @param array<string, mixed> $audit
     * @param list<array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    public static function linkedFor(array $audit, array $items): array
    {
        $types = [];
        foreach ($items as $item) {
            $type = $item['fields']['linked_type'] ?? null;
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }
        if ($types === []) {
            return [];
        }

        return LinkedWork::resolve(
            (int) $audit['account_id'],
            (int) $audit['facility_id'],
            (int) $audit['company_id'],
            $audit['executed_on'] !== null ? (string) $audit['executed_on'] : null,
            $audit['visit_id'] !== null ? (int) $audit['visit_id'] : null,
            $types,
        );
    }

    /**
     * What the previous audit of the same kind found here, keyed by item id of
     * THIS audit. Chapter 15.4: an item that failed last year is shown with the
     * old description and deadline, so the technician knows what to verify
     * before they answer it.
     *
     * @param array<string, mixed> $audit
     * @param list<array<string, mixed>> $items
     * @return array{audit_id: int, executed_on: ?string, findings: array<int, array<string, mixed>>}|null
     */
    public static function previousFindings(int $accountId, array $audit, array $items): ?array
    {
        $previousId = self::previousAuditId($accountId, $audit);
        if ($previousId === null) {
            return null;
        }

        $stmt = Db::pdo()->prepare('SELECT executed_on FROM inspections WHERE id = ?');
        $stmt->execute([$previousId]);
        $executedOn = $stmt->fetchColumn();

        $byKey = [];
        foreach (self::loadItems($previousId) as $item) {
            $f = $item['fields'];
            if (($f['result'] ?? null) !== 'nevyhovuje') {
                continue;
            }
            $byKey[self::itemKey($f)] = [
                'description' => $f['defect_description'] ?? null,
                'measure'     => $f['measure'] ?? null,
                'deadline'    => $f['deadline'] ?? null,
            ];
        }

        $findings = [];
        foreach ($items as $item) {
            $key = self::itemKey($item['fields']);
            if (isset($byKey[$key])) {
                $findings[(int) $item['id']] = $byKey[$key];
            }
        }

        return [
            'audit_id'    => $previousId,
            'executed_on' => $executedOn !== false ? $executedOn : null,
            'findings'    => $findings,
        ];
    }

    /** The most recent finished audit of the same kind at the same prevádzka. */
    public static function previousAuditId(int $accountId, array $audit): ?int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM inspections
             WHERE  account_id  = ?
               AND  facility_id = ?
               AND  type        = ?
               AND  id         <> ?
               AND  status      = "finalized"
               AND  archived_at IS NULL
             ORDER  BY executed_on DESC, id DESC
             LIMIT  1'
        );
        $stmt->execute([$accountId, $audit['facility_id'], $audit['type'], $audit['id']]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    // --- helpers ------------------------------------------------------------

    /**
     * Identity of a checklist question across audits: its section and its
     * wording. Not the template item id — an item reworded between two audits
     * is a different question, and matching it to last year's finding would
     * attach a finding to something nobody asked.
     *
     * @param array<string, mixed> $fields
     */
    private static function itemKey(array $fields): string
    {
        return ($fields['section_code'] ?? '') . '|' . mb_strtolower(trim((string) ($fields['text'] ?? '')));
    }

    /** @param array<string, mixed> $fields */
    private static function writeFields(int $itemId, array $fields): void
    {
        Db::pdo()->prepare('UPDATE inspection_items SET fields = ? WHERE id = ?')
            ->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), $itemId]);
    }

    /** @return array<string, mixed> */
    private static function loadOrFail(?int $accountId, int $id): array
    {
        $sql = 'SELECT id, account_id, company_id, facility_id, visit_id, type,
                       audit_template_id, audit_scope, executed_on, status
                FROM   inspections
                WHERE  id = ? AND archived_at IS NULL';
        $args = [$id];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $args[] = $accountId;
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if (!$row || !AuditCatalog::isAuditType((string) $row['type'])) {
            Response::error('Audit sa nenašiel.', 404);
        }
        return $row;
    }

    /** @param array<string, mixed> $audit */
    private static function assertEditable(array $audit): void
    {
        if (($audit['status'] ?? '') === 'finalized') {
            Response::error('Audit je uzamknutý — protokol už bol vystavený.', 409);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $kind = AuditCatalog::kindForType((string) $row['type']);
        return [
            'id'          => (int) $row['id'],
            'type'        => (string) $row['type'],
            'kind'        => $kind,
            'scope'       => $row['audit_scope'] !== null ? (string) $row['audit_scope'] : null,
            'template_id' => $row['audit_template_id'] !== null ? (int) $row['audit_template_id'] : null,
            'executed_on' => $row['executed_on'],
            'status'      => (string) $row['status'],
            'visit_id'    => $row['visit_id'] !== null ? (int) $row['visit_id'] : null,
        ];
    }
}
