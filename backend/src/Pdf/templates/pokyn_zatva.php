<?php
/**
 * Pokyn na zabezpečenie ochrany pred požiarmi pri žatevných prácach
 * (change request 2.3).
 *
 * Layout follows docs/handoff/zmeny-2026-07/POapp_sablona_pokyn_zatva.docx in
 * the same house style as the other protocols. It lives in the training tree
 * (a document issued to the client's employees, not a control of a device) but
 * has neither an attendee table nor an item table: the record carries the
 * instruction text as an ordered list of sections, each of which the technician
 * may edit before generating.
 *
 * @var string $number
 * @var string $generated_at
 * @var array  $brand          name, color, logo_data_uri
 * @var array  $training       type, training_type_label, date, topics, status
 * @var array  $company        name, ico, address, city, approver
 * @var array  $facility       name, address, city
 * @var array  $trainer        fullname, certification_number, signature_data_uri
 * @var array  $pokyn          year, approver, sections
 */
$h = static fn(?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

$formatDate = static function (?string $iso): string {
  if (!$iso)
    return '—';
  $ts = strtotime($iso);
  return $ts ? date('j. n. Y', $ts) : $iso;
};

$year     = (int) ($pokyn['year'] ?? 0);
$sections = is_array($pokyn['sections'] ?? null) ? $pokyn['sections'] : [];
$issuedOn = $training['date'] ?? null;

// Per-document override wins over the company's recorded schvaľujúca osoba.
$approver = trim((string) ($pokyn['approver'] ?? '')) ?: trim((string) ($company['approver'] ?? ''));

// A Pokyn may be issued for the whole company — the facility is optional here,
// unlike on the inspection protocols.
$facilityName = trim((string) ($facility['name'] ?? ''));
$city = ($facility['city'] ?? '') ?: ($company['city'] ?? '');

/**
 * Section bodies are plain text the technician edited in a textarea. Lines
 * beginning with an em/en dash are the template's bullet lists, so they render
 * as list items; everything else is a paragraph.
 */
$renderBody = static function (string $text) use ($h): string {
  $lines = preg_split('/\R/', $text) ?: [];
  $out = '';
  $inList = false;
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
      continue;
    }
    if (preg_match('/^[—–-]\s*(.*)$/u', $line, $m)) {
      if (!$inList) {
        $out .= '<ul>';
        $inList = true;
      }
      $out .= '<li>' . $h($m[1]) . '</li>';
      continue;
    }
    if ($inList) {
      $out .= '</ul>';
      $inList = false;
    }
    $out .= '<p>' . $h($line) . '</p>';
  }
  if ($inList) {
    $out .= '</ul>';
  }
  return $out;
};
?>
<style>
  body {
    font-family: dejavusans, sans-serif;
    color: #1a1a1f;
    font-size: 10pt;
  }

  .hdr {
    border-collapse: collapse;
    width: 100%;
  }

  .hdr td {
    vertical-align: middle;
    padding: 3pt 0;
  }

  .hdr-inner {
    border-collapse: collapse;
  }

  .hdr-inner td {
    vertical-align: middle;
    padding: 0;
  }

  .logo-img {
    max-height: 36pt;
    max-width: 80pt;
  }

  .logo-box {
    border: 1pt solid #bbb;
    color: #999;
    font-size: 7pt;
    text-align: center;
    padding: 5pt 6pt;
    width: 36pt;
  }

  .hdr-company {
    font-size: 12.5pt;
    font-weight: bold;
  }

  .hdr-sub {
    font-size: 8.5pt;
    color: #555;
  }

  .hdr-right {
    text-align: right;
  }

  .hdr-title {
    font-size: 12pt;
    font-weight: bold;
    color: <?= $h($brandColor) ?>;
  }

  .hdr-meta {
    font-size: 9pt;
    color: #555;
  }

  .doc-title {
    text-align: center;
    margin: 6pt 0 2pt;
  }

  .doc-title .p-o-k-y-n {
    font-size: 16pt;
    font-weight: bold;
    letter-spacing: 6pt;
    color: #1a1a1f;
  }

  .doc-title .sub {
    font-size: 10pt;
    color: #2a2a32;
    margin-top: 3pt;
  }

  h2 {
    background: <?= $h($brandColor) ?>;
    color: #fff;
    font-size: 9pt;
    font-weight: bold;
    margin: 9pt 0 0;
    text-transform: uppercase;
    letter-spacing: .3pt;
    padding: 3pt 6pt;
  }

  .bi {
    border-collapse: collapse;
    width: 100%;
    font-size: 9pt;
  }

  .bi td {
    padding: 3pt 6pt;
    border: 1pt solid #dde0e6;
    vertical-align: top;
  }

  .bl {
    background: #f7f7f9;
    font-weight: bold;
    color: #6b6b75;
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
    white-space: nowrap;
    width: 14%;
  }

  .bv {
    width: 36%;
  }

  .scope {
    margin-top: 5pt;
    padding: 5pt 8pt;
    background: #f7f7f9;
    border: 1pt solid #e5e5ea;
    font-size: 9pt;
  }

  .sect {
    font-size: 9.5pt;
    padding: 2pt 0 0;
  }

  .sect p {
    margin: 3pt 0;
    text-align: justify;
  }

  .sect ul {
    margin: 3pt 0 3pt 12pt;
    padding: 0;
  }

  .sect li {
    margin: 1.5pt 0;
  }

  .sig-tbl {
    border-collapse: collapse;
    width: 100%;
    margin-top: 8pt;
    font-size: 9pt;
  }

  .sig-tbl th {
    background: #f3f3f6;
    padding: 4pt 6pt;
    border: 1pt solid #d6d6dc;
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
    font-weight: bold;
    color: #2a2a32;
  }

  .sig-tbl td {
    border: 1pt solid #e5e5ea;
    padding: 6pt;
    vertical-align: top;
  }

  .sig-row td {
    height: 36pt;
    vertical-align: bottom;
    text-align: center;
  }

  .sig-img {
    max-height: 38pt;
    max-width: 150pt;
  }

  .sig-line {
    border-top: 1pt solid #2a2a32;
    margin-top: 4pt;
    padding-top: 3pt;
    font-size: 8pt;
    color: #6b6b75;
  }

  .footer {
    border-top: 1pt solid #e5e5ea;
    margin-top: 8pt;
    padding-top: 4pt;
    font-size: 8pt;
    color: #6b6b75;
  }
