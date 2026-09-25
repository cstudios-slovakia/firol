<?php

declare(strict_types=1);

namespace Firol\Support;

use Firol\Db;

/**
 * The technician's firm as printed in the „Zhotoviteľ" row — chapter 1.3.3.
 *
 * Name, IČO and address come from the account (the firm the technician works
 * for), not from the client the protocol is about. A company certificate is
 * added only when the úkon requires one. Today that is `skolenie_bozp` and
 * the firm's `vv` („oprávnenie na výchovu a vzdelávanie"). Missing IČO or
 * address is left out; it never blocks issuing.
 *
 * The block is frozen onto the úkon when the protocol is issued
 * (`inspections.details.issued_contractor`, or `trainings.fields` for a
 * školenie / pokyn). A later re-render — a signature on screen, chapter 13 —
 * prints that copy, so renaming the firm or editing its oprávnenie afterwards
 * does not rewrite a document that already has a number.
 */
final class Contractor
{
    /** Key of the frozen block. Server-owned: header validators do not keep it. */
    public const SNAPSHOT_KEY = 'issued_contractor';

    /**
     * The firm as it stands now, plus the company certificate this document
     * type prints, if any.
     *
     * @return array{name: string, ico: ?string, address: ?string, certificate: ?array<string, string>}
     */
    public static function live(int $accountId, string $documentType): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT invoice_company_name, invoice_ico, invoice_street, invoice_postal_code, invoice_city
             FROM   accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $a = $stmt->fetch() ?: [];

