<?php

declare(strict_types=1);

namespace Firol\Pdf;

/**
 * The structured „Zistené nedostatky" block of a preventívna prehliadka and the
 * table of the previous prehliadka's defects (spec 6.5, 7.1; sample record
 * 2_Vzor_hotoveho_zaznamu.pdf). Shared by the PPP record and the kniha BOZP
 * record that a BOZP prehliadka yields, so both read the same way.
 *
 * Every defect has the same build — ZISTENÝ STAV · PREDPIS · ODÔVODNENIE ·
 * OPATRENIE · TERMÍN · ZODPOVEDNÝ — and the PREDPIS is its own row, never part
 * of a sentence. A row whose text is empty is left out. A defect carried over
 * from the last prehliadka is tagged NEDOSTATOK PRETRVÁVA.
 *
 * Markup relies on the `<h2>` band and `table.grid` styles every
 * {@see ProtocolLayout} document has; the block adds only its own small ones.
 * Data comes from {@see \Firol\Support\PrehliadkaRecord}.
 */
final class PrehliadkaDefectsBlock
{
    /**
     * @param list<array<string, mixed>> $points {@see \Firol\Support\PrehliadkaRecord::points()}
     * @param array{title?: string, photos?: bool, when_none?: string} $options
     *        photos     true → each point says how many photos are in the appendix
     *        when_none  sentence printed, under the band, when there are no points;
     *                   without it the section is left out
     */
    public static function render(array $points, array $options = []): string
    {
        $title = $options['title'] ?? 'Zistené nedostatky';
        if ($points === []) {
            return isset($options['when_none'])
                ? '<h2>' . self::esc($title) . '</h2><div class="pt-none">' . self::esc($options['when_none']) . '</div>'
                : '';
        }

        $html = self::styles() . '<h2>' . self::esc($title) . '</h2>';
        foreach ($points as $p) {
            $meta = [];
            if (($p['where'] ?? '') !== '') {
                $meta[] = $p['where'];
            }
            if (($p['devices'] ?? '') !== '') {
                $meta[] = $p['devices'];
            }
            if (!empty($options['photos']) && (int) ($p['photos'] ?? 0) > 0) {
                $meta[] = self::photos((int) $p['photos']) . ' v prílohe';
            }

            $deadline = trim(implode(' · ', array_filter(
                [(string) ($p['deadline_text'] ?? ''), (string) ($p['responsible'] ?? '')],
                static fn (string $s): bool => $s !== '',
            )));

            $rows = [
                'Zistený stav'          => $p['description'] ?? '',
                'Predpis'               => $p['regulation'] ?? '',
                'Odôvodnenie'           => $p['justification'] ?? '',
                'Opatrenie'             => $p['measure'] ?? '',
                'Termín · zodpovedný'   => $deadline,
            ];

            $html .= '<table class="pt"><tr><td class="pt-head" colspan="2"><b>' . (int) $p['number'] . '. '
                . self::esc((string) $p['title']) . '</b>'
                . (!empty($p['persists']) ? ' <span class="pt-tag">NEDOSTATOK PRETRVÁVA</span>' : '')
                . '</td></tr>';
            if ($meta !== []) {
                $html .= '<tr><td class="pt-meta" colspan="2">' . self::esc(implode(' · ', $meta)) . '</td></tr>';
            }
            foreach ($rows as $label => $text) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }
                $html .= '<tr><td class="pt-l">' . self::esc(mb_strtoupper($label)) . '</td><td class="pt-v">'
                    . nl2br(self::esc($text)) . '</td></tr>';
            }
            $html .= '</table>';
        }
        return $html;
    }

    /**
     * @param list<array{title: string, meta: string, date: ?string, status: string, text: string}> $rows
     */
    public static function previous(array $rows, string $title = 'Nedostatky z predchádzajúcej prehliadky'): string
    {
        if ($rows === []) {
            return '';
        }
        $html = '<h2>' . self::esc($title) . '</h2><table class="grid"><thead><tr>'
            . '<th style="width:50%">Nedostatok</th><th style="width:16%">Z prehliadky</th><th>Stav</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $class = match ($r['status']) {
                'ok'      => 'ok',
                'trva'    => 'bad',
                'waiting' => 'muted',
                default   => '',
            };
            $html .= '<tr><td><b>' . self::esc($r['title']) . '</b>'
                . ($r['meta'] !== '' ? '<br><span class="muted">' . self::esc($r['meta']) . '</span>' : '')
                . '</td><td>' . self::esc(self::date($r['date'])) . '</td>'
                . '<td' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . self::esc($r['text']) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** „1 fotografia", „2 fotografie", „5 fotografií". */
    public static function photos(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'fotografia' : ($n >= 2 && $n <= 4 ? 'fotografie' : 'fotografií'));
    }

    private static function styles(): string
    {
        return <<<'CSS'
<style>
  table.pt { border-collapse: collapse; width: 100%; font-size: 8.8pt; margin-top: 7pt; }
  table.pt td { padding: 2.5pt 5pt; vertical-align: top; }
  td.pt-head { font-size: 10pt; padding-top: 4pt; border-bottom: 1pt solid #d6d6dc; }
  td.pt-meta { color: #4b5563; font-size: 8.3pt; padding-bottom: 3pt; }
  td.pt-l { width: 22%; color: #6b6b75; font-weight: bold; font-size: 7.3pt; letter-spacing: .3pt; padding-right: 8pt; }
  td.pt-v { width: 78%; }
  .pt-tag { color: #c62828; font-weight: bold; font-size: 7.3pt; letter-spacing: .4pt; padding-left: 6pt; }
  .pt-none { border: 1pt solid #e5e5ea; padding: 5pt 6pt; font-size: 9pt; }
</style>
CSS;
    }

    private static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        $ts = strtotime($iso);
        return $ts ? date('j. n. Y', $ts) : $iso;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
