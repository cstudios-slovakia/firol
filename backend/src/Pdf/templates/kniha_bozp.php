<?php
/**
 * Záznam o vykonaní preventívnej prehliadky BOZP (BOZP-RRRR-NNN) —
 * POapp 02. 11. 2026, Option A (2_Vzor_hotoveho_zaznamu_A.pdf).
 *
 * Section order and fixed sentences matching Option A:
 *   Title (NO legal sentence per Rule 0a.1) → Základné údaje (Dátum, Vykonal,
 *   Lehota, Ďalšia do) → Popis objektu → Poznámka → Hlavné vypínače a uzávery
 *   → Vykonané činnosti → Zistené nedostatky → Stav nedostatkov z prehliadky
 *   → Termíny (Čo | Platnosť) → Podpisy (Vykonal | S výsledkom prehliadky
 *   oboznámený).
 *
 * @var string $number
 * @var array  $items         one record — Firol\Support\BozpRecords
 * @var array  $stats
 * @var array  $client_terms  Firol\Support\ClientTerms rows
 * @var array  $photos
 * @var array  $brand
 * @var array  $company
 * @var array  $facility
 * @var array  $inspector
 * @var array|null $handover
 * @var array|null $prehliadka
 */
use Firol\Pdf\DefectsTable;
use Firol\Pdf\PrehliadkaDefectsBlock;
use Firol\Pdf\ProtocolLayout;
use Firol\Pdf\SignatureBlock;
use Firol\Support\Contractor;
use Firol\Support\Defects;
use Firol\Support\Periodicity;
use Firol\Support\PrehliadkaRecord;

$doc = new ProtocolLayout(
    get_defined_vars(),
    ProtocolLayout::BOZP_COLOR,
    'BOZP',
);
$e = [ProtocolLayout::class, 'esc'];

$f = (array) ($items[0]['fields'] ?? []);
$fromPrehliadka = is_array($f['prehliadka'] ?? null)
    ? $f['prehliadka']
    : (is_array($prehliadka ?? null) ? $prehliadka : null);

$title = 'Záznam o vykonaní preventívnej prehliadky BOZP';
$subtitle = null;

$workspaces = array_values(array_filter((array) ($f['workspaces'] ?? []), 'is_string'));
$activities = array_merge(
    array_values(array_filter((array) ($f['activities'] ?? []), 'is_string')),
    array_values(array_filter((array) ($f['custom_activities'] ?? []), 'is_string')),
);
$terms = isset($client_terms) && is_array($client_terms) ? $client_terms : [];
$ho = is_array($handover ?? null) ? $handover : null;

$certLine = !empty($inspector['certification_number'])
    ? '<br><span style="font-size:8pt; color:#555;">č. opr. ' . $e((string) $inspector['certification_number']) . '</span>'
    : '';

$handedOver = is_array($fromPrehliadka['handed_over'] ?? null) ? $fromPrehliadka['handed_over'] : null;
if ($ho !== null) {
    $clientName = SignatureBlock::nameCell($ho);
} elseif ($handedOver !== null && !empty($handedOver['name'])) {
    $clientName = $e((string) $handedOver['name'])
        . (!empty($handedOver['role']) ? ', ' . $e((string) $handedOver['role']) : '');
} elseif (!empty($company['approver'])) {
    $clientName = $e((string) $company['approver']);
} else {
    $clientName = 'Zodpovedná osoba organizácie';
}

$executedOn = ProtocolLayout::date($inspection['executed_on'] ?? null);

$rawDetails = (array) ($inspection['details'] ?? []);
$lehotaZakon = (int) ($fromPrehliadka['lehota_zakon'] ?? ($rawDetails['lehota_zakon'] ?? 3));
$lehotaVlastna = isset($fromPrehliadka['lehota_vlastna']) && (int) $fromPrehliadka['lehota_vlastna'] > 0
    ? (int) $fromPrehliadka['lehota_vlastna']
    : (isset($rawDetails['lehota_vlastna']) && (int) $rawDetails['lehota_vlastna'] > 0 ? (int) $rawDetails['lehota_vlastna'] : null);

$effectiveMonths = ($lehotaVlastna !== null && $lehotaVlastna > 0)
    ? $lehotaVlastna
    : (int) ($inspection['periodicity_value'] ?? $lehotaZakon);

if ($lehotaVlastna !== null && $lehotaVlastna > 0 && $lehotaVlastna < $lehotaZakon) {
    $lehotaHtml = $effectiveMonths . ' mesiacov <span class="muted" style="font-size:8pt;">(dohodnutá kratšia; zákonná ' . $lehotaZakon . ' mesiacov)</span>';
} else {
    $lehotaHtml = $effectiveMonths . ' mesiacov';
}

$nextDateIso = Periodicity::validUntil($inspection['executed_on'] ?? date('Y-m-d'), $effectiveMonths, 'months');
$nextDateFormatted = ProtocolLayout::date($nextDateIso);

$facilityDesc = trim((string) ($fromPrehliadka['description'] ?? ($facility['description'] ?? '')));
$note = trim((string) ($fromPrehliadka['note'] ?? ($fromPrehliadka['poznamka'] ?? ($rawDetails['note'] ?? ($rawDetails['poznamka'] ?? ($inspection['notes'] ?? ''))))));
$switches = (array) ($fromPrehliadka['switches'] ?? []);
?>
<?= $doc->styles() ?>
<?= $doc->header($title, $subtitle) ?>