</style>

<table class="hdr">
  <tr>
    <td width="57%">
      <table class="hdr-inner">
        <tr>
          <td width="50pt" style="padding-right:7pt;">
            <?php if (!empty($brand['logo_data_uri'])): ?>
              <img class="logo-img" src="<?= $h($brand['logo_data_uri']) ?>" alt="">
            <?php else: ?>
              <div class="logo-box">LOGO<br>FIRMY</div>
            <?php endif ?>
          </td>
          <td>
            <div class="hdr-company"><?= $h($company['name']) ?></div>
            <div class="hdr-sub"><?php if ($facilityName !== ''): ?>Prevádzka: <?= $h($facilityName) ?> |
            <?php endif ?>IČO: <?= $h($company['ico']) ?></div>
            <?php if (!empty($facility['address'])): ?>
              <div class="hdr-sub"><?= $h($facility['address']) ?></div><?php endif ?>
          </td>
        </tr>
      </table>
    </td>
    <td width="43%" class="hdr-right">
      <div class="hdr-title">Pokyn na zabezpečenie ochrany pred požiarmi pri žatevných prácach</div>
      <div class="hdr-meta">Č. dokumentu: <?= $h($number) ?></div>
      <div class="hdr-meta">Dátum vydania: <?= $formatDate($issuedOn) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $h($brandColor) ?>; margin:4pt 0 8pt;">

<div class="doc-title">
  <div class="p-o-k-y-n">POKYN</div>
  <div class="sub">na zabezpečenie ochrany pred požiarmi pri žatevných prácach,<br>
    pri zbere a skladovaní objemových krmovín</div>
</div>

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td>
    <td class="bv"><?= $h($company['name']) ?></td>
    <td class="bl">Obdobie platnosti</td>
    <td class="bv"><strong>žatevné práce v roku <?= $year ?: '—' ?></strong></td>
  </tr>
  <tr>
    <td class="bl">IČO</td>
    <td class="bv"><?= $h($company['ico']) ?></td>
    <td class="bl">Dátum vydania</td>
    <td class="bv"><?= $formatDate($issuedOn) ?></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <td class="bv"><?= $facilityName !== '' ? $h($facilityName) : 'celá spoločnosť' ?><?= !empty($facility['address']) ? '<br><span style="font-weight:normal;color:#555;">' . $h($facility['address']) . '</span>' : '' ?></td>
    <td class="bl">Vypracoval</td>
    <td class="bv"><?= $h($trainer['fullname']) ?><?php if (!empty($trainer['certification_number'])): ?>, technik
      PO, č. oprávnenia <?= $h($trainer['certification_number']) ?><?php endif ?></td>
  </tr>
  <tr>
    <td class="bl">Schválil</td>
    <td colspan="3"><?= $approver !== '' ? $h($approver) : '—' ?></td>
  </tr>
</table>

<div class="scope">
  <strong>Určené pre:</strong> všetkých zamestnancov a dodávateľov spoločnosti
  <?= $h($company['name']) ?>, ktorí sa zúčastňujú letných žatevných prác, zberu a skladovania
  objemových krmovín.
</div>

<?php foreach ($sections as $section):
  $title = trim((string) ($section['title'] ?? ''));
  $text  = (string) ($section['text'] ?? ''); ?>
  <?php if ($title !== ''): ?>
    <h2><?= $h($title) ?></h2>
  <?php endif ?>
  <div class="sect"><?= $renderBody($text) ?></div>
<?php endforeach ?>

<?php if (!empty($training['topics'])): ?>
  <div class="scope"><strong>Poznámky:</strong> <?= nl2br($h($training['topics'])) ?></div>
<?php endif ?>

<h2>Záver a podpisy</h2>
<table class="sig-tbl">
  <tr>
    <th width="38%">Vypracoval</th>
    <th width="38%">Schválil</th>
    <th width="24%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $h($trainer['fullname']) ?><?php if (!empty($trainer['certification_number'])): ?><br><span
          style="font-size:8pt; color:#555;">technik PO, č. oprávnenia:
          <?= $h($trainer['certification_number']) ?></span><?php endif ?></td>
    <td><?= $approver !== '' ? $h($approver) : 'Štatutárny zástupca / zodpovedná osoba' ?></td>
    <td><?= $h(($city ? $city . ', ' : '') . $formatDate($issuedOn)) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($trainer['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $h($trainer['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td>
      <div class="sig-line">Podpis schvaľujúcej osoby</div>
    </td>
    <td></td>
  </tr>
</table>

<div class="footer">
  Na vedomie: všetkým zamestnancom a dodávateľom zapojeným do letných poľnohospodárskych prác,
  zúčastneným na žatve a na zbere a skladovaní objemových krmovín.
</div>
