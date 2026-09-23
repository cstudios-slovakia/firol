<?php

declare(strict_types=1);

namespace Firol\Pdf;

use Firol\Support\Contractor;

/**
 * The fixed frame of a block-2 BOZP protocol — header, Základné informácie,
 * the legal sentence, Podpisy and the pätička — as laid out in the binding
 * mockup `02_NAHLADY/bozp_protokoly.html` (chapter 26: „nemenné je poradie
 * sekcií, hlavička, podpisová tabuľka a pätička").
 *
 * ─── USAGE (inside a template under Pdf/templates) ──────────────────────────
 *
 *     $doc = new \Firol\Pdf\ProtocolLayout(get_defined_vars());
 *     echo $doc->styles();
 *     echo $doc->header("Záznam o kontrole\nrebríkov");        // \n = line break
 *     echo $doc->basicInfo();
 *     echo $doc->legal('Kontrola vykonaná podľa …');            // text from the mockup
 *     echo $doc->band('Zoznam kontrolovaných rebríkov');         // <h2> band
 *     …  your sections, using <table class="grid"> / class="ok|bad" …
 *     echo \Firol\Pdf\DefectsTable::render(\Firol\Support\Defects::collect($items));
 *     echo $doc->signatures('rebriky');
 *     echo $doc->footer('Kontrolovaných rebríkov: 4');
 *
 * Reads the standard payload variables (number, brand, inspection, company,
 * facility, inspector, handover). The colour is always the BOZP blue and the
 * header box says BOZP, whatever the account's brand colour: chapter 26 ties
 * the colour and the tag to the odbor, not to the account.
 *
 * The Periodicita row is left out entirely when the úkon has none — the
 * protocol states the period it was issued under, and „bez opakovania" has
 * nothing to state (block 1, chapter 5).
 *
 * The Zhotoviteľ row (chapter 1.3.3, decision 23. 9. 2026) is on every
 * protocol even though the binding mockup does not draw it. It is appended
 * under the rows the mockup does show, and it does not move them.
 * ────────────────────────────────────────────────────────────────────────────
 */
final class ProtocolLayout
{
    public const BOZP_COLOR = '#3D7FC1';

    /** @var array<string, mixed> */
    private array $v;

    private string $color;
    private string $tag;

    /**
     * @param array<string, mixed> $vars the template's variables (get_defined_vars())
     */
    public function __construct(array $vars, string $color = self::BOZP_COLOR, string $tag = 'BOZP')
    {
        $this->v = $vars;
        $this->color = $color;
        $this->tag = $tag;
    }

