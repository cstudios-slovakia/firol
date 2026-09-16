<?php

declare(strict_types=1);

namespace Firol\Audit;

use Firol\Db;

/**
 * The two checklists delivered with the app — block 3 / chapters 15 and 17.
 *
 * `data/audit_bozp.json` and `data/audit_opp.json` are verbatim copies of the
 * files handed over with the specification. They are not edited here and must
 * not be: the wording of every item is tied to a named paragraph that gets
 * printed on a protocol an inspector may read, so a tidy-up of the phrasing is
 * a change to a legal document, not to a string.
 *
 * What this class adds on top of them is the chapter 16 mapping — which item
 * is covered by which separate úkon. That lives in code rather than in the
 * data files for the same reason: the files stay exactly as delivered.
 *
 * Materialisation: a delivered checklist becomes rows in audit_templates the
 * first time an account needs it, and can be reset back to these files at any
 * time („Obnoviť pôvodnú šablónu").
 */
final class AuditCatalog
{
    public const KIND_BOZP = 'bozp';
    public const KIND_OPP  = 'opp';

    public const KINDS = [self::KIND_BOZP, self::KIND_OPP];

    /** Inspection type slug of an audit of each kind. */
    public const INSPECTION_TYPES = [
        self::KIND_BOZP => 'audit_bozp',
        self::KIND_OPP  => 'audit_opp',
    ];

    /**
     * Chapter 16 — which checklist item is covered by which separate úkon,
     * addressed as `<SECTION CODE>.<zero-based index within the section>`.
     *
     * Only items a protocol genuinely evidences are listed. "Zoznam
     * poskytovaných OOPP podľa profesií" is a document the auditor reads; "Stav
     * OOPP — nepoškodené, funkčné" is what the kontrola OOPP protocol attests.
     * Linking the first would let a protocol answer a question it never asked.
     *
     * Several of the BOZP targets are types that arrive with block 2. They are
     * named here already so the mapping has one home; until those úkony exist
     * the resolver finds nothing and the technician fills the item in by hand,
     * which is exactly what should happen.
     *
     * @var array<string, array<string, string>>
     */
    private const LINKS = [
        self::KIND_BOZP => [
            'C.1' => 'skolenie_bozp',
            'C.3' => 'skolenie_bozp',
            'D.4' => 'oopp',
            'D.5' => 'oopp',
            'F.5' => 'oznacenie',
            'F.6' => 'oznacenie',
            'G.1' => 'pracovne_prostriedky',
            'G.7' => 'regale',
            'G.8' => 'rebriky',
            'J.1' => 'fajcenie',
            'J.2' => 'dychova_skuska',
        ],
        self::KIND_OPP => [
            'A.4'  => 'poziarna_kniha',
            'C.0'  => 'poziarna_kniha',
            'D.0'  => 'skolenie_po',
            'F.2'  => 'php',
            'F.4'  => 'hydranty',
            'F.5'  => 'ts_hadic',
            'G.3'  => 'nudzove_osvetlenie',
            'G.4'  => 'pu_akcieschopnost',
            'G.5'  => 'pu_udrzba',
        ],
    ];

    /**
     * Human label of a linked type, for the line the technician reads beside
     * the item („Kontrola OOPP vykonaná 20. 7. 2026 …"). Block 2's types are
     * listed so the label is right the day they ship.
     *
     * @var array<string, string>
     */
    public const LINKED_TYPE_LABELS = [
        'php'                  => 'Kontrola hasiacich prístrojov',
        'hydranty'             => 'Kontrola požiarnych hydrantov',
        'ts_hadic'             => 'Tlaková skúška hadíc',
        'poziarna_kniha'       => 'Preventívna protipožiarna prehliadka',
        'pu_akcieschopnost'    => 'Požiarne uzávery — akcieschopnosť',
        'pu_udrzba'            => 'Požiarne uzávery — prevádzková údržba',
        'nudzove_osvetlenie'   => 'Kontrola núdzového osvetlenia',
        'skolenie_po'          => 'Školenie o ochrane pred požiarmi',
        'skolenie_bozp'        => 'Oboznámenie zamestnancov v oblasti BOZP',
        'oopp'                 => 'Kontrola OOPP',
        'oznacenie'            => 'Kontrola bezpečnostného označenia',
        'pracovne_prostriedky' => 'Kontrola pracovných prostriedkov',
        'rebriky'              => 'Kontrola rebríkov',
        'regale'               => 'Kontrola regálov',
        'fajcenie'             => 'Kontrola dodržiavania zákazu fajčenia',
        'dychova_skuska'       => 'Dychová skúška',
    ];

