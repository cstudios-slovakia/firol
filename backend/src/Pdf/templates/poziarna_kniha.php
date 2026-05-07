<?php
/**
 * Požiarna kniha entry. One inspection = one record (the schema allows
 * many for uniformity but the controller blocks more than one).
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

$activityLabels = [
    'visual_check'                => 'Vizuálna kontrola priestorov spoločnosti',
    'rphp_check'                  => 'Kontrola stavu, označenia a dostupnosti RPHP',
    'hydranty_check'              => 'Kontrola stavu, označenia a dostupnosti požiarnych hydrantov',
    'escape_routes_check'         => 'Kontrola stavu, označenia a voľnosti únikových ciest',
    'pu_check'                    => 'Kontrola akcieschopnosti požiarnych uzáverov',
    'training_initial'            => 'Vykonané vstupné školenie z predpisov OPP',
    'training_repeated'           => 'Vykonané opakované školenie vedúcich a ostatných zamestnancov',
    'electrical_equipment_check'  => 'Kontrola stavu používaných elektrických zariadení',
    'technical_equipment_check'   => 'Kontrola stavu používaných technických zariadení',
    'electrical_appliances_check' => 'Kontrola stavu používaných elektrických spotrebičov',
    'documentation_check'         => 'Kontrola aktuálnosti dokumentácie požiarnej ochrany',
    'employee_list_check'         => 'Kontrola aktuálneho zoznamu zamestnancov a ich školení',
    'fire_drill'                  => 'Vykonaný cvičný požiarny poplach',
    'fire_cabinet_check'          => 'Kontrola hasičskej skrine na 2. poschodí',
];
$resultLabels = [
    'bez_nedostatkov'    => 'Bez zistených nedostatkov',
    'zistene_nedostatky' => 'Zistené nedostatky — nutné riešenie',
];

$formatDate = static function (?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('j. n. Y', $ts) : $iso;
};

$record = $items[0]['fields'] ?? [];
$activeSlugs = is_array($record['activities'] ?? null) ? $record['activities'] : [];
$result = (string) ($record['result'] ?? '');
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
  ul.activities { list-style: none; padding: 0; margin: 4pt 0; font-size: 9.5pt; }
  ul.activities li { padding: 3pt 0; border-bottom: 1pt dotted #e5e5ea; }
  ul.activities .checkbox { display: inline-block; width: 12pt; height: 12pt; border: 1pt solid #4a4a52; vertical-align: middle; margin-right: 6pt; text-align: center; line-height: 11pt; font-weight: bold; }
  ul.activities .checked { background: #e8f5e6; color: #2e7d32; }
  ul.activities .unchecked { background: #fff; color: transparent; }
  .result-banner { margin-top: 14pt; padding: 8pt 12pt; border-radius: 6pt; font-size: 11pt; font-weight: bold; text-align: center; }
  .result-bez { background: #e8f5e6; color: #2e7d32; }
  .result-zistene { background: #fde8e6; color: #c4231b; }
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
  <div class="title"><?= $h($brand['name'] ?? 'Firol') ?> · Požiarna kniha — záznam</div>
  <div class="subtitle">Pravidelný záznam o stave protipožiarnej ochrany</div>
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
</table>

<h2>Záznam</h2>
<table class="kv">
  <tr><td class="label">Dátum záznamu</td><td><strong><?= $formatDate($inspection['executed_on'] ?? null) ?></strong></td></tr>
  <tr><td class="label">Periodicita</td><td><?= (int) ($inspection['periodicity_months'] ?? 0) ?> mesiacov</td></tr>
  <tr><td class="label">Vykonal</td><td><?= $h($inspector['fullname']) ?></td></tr>
  <?php if (!empty($inspector['certification_number'])): ?>
  <tr><td class="label">Číslo oprávnenia</td><td><?= $h($inspector['certification_number']) ?></td></tr>
  <?php endif ?>
  <tr><td class="label">Prehliadnuté pracoviská</td><td><?= nl2br($h($record['workspaces'] ?? '')) ?></td></tr>
</table>

<h2>Vykonané činnosti</h2>
<ul class="activities">
  <?php foreach ($activityLabels as $slug => $label): $checked = in_array($slug, $activeSlugs, true); ?>
    <li>
      <span class="checkbox <?= $checked ? 'checked' : 'unchecked' ?>"><?= $checked ? '✓' : '' ?></span>
      <?= $h($label) ?>
    </li>
  <?php endforeach ?>
  <?php if (!empty($record['activities_other'])): ?>
    <li>
      <span class="checkbox checked">✓</span>
      <em><?= $h($record['activities_other']) ?></em>
    </li>
  <?php endif ?>
</ul>

<div class="result-banner result-<?= $result === 'bez_nedostatkov' ? 'bez' : 'zistene' ?>">
  Výsledok: <?= $h($resultLabels[$result] ?? '') ?>
</div>

<?php if (!empty($record['notes']) || !empty($inspection['notes'])): ?>
<h2>Poznámky</h2>
<?php if (!empty($record['notes'])): ?>
  <p><?= nl2br($h($record['notes'])) ?></p>
<?php endif ?>
<?php if (!empty($inspection['notes'])): ?>
  <p class="small"><?= nl2br($h($inspection['notes'])) ?></p>
<?php endif ?>
<?php endif ?>

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
