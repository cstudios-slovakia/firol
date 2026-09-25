<?php

declare(strict_types=1);

namespace Firol\Pdf;

/**
 * The „Zistené nedostatky" section of a protocol — block 2 / chapters 7 and 26.
 *
 * ─── USAGE (inside a PDF template) ──────────────────────────────────────────
 *
 *     <?= \Firol\Pdf\DefectsTable::render(\Firol\Support\Defects::collect($items)) ?>
 *
 * `$items` is the payload's item list (ordered by position, each with `id`
 * and `fields`) — the same list the photo appendix numbers from, so the
 * „Nedostatok č. N" under a photo is row N here.
 *
 * Returns an empty string when there are no nedostatky: the section is not
 * printed at all (chapter 26, „sekcie bez obsahu sa nevypisujú").
 *
 * Markup: an `<h2>` band followed by `<table class="grid">`, i.e. the band and
 * grid styles every block-2 template gets from {@see ProtocolLayout::styles()}.
 * Options:
 *
 *   title       band text                 default „Zistené nedostatky"
 *   labels      [popis, opatrenie, termín] default the kniha BOZP mockup's
 *               „Popis nedostatku / Navrhované opatrenie / Termín odstránenia";
 *               pass DefectsTable::SHORT_LABELS for „Popis / Opatrenie / Termín"
 *   photo_note  true → prints „Fotodokumentácia zistených nedostatkov tvorí
 *               prílohu tohto záznamu." under the table (only say it when the
 *               appendix really is attached)
 *
 * Backend counterpart: Firol\Support\Defects (storage, validation, numbering).
 * ────────────────────────────────────────────────────────────────────────────
 */
final class DefectsTable
{
    public const LONG_LABELS  = ['Popis nedostatku', 'Navrhované opatrenie', 'Termín odstránenia'];
    public const SHORT_LABELS = ['Popis', 'Opatrenie', 'Termín'];

    /**
     * @param list<array{number: int, description: string, measure: ?string, deadline: ?string}> $rows
     * @param array{title?: string, labels?: array{0: string, 1: string, 2: string}, photo_note?: bool} $options
     */
    public static function render(array $rows, array $options = []): string
    {
        if ($rows === []) {
            return '';
        }
        $title  = $options['title'] ?? 'Zistené nedostatky';
        $labels = $options['labels'] ?? self::LONG_LABELS;

        $html = '<h2>' . self::esc($title) . '</h2>'
            . '<table class="grid"><thead><tr>'
            . '<th style="width:5%">Č.</th>'
            . '<th style="width:47%">' . self::esc($labels[0]) . '</th>'
            . '<th style="width:28%">' . self::esc($labels[1]) . '</th>'
            . '<th>' . self::esc($labels[2]) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td>' . (int) $row['number'] . '</td>'
                . '<td>' . nl2br(self::esc($row['description'])) . '</td>'
                . '<td>' . ($row['measure'] !== null ? nl2br(self::esc($row['measure'])) : '—') . '</td>'
                . '<td>' . ($row['deadline'] !== null ? self::esc(self::date($row['deadline'])) : '—') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        if (!empty($options['photo_note'])) {
            $html .= '<div class="small-note">Fotodokumentácia zistených nedostatkov tvorí prílohu tohto záznamu.</div>';
        }
        return $html;
    }

    private static function date(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date('j. n. Y', $ts) : $iso;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
