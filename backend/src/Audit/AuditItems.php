<?php

declare(strict_types=1);

namespace Firol\Audit;

/**
 * The shape of one evaluated audit item, and the sums drawn from a whole
 * audit — block 3 / chapter 15.
 *
 * An audit item is an `inspection_items` row whose `fields` carry both the
 * question (copied from the checklist when the audit was created) and the
 * answer. The question travels WITH the answer on purpose: a protocol issued
 * in March has to keep reading the way it read in March even after the
 * technician rewords the checklist in June.
 */
final class AuditItems
{
    public const SCOPES  = ['vstupny', 'rocny'];
    public const RESULTS = ['vyhovuje', 'nevyhovuje', 'neaplikovatelne'];

    /** Which checklist items belong in an audit of the given scope. */
    public static function scopeIncludes(string $auditScope, string $itemScope): bool
    {
        return match ($itemScope) {
            'V'     => $auditScope === 'vstupny',
            'R'     => $auditScope === 'rocny',
            default => true,
        };
    }

    /**
     * Normalise the answer coming off the wire onto the question already
     * stored, so a PATCH can never rewrite the item's text, its legal basis or
     * which section it sits in. Only the evaluation is the technician's to
     * change here; rewording belongs to the checklist editor (chapter 17).
     *
     * @param array<string, mixed> $stored  the item's current fields
     * @param array<string, mixed> $body    the request payload
     * @return array<string, mixed>
     * @throws \InvalidArgumentException with a Slovak message on bad input
     */
    public static function applyAnswer(array $stored, array $body): array
    {
        $result = $body['result'] ?? null;
        if ($result !== null && $result !== '') {
            if (!is_string($result) || !in_array($result, self::RESULTS, true)) {
                throw new \InvalidArgumentException('Neznámy výsledok hodnotenia.');
            }
        } else {
            $result = null;
        }

        // Poznámka and fotky belong to every result, not only to a failing one:
        // a photo of a compliant item (the poplachové smernice on the wall) is
        // how an auditor evidences the state they found. Photos hang off the
        // item row itself, so only the note is handled here.
        $note = self::text($body['note'] ?? null, 2000, 'Poznámka je príliš dlhá.');

        $description = null;
        $measure     = null;
        $deadline    = null;

        if ($result === 'nevyhovuje') {
            $description = self::text(
                $body['defect_description'] ?? null,
                1000,
                'Popis nedostatku je príliš dlhý.',
            );
            if ($description === null) {
                throw new \InvalidArgumentException('Pri nevyhovujúcej položke doplň popis nedostatku.');
            }
            $measure  = self::text($body['measure'] ?? null, 1000, 'Opatrenie je príliš dlhé.');
            $deadline = self::date($body['deadline'] ?? null);
        }
        // For any other result the defect fields are dropped rather than kept
        // hidden. A description left behind by a corrected answer would print
        // on nothing but would still be in the export — and would read as a
        // finding the technician had withdrawn.

        return [
            ...$stored,
            'result'            => $result,
            'note'              => $note,
            'defect_description' => $description,
            'measure'           => $measure,
            'deadline'          => $deadline,
        ];
    }

    /**
     * Fields of a fresh item copied out of a checklist.
     *
     * @param array<string, mixed> $templateItem
     * @return array<string, mixed>
     */
    public static function fromTemplate(
        array $templateItem,
        string $sectionCode,
        string $sectionName,
        int $sectionPosition,
    ): array {
        return [
            'section_code'     => $sectionCode,
            'section_name'     => $sectionName,
            'section_position' => $sectionPosition,
            'section_excluded' => false,
            'text'             => (string) $templateItem['text'],
            'legal_basis'      => $templateItem['legal_basis'] ?? null,
            'scope'            => (string) ($templateItem['scope'] ?? 'VR'),
            'linked_type'      => $templateItem['linked_type'] ?? null,
            'is_custom'        => (bool) ($templateItem['is_custom'] ?? false),
            'result'           => null,
            'note'             => null,
            'defect_description' => null,
            'measure'          => null,
            'deadline'         => null,
            // Chapter 16 — set when the answer was taken over from a separate
            // úkon, so the protocol can print which protocol covered it.
            'taken_from'       => null,
        ];
    }

