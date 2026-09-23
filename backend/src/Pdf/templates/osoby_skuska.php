<?php
/**
 * Záznam o vykonaní dychovej skúšky / Záznam o kontrole na zistenie požitia
 * omamných a psychotropných látok — block 2, chapters 7, 8.1 and 26.
 *
 * Layout: the binding mockup `02_NAHLADY/bozp_protokoly.html` — the filled
 * record („Záznam o vykonaní dychovej skúšky", „Záznam o kontrole na zistenie
 * požitia…") and the separate „verzia na ručné doplnenie". The kontrola
 * omamných látok has no blank mockup of its own; its blank form follows the
 * dychová skúška one without the measured-value column, as chapter 8.1 asks
 * of both.
 *
 * Blank variant (`$persons['variant'] === 'prazdny'`, chapter 8.1):
 *   - only poradie, meno and pracovné zaradenie are printed; čas, nameraná
 *     hodnota, výsledok and podpis are empty, rows at least 9 mm tall;
 *   - „Poznámky a opatrenia" is an empty field instead of the opatrenia;
 *   - the legend of the results sits under the table;
 *   - the footer says „Vyplnené ručne na mieste";
 *   - the technician's signature is NOT printed from the profile — an empty
 *     line labelled „Podpis technika" is left instead.
 *
 * @var string     $number
 * @var array      $inspection  executed_on, periodicity_label
 * @var array      $company, $facility, $inspector
 * @var list<array> $items
 * @var array      $persons     type, variant, details, first_time
 * @var array      $contractor  name, ico, address, certificate
 * @var array|null $handover
 */

use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Pdf\SignatureBlock;
use Firol\Support\Defects;
use Firol\Support\PersonList;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];

$isAlcohol = $persons['type'] === PersonList::DYCHOVA_SKUSKA;
$blank     = $persons['variant'] === PersonList::VARIANT_BLANK;
$details   = (array) ($persons['details'] ?? []);

$title = $isAlcohol
    ? "Záznam o vykonaní dychovej skúšky\nna zistenie požitia alkoholu"
    : "Záznam o kontrole na zistenie požitia\nomamných a psychotropných látok";
$performedLabel = $isAlcohol ? 'Skúšku vykonal' : 'Kontrolu vykonal';
$legal = $isAlcohol
    ? 'Skúška vykonaná podľa § 9 ods. 1 písm. b) zákona č. 124/2006 Z. z. Zamestnanec je povinný podrobiť sa '
      . 'vyšetreniu na zistenie požitia alkoholu podľa § 12 ods. 2 písm. l) toho istého zákona.'
    : 'Kontrola vykonaná podľa § 9 ods. 1 písm. b) zákona č. 124/2006 Z. z. Zamestnanec je povinný podrobiť sa '
      . 'vyšetreniu na zistenie požitia omamných a psychotropných látok podľa § 12 ods. 2 písm. l) toho istého zákona.';

// Short labels in the table, as the mockup prints them; the legend under the
// blank form spells „odmietol podrobiť sa skúške" out.
$resultShort = ['negativny' => 'Negatívny', 'pozitivny' => 'Pozitívny', 'odmietol' => 'Odmietol'];
$resultClass = ['negativny' => 'ok', 'pozitivny' => 'bad', 'odmietol' => 'bad'];

$executed = ProtocolLayout::date($inspection['executed_on'] ?? null);
$dateCell = $executed;
if (!$blank && !empty($persons['first_time'])) {
    $dateCell .= ', ' . $persons['first_time'];
}

$inspectorCell = $e($inspector['fullname'] ?? '');
if (!empty($inspector['certification_number'])) {
    $inspectorCell .= '<br><span style="font-size:8pt; color:#555;">bezpečnostný technik, č. opr.: '
        . $e($inspector['certification_number']) . '</span>';
}

$facilityCell = $e($facility['name'] ?? '');
if (!empty($facility['address'])) {
    $facilityCell .= '<br><span style="color:#555;">' . $e($facility['address']) . '</span>';
}

