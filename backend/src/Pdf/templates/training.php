<?php
/**
 * Training (Školenie) protocol template. The attendee list is the heart
 * of the document — each row carries the trainee's name, position and
 * inlined signature so the protocol stays self-contained even after the
 * stored PNG is deleted.
 *
 * @var string $number          e.g. SKO-2026-001
 * @var string $generated_at
 * @var array  $brand           name, color
 * @var array  $training        type, training_type_label, date,
 *                              duration_min, topics, status
 * @var array  $company         name, ico, address
 * @var array  $facility        name, address (may be empty arrays if
 *                              the training has no facility scope)
 * @var array  $trainer         fullname, certification_number,
 *                              signature_data_uri
 * @var list<array>             $trainees   id, fullname, position,
 *                                          signature_data_uri, signed_at
 */
$h = static fn (?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

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
  table.attendees { font-size: 9pt; margin-top: 4pt; }
  table.attendees th { background: #f3f3f6; color: #2a2a32; padding: 4pt 6pt; border: 1pt solid #d6d6dc; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.attendees td { padding: 6pt; border: 1pt solid #e5e5ea; vertical-align: middle; }
  table.attendees .sig-cell { text-align: center; }
  table.attendees .sig-img { max-height: 36pt; max-width: 140pt; }
  .topics { white-space: pre-wrap; font-size: 9.5pt; line-height: 1.4; padding: 4pt 0; }
  .signature-block { margin-top: 22pt; }
  .signature-block .row { display: table; width: 100%; }
  .signature-block .col { display: table-cell; width: 50%; vertical-align: bottom; padding-right: 18pt; }
  .signature-line { border-top: 1pt solid #2a2a32; margin-top: 50pt; padding-top: 3pt; font-size: 8.5pt; }
  .signature-img { max-height: 48pt; max-width: 180pt; margin-bottom: -8pt; }
  .small { color: #6b6b75; font-size: 8pt; }
  .footer { color: #8a8a92; font-size: 7.5pt; text-align: center; margin-top: 20pt; }
</style>

<div class="brand-bar">
  <span class="doc-number"><?= $h($number) ?></span>
  <div class="title"><?= $h($brand['name'] ?? 'Firol') ?> · Záznam o vykonanom školení</div>
  <div class="subtitle"><?= $h($training['training_type_label'] ?? '') ?></div>
</div>

<h2>Klient</h2>
<table class="kv">
  <tr><td class="label">Spoločnosť</td><td><?= $h($company['name']) ?></td></tr>
  <tr><td class="label">IČO</td><td><?= $h($company['ico']) ?></td></tr>
  <tr><td class="label">Adresa</td><td><?= $h($company['address']) ?></td></tr>
</table>

<?php if (!empty($facility['name'])): ?>
<h2>Prevádzka</h2>
<table class="kv">
  <tr><td class="label">Názov</td><td><?= $h($facility['name']) ?></td></tr>
  <?php if (!empty($facility['address'])): ?>
  <tr><td class="label">Adresa</td><td><?= $h($facility['address']) ?></td></tr>
  <?php endif ?>
</table>
<?php endif ?>

<h2>Školenie</h2>
<table class="kv">
  <tr><td class="label">Dátum</td><td><strong><?= $formatDate($training['date'] ?? null) ?></strong></td></tr>
  <?php if (!empty($training['duration_min'])): ?>
  <tr><td class="label">Dĺžka</td><td><?= (int) $training['duration_min'] ?> minút</td></tr>
  <?php endif ?>
  <tr><td class="label">Školiteľ</td><td>
    <?= $h($trainer['fullname']) ?>
    <?php if (!empty($trainer['certification_number'])): ?>
      <span class="small"> · oprávnenie č. <?= $h($trainer['certification_number']) ?></span>
    <?php endif ?>
  </td></tr>
</table>

<?php if (!empty($training['topics'])): ?>
<h2>Obsah školenia</h2>
<div class="topics"><?= nl2br($h($training['topics'])) ?></div>
<?php endif ?>

<h2>Účastníci školenia (<?= count($trainees) ?>)</h2>
<table class="attendees">
  <thead>
    <tr>
      <th style="width: 4%">#</th>
      <th style="width: 30%">Meno a priezvisko</th>
      <th style="width: 26%">Pracovné zaradenie</th>
      <th style="width: 30%">Podpis</th>
      <th style="width: 10%">Dátum</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($trainees as $idx => $tr): ?>
    <tr>
      <td><?= $idx + 1 ?></td>
      <td><strong><?= $h($tr['fullname']) ?></strong></td>
      <td><?= $h($tr['position'] ?? null) ?></td>
      <td class="sig-cell">
        <?php if (!empty($tr['signature_data_uri'])): ?>
          <img class="sig-img" src="<?= $h($tr['signature_data_uri']) ?>" alt="Podpis">
        <?php else: ?>
          <span class="small">— bez podpisu —</span>
        <?php endif ?>
      </td>
      <td><?= $formatDate(substr((string) ($tr['signed_at'] ?? ''), 0, 10)) ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>

<div class="signature-block">
  <div class="row">
    <div class="col">
      <?php if (!empty($trainer['signature_data_uri'])): ?>
        <img class="signature-img" src="<?= $h($trainer['signature_data_uri']) ?>" alt="Podpis školiteľa">
      <?php endif ?>
      <div class="signature-line">
        <strong><?= $h($trainer['fullname']) ?></strong>
        <?php if (!empty($trainer['certification_number'])): ?>
          <div class="small">Oprávnenie č. <?= $h($trainer['certification_number']) ?></div>
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