    /**
     * An item the technician typed into a running audit. Section must be one
     * the audit already has, or a new one they are adding.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function customFromBody(array $body, int $sectionPosition): array
    {
        $text = self::text($body['text'] ?? null, 400, 'Znenie položky je príliš dlhé.');
        if ($text === null) {
            throw new \InvalidArgumentException('Zadaj znenie položky.');
        }
        $sectionCode = self::text($body['section_code'] ?? null, 8, 'Kód sekcie je príliš dlhý.');
        $sectionName = self::text($body['section_name'] ?? null, 160, 'Názov sekcie je príliš dlhý.');
        if ($sectionCode === null || $sectionName === null) {
            throw new \InvalidArgumentException('Zadaj sekciu, do ktorej položka patrí.');
        }

        return [
            'section_code'     => $sectionCode,
            'section_name'     => $sectionName,
            'section_position' => $sectionPosition,
            'section_excluded' => (bool) ($body['section_excluded'] ?? false),
            'text'             => $text,
            'legal_basis'      => self::text($body['legal_basis'] ?? null, 160, 'Právny základ je príliš dlhý.'),
            'scope'            => self::scope($body['scope'] ?? 'VR'),
            'linked_type'      => null,
            'is_custom'        => true,
            'result'           => null,
            'note'             => null,
            'defect_description' => null,
            'measure'          => null,
            'deadline'         => null,
            'taken_from'       => null,
        ];
    }

    /**
     * Section-by-section and overall figures, plus the numbered list of
     * findings. Used by both the fill screen ("Vyplnené 84 zo 109") and the
     * protocol, so the two can never disagree.
     *
     * Excluded sections are counted out of everything: their items are not
     * unanswered questions, they are questions that were never asked.
     *
     * @param list<array<string, mixed>> $items  inspection_items rows (fields decoded)
     * @return array<string, mixed>
     */
    public static function summarize(array $items): array
    {
        $sections = [];
        $defects  = [];
        $answered = 0;
        $total    = 0;
        $ok       = 0;
        $failed   = 0;

        foreach ($items as $item) {
            $f    = $item['fields'] ?? [];
            $code = (string) ($f['section_code'] ?? '');
            if (!isset($sections[$code])) {
                $sections[$code] = [
                    'code'            => $code,
                    'name'            => (string) ($f['section_name'] ?? ''),
                    'position'        => (int) ($f['section_position'] ?? 0),
                    'excluded'        => (bool) ($f['section_excluded'] ?? false),
                    'vyhovuje'        => 0,
                    'nevyhovuje'      => 0,
                    'neaplikovatelne' => 0,
                    'unanswered'      => 0,
                    'total'           => 0,
                ];
            }
            // One item carrying the flag is enough — the bulk action writes it
            // onto all of them, but a half-written batch must not read as half
            // excluded.
            if (!empty($f['section_excluded'])) {
                $sections[$code]['excluded'] = true;
            }

            $sections[$code]['total']++;
            $result = $f['result'] ?? null;
            if (is_string($result) && isset($sections[$code][$result])) {
                $sections[$code][$result]++;
            } else {
                $sections[$code]['unanswered']++;
            }
        }

        foreach ($items as $item) {
            $f    = $item['fields'] ?? [];
            $code = (string) ($f['section_code'] ?? '');
            if (!empty($sections[$code]['excluded'])) {
                continue;
            }
            $total++;
            $result = $f['result'] ?? null;
            if (is_string($result) && in_array($result, self::RESULTS, true)) {
                $answered++;
            }
            if ($result === 'vyhovuje') {
                $ok++;
            }
            if ($result === 'nevyhovuje') {
                $failed++;
                $defects[] = [
                    'number'      => count($defects) + 1,
                    'item_id'     => (int) ($item['id'] ?? 0),
                    'section'     => $code,
                    'text'        => (string) ($f['text'] ?? ''),
                    'description' => (string) ($f['defect_description'] ?? ''),
                    'measure'     => $f['measure'] ?? null,
                    'deadline'    => $f['deadline'] ?? null,
                ];
            }
        }

        $sections = array_values($sections);
        usort(
            $sections,
            static fn (array $a, array $b): int => [$a['position'], $a['code']] <=> [$b['position'], $b['code']],
        );
        foreach ($sections as &$section) {
            $section['status'] = $section['nevyhovuje'] > 0 ? 'nedostatok' : 'v_poriadku';
        }
        unset($section);

        return [
            'sections'  => $sections,
            'defects'   => $defects,
            // "Vyplnené 84 zo 109" — the only two numbers the technician
            // watches while walking the building.
            'answered'  => $answered,
            'total'     => $total,
            'vyhovuje'  => $ok,
            'nevyhovuje' => $failed,
            // The headline word on the protocol. Deliberately three-valued:
            // an audit with findings is not "nevyhovujúci", it is compliant
            // with reservations, and that distinction is the one the client
            // argues about.
            'verdict'   => $failed === 0 ? 'vyhovujuci' : 'vyhovujuci_s_vyhradami',
        ];
    }

    /** Labels for the verdict, as printed. */
    public const VERDICT_LABELS = [
        'vyhovujuci'             => 'Vyhovujúci',
        'vyhovujuci_s_vyhradami' => 'Vyhovujúci s výhradami',
    ];

    private static function scope(mixed $value): string
    {
        $value = is_string($value) ? strtoupper(trim($value)) : '';
        return in_array($value, ['V', 'R', 'VR'], true) ? $value : 'VR';
    }

    private static function text(mixed $value, int $max, string $tooLong): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Neplatná hodnota textového poľa.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException($tooLong);
        }
        return $value;
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('Termín odstránenia musí byť dátum.');
        }
        return $value;
    }
}
