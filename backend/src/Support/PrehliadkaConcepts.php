<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Controllers\InspectionPhotoController;
use Firol\Db;
use Firol\Storage\Storage;

/**
 * Koncepty riadnych kontrol z uzavretej prehliadky (spec 7.3, plan phase 8b).
 *
 * Closing a preventívna prehliadka leaves one DRAFT úkon per riadna kontrola
 * ticked in step 1:
 *
 *   - the devices come from the latest finalized úkon of that type at the
 *     prevádzka ({@see CarryOver}, so a disposed device stays behind and the
 *     identification travels without last year's verdict), followed by the
 *     devices the walk-through wrote down that the list did not know
 *     (`details.new_devices`);
 *   - a device named in a defect gets the type's failing result — `O` for a
 *     hasiaci prístroj (decision D3), „nevyhovuje" for the others — with the
 *     defect text in the item's own note field and the photos of those defects
 *     copied onto the item;
 *   - every other device gets the passing result.
 *
 * A concept is NOT a protocol (spec 7.3: the app must not issue for a control
 * something the technician did not confirm). It is marked in `details`:
 *
 *     concept_from_prehliadka   int        the prehliadka it came from
 *     concept_built_at          datetime   when the app last filled it
 *     concept_item_count        int        how many items it had then
 *     concept_confirmed_at      datetime|null   set by the technician
 *
 * and {@see \Firol\Controllers\DocumentController::generateForInspectionInternal()}
 * refuses it until `concept_confirmed_at` is set. `source_inspection_id` is the
 * prehliadka.
 *
 * Idempotent per prehliadka and type. Closing again after „Upraviť" refreshes
 * a concept nobody has touched since it was built (its items, edits and
 * photos are compared with `concept_built_at`) and leaves a confirmed, edited
 * or already issued one exactly as it is.
 */
final class PrehliadkaConcepts
{
    public const KEY = 'concept_from_prehliadka';
    public const BUILT_KEY = 'concept_built_at';
    public const COUNT_KEY = 'concept_item_count';
    public const CONFIRMED_KEY = 'concept_confirmed_at';

    /** Status a hasiaci prístroj with a walk-through defect gets (decision D3). */
    private const PHP_FAILING_STATUS = 'O';
    private const PHP_PASSING_STATUS = 'A';

    /** Longest note the item validators accept. */
    private const MAX_NOTE = 500;

    /** The kinds of požiarny uzáver, found in a hand-written description. */
    private const PU_KIND_WORDS = ['dver' => 'dvere', 'okn' => 'okno', 'klap' => 'klapka'];

    /** @param array<string, mixed>|null $details */
    public static function isConcept(?array $details): bool
    {
        return is_array($details) && isset($details[self::KEY]) && is_int($details[self::KEY]);
    }

    /**
     * A concept the technician has not confirmed yet.
     *
     * @param array<string, mixed>|null $details
     */
    public static function isPending(?array $details): bool
    {
        return self::isConcept($details) && empty($details[self::CONFIRMED_KEY]);
    }