// Použitý prostriedok. On the blank form an empty device field is left
// empty for the pen, like the rest of what is written on site.
if ($isAlcohol) {
    $deviceLabel = 'Prístroj';
    $device = (string) ($details['device_type'] ?? '');
    $idLabel = 'Výr. č. / kalibrácia';
    $serial = (string) ($details['device_serial'] ?? '');
    $calibration = !empty($details['calibration_valid_to'])
        ? 'platná do ' . ProtocolLayout::date((string) $details['calibration_valid_to'])
        : '';
    $idValue = implode(' / ', array_filter([$serial, $calibration], static fn ($v) => $v !== ''));
} else {
    $deviceLabel = 'Použitý prostriedok';
    $device = (string) ($details['test_type'] ?? '');
    $idLabel = 'Šarža / exspirácia';
    $expiry = PersonList::formatMonthOrDate($details['expiry'] ?? null);
    $idValue = implode(' / ', array_filter([(string) ($details['batch'] ?? ''), $expiry], static fn ($v) => $v !== ''));
}

$contractorCell = \Firol\Support\Contractor::cellHtml(is_array($contractor ?? null) ? $contractor : []);

$periodicity = $inspection['periodicity_label'] ?? null;
$measures = trim((string) ($details['measures'] ?? ''));
$count = count($items);

echo $doc->styles();
?>
<style>
  .warn-box { padding: 5pt 8pt; background: #fff8ec; border-left: 2pt solid #b7791f; font-size: 9pt; color: #4b3a17; }
  .blank-row td { height: 9.5mm; }
  .sig-cell-img { max-height: 22pt; max-width: 90pt; }
</style>
<?= $doc->header($title) ?>

<?php if ($blank): ?>
  <div class="note-box" style="margin-bottom:6pt;">
    <b>Verzia na ručné doplnenie.</b> Zoznam osôb je vytlačený vopred; čas,<?= $isAlcohol ? ' nameraná hodnota,' : '' ?>
    výsledok a podpis sa dopĺňajú perom priamo pri <?= $isAlcohol ? 'skúške' : 'kontrole' ?>.
  </div>
<?php endif ?>

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td><td class="bv"><?= $e($company['name'] ?? '') ?></td>
    <td class="bl"><?= $blank ? 'Dátum' : 'Dátum a čas' ?></td><td class="bv"><strong><?= $e($dateCell) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td><td class="bv"><?= $facilityCell ?></td>
    <td class="bl"><?= $e($performedLabel) ?></td><td class="bv"><?= $inspectorCell ?></td>
  </tr>
  <tr>
    <td class="bl"><?= $e($deviceLabel) ?></td><td class="bv"><?= $e($device) ?></td>
    <td class="bl"><?= $e($idLabel) ?></td><td class="bv"><?= $e($idValue) ?></td>
  </tr>
  <?php // Chapter 5: a period is printed bare, and only when there is one. ?>
  <?php if ($periodicity !== null): ?>
    <tr>
      <td class="bl">Periodicita</td><td class="bv" colspan="3"><?= $e($periodicity) ?></td>
    </tr>
  <?php endif ?>
  <?php // Chapter 1.3.3 — „Zhotoviteľ: názov firmy, IČO, adresa". ?>
  <tr>
    <td class="bl">Zhotoviteľ</td><td class="bv" colspan="3"><?= $contractorCell ?></td>
  </tr>
</table>

<?= $doc->legal($legal) ?>

<h2><?= $isAlcohol ? 'Zoznam skúšaných osôb' : 'Zoznam kontrolovaných osôb' ?></h2>
<table class="grid"<?= $blank ? ' style="font-size:9pt;"' : '' ?>>
  <thead>
    <tr>
      <th style="width:5%">Č.</th>
      <?php if ($isAlcohol): ?>
        <th style="width:26%">Meno a priezvisko</th>
        <th style="width:<?= $blank ? 22 : 24 ?>%">Pracovné zaradenie</th>
        <th style="width:10%">Čas</th>
        <th style="width:<?= $blank ? 14 : 13 ?>%">Nameraná hodnota</th>
        <th style="width:<?= $blank ? 11 : 12 ?>%">Výsledok</th>
      <?php else: ?>
        <th style="width:27%">Meno a priezvisko</th>
        <th style="width:24%">Pracovné zaradenie</th>
        <th style="width:11%">Čas</th>
        <th style="width:16%">Výsledok</th>
      <?php endif ?>
      <th>Podpis</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $idx => $item): ?>
      <?php $f = (array) ($item['fields'] ?? []); ?>
      <?php $r = (string) ($f['result'] ?? ''); ?>
      <tr<?= $blank ? ' class="blank-row"' : '' ?>>
        <td><?= $idx + 1 ?></td>
        <td><?= $e($f['name'] ?? '') ?></td>
        <td><?= $e($f['position'] ?? '') ?></td>
        <?php if ($blank): ?>
          <td></td>
          <?php if ($isAlcohol): ?><td></td><?php endif ?>
          <td></td>
          <td></td>
        <?php else: ?>
          <td><?= $e($f['time'] ?? '') ?></td>
          <?php if ($isAlcohol): ?>
            <?php $value = trim((string) ($f['value'] ?? '')); ?>
            <td><?= $value === '' ? '—' : $e(str_contains($value, '‰') ? $value : $value . ' ‰') ?></td>
          <?php endif ?>
          <td class="<?= $resultClass[$r] ?? '' ?>"><?= $e($resultShort[$r] ?? '') ?></td>
          <td style="text-align:center;">
            <?php if (!empty($f['signature'])): ?>
              <img class="sig-cell-img" src="<?= $e($f['signature']) ?>" alt="">
            <?php endif ?>
          </td>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?php if ($blank): ?>
  <div class="small-note">Výsledok sa uvádza ako: negatívny · pozitívny · odmietol podrobiť sa skúške.</div>

  <h2>Poznámky a opatrenia</h2>
  <table class="grid"><tr><td style="height:22mm;"></td></tr></table>
