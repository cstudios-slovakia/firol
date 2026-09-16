<?php
/**
 * Audit BOZP / Audit ochrany pred požiarmi — block 3 / chapters 15 and 26.
 *
 * Layout follows the delivered mockup (`02_NAHLADY/bozp_protokoly.html`): the
 * header with the odbor tag, Základné informácie, the legal sentence, Výsledok
 * podľa sekcií, Zistené nedostatky, Celkové hodnotenie, Podpisy, pätička.
 *
 * One section the mockup does not show is printed between the section summary
 * and the findings: the item-by-item evaluation, with its legal basis beside
 * each question. Chapter 17 requires that column — „ak je právny základ
 * prázdny, na protokole sa stĺpec pre danú položku nechá prázdny" — and a
 * summary of counts has nowhere to put it. It is also what makes the document
 * worth anything to the client: a row of numbers does not tell them which
 * question was answered how.
 *
 * Two omissions are deliberate and are rules, not styling:
 *   - an item marked `neaplikovateľné` is not printed at all (15.2), and
 *   - an excluded section is replaced by one line saying it was excluded, so
 *     the reader can tell "not asked" from "asked and passed" (15.3).
 *
 * @var string $number
 * @var array  $brand       name, color, logo_data_uri
 * @var array  $inspection   executed_on, periodicity_label, notes
 * @var array  $company      name, ico, address, city
 * @var array  $facility     name, address, city
 * @var array  $inspector    fullname, certification_number, signature_data_uri
 * @var array  $audit        kind, scope, scope_label, title, legal_basis,
 *                           tag, sections, defects, summary, excluded
 * @var array|null $handover
 */
