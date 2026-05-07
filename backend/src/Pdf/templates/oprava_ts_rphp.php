<?php
/**
 * Oprava + plnenie + TS RPHP protocol template.
 * Receives via PdfRenderer::renderOpravaTsRphp:
 *   string $number, $generated_at; array $brand, $inspection, $company,
 *   $facility, $inspector, list<array> $items, array $stats
 *   (tlakova_skuska, oprava, plnenie, total).
 *
 * @var string $number
 * @var string $generated_at
 * @var array  $brand
 * @var array  $inspection
 * @var array  $company
 * @var array  $facility
 * @var array  $inspector
 * @var array  $items
 * @var array  $stats
 */
$h = static fn (?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

$actionLabels = [
    'tlakova_skuska' => 'Tlaková skúška',
    'oprava'         => 'Oprava',
    'plnenie'        => 'Plnenie',
];

$formatDate = static function (?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('j. n. Y', $ts) : $iso;
};
?>
<style>
  body { font-family: dejavusans, sans-serif; color: #1a1a1f; font-size: 10pt; }
  .brand-bar { background: <?= $h($brandColor) ?>; color: #fff; padding: 8pt 12pt; border-radius: 6pt; }
  .brand-bar .title { font-size: 14pt; font-weight: bold; letter-spacing: .3pt; }
  .brand-bar .subtitle { font-size: 9pt; opacity: .9; }
  .doc-number { float: right; font-family: monospace; font-size: 11pt; background: rgba(255,255,255,.18); padding: 3pt 7pt; border-radius: 4pt; }
  h2 { color: <?= $h($brandColor) ?>; font-size: 11pt; margin: 14pt 0 4pt; border-bottom: 1pt solid #e5e5ea; padding-bottom: 2pt; }
  table { border-collapse: collapse; width: 100%; }
  table.kv { font-size: 9.5pt; }
  table.kv td { padding: 2pt 0; vertical-align: top; }
  table.kv td.label { width: 30%; color: #6b6b75; font-weight: bold; text-transform: uppercase; font-size: 8pt; letter-spacing: .4pt; }
  table.items { font-size: 8.5pt; margin-top: 4pt; }
  table.items th { background: #f3f3f6; color: #2a2a32; padding: 4pt 5pt; border: 1pt solid #d6d6dc; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.items td { padding: 4pt 5pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  .action-pill { display: inline-block; padding: 1pt 5pt; border-radius: 8pt; font-weight: bold; font-size: 7.5pt; margin: 1pt 1pt 0 0; background: #e8eef9; color: #1f4ea3; }
  .stats { margin-top: 10pt; }
  .stats td { padding: 6pt 8pt; border: 1pt solid #e5e5ea; text-align: center; font-size: 9pt; }
  .stats .num { font-size: 14pt; font-weight: bold; display: block; margin-top: 2pt; }
  .signature-block { margin-top: 22pt; }
  .signature-block .row { display: table; width: 100%; }
  .signature-block .col { display: table-cell; width: 50%; vertical-align: bottom; padding-right: 18pt; }
  .signature-line { border-top: 1pt solid #2a2a32; margin-top: 50pt; padding-top: 3pt; font-size: 8.5pt; }
  .signature-img { max-height: 48pt; max-width: 180pt; margin-bottom: -8pt; }
  .small { color: #6b6b75; font-size: 8pt; }
  .notes { font-style: italic; color: #4a4a52; }
  .footer { color: #8a8a92; font-size: 7.5pt; text-align: center; margin-top: 20pt; }
</style>

<div class="brand-bar">
  <span class="doc-number"><?= $h($number) ?></span>
  <div class="title"><?= $h($brand['name'] ?? 'Firol') ?> · Záznam o oprave, plnení a TS RPHP</div>
  <div class="subtitle">Servis hasiacich prístrojov · revízia podľa zákona NR SR č. 314/2001 Z. z. a vyhl. MV SR č. 121/2002 Z. z.</div>
</div>

<h2>Klient</h2>
<table class="kv">
  <tr><td class="label">Spoločnosť</td><td><?= $h($company['name']) ?></td></tr>
  <tr><td class="label">IČO</td><td><?= $h($company['ico']) ?></td></tr>
  <tr><td class="label">Adresa</td><td><?= $h($company['address']) ?></td></tr>
</table>

<h2>Prevádzka</h2>
<table class="kv">
  <tr><td class="label">Názov</td><td><?= $h($facility['name']) ?></td></tr>
  <tr><td class="label">Adresa</td><td><?= $h($facility['address']) ?></td></tr>
  <?php if (!empty($facility['contact_person'])): ?>
  <tr><td class="label">Kontaktná osoba</td><td><?= $h($facility['contact_person']) ?></td></tr>
  <?php endif ?>
</table>

<h2>Servis</h2>
<table class="kv">
  <tr><td class="label">Dátum</td><td><strong><?= $formatDate($inspection['executed_on'] ?? null) ?></strong></td></tr>
  <tr><td class="label">Periodicita</td><td><?= (int) ($inspection['periodicity_months'] ?? 0) ?> mesiacov</td></tr>
  <tr><td class="label">Vykonal</td><td><?= $h($inspector['fullname']) ?></td></tr>
  <?php if (!empty($inspector['certification_number'])): ?>
  <tr><td class="label">Číslo oprávnenia</td><td><?= $h($inspector['certification_number']) ?>
    <?php if (!empty($inspector['valid_from']) || !empty($inspector['valid_to'])): ?>
      <span class="small">(platnosť: <?= $formatDate($inspector['valid_from'] ?? null) ?> – <?= $formatDate($inspector['valid_to'] ?? null) ?>)</span>
    <?php endif ?>
  </td></tr>
  <?php endif ?>
  <?php if (!empty($inspection['notes'])): ?>
  <tr><td class="label">Poznámky</td><td><?= nl2br($h($inspection['notes'])) ?></td></tr>
  <?php endif ?>
</table>

<h2>Zoznam servisovaných hasiacich prístrojov</h2>
<table class="items">
  <thead>
    <tr>
      <th style="width: 4%">#</th>
      <th style="width: 14%">Výrobca</th>
      <th style="width: 9%">Typ</th>
      <th style="width: 14%">Sériové č.</th>
      <th style="width: 7%">Rok</th>
      <th style="width: 19%">Umiestnenie</th>
      <th style="width: 17%">Vykonané úkony</th>
      <th style="width: 16%">Poznámky</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $idx => $it): $f = $it['fields']; $actions = is_array($f['actions'] ?? null) ? $f['actions'] : []; ?>
    <tr>
      <td><?= $idx + 1 ?></td>
      <td><?= $h($f['manufacturer']) ?></td>
      <td><?= $h($f['type']) ?></td>
      <td><?= $h($f['serial']) ?></td>
      <td><?= (int) ($f['year'] ?? 0) ?: '—' ?></td>
      <td><?= $h($f['location']) ?></td>
      <td>
        <?php foreach ($actions as $a): ?>
          <span class="action-pill"><?= $h($actionLabels[$a] ?? $a) ?></span>
        <?php endforeach ?>
      </td>
      <td class="notes"><?= !empty($f['notes']) ? nl2br($h($f['notes'])) : '—' ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>

<table class="stats">
  <tr>
    <td>Spolu prístrojov<span class="num"><?= (int) $stats['total'] ?></span></td>
    <td>Tlaková skúška<span class="num" style="color:#1f4ea3"><?= (int) ($stats['tlakova_skuska'] ?? 0) ?></span></td>
    <td>Oprava<span class="num" style="color:#1f4ea3"><?= (int) ($stats['oprava'] ?? 0) ?></span></td>
    <td>Plnenie<span class="num" style="color:#1f4ea3"><?= (int) ($stats['plnenie'] ?? 0) ?></span></td>
  </tr>
</table>

<div class="signature-block">
  <div class="row">
    <div class="col">
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="signature-img" src="<?= $h($inspector['signature_data_uri']) ?>" alt="Podpis">
      <?php endif ?>
      <div class="signature-line">
        <strong><?= $h($inspector['fullname']) ?></strong>
        <?php if (!empty($inspector['certification_number'])): ?>
          <div class="small">Oprávnenie č. <?= $h($inspector['certification_number']) ?></div>
        <?php endif ?>
      </div>
    </div>
    <div class="col">
      <div class="signature-line">
        <strong>Za klienta</strong>
        <div class="small">Podpis a pečiatka zástupcu spoločnosti</div>
      </div>
    </div>
  </div>
</div>

<div class="footer">
  Vystavené v aplikácii Firol · <?= $h($number) ?> · vygenerované <?= $formatDate(substr((string) $generated_at, 0, 10)) ?>
</div>
