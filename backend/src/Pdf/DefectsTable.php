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

    /**
     * Renders the „Zistené nedostatky" section of a routine control protocol
     * (Option A / docKontrola in prototype).
     *
     * Cleanly renders:
     *   Header: {idx}. {Device title}
     *   Meta: {Where} · v. č. {Serial} · {N} foto v prílohe
     *   Table:
     *     Zistený stav: ...
     *     Predpis: ... (purple #5B3E8C)
     *     Opatrenie: ...
     *     Termín: bold red deadline · responsible
     *
     * When there are no failing devices:
     *   „Všetky skontrolované zariadenia vyhovujú. Nedostatky neboli zistené."
     *
     * @param list<array<string, mixed>> $items
     * @param string $type
     * @param array<string, mixed> $options
     */
    public static function renderRoutineDefects(array $items, string $type, array $options = []): string
    {
        $photoCounts = (array) ($options['photo_counts'] ?? []);
        $title = (string) ($options['title'] ?? 'Zistené nedostatky');

        $failing = [];
        foreach ($items as $idx => $it) {
            $f = (array) ($it['fields'] ?? []);
            $itemId = (int) ($it['id'] ?? 0);
            $hasPrehliadkaDefects = !empty($f['prehliadka_defects']) && is_array($f['prehliadka_defects']);

            $isFailing = false;
            switch ($type) {
                case 'php':
                    $st = (string) ($f['status'] ?? '');
                    $isFailing = ($st !== 'A' && $st !== '') || !empty($f['notes']) || $hasPrehliadkaDefects;
                    break;
                case 'hydranty':
                    $r = (string) ($f['result'] ?? '');
                    $isFailing = $r === 'nevyhovuje' || !empty($f['defects']) || $hasPrehliadkaDefects;
                    break;
                case 'rebriky':
                case 'regale':
                    $r = (string) ($f['result'] ?? '');
                    $isFailing = $r === 'nevyhovuje' || $r === 'vyradene' || !empty($f['faults']) || $hasPrehliadkaDefects;
                    break;
                default: // pu_akcieschopnost, pu_udrzba, nudzove_osvetlenie, ts_hadic
                    $r = (string) ($f['result'] ?? '');
                    $isFailing = $r === 'nevyhovuje' || !empty($f['notes']) || $hasPrehliadkaDefects;
                    break;
            }

            if ($isFailing) {
                $failing[] = [
                    'index'   => $idx + 1,
                    'item_id' => $itemId,
                    'fields'  => $f,
                ];
            }
        }

        if ($failing === []) {
            if (!empty($options['hide_if_empty'])) {
                return '';
            }
            return '<h2>' . self::esc($title) . '</h2>'
                . '<div style="padding: 5pt 0; font-size: 9pt; color: #555;">'
                . 'Všetky skontrolované zariadenia vyhovujú. Nedostatky neboli zistené.'
                . '</div>';
        }

        $html = '<h2>' . self::esc($title) . '</h2>';

        foreach ($failing as $j => $fail) {
            $f = $fail['fields'];
            $itemId = $fail['item_id'];
            $idx = $fail['index'];
            $num = $j + 1;

            $deviceInfo = self::identifyDevice($type, $idx, $f);
            $metaParts = [];
            if ($deviceInfo['where'] !== '') {
                $metaParts[] = $deviceInfo['where'];
            }
            if ($deviceInfo['serial'] !== '') {
                $metaParts[] = 'v. č. ' . $deviceInfo['serial'];
            }
            $pCount = (int) ($photoCounts[$itemId] ?? 0);
            if ($pCount > 0) {
                $metaParts[] = $pCount . ' foto v prílohe';
            }
            $metaLine = implode(' · ', $metaParts);

            $prehliadkaDefects = (array) ($f['prehliadka_defects'] ?? []);
            if ($prehliadkaDefects !== []) {
                foreach ($prehliadkaDefects as $d) {
                    $d = (array) $d;
                    $state = trim((string) ($d['description'] ?? ''));
                    if ($state === '') {
                        $state = trim((string) ($d['title'] ?? ''));
                    }
                    if ($state === '') {
                        $state = trim((string) ($d['text'] ?? ''));
                    }
                    $predpis = trim((string) ($d['regulation'] ?? ''));
                    $opatrenie = trim((string) ($d['measure'] ?? ''));

                    $deadline = trim((string) ($d['deadline_date'] ?? ''));
                    if ($deadline !== '') {
                        $deadlineFormatted = self::date($deadline);
                    } else {
                        $deadlineFormatted = trim((string) ($d['deadline_text'] ?? ''));
                    }
                    $responsible = trim((string) ($d['responsible'] ?? ''));

                    $terminHtml = '';
                    if ($deadlineFormatted !== '') {
                        $terminHtml = '<b style="color:#c62828;">' . self::esc($deadlineFormatted) . '</b>';
                        if ($responsible !== '') {
                            $terminHtml .= ' · ' . self::esc($responsible);
                        }
                    } elseif ($responsible !== '') {
                        $terminHtml = self::esc($responsible);
                    }

                    $html .= self::renderDefectBox($num, $deviceInfo['title'], $metaLine, $state, $predpis, $opatrenie, $terminHtml);
                }
            } else {
                // Standalone routine inspection defect
                $state = trim((string) ($f['faults'] ?? $f['defects'] ?? $f['notes'] ?? ''));
                if ($state === '') {
                    if ($type === 'php' && isset($f['status'])) {
                        $statusLabels = ['TS' => 'Tlaková skúška', 'O' => 'Vyžaduje opravu', 'V' => 'Vyradený z prevádzky'];
                        $state = $statusLabels[$f['status']] ?? (string) $f['status'];
                    } else {
                        $state = 'Nevyhovuje';
                    }
                }
                $predpis = trim((string) ($f['regulation'] ?? ''));
                $opatrenie = trim((string) ($f['measure'] ?? ''));

                $deadline = trim((string) ($f['deadline'] ?? ''));
                $deadlineFormatted = $deadline !== '' ? self::date($deadline) : '';
                $responsible = trim((string) ($f['responsible'] ?? ''));

                $terminHtml = '';
                if ($deadlineFormatted !== '') {
                    $terminHtml = '<b style="color:#c62828;">' . self::esc($deadlineFormatted) . '</b>';
                    if ($responsible !== '') {
                        $terminHtml .= ' · ' . self::esc($responsible);
                    }
                } elseif ($responsible !== '') {
                    $terminHtml = self::esc($responsible);
                }

                $html .= self::renderDefectBox($num, $deviceInfo['title'], $metaLine, $state, $predpis, $opatrenie, $terminHtml);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $f
     * @return array{title: string, where: string, serial: string}
     */
    private static function identifyDevice(string $type, int $idx, array $f): array
    {
        $where = trim((string) ($f['location'] ?? ''));
        $serial = '';

        switch ($type) {
            case 'php':
                $m = trim((string) ($f['manufacturer'] ?? ''));
                $t = trim((string) ($f['type'] ?? ''));
                $title = 'HP č. ' . $idx . ' · ' . trim($m . ' ' . $t);
                $serial = trim((string) ($f['serial'] ?? ''));
                break;
            case 'hydranty':
                $typeLabel = ($f['type'] ?? 'other') === 'other'
                    ? trim((string) ($f['type_other'] ?? 'iný'))
                    : trim((string) ($f['type'] ?? ''));
                $title = 'Požiarny vodovod č. ' . $idx . ' · ' . $typeLabel;
                break;
            case 'rebriky':
                $inv = trim((string) ($f['inventory_number'] ?? ''));
                $t = trim((string) ($f['type'] ?? ''));
                $title = 'Rebrík č. ' . $idx . ' · ' . trim($inv . ' ' . $t);
                $serial = $inv;
                break;
            case 'regale':
                $lbl = trim((string) ($f['label'] ?? ''));
                $t = trim((string) ($f['type'] ?? ''));
                $title = 'Regál č. ' . $idx . ' · ' . trim($lbl . ' ' . $t);
                $serial = $lbl;
                break;
            case 'pu_akcieschopnost':
            case 'pu_udrzba':
                $kind = (string) ($f['kind'] ?? '');
                $kindName = match ($kind) {
                    'dvere'  => 'Požiarne dvere',
                    'okno'   => 'Požiarne okno',
                    'klapka' => 'Požiarna klapka',
                    default  => 'Požiarny uzáver',
                };
                $ident = trim((string) ($f['identifier'] ?? ''));
                $title = $kindName . ' č. ' . $idx . ($ident !== '' ? ' · ' . $ident : '');
                $serial = $ident;
                break;
            case 'nudzove_osvetlenie':
                $evid = trim((string) ($f['evid_number'] ?? ''));
                $lum = trim((string) ($f['luminaire_type'] ?? ''));
                $title = 'Svietidlo č. ' . $idx . ' · ' . trim($evid . ' ' . $lum);
                $serial = $evid;
                break;
            case 'ts_hadic':
                $inv = trim((string) ($f['inventory_number'] ?? ''));
                $ht = trim((string) ($f['hose_type'] ?? ''));
                $title = 'Hadica č. ' . $idx . ' · ' . trim($inv . ' ' . $ht);
                $serial = $inv;
                break;
            default:
                $title = 'Zariadenie č. ' . $idx;
                break;
        }

        return ['title' => $title, 'where' => $where, 'serial' => $serial];
    }

    private static function renderDefectBox(
        int $num,
        string $title,
        string $metaLine,
        string $state,
        string $predpis,
        string $opatrenie,
        string $terminHtml,
    ): string {
        $html = '<div style="margin: 6pt 0 8pt; page-break-inside: avoid;">'
            . '<div style="font-weight:bold; font-size:9.5pt; color:#1a1a1f;">' . $num . '. ' . self::esc($title) . '</div>';

        if ($metaLine !== '') {
            $html .= '<div style="font-size:8pt; color:#6b6b75; margin: 1pt 0 3pt;">' . self::esc($metaLine) . '</div>';
        }

        $html .= '<table style="border-collapse:collapse; width:100%; font-size:9pt; margin-top:2pt;">';

        if ($state !== '') {
            $html .= '<tr>'
                . '<td style="width:85pt; font-size:7.5pt; font-weight:bold; color:#6b6b75; text-transform:uppercase; letter-spacing:.3pt; vertical-align:top; padding:2pt 4pt 2pt 0;">Zistený stav</td>'
                . '<td style="font-size:9pt; color:#1a1a1f; vertical-align:top; padding:2pt 4pt;">' . nl2br(self::esc($state)) . '</td>'
                . '</tr>';
        }

        if ($predpis !== '') {
            $html .= '<tr>'
                . '<td style="width:85pt; font-size:7.5pt; font-weight:bold; color:#6b6b75; text-transform:uppercase; letter-spacing:.3pt; vertical-align:top; padding:2pt 4pt 2pt 0;">Predpis</td>'
                . '<td style="font-size:9pt; color:#5B3E8C; font-weight:600; vertical-align:top; padding:2pt 4pt;">' . nl2br(self::esc($predpis)) . '</td>'
                . '</tr>';
        }

        if ($opatrenie !== '') {
            $html .= '<tr>'
                . '<td style="width:85pt; font-size:7.5pt; font-weight:bold; color:#6b6b75; text-transform:uppercase; letter-spacing:.3pt; vertical-align:top; padding:2pt 4pt 2pt 0;">Opatrenie</td>'
                . '<td style="font-size:9pt; color:#1a1a1f; vertical-align:top; padding:2pt 4pt;">' . nl2br(self::esc($opatrenie)) . '</td>'
                . '</tr>';
        }

        if ($terminHtml !== '') {
            $html .= '<tr>'
                . '<td style="width:85pt; font-size:7.5pt; font-weight:bold; color:#6b6b75; text-transform:uppercase; letter-spacing:.3pt; vertical-align:top; padding:2pt 4pt 2pt 0;">Termín</td>'
                . '<td style="font-size:9pt; color:#1a1a1f; vertical-align:top; padding:2pt 4pt;">' . $terminHtml . '</td>'
                . '</tr>';
        }

        $html .= '</table></div>';
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