    public function styles(): string
    {
        $c = self::esc($this->color);
        return <<<CSS
<style>
  body { font-family: dejavusans, sans-serif; color: #1a1a1f; font-size: 9.5pt; }
  .hdr { border-collapse: collapse; width: 100%; }
  .hdr td { vertical-align: middle; padding: 3pt 0; }
  .hdr-inner { border-collapse: collapse; }
  .hdr-inner td { vertical-align: middle; padding: 0; }
  .logo-img { max-height: 36pt; max-width: 80pt; }
  .logo-box { border: 1pt solid #bbb; color: #999; font-size: 7pt; text-align: center; padding: 5pt 6pt; width: 36pt; }
  .hdr-company { font-size: 12.5pt; font-weight: bold; }
  .hdr-sub { font-size: 8.5pt; color: #555; }
  .hdr-right { text-align: right; }
  .tag { border: 1pt solid {$c}; color: {$c}; font-size: 7.5pt; font-weight: bold; letter-spacing: .6pt; padding: 1pt 5pt; }
  .hdr-title { font-size: 11pt; font-weight: bold; color: {$c}; }
  .hdr-meta { font-size: 9pt; color: #555; }
  h2 { background: {$c}; color: #fff; font-size: 9pt; font-weight: bold; margin: 8pt 0 0; text-transform: uppercase; letter-spacing: .3pt; padding: 3pt 6pt; }
  .bi { border-collapse: collapse; width: 100%; font-size: 9pt; }
  .bi td { padding: 3pt 6pt; border: 1pt solid #dde0e6; vertical-align: top; }
  .bl { background: #f7f7f9; font-weight: bold; color: #6b6b75; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; white-space: nowrap; width: 14%; }
  .bv { width: 36%; }
  .legal-box { margin: 5pt 0 0; padding: 5pt 8pt; background: #eef5ff; border-left: 2pt solid {$c}; font-size: 8.5pt; color: #1a3a6b; }
  .text-box { border: 1pt solid #e5e5ea; padding: 4pt 6pt; font-size: 9pt; }
  .note-box { margin-top: 0; padding: 5pt 8pt; background: #f4f8fc; border-left: 2pt solid {$c}; font-size: 9pt; }
  .warn-box { margin: 5pt 0 0; padding: 5pt 8pt; background: #fff8ec; border-left: 2pt solid #b7791f; font-size: 8.5pt; color: #4b3a17; }
  table.grid { border-collapse: collapse; width: 100%; font-size: 8.5pt; }
  table.grid th { background: #eef3f9; color: #1f2937; padding: 4pt 5pt; border: 1pt solid #d6d6dc; text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.grid td { padding: 3pt 5pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  .ok { color: #1e7a46; font-weight: bold; }
  .bad { color: #c62828; font-weight: bold; }
  .check { color: {$c}; font-weight: bold; }
  .muted { color: #6b6b75; }
  .small-note { font-size: 8pt; color: #6b6b75; margin-top: 3pt; }
  table.verdict { border-collapse: collapse; width: 100%; font-size: 9pt; }
  table.verdict td { padding: 5pt 8pt; border: 1pt solid #e5e5ea; }
  .sig-tbl { border-collapse: collapse; width: 100%; font-size: 9pt; }
  .sig-tbl th { background: #eef3f9; padding: 4pt 6pt; border: 1pt solid #d6d6dc; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; font-weight: bold; color: #2a2a32; text-align: left; }
  .sig-tbl td { border: 1pt solid #e5e5ea; padding: 6pt; vertical-align: top; }
  .sig-row td { height: 36pt; vertical-align: bottom; text-align: center; }
  .sig-img { max-height: 38pt; max-width: 150pt; }
  .sig-line { border-top: 1pt solid #2a2a32; margin-top: 4pt; padding-top: 3pt; font-size: 8pt; color: #6b6b75; }
  .footer { border-top: 1pt solid #e5e5ea; margin-top: 8pt; padding-top: 4pt; font-size: 8pt; color: #6b6b75; }
</style>
CSS;
    }

    /** Header: client left, odbor tag + title + number + date right. */
    public function header(string $title): string
    {
        $brand = (array) ($this->v['brand'] ?? []);
        $company = (array) ($this->v['company'] ?? []);
        $facility = (array) ($this->v['facility'] ?? []);

        $logo = !empty($brand['logo_data_uri'])
            ? '<img class="logo-img" src="' . self::esc((string) $brand['logo_data_uri']) . '" alt="">'
            : '<div class="logo-box">LOGO<br>FIRMY</div>';
        $address = !empty($facility['address'])
            ? '<div class="hdr-sub">' . self::esc((string) $facility['address']) . '</div>'
            : '';
        $titleHtml = implode('<br>', array_map([self::class, 'esc'], explode("\n", $title)));

        return '<table class="hdr"><tr>'
            . '<td width="48%"><table class="hdr-inner"><tr>'
            . '<td width="50pt" style="padding-right:7pt;">' . $logo . '</td>'
            . '<td><div class="hdr-company">' . self::esc((string) ($company['name'] ?? '')) . '</div>'
            . '<div class="hdr-sub">Prevádzka: ' . self::esc((string) ($facility['name'] ?? '')) . ' | IČO: '
            . self::esc((string) ($company['ico'] ?? '—')) . '</div>' . $address . '</td>'
            . '</tr></table></td>'
            . '<td width="52%" class="hdr-right">'
            . '<div><span class="tag">' . self::esc($this->tag) . '</span></div>'
            . '<div class="hdr-title">' . $titleHtml . '</div>'
            . '<div class="hdr-meta">Č. dokumentu: ' . self::esc((string) ($this->v['number'] ?? '')) . '</div>'
            . '<div class="hdr-meta">Dátum: ' . self::esc($this->executedOn()) . '</div>'
            . '</td></tr></table>'
            . '<hr style="border:none; border-top:2pt solid ' . self::esc($this->color) . '; margin:4pt 0 6pt;">';
    }

    /** Základné informácie: Spoločnosť, IČO, Prevádzka | Dátum, Periodicita, Kontrolu vykonal. */
    public function basicInfo(string $dateLabel = 'Dátum kontroly', string $performedLabel = 'Kontrolu vykonal'): string
    {
        $company = (array) ($this->v['company'] ?? []);
        $facility = (array) ($this->v['facility'] ?? []);
        $periodicity = $this->v['inspection']['periodicity_label'] ?? null;

        $facilityCell = self::esc((string) ($facility['name'] ?? ''));
        if (!empty($facility['address'])) {
            $facilityCell .= '<br><span style="color:#555;">' . self::esc((string) $facility['address']) . '</span>';
        }

        $html = '<h2>Základné informácie</h2><table class="bi">'
            . '<tr><td class="bl">Spoločnosť</td><td class="bv">' . self::esc((string) ($company['name'] ?? '')) . '</td>'
            . '<td class="bl">' . self::esc($dateLabel) . '</td><td class="bv"><strong>' . self::esc($this->executedOn()) . '</strong></td></tr>';

        if ($periodicity !== null) {
            $html .= '<tr><td class="bl">IČO</td><td class="bv">' . self::esc((string) ($company['ico'] ?? '—')) . '</td>'
                . '<td class="bl">Periodicita</td><td class="bv">' . self::esc((string) $periodicity) . '</td></tr>';
        } else {
            $html .= '<tr><td class="bl">IČO</td><td class="bv" colspan="3">' . self::esc((string) ($company['ico'] ?? '—')) . '</td></tr>';
        }

        $html .= '<tr><td class="bl">Prevádzka</td><td class="bv">' . $facilityCell . '</td>'
            . '<td class="bl">' . self::esc($performedLabel) . '</td><td class="bv">' . $this->inspectorCell() . '</td></tr>'
            . Contractor::basicInfoRow(is_array($this->v['contractor'] ?? null) ? $this->v['contractor'] : [])
            . '</table>';
        return $html;
    }

    /** The legal sentence under Základné informácie — wording from the mockup. */
    public function legal(string $text): string
    {
        return '<div class="legal-box">' . self::esc($text) . '</div>';
    }

    /**
     * The legal sentence in the mockup's amber variant — rebríky and regály
     * print their „Kontrola vykonaná v rozsahu podľa STN EN …" in it.
     */
    public function legalWarn(string $text): string
    {
        return '<div class="warn-box">' . self::esc($text) . '</div>';
    }

    /** A section band. */
    public function band(string $title): string
    {
        return '<h2>' . self::esc($title) . '</h2>';
    }

    /** One bordered cell of free text (Prehliadnuté pracoviská, Rozsah kontroly, …). */
    public function textBox(string $text): string
    {
        return '<div class="text-box">' . nl2br(self::esc($text)) . '</div>';
    }

    /** Podpisy: Kontrolu vykonal | Za organizáciu — … | Miesto a dátum. */
    public function signatures(string $documentType, string $performedLabel = 'Kontrolu vykonal'): string
    {
        $inspector = (array) ($this->v['inspector'] ?? []);
        $company = (array) ($this->v['company'] ?? []);
        $facility = (array) ($this->v['facility'] ?? []);
        $handover = $this->v['handover'] ?? null;

        $city = ($facility['city'] ?? '') ?: ($company['city'] ?? '');
        $place = ($city ? $city . ', ' : '') . $this->executedOn();

        $sig = !empty($inspector['signature_data_uri'])
            ? '<img class="sig-img" src="' . self::esc((string) $inspector['signature_data_uri']) . '" alt="">'
            : '';

        return '<h2>Podpisy</h2><table class="sig-tbl">'
            . '<tr><th width="34%">' . self::esc($performedLabel) . '</th>'
            . '<th width="40%">' . SignatureBlock::heading($documentType) . '</th>'
            . '<th width="26%">Miesto a dátum</th></tr>'
            . '<tr><td>' . $this->inspectorCell() . '</td>'
            . '<td>' . SignatureBlock::nameCell(is_array($handover) ? $handover : null, (string) ($company['approver'] ?? '')) . '</td>'
            . '<td>' . SignatureBlock::placeAndDate(is_array($handover) ? $handover : null, $place) . '</td></tr>'
            . '<tr class="sig-row"><td>' . $sig . '<div class="sig-line"></div></td>'
            . '<td>' . SignatureBlock::signCell(is_array($handover) ? $handover : null, $documentType) . '</td>'
            . '<td></td></tr></table>';
    }

    /** Pätička: „Vypracoval: meno | č. oprávnenia: X" plus an optional count. */
    public function footer(string $extra = ''): string
    {
        $inspector = (array) ($this->v['inspector'] ?? []);
        $html = '<div class="footer">Vypracoval: ' . self::esc((string) ($inspector['fullname'] ?? ''));
        if (!empty($inspector['certification_number'])) {
            $html .= ' | č. oprávnenia: ' . self::esc((string) $inspector['certification_number']);
        }
        if ($extra !== '') {
            $html .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . self::esc($extra);
        }
        return $html . '</div>';
    }

    /** „j. n. Y" for an ISO date, „—" for none. */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        $ts = strtotime($iso);
        return $ts ? date('j. n. Y', $ts) : $iso;
    }

    public static function esc(?string $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private function executedOn(): string
    {
        return self::date($this->v['inspection']['executed_on'] ?? null);
    }

    /** Name plus „bezpečnostný technik, č. opr.: …" as the mockup prints it. */
    private function inspectorCell(): string
    {
        $inspector = (array) ($this->v['inspector'] ?? []);
        $html = self::esc((string) ($inspector['fullname'] ?? ''));
        if (!empty($inspector['certification_number'])) {
            $html .= '<br><span style="font-size:8pt; color:#555;">bezpečnostný technik, č. opr.: '
                . self::esc((string) $inspector['certification_number']) . '</span>';
        }
        return $html;
    }
}
