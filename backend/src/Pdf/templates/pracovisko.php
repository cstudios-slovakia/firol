<?php
/**
 * Záznam o kontrole pracoviska a pracovného prostredia — block 2, PRAC-RRRR-NNN.
 *
 * Section order is the binding mockup's: Základné informácie → legal sentence
 * → Kontrolované priestory → Výsledok kontroly podľa oblastí → Zistené
 * nedostatky → Výsledok → Podpisy → pätička. Only the areas the technician
 * evaluated are printed; the nedostatky table is left out when there are none.
 *
 * @var array $items  one record — Firol\Support\BozpRecords
 * @var array $photos
 */
use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Support\BozpRecords;
use Firol\Support\Defects;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];
$f = (array) ($items[0]['fields'] ?? []);

$spaces = array_values(array_filter((array) ($f['spaces'] ?? []), 'is_string'));
$areas = array_values(array_filter((array) ($f['areas'] ?? []), 'is_array'));
$defects = Defects::collect($items);
$overall = (string) ($f['overall'] ?? '');
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole pracoviska\na pracovného prostredia") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola pracoviska podľa nariadenia vlády SR č. 391/2006 Z. z. o minimálnych bezpečnostných a zdravotných požiadavkách na pracovisko a § 9 ods. 1 zákona č. 124/2006 Z. z.') ?>

<?= $doc->band('Kontrolované priestory') ?>
<?= $doc->textBox(implode(', ', $spaces)) ?>

<?= $doc->band('Výsledok kontroly podľa oblastí') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:5%">Č.</th>
      <th style="width:55%">Kontrolovaná oblasť</th>
      <th style="width:16%">Výsledok</th>
      <th>Poznámka</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($areas as $i => $area): ?>
      <?php $r = (string) ($area['result'] ?? ''); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $e((string) ($area['name'] ?? '')) ?></td>
        <td class="<?= $r === 'nevyhovuje' ? 'bad' : 'ok' ?>"><?= $e(BozpRecords::PASS_FAIL[$r] ?? '—') ?></td>
        <td><?= !empty($area['note']) ? nl2br($e((string) $area['note'])) : '—' ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= DefectsTable::render($defects, ['labels' => DefectsTable::SHORT_LABELS, 'photo_note' => !empty($photos)]) ?>

<?= $doc->band('Výsledok') ?>
<table class="verdict">
  <tr>
    <td style="width:50%"><span class="muted" style="font-size:8pt;">Zistených nedostatkov</span><br>
      <strong class="<?= $defects !== [] ? 'bad' : 'ok' ?>"><?= count($defects) ?></strong></td>
    <td><span class="muted" style="font-size:8pt;">Celkové hodnotenie</span><br>
      <strong class="<?= $overall === 'vyhovujuci' ? 'ok' : 'bad' ?>"><?= $e(BozpRecords::OVERALL[$overall] ?? '—') ?></strong></td>
  </tr>
</table>

<?= $doc->signatures('pracovisko') ?>
<?= $doc->footer() ?>