<?= $doc->band('Základné údaje') ?>
<table class="grid" style="margin-top:0;">
  <tr>
    <th style="width:14%">Dátum</th>
    <td style="width:36%"><strong><?= $e($executedOn) ?></strong></td>
    <th style="width:14%">Vykonal</th>
    <td style="width:36%"><?= $e((string) ($inspector['fullname'] ?? '')) ?><?= $certLine ?></td>
  </tr>
  <tr>
    <th>Lehota</th>
    <td><?= $lehotaHtml ?></td>
    <th>Ďalšia do</th>
    <td><strong><?= $e($nextDateFormatted) ?></strong></td>
  </tr>
  <?php if (!empty($contractor['name'])): ?>
    <tr>
      <th>Zhotoviteľ</th>
      <td colspan="3"><?= Contractor::cellHtml($contractor) ?></td>
    </tr>
  <?php endif ?>
</table>

<?php if ($facilityDesc !== ''): ?>
  <?= $doc->band('Popis objektu') ?>
  <div class="text-box"><?= nl2br($e($facilityDesc)) ?></div>
<?php endif ?>

<?php if ($note !== ''): ?>
  <?= $doc->band('Poznámka') ?>
  <div class="text-box"><?= nl2br($e($note)) ?></div>
<?php endif ?>

<?php if ($switches !== []): ?>
  <?= $doc->band('Hlavné vypínače a uzávery') ?>
  <table class="bi">
    <?php foreach ($switches as $row): ?>
      <tr>
        <td class="bl" style="width:36%"><?= $e((string) $row['label']) ?></td>
        <td class="bv"><?= $e((string) $row['value']) ?></td>
      </tr>
    <?php endforeach ?>
  </table>
<?php endif ?>

<?php if ($activities !== []): ?>
  <?= $doc->band('Vykonané činnosti') ?>
  <ul style="margin:4pt 0 4pt; padding-left:18pt; line-height:1.4;">
    <?php foreach ($activities as $activity): ?>
      <li><?= $e((string) $activity) ?></li>
    <?php endforeach ?>
  </ul>
  <div class="small-note" style="font-style:italic; color:#6b6b75; margin-top:3pt;"><?= $e(PrehliadkaRecord::CHECKED) ?></div>
<?php elseif ($workspaces !== [] && $fromPrehliadka === null): ?>
  <?= $doc->band('Prehliadnuté pracoviská') ?>
  <?= $doc->textBox(implode(', ', $workspaces)) ?>
<?php endif ?>

<?php if ($fromPrehliadka !== null): ?>
  <?= PrehliadkaDefectsBlock::render((array) ($fromPrehliadka['points'] ?? []), [
      'photos'    => !empty($photos),
      'when_none' => PrehliadkaRecord::NO_DEFECTS,
  ]) ?>

  <?php
  $prevRows = (array) ($fromPrehliadka['previous'] ?? []);
  $prevDate = '';
  if ($prevRows !== []) {
      $firstDate = $prevRows[0]['date'] ?? null;
      $prevDate = $firstDate ? ' ' . ProtocolLayout::date($firstDate) : '';
  }
  ?>
  <?= PrehliadkaDefectsBlock::previous($prevRows, 'Stav nedostatkov z prehliadky' . $prevDate) ?>
<?php else: ?>
  <?= DefectsTable::render(Defects::collect($items), ['photo_note' => !empty($photos)]) ?>
<?php endif ?>

<?php if ($terms !== []): ?>
  <?= $doc->band('Termíny') ?>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:65%">Čo</th>
        <th>Platnosť</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($terms as $t): ?>
        <tr>
          <td><?= $e((string) ($t['label'] ?? $t['n'] ?? '')) ?></td>
          <td><?= $e((string) ($t['valid_until'] ?? $t['valid'] ?? $t['d'] ?? $t['state'] ?? '—')) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<?= $doc->band('Podpisy') ?>
<table class="sig-tbl">
  <tr>
    <th width="50%">Vykonal</th>
    <th width="50%">S výsledkom prehliadky oboznámený</th>
  </tr>
  <tr>
    <td>
      <?= $e((string) ($inspector['fullname'] ?? '')) ?>
      <?= $certLine ?>
    </td>
    <td><?= $clientName ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $e((string) $inspector['signature_data_uri']) ?>" alt="">
      <?php else: ?>
        <i class="muted" style="font-size:8.5pt;">podpísané</i>
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td>
      <?php if (!empty($ho['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $e((string) $ho['signature_data_uri']) ?>" alt="">
        <div class="sig-line"></div>
      <?php elseif (!empty($ho['signed_on'])): ?>
        <i class="muted" style="font-size:8.5pt;">podpísané na displeji</i>
        <div class="sig-line"></div>
      <?php else: ?>
        <i class="muted" style="font-size:8.5pt;">podpis po vytlačení</i>
        <div class="sig-line"></div>
      <?php endif ?>
    </td>
  </tr>
</table>

<?= $doc->footer() ?>