    /**
     * Create, refresh or keep the concept of every ticked routine control.
     *
     * Runs inside the closer's transaction. Photo files written are appended to
     * `$files` (removed again when the transaction fails); files of a replaced
     * concept go to `$staleFiles` and are removed only after the commit.
     *
     * @param array<string, mixed> $inspection the prehliadka row
     * @param array<string, mixed> $details validated PrehliadkaDetails
     * @param list<array<string, mixed>> $items the defects (`id`, `fields`)
     * @param list<string> $files
     * @param list<string> $staleFiles
     * @return list<array{inspection_id: int, type: string, state: string}> state = created|refreshed|kept
     */
    public static function build(
        int $accountId,
        int $userId,
        array $inspection,
        array $details,
        array $items,
        array &$files,
        array &$staleFiles,
    ): array {
        $routine = array_values(array_filter((array) ($details['routine'] ?? []), 'is_string'));

        $out = [];
        foreach ($routine as $type) {
            $one = self::buildOne($accountId, $userId, $inspection, $details, $items, $type, $files, $staleFiles);
            if ($one !== null) {
                $out[] = $one;
            }
        }

        // A type that was ticked when this prehliadka was closed first and is not
        // any more: its untouched concept would otherwise stay behind as a draft
        // nobody asked for. A touched one is the technician's work — left alone.
        foreach (self::conceptsOf($accountId, (int) $inspection['id']) as $row) {
            if (in_array($row['type'], $routine, true) || $row['status'] !== 'draft') {
                continue;
            }
            if (!self::isTouched($row)) {
                self::archive($accountId, $row['id']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $inspection
     * @param array<string, mixed> $details
     * @param list<array<string, mixed>> $items
     * @param list<string> $files
     * @param list<string> $staleFiles
     * @return array{inspection_id: int, type: string, state: string}|null null when there is nothing to prepare
     */
    private static function buildOne(
        int $accountId,
        int $userId,
        array $inspection,
        array $details,
        array $items,
        string $type,
        array &$files,
        array &$staleFiles,
    ): ?array {
        $pdo = Db::pdo();
        $prehliadkaId = (int) $inspection['id'];
        $facilityId = (int) $inspection['facility_id'];

        $existing = null;
        foreach (self::conceptsOf($accountId, $prehliadkaId) as $row) {
            if ($row['type'] === $type) {
                $existing = $row;
                break;
            }
        }
        // Issued already, or the technician's work in progress: not ours to redo.
        if ($existing !== null && ($existing['status'] !== 'draft' || self::isTouched($existing))) {
            return ['inspection_id' => $existing['id'], 'type' => $type, 'state' => 'kept'];
        }

        $failing = self::failingDevices($items, $type);
        $built = self::conceptItems($accountId, $facilityId, $type, $details, $failing);
        if ($built === []) {
            // No devices of this type known anywhere — only a stale draft to retire.
            if ($existing !== null) {
                self::archive($accountId, $existing['id']);
            }
            return null;
        }

        $visitId = self::visitOf($prehliadkaId);
        $state = 'created';
        if ($existing === null) {
            [$value, $unit] = self::lastPeriodicity($accountId, $facilityId, $type);
            $pdo->prepare(
                'INSERT INTO inspections
                    (account_id, company_id, facility_id, visit_id, source_inspection_id, type,
                     periodicity_value, periodicity_unit, periodicity_is_custom,
                     executed_on, inspector_user_id, status, is_preventive_inspection, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", 1, ?)'
            )->execute([
                $accountId,
                (int) $inspection['company_id'],
                $facilityId,
                $visitId,
                $prehliadkaId,
                $type,
                $value,
                $unit,
                Periodicity::isCustom($type, $value, $unit) ? 1 : 0,
                (string) $inspection['executed_on'],
                (int) $inspection['inspector_user_id'],
                $userId,
            ]);
            $conceptId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE inspections SET billing_mode = ? WHERE id = ?')
                ->execute([Invoicing::companyMode((int) $inspection['company_id']), $conceptId]);
        } else {
            $state = 'refreshed';
            $conceptId = $existing['id'];
            // Whatever the first close copied is replaced; its photo files go after the commit.
            $old = $pdo->prepare('SELECT file_path, thumb_path FROM inspection_item_photos WHERE inspection_id = ?');
            $old->execute([$conceptId]);
            foreach ($old->fetchAll() as $p) {
                $staleFiles[] = Storage::absolute((string) $p['file_path']);
                $staleFiles[] = Storage::absolute((string) $p['thumb_path']);
            }
            $pdo->prepare('DELETE FROM inspection_items WHERE inspection_id = ?')->execute([$conceptId]);
            // The prehliadka may have moved to another date, technician or visit while it was open.
            $pdo->prepare(
                'UPDATE inspections SET executed_on = ?, inspector_user_id = ?, visit_id = ? WHERE id = ? AND account_id = ?'
            )->execute([
                (string) $inspection['executed_on'],
                (int) $inspection['inspector_user_id'],
                $visitId,
                $conceptId,
                $accountId,
            ]);
        }

        $insert = $pdo->prepare('INSERT INTO inspection_items (inspection_id, position, fields) VALUES (?, ?, ?)');
        foreach ($built as $i => $row) {
            $insert->execute([$conceptId, $i + 1, json_encode($row['fields'], JSON_UNESCAPED_UNICODE)]);
            $itemId = (int) $pdo->lastInsertId();
            if ($row['photo_items'] !== []) {
                self::copyPhotos($accountId, $prehliadkaId, $row['photo_items'], $conceptId, $itemId, $files);
            }
        }

        // Stamped last, so no item or photo written above counts as the technician's edit.
        $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        $pdo->prepare('UPDATE inspections SET details = ? WHERE id = ? AND account_id = ?')->execute([
            InspectionDetails::encode([
                self::KEY           => $prehliadkaId,
                self::BUILT_KEY     => $now,
                self::COUNT_KEY     => count($built),
                self::CONFIRMED_KEY => null,
            ]),
            $conceptId,
            $accountId,
        ]);

        return ['inspection_id' => $conceptId, 'type' => $type, 'state' => $state];
    }

    private static function archive(int $accountId, int $inspectionId): void
    {
        Db::pdo()->prepare('UPDATE inspections SET archived_at = NOW() WHERE id = ? AND account_id = ?')
            ->execute([$inspectionId, $accountId]);
    }

    /**
     * The devices of a type that the defects name: reference → the defects
     * (their item id and text) that name it. References are `s{source_item_id}`
     * for a device of the derived list and `n{tmp_id}` for a new one.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, array{defects: list<array{item_id: int, text: string}>}>
     */
    private static function failingDevices(array $items, string $type): array
    {
        $out = [];
        foreach ($items as $item) {
            $f = (array) ($item['fields'] ?? []);
            $text = self::defectText($f);
            foreach ((array) ($f['devices'] ?? []) as $d) {
                if (!is_array($d) || ($d['type'] ?? null) !== $type) {
                    continue;
                }
                if (isset($d['source_item_id']) && is_int($d['source_item_id'])) {
                    $ref = 's' . $d['source_item_id'];
                } elseif (isset($d['new_device_tmp_id']) && is_string($d['new_device_tmp_id'])) {
                    $ref = 'n' . $d['new_device_tmp_id'];
                } else {
                    continue; // only a snapshot is left; nothing to point at
                }
                $out[$ref]['defects'][] = ['item_id' => (int) $item['id'], 'text' => $text];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $fields */
    private static function defectText(array $fields): string
    {
        $title = trim((string) ($fields['title'] ?? ''));
        $description = trim((string) ($fields['description'] ?? ''));
        if ($title !== '' && $description !== '' && $title !== $description) {
            return $title . ': ' . $description;
        }
        return $title !== '' ? $title : $description;
    }

    /**
     * The items of the concept, in the order the technician knows them: the
     * carried devices first, the ones written down during the walk after.
     *
     * @param array<string, mixed> $details
     * @param array<string, array{defects: list<array{item_id: int, text: string}>}> $failing
     * @return list<array{fields: array<string, mixed>, photo_items: list<int>}>
     */
    private static function conceptItems(int $accountId, int $facilityId, string $type, array $details, array $failing): array
    {
        $rows = [];

        $sourceId = CarryOver::latestSourceId($accountId, $facilityId, $type);
        if ($sourceId !== null) {
            $stmt = Db::pdo()->prepare(
                'SELECT id, fields FROM inspection_items WHERE inspection_id = ? ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$sourceId]);
            foreach ($stmt->fetchAll() as $row) {
                $source = json_decode((string) $row['fields'], true);
                if (!is_array($source)) {
                    continue;
                }
                $carried = CarryOver::mapItem($type, $source);
                if ($carried === null) {
                    continue; // disposed last time — stays behind
                }
                $rows[] = self::withResult($type, $carried, $failing['s' . (int) $row['id']] ?? null);
            }
        }

        foreach ((array) ($details['new_devices'] ?? []) as $nd) {
            if (!is_array($nd) || ($nd['type'] ?? null) !== $type || !is_string($nd['tmp_id'] ?? null)) {
                continue;
            }
            $rows[] = self::withResult($type, self::newDeviceFields($type, $nd), $failing['n' . $nd['tmp_id']] ?? null);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $fields identification + blank assessment
     * @param array{defects: list<array{item_id: int, text: string}>}|null $hit
     * @return array{fields: array<string, mixed>, photo_items: list<int>}
     */
    private static function withResult(string $type, array $fields, ?array $hit): array
    {
        $fail = $hit !== null;
        $note = null;
        if ($hit !== null) {
            $texts = array_values(array_unique(array_filter(
                array_map(static fn (array $d): string => $d['text'], $hit['defects']),
                static fn (string $t): bool => $t !== '',
            )));
            $note = implode(' | ', $texts);
            if (mb_strlen($note) > self::MAX_NOTE) {
                $note = mb_substr($note, 0, self::MAX_NOTE - 1) . '…';
            }
            $note = $note === '' ? null : $note;
        }

        switch ($type) {
            case 'php':
                $fields['status'] = $fail ? self::PHP_FAILING_STATUS : self::PHP_PASSING_STATUS;
                $fields['notes'] = $note;
                break;
            case 'hydranty':
                $fields['result'] = $fail ? 'nevyhovuje' : 'vyhovuje';
                $fields['defects'] = $note;
                break;
            case 'rebriky':
            case 'regale':
                $fields['result'] = $fail ? 'nevyhovuje' : 'vyhovuje';
                $fields['faults'] = $note;
                break;
            default: // pu_akcieschopnost, pu_udrzba, nudzove_osvetlenie
                $fields['result'] = $fail ? 'nevyhovuje' : 'vyhovuje';
                $fields['notes'] = $note;
        }

        $photoItems = $hit === null
            ? []
            : array_values(array_unique(array_map(static fn (array $d): int => $d['item_id'], $hit['defects'])));
        return ['fields' => $fields, 'photo_items' => $photoItems];
    }

    /**
     * Identification of a device the walk-through wrote down — the inverse of
     * {@see FacilityDevices} (label, serial, description, location). What the
     * walk did not ask for stays blank for the technician to fill in.
     *
     * @param array<string, mixed> $nd
     * @return array<string, mixed>
     */
    private static function newDeviceFields(string $type, array $nd): array
    {
        $label = (string) ($nd['label'] ?? '');
        $serial = is_string($nd['serial'] ?? null) && $nd['serial'] !== '' ? $nd['serial'] : null;
        $description = is_string($nd['description'] ?? null) ? $nd['description'] : '';
        $location = is_string($nd['location'] ?? null) ? $nd['location'] : '';

        $identification = match ($type) {
            'php' => [
                'manufacturer' => $description, 'type' => '', 'serial' => $serial ?? $label,
                'year' => null, 'location' => $location,
            ],
            'hydranty' => [
                'type' => 'other', 'type_other' => $label, 'location' => $location, 'hose_count' => null,
            ],
            'pu_akcieschopnost', 'pu_udrzba' => [
                'kind' => self::puKind($description), 'identifier' => $label, 'manufacturer' => '', 'location' => $location,
            ],
            'nudzove_osvetlenie' => [
                'evid_number' => $label, 'floor' => null, 'luminaire_type' => $description,
                'manufacturer' => '', 'location' => $location,
            ],
            'rebriky' => [
                'inventory_number' => $label, 'type' => $description, 'manufacturer' => '',
                'year' => null, 'location' => $location,
            ],
            'regale' => [
                'label' => $label, 'type' => $description, 'capacity' => '', 'location' => $location,
            ],
            default => [],
        };
        return $identification + CarryOver::blank($type);
    }

    private static function puKind(string $description): string
    {
        $d = mb_strtolower($description);
        foreach (self::PU_KIND_WORDS as $word => $kind) {
            if (str_contains($d, $word)) {
                return $kind;
            }
        }
        return '';
    }

    /**
     * The photos of the defects that name a device, copied onto its item.
     * The copy is a file of its own (a later edit of the prehliadka's photos
     * must not move the concept's).
     *
     * @param list<int> $fromItemIds defect items of the prehliadka
     * @param list<string> $files
     */
    private static function copyPhotos(
        int $accountId,
        int $fromInspectionId,
        array $fromItemIds,
        int $toInspectionId,
        int $toItemId,
        array &$files,
    ): void {
        $pdo = Db::pdo();
        $in = implode(',', array_fill(0, count($fromItemIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT file_path, thumb_path, byte_size, width, height
             FROM   inspection_item_photos
             WHERE  inspection_id = ? AND account_id = ? AND item_id IN ($in)
             ORDER  BY item_id ASC, position ASC, id ASC"
        );
        $stmt->execute([$fromInspectionId, $accountId, ...$fromItemIds]);
        $photos = $stmt->fetchAll();
        if ($photos === []) {
            return;
        }

        Storage::ensureDir(Storage::photoDir($accountId, $toInspectionId));
        $insert = $pdo->prepare(
            'INSERT INTO inspection_item_photos
                (account_id, inspection_id, item_id, defect_key, position,
                 file_path, thumb_path, byte_size, width, height)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        );
        $position = 0;
        foreach ($photos as $p) {
            if ($position >= InspectionPhotoController::MAX_PER_ITEM) {
                break;
            }
            $srcFull = Storage::absolute((string) $p['file_path']);
            $srcThumb = Storage::absolute((string) $p['thumb_path']);
            if (!is_file($srcFull) || !is_file($srcThumb)) {
                error_log('[concept-photos] file missing: ' . $srcFull);
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $relFull = Storage::photoRelative($accountId, $toInspectionId, $token);
            $relThumb = Storage::photoThumbRelative($accountId, $toInspectionId, $token);
            $dstFull = Storage::absolute($relFull);
            $dstThumb = Storage::absolute($relThumb);
            if (!copy($srcFull, $dstFull)) {
                throw new \RuntimeException('Failed to copy a photo to the concept.');
            }
            $files[] = $dstFull;
            if (!copy($srcThumb, $dstThumb)) {
                throw new \RuntimeException('Failed to copy a photo thumbnail to the concept.');
            }
            $files[] = $dstThumb;

            $position++;
            $insert->execute([
                $accountId, $toInspectionId, $toItemId, $position, $relFull, $relThumb,
                (int) $p['byte_size'], (int) $p['width'], (int) $p['height'],
            ]);
        }
    }

    /**
     * The concepts a prehliadka has produced, not archived.
     *
     * @return list<array{id: int, type: string, status: string, details: array<string, mixed>}>
     */
    public static function conceptsOf(int $accountId, int $prehliadkaId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, type, status, details FROM inspections
             WHERE  account_id = ? AND source_inspection_id = ? AND archived_at IS NULL AND type <> "kniha_bozp"
             ORDER  BY id ASC'
        );
        $stmt->execute([$accountId, $prehliadkaId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $details = InspectionDetails::decode($row['details'] ?? null);
            if ($details === null || !self::isConcept($details) || $details[self::KEY] !== $prehliadkaId) {
                continue; // a follow-up of some other kind
            }
            $out[] = [
                'id'      => (int) $row['id'],
                'type'    => (string) $row['type'],
                'status'  => (string) $row['status'],
                'details' => $details,
            ];
        }
        return $out;
    }

    /**
     * What the result screen shows per concept: how many devices it holds and
     * how many of them „nevyhovuje" (status O for a hasiaci prístroj).
     *
     * @return list<array{inspection_id: int, type: string, status: string, confirmed: bool, items: int, failing: int}>
     */
    public static function summary(int $accountId, int $prehliadkaId): array
    {
        $stmt = Db::pdo()->prepare('SELECT fields FROM inspection_items WHERE inspection_id = ?');
        $out = [];
        foreach (self::conceptsOf($accountId, $prehliadkaId) as $c) {
            $stmt->execute([$c['id']]);
            $items = 0;
            $failing = 0;
            foreach ($stmt->fetchAll() as $row) {
                $f = json_decode((string) $row['fields'], true);
                $items++;
                if (is_array($f) && ($c['type'] === 'php'
                    ? ($f['status'] ?? null) === self::PHP_FAILING_STATUS
                    : ($f['result'] ?? null) === 'nevyhovuje')) {
                    $failing++;
                }
            }
            $out[] = [
                'inspection_id' => $c['id'],
                'type'          => $c['type'],
                'status'        => $c['status'],
                'confirmed'     => !empty($c['details'][self::CONFIRMED_KEY]),
                'items'         => $items,
                'failing'       => $failing,
            ];
        }
        return $out;
    }

    /**
     * The items of a concept that still lack a required field — what stands
     * between it and a confirmation (spec 7.3: the app must not issue for a
     * control something incomplete the technician never filled in). Which fields
     * a COMPLETE item of the type needs is decided by the item validators of
     * {@see \Firol\Controllers\InspectionItemController::missingFields()}, the
     * same ones that refuse an incomplete item on save — nothing is repeated here.
     *
     * Devices the walk-through wrote down start with the fields it did not ask
     * for blank (year, výrobca, druh uzáveru, …); a device carried over from the
     * last úkon is complete in its identification but, like every concept item,
     * has the measurements of the type blank (HS / HD / Q, trvanie svietenia, …)
     * — those are required too, as for any úkon of that type. A row whose
     * `fields` is not an object (odd legacy data) counts as having nothing filled in.
     *
     * @param list<array<string, mixed>> $items each with `id`, `position` and `fields`
     * @return list<array{item_id: int, name: string, missing: list<string>}>
     */
    public static function incompleteItems(string $type, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $missing = \Firol\Controllers\InspectionItemController::missingFields($type, $fields);
            if ($missing === []) {
                continue;
            }
            // The label alone does not tell devices apart (every DN52 hydrant reads „DN52"),
            // so the place goes with it.
            $shaped = FacilityDevices::shape($type, $fields);
            $name = $shaped === null
                ? 'Položka č. ' . (int) ($item['position'] ?? 0)
                : $shaped['label'] . ($shaped['location'] !== null ? ' · ' . $shaped['location'] : '');
            $out[] = [
                'item_id' => (int) $item['id'],
                'name'    => $name,
                'missing' => $missing,
            ];
        }
        return $out;
    }

    /**
     * The Slovak refusal that names the devices and what each one lacks, e.g.
     * „Koncept nie je úplný — doplň povinné údaje: LP1-0002 (Typ, Rok výroby)."
     * A long list is cut after five devices.
     *
     * @param list<array{item_id: int, name: string, missing: list<string>}> $incomplete
     */
    public static function incompleteMessage(array $incomplete): string
    {
        $shown = array_slice($incomplete, 0, 5);
        $parts = array_map(
            static fn (array $r): string => $r['name'] . ' (' . implode(', ', $r['missing']) . ')',
            $shown,
        );
        $message = 'Koncept nie je úplný — doplň povinné údaje: ' . implode('; ', $parts) . '.';
        $rest = count($incomplete) - count($shown);
        if ($rest > 0) {
            $message .= ' Ešte ' . $rest . ' ' . ($rest === 1 ? 'zariadenie' : ($rest < 5 ? 'zariadenia' : 'zariadení')) . '.';
        }
        return $message;
    }

    /**
     * Has the technician worked on this concept since the app filled it:
     * confirmed it, edited, added or removed an item, or added a photo.
     *
     * @param array{id: int, details: array<string, mixed>} $concept
     */
    private static function isTouched(array $concept): bool
    {
        if (!empty($concept['details'][self::CONFIRMED_KEY])) {
            return true;
        }
        $built = $concept['details'][self::BUILT_KEY] ?? null;
        if (!is_string($built)) {
            return true; // unknown state: do not overwrite
        }
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) AS n, MAX(updated_at) AS u, MAX(created_at) AS c,
                    (SELECT MAX(p.created_at) FROM inspection_item_photos p WHERE p.inspection_id = ?) AS p
             FROM   inspection_items WHERE inspection_id = ?'
        );
        $stmt->execute([$concept['id'], $concept['id']]);
        $r = $stmt->fetch();
        if ((int) $r['n'] !== (int) ($concept['details'][self::COUNT_KEY] ?? -1)) {
            return true;
        }
        foreach (['u', 'c', 'p'] as $k) {
            if ($r[$k] !== null && strcmp((string) $r[$k], $built) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Last used periodicity of this type here, else the type's recommended first one.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private static function lastPeriodicity(int $accountId, int $facilityId, string $type): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT periodicity_value, periodicity_unit FROM inspections
             WHERE  account_id = ? AND facility_id = ? AND type = ?
               AND  status = "finalized" AND archived_at IS NULL AND executed_on IS NOT NULL
             ORDER  BY executed_on DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $facilityId, $type]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                $row['periodicity_value'] !== null ? (int) $row['periodicity_value'] : null,
                $row['periodicity_unit'] !== null ? (string) $row['periodicity_unit'] : null,
            ];
        }
        $recommended = Periodicity::RECOMMENDED_MONTHS[$type] ?? [];
        return isset($recommended[0]) ? [$recommended[0], 'mesiac'] : [null, null];
    }

    private static function visitOf(int $inspectionId): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT visit_id FROM inspections WHERE id = ?');
        $stmt->execute([$inspectionId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (int) $v : null;
    }
}