<?php elseif ($measures !== ''): ?>
  <?php // Printed only when written — chapter 26, empty sections are left out. ?>
  <h2>Opatrenia pri pozitívnom výsledku a odmietnutí</h2>
  <div class="warn-box"><?= nl2br($e($measures)) ?></div>
<?php endif ?>

<?php // Chapter 7 — nedostatky can be added to every type (Firol\Support\Defects).
      // On the blank form they are part of what is written by hand. ?>
<?= $blank ? '' : DefectsTable::render(Defects::collect($items), ['labels' => DefectsTable::SHORT_LABELS]) ?>

<h2>Podpisy</h2>
<table class="sig-tbl">
  <tr>
    <th width="34%"><?= $e($performedLabel) ?></th>
    <th width="40%"><?= SignatureBlock::heading((string) $persons['type']) ?></th>
    <th width="26%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $inspectorCell ?></td>
    <td><?= SignatureBlock::nameCell(is_array($handover ?? null) ? $handover : null, (string) ($company['approver'] ?? '')) ?></td>
    <?php $city = ($facility['city'] ?? '') ?: ($company['city'] ?? ''); ?>
    <td><?= SignatureBlock::placeAndDate(is_array($handover ?? null) ? $handover : null, ($city ? $city . ', ' : '') . $executed) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php // The blank form never prints the profile signature (8.1). ?>
      <?php if (!$blank && !empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $e($inspector['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line">Podpis technika</div>
    </td>
    <td><?= SignatureBlock::signCell(is_array($handover ?? null) ? $handover : null, (string) $persons['type']) ?></td>
    <td></td>
  </tr>
</table>

<?= $doc->footer($blank
    ? 'Vyplnené ručne na mieste'
    : ($isAlcohol ? 'Skúšaných osôb: ' : 'Kontrolovaných osôb: ') . $count) ?>
