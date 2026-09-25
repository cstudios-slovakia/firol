<?php

declare(strict_types=1);

namespace Firol\Documents;

use Firol\Db;
use Firol\Storage\Storage;
use Firol\Support\AccountCertificates;
use Firol\Support\Contractor;
use Firol\Support\InspectionDetails;
use Firol\Support\PersonList;

/**
 * Issuing the protocol of a dychová skúška, a kontrola omamných látok or an
 * oboznámenie BOZP — chapters 8, 8.1 and 26. Called from DocumentController;
 * kept here so the three types' rules sit in one place.
 *
 * Numbering and versions (chapter 8.1 + documents.version from block 1)
 * ----------------------------------------------------------------------
 * A test can be printed blank for handwriting („prazdny") or as the filled
 * record („vyplneny"). Both are the SAME protocol and carry ONE number:
 *
 *   1. The blank form is issued: a number is allocated (DS-2026-007, version
 *      1, form_variant = prazdny) and the úkon locks like any issued protocol
 *      — the paper exists, the client signs it, the calendar counts the test
 *      as done.
 *   2. Optionally the technician types the handwritten results in
 *      („Doplniť výsledky" reopens the úkon WITHOUT discarding the form) and
 *      issues the filled record: DS-2026-007 version 2, form_variant =
 *      vyplneny. The blank file stays in document_versions.
 *
 * So the signed paper and the legible record in the app point at the same
 * number, exactly as a protocol signed on screen does (chapter 13), and no
 * number is spent on a sheet that is only the same test written differently.
 */
final class PersonProtocol
{
    /**
     * Everything that must be true before the protocol is issued, or null
     * when it can be. Returns the error in DocumentController's shape.
     *
     * @param array<string, mixed>       $inspection row incl. `details`
     * @param list<array<string, mixed>> $items
     * @return array{error: string, status: int}|null
     */
    public static function check(int $accountId, array $inspection, array $items, string $variant): ?array
    {
        $type = (string) $inspection['type'];
        if ($variant === PersonList::VARIANT_BLANK && !PersonList::isTestType($type)) {
            return ['error' => 'Prázdny formulár sa dá vytlačiť len pri dychovej skúške a kontrole omamných látok.', 'status' => 422];
        }
        $missing = PersonList::missingForProtocol(
            $type,
            InspectionDetails::decode($inspection['details'] ?? null),
            $items,
            $variant,
        );
        if ($missing !== []) {
            return ['error' => implode(' ', $missing), 'status' => 422];
        }
        // Chapter 5.3 — an oboznámenie is issued under the firm's oprávnenie
        // na výchovu a vzdelávanie. Without it the protocol would name no
        // oprávnenie at all, so it is not issued (texty_ui.json, chyba_firemne).
        if ($type === PersonList::SKOLENIE_BOZP && AccountCertificates::get($accountId, 'vv') === null) {
            return [
                'error'  => 'Firma nemá zadané potrebné oprávnenie. Obráťte sa na hlavného používateľa.',
                'status' => 422,
            ];
        }
        return null;
    }

    /**
     * What the templates need beyond the standard payload.
     *
     * The Zhotoviteľ block itself is not built here. Every protocol gets it
     * from {@see \Firol\Support\Contractor}, frozen at issue time, so a vv
     * number changed in the settings does not rewrite a signed document.
     *
     * @param array<string, mixed>       $inspection
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function payload(array $inspection, array $items, string $variant): array
    {
        $type = (string) $inspection['type'];
        $details = InspectionDetails::decode($inspection['details'] ?? null) ?? [];
        unset($details[self::CONTRACTOR_SNAPSHOT_KEY]);

        // Earliest time of the day on the list — „Dátum a čas: 12. 8. 2026,
        // 06:15" in the mockup. Never the time the PDF was made.
        $times = [];
        foreach ($items as $item) {
            $t = (string) (($item['fields'] ?? [])['time'] ?? '');
            if ($t !== '') {
                $times[] = $t;
            }
        }
        sort($times);

        $out = [
            'persons' => [
                'type'         => $type,
                'variant'      => $variant,
                'details'      => $details,
                'first_time'   => $variant === PersonList::VARIANT_FILLED ? ($times[0] ?? null) : null,
                'kind_label'   => $type === PersonList::SKOLENIE_BOZP ? PersonList::trainingKindLabel($details) : null,
            ],
        ];
        return $out;
    }

    /**
     * Key in inspections.details holding the Zhotoviteľ block as it was
     * printed when the protocol was issued. Written by the server only: the
     * header validator (PersonList::validateDetails) drops it from any PATCH,
     * so editing a reopened úkon clears it and the next issue writes it anew.
     */
    public const CONTRACTOR_SNAPSHOT_KEY = Contractor::SNAPSHOT_KEY;

    /**
     * The blank form already issued for this test, when the filled record is
     * now being issued (or the blank one re-issued) under its number.
     *
     * @return array{id: int, number: string, version: int}|null
     */
    public static function issuedBlank(int $accountId, int $inspectionId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, number, version, form_variant FROM documents
             WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?
             ORDER  BY id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $inspectionId]);
        $row = $stmt->fetch();
        if (!$row || $row['form_variant'] !== PersonList::VARIANT_BLANK) {
            return null;
        }
        return ['id' => (int) $row['id'], 'number' => (string) $row['number'], 'version' => (int) $row['version']];
    }

    /**
     * The client's on-screen signature of a protocol (chapter 13), in the
     * shape the templates print, or null when it was not signed on screen.
     *
     * @return array<string, mixed>|null
     */
    public static function handoverFor(int $documentId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT fullname, role_title, place, signed_on, signature_path
             FROM   document_handovers WHERE document_id = ?'
        );
        $stmt->execute([$documentId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $uri = null;
        $abs = Storage::absolute((string) $row['signature_path']);
        if (is_file($abs)) {
            $bytes = file_get_contents($abs);
            if ($bytes !== false) {
                $uri = 'data:image/png;base64,' . base64_encode($bytes);
            }
        }
        return [
            'fullname'           => (string) $row['fullname'],
            'role_title'         => (string) $row['role_title'],
            'place'              => (string) $row['place'],
            'signed_on'          => (string) $row['signed_on'],
            'signature_data_uri' => $uri,
        ];
    }

    /** The variant of the protocol as last issued — what a re-render reproduces. */
    public static function currentVariant(int $accountId, int $inspectionId): string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT form_variant FROM documents
             WHERE  account_id = ? AND parent_type = "inspection" AND parent_id = ?
             ORDER  BY id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $inspectionId]);
        $v = $stmt->fetchColumn();
        return $v === PersonList::VARIANT_BLANK ? PersonList::VARIANT_BLANK : PersonList::VARIANT_FILLED;
    }
}