    /** Kind of audit an inspection type slug denotes, or null when it is not an audit. */
    public static function kindForType(string $inspectionType): ?string
    {
        foreach (self::INSPECTION_TYPES as $kind => $type) {
            if ($type === $inspectionType) {
                return $kind;
            }
        }
        return null;
    }

    public static function isAuditType(string $inspectionType): bool
    {
        return self::kindForType($inspectionType) !== null;
    }

    /**
     * The delivered checklist, straight from its JSON file, with `linked_type`
     * resolved onto each item.
     *
     * @return array{name: string, legal_basis: string, sections: list<array{
     *     code: string, name: string,
     *     items: list<array{text: string, legal_basis: ?string, scope: string, linked_type: ?string}>
     * }>}
     */
    public static function delivered(string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown audit kind: $kind");
        }

        $raw = file_get_contents(__DIR__ . '/data/audit_' . $kind . '.json');
        if ($raw === false) {
            throw new \RuntimeException("Audit template file for '$kind' is missing.");
        }
        /** @var array<string, mixed> $json */
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $links = self::LINKS[$kind];
        $sections = [];
        foreach (($json['sekcie'] ?? []) as $section) {
            $code = (string) ($section['kod'] ?? '');
            $items = [];
            foreach (($section['polozky'] ?? []) as $index => $item) {
                $legal = $item['pravny_zaklad'] ?? null;
                $items[] = [
                    'text'        => (string) ($item['text'] ?? ''),
                    'legal_basis' => is_string($legal) && $legal !== '' ? $legal : null,
                    'scope'       => self::normalizeScope($item['rozsah'] ?? 'VR'),
                    'linked_type' => $links[$code . '.' . $index] ?? null,
                ];
            }
            $sections[] = [
                'code'  => $code,
                'name'  => (string) ($section['nazov'] ?? ''),
                'items' => $items,
            ];
        }