$h = static fn(?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $audit['color'] ?? ($brand['color'] ?? '#3D7FC1');

$formatDate = static function (?string $iso): string {
  if (!$iso)
    return '—';
  $ts = strtotime($iso);
  return $ts ? date('j. n. Y', $ts) : $iso;
};

$periodicity = $inspection['periodicity_label'] ?? null;
$summary = $audit['summary'];
$documentType = $audit['type'];

$inspectorLine = $h($inspector['fullname']);
if (!empty($inspector['certification_number'])) {
  $inspectorLine .= '<br><span style="font-weight:normal;color:#555;">'
    . $h($audit['cert_label']) . ', č. opr.: ' . $h($inspector['certification_number']) . '</span>';
}

$city = ($facility['city'] ?? '') ?: ($company['city'] ?? '');
$miesto = ($city ? $city . ', ' : '') . $formatDate($inspection['executed_on'] ?? null);

$resultLabels = [
  'vyhovuje'   => 'Vyhovuje',
  'nevyhovuje' => 'Nevyhovuje',
];
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

  /* The odbor in words. Chapter 26: the colour must never be the only signal,
     because a black-and-white printout is what ends up in the file. */
  .tag {
    border: 1pt solid <?= $h($brandColor) ?>;
    color: <?= $h($brandColor) ?>;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: .6pt;
    padding: 1pt 5pt;
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

  .legal-box {
    margin: 4pt 0;
    padding: 5pt 8pt;
    background: #eef5ff;
    border: 1pt solid #c5d8f5;
    font-size: 8.5pt;
    color: #1a3a6b;
  }

  table.grid {
    border-collapse: collapse;
    width: 100%;
    font-size: 8.5pt;
    margin-top: 4pt;
  }

  table.grid th {
    background: #f3f3f6;
    color: #2a2a32;
    padding: 4pt 5pt;
    border: 1pt solid #d6d6dc;
    text-align: left;
    font-size: 7.5pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
  }

  table.grid td {
    padding: 3pt 5pt;
    border: 1pt solid #e5e5ea;
    vertical-align: top;
  }

  .sec-head td {
    background: #f7f7f9;
    font-weight: bold;
    font-size: 9pt;
  }

  .ok {
    color: #2e7d32;
    font-weight: bold;
  }

  .bad {
    color: #c62828;
    font-weight: bold;
  }

  .muted {
    color: #6b6b75;
  }

  .note-line {
    font-size: 8pt;
    color: #555;
  }

  .excluded-note {
    font-size: 8.5pt;
    color: #555;
    margin-top: 3pt;
  }

  table.verdict {
    border-collapse: collapse;
    width: 100%;
    font-size: 9pt;
    margin-top: 4pt;
  }

  table.verdict td {
    padding: 5pt 8pt;
    border: 1pt solid #e5e5ea;
    text-align: center;
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
    <td width="53%">
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
    <td width="47%" class="hdr-right">
      <div><span class="tag"><?= $h($audit['tag']) ?></span></div>
      <div class="hdr-title"><?= $h($audit['title']) ?></div>
      <div class="hdr-meta">Č. dokumentu: <?= $h($number) ?></div>
      <div class="hdr-meta">Dátum: <?= $formatDate($inspection['executed_on'] ?? null) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $h($brandColor) ?>; margin:4pt 0 8pt;">

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td>
    <td class="bv"><?= $h($company['name']) ?></td>
    <td class="bl"><?= $h($audit['date_label']) ?></td>
    <td class="bv"><strong><?= $formatDate($inspection['executed_on'] ?? null) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">IČO</td>
    <?php // „Bez opakovania" prints no Periodicita row at all rather than a
          // dash: a protocol states the period it was issued under, and where
          // there is none there is nothing to state (chapter 5). ?>
    <td class="bv"<?= $periodicity === null ? ' colspan="3"' : '' ?>><?= $h($company['ico']) ?></td>
    <?php if ($periodicity !== null): ?>
      <td class="bl">Periodicita</td>
      <td class="bv"><?= $h($periodicity) ?></td>
    <?php endif ?>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <td class="bv"><?= $h($facility['name']) ?><?= !empty($facility['address'])
      ? '<br><span style="font-weight:normal;color:#555;">' . $h($facility['address']) . '</span>' : '' ?></td>
    <td class="bl"><?= $h($audit['performed_label']) ?></td>
    <td class="bv"><?= $inspectorLine ?></td>
  </tr>
</table>

<div class="legal-box"><?= $h($audit['legal_sentence']) ?></div>

<h2>Výsledok podľa sekcií</h2>
<table class="grid">
  <thead>
    <tr>
      <th style="width:7%">Sekcia</th>
      <th style="width:45%">Oblasť</th>
      <th style="width:11%">Vyhovuje</th>
      <th style="width:12%">Nevyhovuje</th>
      <th style="width:13%">Neaplikovateľné</th>
      <th>Stav</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($audit['sections'] as $section): ?>
      <?php if ($section['excluded']) {
        continue;
      } ?>
      <tr>
        <td><?= $h($section['code']) ?></td>
        <td><?= $h($section['name']) ?></td>
        <td><?= (int) $section['vyhovuje'] ?></td>
        <td><?= (int) $section['nevyhovuje'] ?></td>
        <td><?= (int) $section['neaplikovatelne'] ?></td>
        <td class="<?= $section['nevyhovuje'] > 0 ? 'bad' : 'ok' ?>">
          <?= $section['nevyhovuje'] > 0 ? 'nedostatok' : 'v poriadku' ?>
        </td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php foreach ($audit['excluded'] as $excluded): ?>
  <div class="excluded-note">Sekcia <?= $h($excluded['code']) ?>
    (<?= $h($excluded['name']) ?>) bola označená ako neaplikovateľná a nie je súčasťou tohto protokolu.</div>
<?php endforeach ?>

<?php // Item-by-item evaluation. Items marked neaplikovateľné were filtered
      // out upstream, so a section that ends up empty here is simply left
      // out — an empty box tells the reader nothing (chapter 15, „prázdne
      // sekcie sa nezobrazujú"). ?>
<h2>Hodnotenie podľa sekcií</h2>
<table class="grid">
  <thead>
    <tr>
      <th style="width:5%">Č.</th>
      <th style="width:47%">Položka</th>
      <th style="width:25%">Právny základ</th>
      <th>Výsledok</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($audit['sections'] as $section): ?>
      <?php if ($section['excluded'] || $section['printed'] === []) {
        continue;
      } ?>
      <tr class="sec-head">
        <td colspan="4"><?= $h($section['code']) ?> — <?= $h($section['name']) ?></td>
      </tr>
      <?php foreach ($section['printed'] as $row): ?>
        <tr>
          <td><?= $h($section['code']) ?>.<?= (int) $row['number'] ?></td>
          <td>
            <?= $h($row['text']) ?>
            <?php if (!empty($row['note'])): ?>
              <div class="note-line"><?= nl2br($h($row['note'])) ?></div>
            <?php endif ?>
          </td>
          <td class="muted"><?= $row['legal_basis'] !== null ? $h($row['legal_basis']) : '' ?></td>
          <td class="<?= $row['result'] === 'nevyhovuje' ? 'bad' : ($row['result'] === 'vyhovuje' ? 'ok' : 'muted') ?>">
            <?= $h($resultLabels[$row['result']] ?? '—') ?>
          </td>
        </tr>
      <?php endforeach ?>
    <?php endforeach ?>
  </tbody>
</table>

<?php if ($audit['defects'] !== []): ?>
  <h2>Zistené nedostatky</h2>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:5%">Č.</th>
        <th style="width:8%">Sekcia</th>
        <th style="width:39%">Popis</th>
        <th style="width:29%">Opatrenie</th>
        <th>Termín</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($audit['defects'] as $defect): ?>
        <tr>
          <td><?= (int) $defect['number'] ?></td>
          <td><?= $h($defect['section']) ?></td>
          <td><?= $h($defect['description']) ?></td>
          <td><?= $defect['measure'] !== null ? $h($defect['measure']) : '—' ?></td>
          <td><?= $defect['deadline'] !== null ? $h($formatDate($defect['deadline'])) : '—' ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<h2>Celkové hodnotenie</h2>
<table class="verdict">
  <tr>
    <td style="width:25%"><span class="muted" style="font-size:8pt;">Hodnotených položiek</span><br>
      <strong><?= (int) $summary['evaluated'] ?></strong></td>
    <td style="width:25%"><span class="muted" style="font-size:8pt;">Vyhovuje</span><br>
      <strong class="ok"><?= (int) $summary['vyhovuje'] ?></strong></td>
    <td style="width:25%"><span class="muted" style="font-size:8pt;">Nevyhovuje</span><br>
      <strong class="<?= $summary['nevyhovuje'] > 0 ? 'bad' : 'ok' ?>"><?= (int) $summary['nevyhovuje'] ?></strong></td>
    <td><span class="muted" style="font-size:8pt;">Hodnotenie</span><br>
      <strong class="<?= $summary['nevyhovuje'] > 0 ? 'bad' : 'ok' ?>"><?= $h($summary['verdict_label']) ?></strong></td>
  </tr>
</table>

<?php if (!empty($inspection['notes'])): ?>
  <h2>Poznámka</h2>
  <div style="margin-top:4pt; font-size:9pt;"><?= nl2br($h($inspection['notes'])) ?></div>
<?php endif ?>

<h2>Podpisy</h2>
<table class="sig-tbl">
  <tr>
    <th width="38%"><?= $h($audit['performed_label']) ?></th>
    <th width="38%"><?= \Firol\Pdf\SignatureBlock::heading($documentType) ?></th>
    <th width="24%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $h($inspector['fullname']) ?><?php if (!empty($inspector['certification_number'])): ?><br><span
          style="font-size:8pt; color:#555;"><?= $h($audit['cert_label']) ?>, č. oprávnenia:
          <?= $h($inspector['certification_number']) ?></span><?php endif ?></td>
    <td><?= \Firol\Pdf\SignatureBlock::nameCell($handover ?? null, (string) ($company['approver'] ?? '')) ?></td>
    <td><?= \Firol\Pdf\SignatureBlock::placeAndDate($handover ?? null, $miesto) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $h($inspector['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td>
      <?= \Firol\Pdf\SignatureBlock::signCell($handover ?? null, $documentType) ?>
    </td>
    <td></td>
  </tr>
</table>

<div class="footer">
  Vypracoval: <?= $h($inspector['fullname']) ?><?php if (!empty($inspector['certification_number'])): ?> | č.
    oprávnenia: <?= $h($inspector['certification_number']) ?><?php endif ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Hodnotených položiek: <?= (int) $summary['evaluated'] ?>
</div>
