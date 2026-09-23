<?php
/**
 * Záznam o kontrole dodržiavania zákazu fajčenia — block 2, ZF-RRRR-NNN.
 *
 * Section order is the binding mockup's: Základné informácie → legal sentence
 * → Rozsah kontroly → Zistenia → Opatrenia → Podpisy → pätička. The mockup has
 * no nedostatky table; chapter 7 lets every type carry nedostatky, so when the
 * technician recorded some they are printed right after the Zistenia they
 * come from, and the section is left out otherwise. Opatrenia is left out
 * when empty.
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

$rows = array_values(array_filter((array) ($f['rows'] ?? []), 'is_array'));
$defects = Defects::collect($items);
$measures = is_string($f['measures'] ?? null) ? trim($f['measures']) : '';
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole dodržiavania\nzákazu fajčenia") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola dodržiavania zákazu fajčenia podľa § 9 ods. 1 písm. b) zákona č. 124/2006 Z. z. Zamestnanec nesmie fajčiť na pracoviskách, kde je fajčenie zakázané, podľa § 12 ods. 2 zákona.') ?>

<?= $doc->band('Rozsah kontroly') ?>
<?= $doc->textBox((string) ($f['scope'] ?? '')) ?>

<?= $doc->band('Zistenia') ?>
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
    <?php foreach ($rows as $i => $row): ?>
      <?php $r = (string) ($row['result'] ?? ''); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $e((string) ($row['area'] ?? '')) ?></td>
        <td class="<?= $r === 'nevyhovuje' ? 'bad' : 'ok' ?>"><?= $e(BozpRecords::PASS_FAIL[$r] ?? '—') ?></td>
        <td><?= !empty($row['note']) ? nl2br($e((string) $row['note'])) : '—' ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= DefectsTable::render($defects, ['labels' => DefectsTable::SHORT_LABELS, 'photo_note' => !empty($photos)]) ?>

<?php if ($measures !== ''): ?>
  <?= $doc->band('Opatrenia') ?>
  <div class="note-box"><?= nl2br($e($measures)) ?></div>
<?php endif ?>

<?= $doc->signatures('fajcenie') ?>
<?= $doc->footer() ?>