        return [
            'name'        => (string) ($json['nazov'] ?? ''),
            'legal_basis' => (string) ($json['pravny_zaklad'] ?? ''),
            'sections'    => $sections,
        ];
    }

    /**
     * Legal sentence printed under „Základné informácie" on the protocol. Comes
     * from the delivered file, never from here — see the class comment.
     */
    public static function legalBasis(string $kind): string
    {
        return self::delivered($kind)['legal_basis'];
    }

    /**
     * The account's checklists of one kind, newest last, each with its
     * sections and items. Materialises the delivered one on first use so an
     * account that has never opened the settings screen can still start an
     * audit.
     *
     * @return list<array<string, mixed>>
     */
    public static function templatesFor(int $accountId, string $kind): array
    {
        self::ensureDelivered($accountId, $kind);

        $stmt = Db::pdo()->prepare(
            'SELECT id, kind, name, is_custom, source_key, created_at, updated_at
             FROM   audit_templates
             WHERE  account_id = ? AND kind = ? AND archived_at IS NULL
             ORDER  BY is_custom ASC, name ASC, id ASC'
        );
        $stmt->execute([$accountId, $kind]);

        $templates = [];
        foreach ($stmt->fetchAll() as $row) {
            $templates[] = self::shape($row, self::loadSections((int) $row['id']));
        }
        return $templates;
    }

    /**
     * Materialise the delivered checklist for an account if it has none of
     * that kind yet. Returns the template id.
     */
    public static function ensureDelivered(int $accountId, string $kind): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM audit_templates
             WHERE  account_id = ? AND kind = ? AND is_custom = 0 AND archived_at IS NULL
             ORDER  BY id ASC LIMIT 1'
        );
        $stmt->execute([$accountId, $kind]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $delivered = self::delivered($kind);
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO audit_templates (account_id, kind, name, is_custom, source_key)
             VALUES (?, ?, ?, 0, ?)'
        )->execute([$accountId, $kind, $delivered['name'], 'audit_' . $kind]);
        $templateId = (int) $pdo->lastInsertId();

        self::writeSections($templateId, $delivered['sections'], isCustom: false);
        return $templateId;
    }

    /**
     * Reset a delivered checklist to the state it was shipped in — chapter 17,
     * „Dodanú šablónu možno kedykoľvek obnoviť do pôvodného stavu". Audits
     * already created from it keep their own copy and are untouched.
     */
    public static function restore(int $templateId, string $kind): void
    {
        $delivered = self::delivered($kind);
        $pdo = Db::pdo();
        $pdo->prepare(
            'DELETE FROM audit_template_sections WHERE template_id = ?'
        )->execute([$templateId]);
        $pdo->prepare(
            'UPDATE audit_templates SET name = ? WHERE id = ?'
        )->execute([$delivered['name'], $templateId]);
        self::writeSections($templateId, $delivered['sections'], isCustom: false);
    }

    /**
     * Write a list of sections (each with its items) under a template.
     *
     * @param list<array{code: string, name: string, items: list<array<string, mixed>>}> $sections
     */
    public static function writeSections(int $templateId, array $sections, bool $isCustom): void
    {
        $pdo = Db::pdo();
        $insSection = $pdo->prepare(
            'INSERT INTO audit_template_sections (template_id, code, name, position)
             VALUES (?, ?, ?, ?)'
        );
        $insItem = $pdo->prepare(
            'INSERT INTO audit_template_items
                (section_id, text, legal_basis, scope, linked_type, position, is_custom)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $sectionPos = 0;
        foreach ($sections as $section) {
            $insSection->execute([
                $templateId,
                (string) $section['code'],
                (string) $section['name'],
                ++$sectionPos,
            ]);
            $sectionId = (int) $pdo->lastInsertId();

            $itemPos = 0;
            foreach ($section['items'] as $item) {
                $insItem->execute([
                    $sectionId,
                    (string) $item['text'],
                    $item['legal_basis'] ?? null,
                    self::normalizeScope($item['scope'] ?? 'VR'),
                    $item['linked_type'] ?? null,
                    ++$itemPos,
                    ($item['is_custom'] ?? $isCustom) ? 1 : 0,
                ]);
            }
        }
    }

    /**
     * One template with its sections and items, or null when it does not
     * belong to the account.
     *
     * @return array<string, mixed>|null
     */
    public static function template(int $accountId, int $templateId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, kind, name, is_custom, source_key, created_at, updated_at
             FROM   audit_templates
             WHERE  id = ? AND account_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$templateId, $accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::shape($row, self::loadSections($templateId));
    }

    /**
     * Sections + items of a template, ordered.
     *
     * @return list<array<string, mixed>>
     */
    public static function loadSections(int $templateId): array
    {
        $secStmt = Db::pdo()->prepare(
            'SELECT id, code, name, position FROM audit_template_sections
             WHERE  template_id = ? ORDER BY position ASC, id ASC'
        );
        $secStmt->execute([$templateId]);
        $sections = $secStmt->fetchAll();
        if ($sections === []) {
            return [];
        }

        $ids = array_map(static fn (array $s): int => (int) $s['id'], $sections);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = Db::pdo()->prepare(
            'SELECT id, section_id, text, legal_basis, scope, linked_type, position, is_custom
             FROM   audit_template_items
             WHERE  section_id IN (' . $placeholders . ')
             ORDER  BY position ASC, id ASC'
        );
        $itemStmt->execute($ids);

        $bySection = [];
        foreach ($itemStmt->fetchAll() as $item) {
            $bySection[(int) $item['section_id']][] = [
                'id'          => (int) $item['id'],
                'text'        => (string) $item['text'],
                'legal_basis' => $item['legal_basis'] !== null ? (string) $item['legal_basis'] : null,
                'scope'       => (string) $item['scope'],
                'linked_type' => $item['linked_type'] !== null ? (string) $item['linked_type'] : null,
                'position'    => (int) $item['position'],
                'is_custom'   => (bool) $item['is_custom'],
            ];
        }

        $out = [];
        foreach ($sections as $section) {
            $out[] = [
                'id'       => (int) $section['id'],
                'code'     => (string) $section['code'],
                'name'     => (string) $section['name'],
                'position' => (int) $section['position'],
                'items'    => $bySection[(int) $section['id']] ?? [],
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function shape(array $row, array $sections): array
    {
        $itemCount = 0;
        foreach ($sections as $section) {
            $itemCount += count($section['items']);
        }
        return [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'name'       => (string) $row['name'],
            'is_custom'  => (bool) $row['is_custom'],
            'source_key' => $row['source_key'] !== null ? (string) $row['source_key'] : null,
            'item_count' => $itemCount,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'sections'   => $sections,
        ];
    }

    private static function normalizeScope(mixed $scope): string
    {
        $scope = is_string($scope) ? strtoupper(trim($scope)) : '';
        return in_array($scope, ['V', 'R', 'VR'], true) ? $scope : 'VR';
    }
}
