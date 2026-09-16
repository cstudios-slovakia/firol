<?php

declare(strict_types=1);

namespace Firol\Controllers;

use Firol\Audit\AuditCatalog;
use Firol\Auth\Csrf;
use Firol\Auth\Tenant;
use Firol\Db;
use Firol\Http\Request;
use Firol\Http\Response;

/**
 * Editing the audit checklists — block 3 / chapter 17.
 *
 * The two checklists that ship with the app are a DEFAULT, not a fixed list.
 * A technician who has audited the same three factories for a decade knows
 * which question they never ask and which one they always add, and a checklist
 * they cannot change is a checklist they will keep on paper beside the phone.
 *
 * So: any item can be reworded or deleted, own items and own sections can be
 * added, everything can be reordered, and several checklists can live side by
 * side („Audit BOZP — výroba", „Audit BOZP — kancelárie"). The delivered one
 * can always be put back the way it came.
 *
 * None of this touches an audit that already exists. Every audit copies the
 * checklist it was created from, so a protocol issued in March keeps reading
 * the way it read in March.
 */
final class AuditTemplateController
{
    public static function index(Request $req): void
    {
        $accountId = Tenant::currentAccountId();
        $kind = self::kindParam($req->query('kind'));

        $kinds = $kind !== null ? [$kind] : AuditCatalog::KINDS;
        $items = [];
        foreach ($kinds as $k) {
            foreach (AuditCatalog::templatesFor($accountId, $k) as $template) {
                $items[] = $template;
            }
        }

        Response::json(['items' => $items]);
    }

    public static function show(Request $req, array $params): void
    {
        $accountId = Tenant::currentAccountId();
        $template = AuditCatalog::template($accountId, (int) $params['id']);
        if ($template === null) {
            Response::error('Kontrolný list sa nenašiel.', 404);
        }
        Response::json(['template' => $template]);
    }

    /**
     * Create a checklist of one's own. With `copy_from` it starts as a copy of
     * an existing one — which is how „Audit BOZP — kancelárie" actually gets
     * made: from the factory one, minus the six questions about forklifts.
     */
    public static function store(Request $req): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();

        $kind = self::kindParam($req->jsonString('kind'));
        if ($kind === null) {
            Response::error('Vyber, či ide o kontrolný list BOZP alebo OPP.', 422);
        }
        $name = self::name($req->jsonString('name'));
        $copyFrom = $req->jsonInt('copy_from');

