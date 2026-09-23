<?php
/**
 * Záznam o oboznámení zamestnancov v oblasti BOZP — block 2, chapters 7, 8
 * and 26. Layout: the binding mockup `02_NAHLADY/bozp_protokoly.html`.
 *
 * Chapter 8: the app keeps no osnova. The protocol prints the header, the
 * druh and časový rozsah, the ODKAZ na osnovu and the prezenčná listina —
 * never a tematický plán or the content point by point.
 *
 * Signed under the firm's oprávnenie na výchovu a vzdelávanie (`vv`,
 * chapter 5.3), printed in the Zhotoviteľ row together with the firm
 * (chapter 1.3.3) and next to the trainer. The trainer's personal
 * bezpečnostný technik osvedčenie follows when they have one — the chapter
 * 1.3.3 example shows both.
 *
 * @var string     $number
 * @var array      $inspection  executed_on, periodicity_label
 * @var array      $company, $facility, $inspector
 * @var list<array> $items
 * @var array      $persons     details, kind_label
 * @var array      $contractor  name, ico, address, certificate{label, number}
 * @var array|null $handover
 */

use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Pdf\SignatureBlock;
use Firol\Support\Defects;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];

$details = (array) ($persons['details'] ?? []);
$executed = ProtocolLayout::date($inspection['executed_on'] ?? null);
$duration = isset($details['duration_min']) && $details['duration_min'] !== null
    ? (int) $details['duration_min'] . ' minút'
    : null;
$periodicity = $inspection['periodicity_label'] ?? null;
$certificate = $contractor['certificate'] ?? null;

$contractorCell = $e($contractor['name'] ?? '');
if (!empty($contractor['ico'])) {
    $contractorCell .= ', IČO ' . $e($contractor['ico']);
}
if (!empty($contractor['address'])) {
    $contractorCell .= ', ' . $e($contractor['address']);
}
if (is_array($certificate)) {
    $contractorCell .= '<br>' . $e($certificate['label']) . ': ' . $e($certificate['number']);
}

// „Technik BOZP test | č. oprávnenia na výchovu a vzdelávanie: VVZ-0456/2021"
$trainerCell = $e($inspector['fullname'] ?? '');
if (is_array($certificate)) {
    $trainerCell .= ' | ' . $e($certificate['number_label']) . ': ' . $e($certificate['number']);
}
if (!empty($inspector['certification_number'])) {
    $trainerCell .= '<br><span style="font-size:8pt; color:#555;">bezpečnostný technik, osvedčenie č.: '
        . $e($inspector['certification_number']) . '</span>';
}

$facilityCell = $e($facility['name'] ?? '');
if (!empty($facility['address'])) {
    $facilityCell .= '<br><span style="color:#555;">' . $e($facility['address']) . '</span>';
}

$count = count($items);
$signedHandover = is_array($handover ?? null) ? $handover : null;

echo $doc->styles();
?>
<style>
  .sig-cell-img { max-height: 22pt; max-width: 110pt; }
</style>
<?= $doc->header("Záznam o oboznámení zamestnancov\nv oblasti BOZP") ?>

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td><td class="bv"><?= $e($company['name'] ?? '') ?></td>
    <td class="bl">Druh oboznámenia</td><td class="bv"><?= $e($persons['kind_label'] ?? '') ?></td>
  </tr>
  <tr>
    <td class="bl">IČO</td><td class="bv"><?= $e($company['ico'] ?? '—') ?></td>
    <td class="bl">Dátum</td><td class="bv"><strong><?= $e($executed) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td><td class="bv"<?= $duration === null ? ' colspan="3"' : '' ?>><?= $facilityCell ?></td>
    <?php if ($duration !== null): ?>
      <td class="bl">Časový rozsah</td><td class="bv"><?= $e($duration) ?></td>
    <?php endif ?>
  </tr>
  <?php if ($periodicity !== null): ?>
    <tr>
      <td class="bl">Periodicita</td><td class="bv" colspan="3"><?= $e($periodicity) ?></td>
    </tr>
  <?php endif ?>
  <tr>
    <td class="bl">Oboznámenie vykonal</td><td colspan="3"><?= $trainerCell ?></td>
  </tr>
  <tr>
    <td class="bl">Zhotoviteľ</td><td colspan="3"><?= $contractorCell ?></td>
  </tr>
</table>

<?= $doc->legal('Oboznámenie vykonané podľa § 7 zákona č. 124/2006 Z. z. o bezpečnosti a ochrane zdravia pri práci v znení neskorších predpisov.') ?>

<h2>Obsah oboznámenia</h2>
<table class="grid"><tr><td style="font-size:9pt;"><?= nl2br($e($details['content'] ?? '')) ?></td></tr></table>

<h2>Prezenčná listina účastníkov</h2>
<table class="grid">
  <thead>
    <tr>
      <th style="width:8%">Por. č.</th>
      <th style="width:28%">Meno, priezvisko, titul</th>
      <th style="width:26%">Pracovné zaradenie</th>
      <th style="width:14%">Dátum</th>
      <th>Podpis</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $idx => $item): ?>
      <?php $f = (array) ($item['fields'] ?? []); ?>
      <tr>
        <td style="height:7mm;"><?= $idx + 1 ?></td>
        <td><?= $e($f['name'] ?? '') ?></td>
        <td><?= $e($f['position'] ?? '') ?></td>
        <td><?= $e(!empty($f['date']) ? ProtocolLayout::date((string) $f['date']) : $executed) ?></td>
        <td style="text-align:center;">
          <?php // Signed on the display, or left empty for the pen (chapter 8). ?>
          <?php if (!empty($f['signature'])): ?>
            <img class="sig-cell-img" src="<?= $e($f['signature']) ?>" alt="">
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= DefectsTable::render(Defects::collect($items), ['labels' => DefectsTable::SHORT_LABELS]) ?>

<table style="width:100%; margin-top:10pt; border-collapse:collapse;">
  <tr>
    <?php // The mockup signs the oboznámenie by the trainer alone. A client
          // signature taken on the screen (chapter 13) is printed beside it
          // when there is one — and not at all when there is none. ?>
    <td style="width:50%; vertical-align:bottom; font-size:9pt;">
      <?php if ($signedHandover !== null): ?>
        <span class="muted" style="font-size:8pt;"><?= SignatureBlock::heading('skolenie_bozp') ?>:</span><br>
        <?= SignatureBlock::nameCell($signedHandover) ?><br>
        <?= SignatureBlock::signCell($signedHandover, 'skolenie_bozp') ?>
      <?php endif ?>
    </td>
    <td style="width:50%; text-align:right; vertical-align:bottom;">
      <span class="muted" style="font-size:8pt;">Podpis školiteľa:</span><br>
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $e($inspector['signature_data_uri']) ?>" alt="">
      <?php else: ?>
        <div style="height:30pt;"></div>
      <?php endif ?>
    </td>
  </tr>
</table>

<div class="footer">
  Oboznámenie vykonal: <?= $e($inspector['fullname'] ?? '') ?><?php if (is_array($certificate)): ?> | č. oprávnenia: <?= $e($certificate['number']) ?><?php endif ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Účastníkov: <?= $count ?>
</div>
