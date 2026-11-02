<?php
/**
 * Záznam o vykonaní preventívnej protipožiarnej prehliadky (PPP-RRRR-NNN) —
 * POapp 02. 11. 2026, Option A (2_Vzor_hotoveho_zaznamu_A.pdf).
 *
 * Section order and fixed sentences:
 *   Title (+ § 14 ods. 3 subtitle ONLY for bytový dom) → Základné údaje (Dátum,
 *   Vykonal, Lehota, Ďalšia do) → Popis objektu → Poznámka → Hlavné vypínače
 *   a uzávery → Vykonané činnosti → Zistené nedostatky → Stav nedostatkov
 *   z prehliadky → Termíny (Čo | Platnosť) → Podpisy (Vykonal | S výsledkom
 *   prehliadky oboznámený).
 *
 * @var string $number
 * @var array  $brand
 * @var array  $inspection    executed_on, …
 * @var array  $company       name, ico, address, city, client_type
 * @var array  $facility      name, address, city, description
 * @var array  $inspector     fullname, certification_number, signature_data_uri
 * @var array|null $handover  the client's on-screen signature, when there is one
 * @var array  $photos
 * @var array  $prehliadka    Firol\Support\PrehliadkaRecord::poBlock()
 */
use Firol\Pdf\PrehliadkaDefectsBlock;
use Firol\Pdf\ProtocolLayout;
use Firol\Pdf\SignatureBlock;
use Firol\Support\Contractor;
use Firol\Support\Periodicity;
use Firol\Support\PrehliadkaRecord;
use Firol\Support\Sections;

$doc = new ProtocolLayout(
    get_defined_vars(),
    (string) (($brand['color'] ?? null) ?: Sections::COLORS[Sections::OPP]),
    'OPP',
);
$e = [ProtocolLayout::class, 'esc'];

$p = (array) ($prehliadka ?? []);
$clientType = (string) ($company['client_type'] ?? '');
$isDom = ($p['kind'] ?? '') === 'dom' || $clientType === 'bytove_druzstvo';
$points = (array) ($p['points'] ?? []);
$activities = (array) ($p['activities'] ?? []);
$terms = (array) ($p['terms'] ?? []);
$handedOver = is_array($p['handed_over'] ?? null) ? $p['handed_over'] : null;
$ho = is_array($handover ?? null) ? $handover : null;

// Rule 6.0a / AC #23 / Decision D5:
// Bytový dom: Title with bytového domu + subtitle podľa § 14 ods. 3 vyhlášky MV SR č. 121/2002 Z. z.
// Firma: Title without bytového domu — NO subtitle anywhere.
if ($isDom) {
    $title = "Záznam o vykonaní preventívnej protipožiarnej prehliadky\nbytového domu";
    $subtitle = 'podľa § 14 ods. 3 vyhlášky MV SR č. 121/2002 Z. z.';
} else {
    $title = 'Záznam o vykonaní preventívnej protipožiarnej prehliadky';
    $subtitle = null;
}

$certLine = !empty($inspector['certification_number'])
    ? '<br><span style="font-size:8pt; color:#555;">technik požiarnej ochrany, č. opr.: ' . $e((string) $inspector['certification_number']) . '</span>'
    : '';

if ($ho !== null) {
    $clientName = SignatureBlock::nameCell($ho);
} elseif ($handedOver !== null && !empty($handedOver['name'])) {
    $clientName = $e((string) $handedOver['name'])
        . (!empty($handedOver['role']) ? ', ' . $e((string) $handedOver['role']) : '');
} else {
    $clientName = 'Zodpovedná osoba organizácie';
}

$executedOn = ProtocolLayout::date($inspection['executed_on'] ?? null);

// Lehota calculation (lehota_zakon + lehota_vlastna)
$rawDetails = (array) ($inspection['details'] ?? []);
$lehotaZakon = (int) ($p['lehota_zakon'] ?? ($rawDetails['lehota_zakon'] ?? ($isDom ? 12 : 3)));
$lehotaVlastna = isset($p['lehota_vlastna']) && (int) $p['lehota_vlastna'] > 0
    ? (int) $p['lehota_vlastna']
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

$facilityDesc = trim((string) ($p['description'] ?? ($facility['description'] ?? '')));
$note = trim((string) ($p['note'] ?? ($p['poznamka'] ?? ($rawDetails['note'] ?? ($rawDetails['poznamka'] ?? ($inspection['notes'] ?? ''))))));
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

<?php if (!empty($p['switches'])): ?>
  <?= $doc->band('Hlavné vypínače a uzávery') ?>
  <table class="bi">
    <?php foreach ($p['switches'] as $row): ?>
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
    <?php foreach ($activities as $a): ?>
      <li><?= $e((string) ($a['label'] ?? $a)) ?></li>
    <?php endforeach ?>
  </ul>
  <div class="small-note" style="font-style:italic; color:#6b6b75; margin-top:3pt;"><?= $e(PrehliadkaRecord::CHECKED) ?></div>
<?php endif ?>

<?= PrehliadkaDefectsBlock::render($points, [
    'photos'    => !empty($p['include_photos'] ?? $includePhotos ?? false),
    'when_none' => PrehliadkaRecord::NO_DEFECTS,
]) ?>

<?php
$prevRows = (array) ($p['previous'] ?? []);
$prevDate = '';
if ($prevRows !== []) {
    $firstDate = $prevRows[0]['date'] ?? null;
    $prevDate = $firstDate ? ' ' . ProtocolLayout::date($firstDate) : '';
}
?>
<?= PrehliadkaDefectsBlock::previous($prevRows, 'Stav nedostatkov z prehliadky' . $prevDate) ?>

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