        $sections = null;
        if ($copyFrom !== null) {
            $source = AuditCatalog::template($accountId, $copyFrom);
            if ($source === null) {
                Response::error('Kontrolný list, z ktorého kopírujeme, sa nenašiel.', 404);
            }
            if ($source['kind'] !== $kind) {
                Response::error('Kontrolný list sa dá kopírovať len v rámci rovnakého odboru.', 422);
            }
            $sections = $source['sections'];
        } else {
            $sections = AuditCatalog::delivered($kind)['sections'];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO audit_templates (account_id, kind, name, is_custom, source_key)
                 VALUES (?, ?, ?, 1, NULL)'
            )->execute([$accountId, $kind, $name]);
            $templateId = (int) $pdo->lastInsertId();
            AuditCatalog::writeSections($templateId, $sections, isCustom: true);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['template' => AuditCatalog::template($accountId, $templateId)], 201);
    }

    /** Rename. The delivered ones can be renamed too — restore puts the name back. */
    public static function update(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);

        $name = self::name($req->jsonString('name'));
        Db::pdo()->prepare('UPDATE audit_templates SET name = ? WHERE id = ?')
            ->execute([$name, $template['id']]);

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    /**
     * Archive a checklist of one's own. A delivered one is never deleted —
     * „Obnoviť pôvodnú šablónu" is the way back from having emptied it, and
     * without it an account could end up with no checklist at all.
     */
    public static function destroy(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);

        if (!$template['is_custom']) {
            Response::error(
                'Dodaný kontrolný list sa nedá zmazať — použi „Obnoviť pôvodný stav".',
                422,
            );
        }

        Db::pdo()->prepare('UPDATE audit_templates SET archived_at = NOW() WHERE id = ?')
            ->execute([$template['id']]);

        Response::noContent();
    }

    /** Put a delivered checklist back the way it shipped (chapter 17). */
    public static function restore(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);

        if ($template['is_custom']) {
            Response::error('Obnoviť sa dá len kontrolný list dodaný s aplikáciou.', 422);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            AuditCatalog::restore((int) $template['id'], (string) $template['kind']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    public static function storeSection(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);

        $code = self::shortText($req->jsonString('code'), 8, 'Kód sekcie');
        $name = self::shortText($req->jsonString('name'), 160, 'Názov sekcie');

        $posStmt = Db::pdo()->prepare(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM audit_template_sections WHERE template_id = ?'
        );
        $posStmt->execute([$template['id']]);

        Db::pdo()->prepare(
            'INSERT INTO audit_template_sections (template_id, code, name, position)
             VALUES (?, ?, ?, ?)'
        )->execute([$template['id'], $code, $name, (int) $posStmt->fetchColumn()]);

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])], 201);
    }

    public static function updateSection(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);
        $sectionId = self::sectionOrFail((int) $params['section_id'], (int) $template['id']);

        $body = $req->json();
        $sets = [];
        $args = [];
        if (array_key_exists('code', $body)) {
            $sets[] = 'code = ?';
            $args[] = self::shortText($req->jsonString('code'), 8, 'Kód sekcie');
        }
        if (array_key_exists('name', $body)) {
            $sets[] = 'name = ?';
            $args[] = self::shortText($req->jsonString('name'), 160, 'Názov sekcie');
        }
        if ($sets !== []) {
            $args[] = $sectionId;
            Db::pdo()->prepare(
                'UPDATE audit_template_sections SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($args);
        }

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    public static function destroySection(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);
        $sectionId = self::sectionOrFail((int) $params['section_id'], (int) $template['id']);

        Db::pdo()->prepare('DELETE FROM audit_template_sections WHERE id = ?')->execute([$sectionId]);

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    public static function storeItem(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);
        $sectionId = self::sectionOrFail((int) $params['section_id'], (int) $template['id']);

        $text = self::shortText($req->jsonString('text'), 400, 'Znenie položky');
        // Optional on an item of one's own, and left blank on the protocol
        // rather than filled with a paragraph nobody checked (chapter 17).
        $legal = $req->jsonString('legal_basis');
        $legal = is_string($legal) && trim($legal) !== '' ? trim($legal) : null;
        if ($legal !== null && mb_strlen($legal) > 160) {
            Response::error('Právny základ je príliš dlhý.', 422);
        }

        $posStmt = Db::pdo()->prepare(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM audit_template_items WHERE section_id = ?'
        );
        $posStmt->execute([$sectionId]);

        Db::pdo()->prepare(
            'INSERT INTO audit_template_items
                (section_id, text, legal_basis, scope, linked_type, position, is_custom)
             VALUES (?, ?, ?, ?, NULL, ?, 1)'
        )->execute([
            $sectionId,
            $text,
            $legal,
            self::scope($req->jsonString('scope')),
            (int) $posStmt->fetchColumn(),
        ]);

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])], 201);
    }

    public static function updateItem(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);
        $itemId = self::itemOrFail((int) $params['item_id'], (int) $template['id']);

        $body = $req->json();
        $sets = [];
        $args = [];
        if (array_key_exists('text', $body)) {
            $sets[] = 'text = ?';
            $args[] = self::shortText($req->jsonString('text'), 400, 'Znenie položky');
        }
        if (array_key_exists('legal_basis', $body)) {
            $legal = $req->jsonString('legal_basis');
            $legal = is_string($legal) && trim($legal) !== '' ? trim($legal) : null;
            if ($legal !== null && mb_strlen($legal) > 160) {
                Response::error('Právny základ je príliš dlhý.', 422);
            }
            $sets[] = 'legal_basis = ?';
            $args[] = $legal;
        }
        if (array_key_exists('scope', $body)) {
            $sets[] = 'scope = ?';
            $args[] = self::scope($req->jsonString('scope'));
        }
        if ($sets !== []) {
            $args[] = $itemId;
            Db::pdo()->prepare(
                'UPDATE audit_template_items SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($args);
        }

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    public static function destroyItem(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);
        $itemId = self::itemOrFail((int) $params['item_id'], (int) $template['id']);

        Db::pdo()->prepare('DELETE FROM audit_template_items WHERE id = ?')->execute([$itemId]);

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    /**
     * Reorder sections, or the items inside one section. Sent as the full list
     * of ids in their new order — a move is one request, not a pair of
     * position writes that could half-apply on a flaky connection.
     */
    public static function reorder(Request $req, array $params): void
    {
        Csrf::require($req);
        $accountId = Tenant::currentAccountId();
        $template = self::loadOrFail($accountId, (int) $params['id']);

        $body = $req->json();
        $sectionIds = $body['section_ids'] ?? null;
        $sectionId  = $req->jsonInt('section_id');
        $itemIds    = $body['item_ids'] ?? null;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if (is_array($sectionIds)) {
                $update = $pdo->prepare(
                    'UPDATE audit_template_sections SET position = ? WHERE id = ? AND template_id = ?'
                );
                $pos = 0;
                foreach ($sectionIds as $id) {
                    $update->execute([++$pos, (int) $id, $template['id']]);
                }
            }
            if ($sectionId !== null && is_array($itemIds)) {
                self::sectionOrFail($sectionId, (int) $template['id']);
                $update = $pdo->prepare(
                    'UPDATE audit_template_items SET position = ? WHERE id = ? AND section_id = ?'
                );
                $pos = 0;
                foreach ($itemIds as $id) {
                    $update->execute([++$pos, (int) $id, $sectionId]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::json(['template' => AuditCatalog::template($accountId, (int) $template['id'])]);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function loadOrFail(int $accountId, int $templateId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, kind, name, is_custom FROM audit_templates
             WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$templateId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Kontrolný list sa nenašiel.', 404);
        }
        $row['is_custom'] = (bool) $row['is_custom'];
        return $row;
    }

    private static function sectionOrFail(int $sectionId, int $templateId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM audit_template_sections WHERE id = ? AND template_id = ?'
        );
        $stmt->execute([$sectionId, $templateId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Sekcia sa nenašla.', 404);
        }
        return $sectionId;
    }

    private static function itemOrFail(int $itemId, int $templateId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM audit_template_items i
             JOIN   audit_template_sections s ON s.id = i.section_id
             WHERE  i.id = ? AND s.template_id = ?'
        );
        $stmt->execute([$itemId, $templateId]);
        if ($stmt->fetchColumn() === false) {
            Response::error('Položka sa nenašla.', 404);
        }
        return $itemId;
    }

    private static function kindParam(?string $value): ?string
    {
        return in_array($value, AuditCatalog::KINDS, true) ? $value : null;
    }

    private static function name(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            Response::error('Zadaj názov kontrolného listu.', 422);
        }
        if (mb_strlen($value) > 120) {
            Response::error('Názov kontrolného listu je príliš dlhý.', 422);
        }
        return $value;
    }

    private static function shortText(?string $value, int $max, string $label): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            Response::error($label . ' nesmie zostať prázdny.', 422);
        }
        if (mb_strlen($value) > $max) {
            Response::error($label . ' je príliš dlhý.', 422);
        }
        return $value;
    }

    private static function scope(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['V', 'R', 'VR'], true) ? $value : 'VR';
    }
}
