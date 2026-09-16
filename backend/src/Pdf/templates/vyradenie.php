<?php
/**
 * Vyraďovací protokol hasiacich prístrojov (change request 2.1).
 *
 * Layout follows docs/handoff/zmeny-2026-07/POapp_sablona_vyradovaci_protokol.docx
 * rendered in the same house style as the other protocols (company header with
 * logo, red section bars, footer).
 *
 * @var string $number
 * @var string $generated_at
 * @var array  $brand          name, color, logo_data_uri
 * @var array  $inspection     executed_on, notes, status, source_number
 * @var array  $company        name, ico, address, city, approver
 * @var array  $facility       name, address, city
 * @var array  $inspector      fullname, certification_number, signature_data_uri
 * @var array  $items          list of { fields: { manufacturer, type, serial, year, location, reason } }
 * @var array  $stats          total
 */
$h = static fn(?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

$formatDate = static function (?string $iso): string {
  if (!$iso)
    return '—';
  $ts = strtotime($iso);
  return $ts ? date('j. n. Y', $ts) : $iso;
};

$inspectorLine = $h($inspector['fullname']);
if (!empty($inspector['certification_number'])) {
  $inspectorLine .= ', technik PO, č. oprávnenia ' . $h($inspector['certification_number']);
}

$city = ($facility['city'] ?? '') ?: ($company['city'] ?? '');
$approver = trim((string) ($company['approver'] ?? ''));
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
    font-size: 12.5pt;
    font-weight: bold;
    color: <?= $h($brandColor) ?>;
  }

  .hdr-meta {
    font-size: 9pt;
    color: #555;
  }

  h2 {
    background: <?= $h($brandColor) ?>;
    color: #fff;
    font-size: 9pt;
    font-weight: bold;
    margin: 8pt 0 0;
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

  .notice {
    margin-top: 5pt;
    padding: 5pt 8pt;
    background: #f7f7f9;
    border: 1pt solid #e5e5ea;
    font-size: 9pt;
  }

  table.items {
    border-collapse: collapse;
    width: 100%;
    font-size: 8.5pt;
    margin-top: 4pt;
  }

  table.items th {
    background: #f3f3f6;
    color: #2a2a32;
    padding: 4pt 5pt;
    border: 1pt solid #d6d6dc;
    text-align: left;
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
  }

  table.items td {
    padding: 4pt 5pt;
    border: 1pt solid #e5e5ea;
    vertical-align: top;
  }

  .declaration {
    margin-top: 8pt;
    padding: 5pt 8pt 5pt 10pt;
    background: #f7f7f9;
    border: 1pt solid #e5e5ea;
    border-left: 3pt solid <?= $h($brandColor) ?>;
    font-size: 8.5pt;
  }

  .declaration p {
    margin: 0 0 3pt;
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
            <div class="hdr-sub">Prevádzka: <?= $h($facility['name']) ?> | IČO: <?= $h($company['ico']) ?></div>
            <?php if (!empty($facility['address'])): ?>
              <div class="hdr-sub"><?= $h($facility['address']) ?></div><?php endif ?>
          </td>
        </tr>
      </table>
    </td>
    <td width="43%" class="hdr-right">
      <div class="hdr-title">Vyraďovací protokol hasiacich prístrojov vyradených z používania</div>
      <div class="hdr-meta">Č. dokumentu: <?= $h($number) ?></div>
      <div class="hdr-meta">Dátum vyradenia: <?= $formatDate($inspection['executed_on'] ?? null) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $h($brandColor) ?>; margin:4pt 0 8pt;">

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td>
    <td class="bv"><?= $h($company['name']) ?></td>
    <td class="bl">Dátum vyradenia</td>
    <td class="bv"><strong><?= $formatDate($inspection['executed_on'] ?? null) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">IČO</td>
    <td class="bv"><?= $h($company['ico']) ?></td>
    <td class="bl">Vyradenie navrhol</td>
    <td class="bv"><?= $inspectorLine ?></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <?php // The "Nadväzuje na kontrolu" column is only printed when this
          // document grew out of a PHP inspection (change request 2.1). ?>
    <td class="bv"<?= empty($inspection['source_number']) ? ' colspan="3"' : '' ?>><?= $h($facility['name']) ?><?= !empty($facility['address']) ? '<br><span style="font-weight:normal;color:#555;">' . $h($facility['address']) . '</span>' : '' ?></td>
    <?php if (!empty($inspection['source_number'])): ?>
    <td class="bl">Nadväzuje na kontrolu</td>
    <td class="bv"><?= $h($inspection['source_number']) ?></td>
    <?php endif ?>
  </tr>
</table>

<h2>Oznámenie o vyradení</h2>
<div class="notice">
  Oznamujeme Vám, že nasledujúce hasiace prístroje boli vyradené z používania, pretože nespĺňajú
  požiadavky podľa STN EN 3 a vyhlášky MV SR č. 347/2022 Z. z. o vlastnostiach a o podmienkach
  prevádzkovania, označovania a zabezpečenia pravidelnej kontroly hasiacich prístrojov.
</div>

<h2>Zoznam vyradených hasiacich prístrojov</h2>
<table class="items">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:15%">Výrobca</th>
      <th style="width:9%">Typ</th>
      <th style="width:16%">Č. výr. série / tlak. nádoby</th>
      <th style="width:7%">Rok výr.</th>
      <th style="width:20%">Umiestnenie</th>
      <th style="width:29%">Dôvod vyradenia</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $idx => $it):
      $f = $it['fields']; ?>
      <tr>
        <td><?= $idx + 1 ?></td>
        <td><?= $h($f['manufacturer'] ?? null) ?></td>
        <td><?= $h($f['type'] ?? null) ?></td>
        <td><?= $h($f['serial'] ?? null) ?></td>
        <td><?= (int) ($f['year'] ?? 0) ?: '—' ?></td>
        <td><?= $h($f['location'] ?? null) ?></td>
        <td><?= $h($f['reason'] ?? null) ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<h2>Vyhlásenie</h2>
<div class="declaration">
  <p>Vyradené hasiace prístroje sa likvidujú odborne spôsobilou osobou v súlade so STN EN 3
    a vyhláškou MV SR č. 347/2022 Z. z. V prípade ich ďalšieho použitia by mohlo dôjsť
    k ťažkým poraneniam.</p>
  <p>Prevádzkovateľovi sa odporúča bezodkladne zabezpečiť náhradu vyradených hasiacich prístrojov
    tak, aby bol dodržaný určený počet a druh hasiacich prístrojov v objekte.</p>
</div>

<h2>Podpisy</h2>
<table class="sig-tbl">
  <tr>
    <th width="38%">Vyradenie navrhol</th>
    <th width="38%">Za spoločnosť prevzal na vedomie</th>
    <th width="24%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $h($inspector['fullname']) ?><?php if (!empty($inspector['certification_number'])): ?><br><span
          style="font-size:8pt; color:#555;">technik PO, č. oprávnenia:
          <?= $h($inspector['certification_number']) ?></span><?php endif ?></td>
    <td><?= \Firol\Pdf\SignatureBlock::nameCell($handover ?? null, $approver) ?></td>
    <td><?= \Firol\Pdf\SignatureBlock::placeAndDate($handover ?? null, ($city ? $city . ', ' : '') . $formatDate($inspection['executed_on'] ?? null)) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $h($inspector['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td>
      <div class="sig-line">Podpis zodpovednej osoby</div>
    </td>
    <td></td>
  </tr>
</table>

<div class="footer">
  Vypracoval: <?= $h($inspector['fullname']) ?><?php if (!empty($inspector['certification_number'])): ?> | č.
    oprávnenia: <?= $h($inspector['certification_number']) ?><?php endif ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Vyradených prístrojov: <?= (int) ($stats['total'] ?? count($items)) ?>
</div>