        return [
            'name'        => (string) ($a['invoice_company_name'] ?? ''),
            'ico'         => self::blankToNull($a['invoice_ico'] ?? null),
            'address'     => Address::format(
                $a['invoice_street'] ?? null,
                $a['invoice_postal_code'] ?? null,
                $a['invoice_city'] ?? null,
            ),
            'certificate' => self::companyCertificate($accountId, $documentType),
        ];
    }

    /**
     * The block frozen at issue time, or null when this úkon was issued
     * before the row existed.
     *
     * @param array<string, mixed> $inspection row incl. `details`
     * @return array<string, mixed>|null
     */
    public static function frozenFromInspection(array $inspection): ?array
    {
        $details = InspectionDetails::decode($inspection['details'] ?? null);
        $frozen = is_array($details) ? ($details[self::SNAPSHOT_KEY] ?? null) : null;
        return is_array($frozen) ? $frozen : null;
    }

    /**
     * Same snapshot, stored beside a pokyn's own payload in `trainings.fields`.
     *
     * @return array<string, mixed>|null
     */
    public static function frozenFromJson(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        $frozen = is_array($decoded) ? ($decoded[self::SNAPSHOT_KEY] ?? null) : null;
        return is_array($frozen) ? $frozen : null;
    }

    /**
     * What a template should print: the frozen block once the protocol has
     * been issued, otherwise the firm as it stands now.
     *
     * @param array<string, mixed>|null $frozen
     * @return array{name: string, ico: ?string, address: ?string, certificate: ?array<string, string>}
     */
    public static function forDocument(int $accountId, string $documentType, ?array $frozen): array
    {
        if (is_array($frozen)) {
            return [
                'name'        => (string) ($frozen['name'] ?? ''),
                'ico'         => self::blankToNull($frozen['ico'] ?? null),
                'address'     => self::blankToNull($frozen['address'] ?? null),
                'certificate' => is_array($frozen['certificate'] ?? null) ? $frozen['certificate'] : null,
            ];
        }
        return self::live($accountId, $documentType);
    }

    /**
     * Write the block onto an inspection. Merges into `details` so a header
     * the úkon already has (device, opatrenia, záver) stays put.
     *
     * @param array<string, mixed> $inspection row incl. `details`
     * @param array<string, mixed> $contractor
     */
    public static function freezeInspection(int $inspectionId, array $inspection, array $contractor): void
    {
        $details = InspectionDetails::decode($inspection['details'] ?? null) ?? [];
        $details[self::SNAPSHOT_KEY] = self::snapshot($contractor);
        Db::pdo()->prepare('UPDATE inspections SET details = ? WHERE id = ?')
            ->execute([InspectionDetails::encode($details), $inspectionId]);
    }

    /**
     * Write the block onto a training. A pokyn already keeps its text in
     * `fields`; the snapshot is one more key, which {@see PokynZatva::decode}
     * ignores, so the instruction text is unchanged.
     *
     * @param array<string, mixed> $contractor
     */
    public static function freezeTraining(int $trainingId, mixed $fieldsJson, array $contractor): void
    {
        $decoded = [];
        if (is_string($fieldsJson) && $fieldsJson !== '') {
            $tmp = json_decode($fieldsJson, true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        }
        $decoded[self::SNAPSHOT_KEY] = self::snapshot($contractor);
        Db::pdo()->prepare('UPDATE trainings SET fields = ? WHERE id = ?')
            ->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE), $trainingId]);
    }

    /**
     * Header data safe to hand to a client or a template. The snapshot is
     * server-owned; a round-trip through a details form must not have to
     * know it exists.
     *
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    public static function visibleDetails(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }
        unset($details[self::SNAPSHOT_KEY]);
        return $details === [] ? null : $details;
    }

    /**
     * The value cell: „Firma s.r.o., IČO 12345678, Ulica 1, 811 01 Mesto"
     * and, when this úkon has one, a second line „oprávnenie na …: číslo".
     * Whichever of IČO and the address is missing is skipped.
     *
     * @param array<string, mixed> $contractor
     */
    public static function cellHtml(array $contractor): string
    {
        $parts = [];
        $name = trim((string) ($contractor['name'] ?? ''));
        if ($name !== '') {
            $parts[] = self::esc($name);
        }
        $ico = self::blankToNull($contractor['ico'] ?? null);
        if ($ico !== null) {
            $parts[] = 'IČO ' . self::esc($ico);
        }
        $html = implode(', ', $parts);

        $address = self::blankToNull($contractor['address'] ?? null);
        if ($address !== null) {
            $html .= ($html !== '' ? ', ' : '') . self::esc($address);
        }

        $certificate = $contractor['certificate'] ?? null;
        if (is_array($certificate)) {
            $number = trim((string) ($certificate['number'] ?? ''));
            if ($number !== '') {
                $label = trim((string) ($certificate['label'] ?? ''));
                $html .= ($html !== '' ? '<br>' : '') . self::esc($label) . ': ' . self::esc($number);
            }
        }
        return $html;
    }

    /** One Základné informácie row, in the same cells as the rows around it. */
    public static function basicInfoRow(array $contractor): string
    {
        return '<tr><td class="bl">Zhotoviteľ</td><td class="bv" colspan="3">'
            . self::cellHtml($contractor) . '</td></tr>';
    }

    /**
     * Company certificate this document type prints, or null when the type
     * needs none (or the account has not entered it). Chapter 5.3: only an
     * oboznámenie BOZP is issued under `vv`.
     *
     * @return array{label: string, number_label: string, number: string}|null
     */
    private static function companyCertificate(int $accountId, string $documentType): ?array
    {
        if ($documentType !== PersonList::SKOLENIE_BOZP) {
            return null;
        }
        $vv = AccountCertificates::get($accountId, 'vv');
        if ($vv === null) {
            return null;
        }
        return [
            'label'        => AccountCertificates::PROTOCOL_LABELS['vv'],
            'number_label' => AccountCertificates::NUMBER_LABELS['vv'],
            'number'       => (string) $vv['number'],
        ];
    }

    /**
     * @param array<string, mixed> $contractor
     * @return array{name: string, ico: ?string, address: ?string, certificate: ?array<string, mixed>}
     */
    private static function snapshot(array $contractor): array
    {
        return [
            'name'        => (string) ($contractor['name'] ?? ''),
            'ico'         => self::blankToNull($contractor['ico'] ?? null),
            'address'     => self::blankToNull($contractor['address'] ?? null),
            'certificate' => is_array($contractor['certificate'] ?? null) ? $contractor['certificate'] : null,
        ];
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
