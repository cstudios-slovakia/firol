<?php

declare(strict_types=1);

namespace Firol\Audit;

use Firol\Support\Sections;

/**
 * Turns an audit's items into the shape its PDF template prints — block 3 /
 * chapters 15 and 26.
 *
 * It is a separate class rather than a branch inside DocumentController
 * because three of the rules here are domain rules, not layout, and belong
 * where they can be read on their own:
 *
 *   - an item answered `neaplikovateľné` is not printed at all,
 *   - an excluded section is replaced by a line naming it as excluded, so
 *     „not asked" never reads as „asked and passed",
 *   - the headline verdict is „Vyhovujúci" or „Vyhovujúci s výhradami" — an
 *     audit with six findings is not a failed audit, and the client will argue
 *     about exactly that word.
 */
final class AuditProtocol
{
    /** Wording that follows the odbor, taken from the delivered mockups. */
    private const KIND_TEXT = [
        AuditCatalog::KIND_BOZP => [
            'tag'             => 'BOZP',
            'title'           => 'Audit BOZP',
            'date_label'      => 'Dátum kontroly',
            'performed_label' => 'Kontrolu vykonal',
            'cert_label'      => 'bezpečnostný technik',
            'section'         => Sections::BOZP,
            'legal_tail'      => 'stavu bezpečnosti a ochrany zdravia pri práci podľa'
                . ' § 9 ods. 1 písm. a) zákona č. 124/2006 Z. z. Vykonané na všetkých'
                . ' pracoviskách a vo všetkých priestoroch spoločnosti.',
        ],
        AuditCatalog::KIND_OPP => [
            'tag'             => 'PO',
            'title'           => 'Audit ochrany pred požiarmi',
            'date_label'      => 'Dátum previerky',
            'performed_label' => 'Previerku vykonal',
            'cert_label'      => 'technik požiarnej ochrany',
            'section'         => Sections::OPP,
            'legal_tail'      => 'stavu ochrany pred požiarmi podľa zákona č. 314/2001 Z. z.'
                . ' o ochrane pred požiarmi a vyhlášky MV SR č. 121/2002 Z. z. o požiarnej'
                . ' prevencii. Kontrola dokumentácie podľa § 31 ods. 3 vyhlášky.',
        ],
    ];

    public const SCOPE_LABELS = [
        'vstupny' => 'vstupný audit',
        'rocny'   => 'ročná previerka',
    ];

    /**
     * @param list<array<string, mixed>> $items  audit items with decoded fields
     * @return array<string, mixed>
     */
    public static function build(string $inspectionType, ?string $scope, array $items): array
    {
        $kind = AuditCatalog::kindForType($inspectionType) ?? AuditCatalog::KIND_BOZP;
        $text = self::KIND_TEXT[$kind];
        $scope = in_array($scope, AuditItems::SCOPES, true) ? $scope : 'rocny';
        $scopeLabel = self::SCOPE_LABELS[$scope];

        $summary = AuditItems::summarize($items);

        // Attach the printable rows to each section. Numbering restarts per
        // section and counts only what is printed, so „A.3" on the protocol is
        // the third row under A — not the third question of the checklist,
        // which the reader cannot see.
        $printedBySection = [];
        foreach ($items as $item) {
            $f = $item['fields'] ?? [];
            if (!empty($f['section_excluded'])) {
                continue;
            }
            $result = $f['result'] ?? null;
            if ($result === 'neaplikovatelne' || $result === null) {
                continue;
            }
            $code = (string) ($f['section_code'] ?? '');
            $printedBySection[$code][] = [
                'number'      => count($printedBySection[$code] ?? []) + 1,
                'text'        => (string) ($f['text'] ?? ''),
                'legal_basis' => isset($f['legal_basis']) && $f['legal_basis'] !== ''
                    ? (string) $f['legal_basis']
                    : null,
                'result'      => $result,
                'note'        => isset($f['note']) && $f['note'] !== '' ? (string) $f['note'] : null,
            ];
        }

        $sections = [];
        $excluded = [];
        foreach ($summary['sections'] as $section) {
            $section['printed'] = $printedBySection[$section['code']] ?? [];
            $sections[] = $section;
            if ($section['excluded']) {
                $excluded[] = ['code' => $section['code'], 'name' => $section['name']];
            }
        }

        return [
            'type'            => $inspectionType,
            'kind'            => $kind,
            'tag'             => $text['tag'],
            'color'           => Sections::COLORS[$text['section']],
            'title'           => $text['title'] . ' — ' . $scopeLabel,
            'scope'           => $scope,
            'scope_label'     => $scopeLabel,
            'date_label'      => $text['date_label'],
            'performed_label' => $text['performed_label'],
            'cert_label'      => $text['cert_label'],
            'legal_sentence'  => self::legalSentence($scope, (string) $text['legal_tail']),
            'sections'        => $sections,
            'excluded'        => $excluded,
            'defects'         => $summary['defects'],
            'summary'         => [
                // What the protocol calls „Hodnotených položiek": the ones it
                // actually prints a verdict for. An item nobody answered and an
                // item marked neaplikovateľné are both absent from the page, so
                // counting them here would leave the reader adding up rows that
                // are not there.
                'evaluated'     => $summary['vyhovuje'] + $summary['nevyhovuje'],
                'vyhovuje'      => $summary['vyhovuje'],
                'nevyhovuje'    => $summary['nevyhovuje'],
                'verdict'       => $summary['verdict'],
                'verdict_label' => AuditItems::VERDICT_LABELS[$summary['verdict']],
            ],
        ];
    }

    private static function legalSentence(string $scope, string $tail): string
    {
        $lead = $scope === 'vstupny' ? 'Vstupný audit' : 'Ročná previerka';
        return $lead . ' ' . $tail;
    }
}
