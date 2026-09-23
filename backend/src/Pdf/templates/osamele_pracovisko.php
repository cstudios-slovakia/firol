<?php
/**
 * Záznam o kontrole osamelých a odlúčených pracovísk — block 2, OSP-RRRR-NNN.
 *
 * Section order is the binding mockup's: Základné informácie → legal sentence
 * → Kontrolované pracoviská → Zistené nedostatky a opatrenia → Výsledok →
 * Podpisy → pätička. The nedostatky table is left out when there are none.
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
$overall = (string) ($f['overall'] ?? '');
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole osamelých\na odlúčených pracovísk") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola podľa § 9 ods. 1 písm. c) zákona č. 124/2006 Z. z. — zamestnávateľ je povinný sústavne kontrolovať a vyžadovať dodržiavanie predpisov na zaistenie bezpečnosti a ochrany zdravia pri práci najmä na pracoviskách, na ktorých zamestnanec pracuje osamotene.') ?>

<?= $doc->band('Kontrolované pracoviská') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:5%">Č.</th>
      <th style="width:26%">Pracovisko</th>
      <th style="width:22%">Vykonávaná činnosť</th>
      <th style="width:16%">Spojenie</th>
      <th style="width:17%">Kontrola prítomnosti</th>
      <th>Výsledok</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $i => $row): ?>
      <?php $r = (string) ($row['result'] ?? ''); $tone = $r === 'nevyhovuje' ? 'bad' : 'ok'; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $e((string) ($row['workplace'] ?? '')) ?></td>
        <td><?= $e((string) ($row['activity'] ?? '')) ?></td>
        <td><?= $e((string) ($row['connection'] ?? '')) ?></td>
        <td><?= $e((string) ($row['presence_check'] ?? '')) ?></td>
        <td class="<?= $tone ?>"><?= $e(BozpRecords::PASS_FAIL[$r] ?? '—') ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= DefectsTable::render($defects, [
  'title'      => 'Zistené nedostatky a opatrenia',
  'labels'     => DefectsTable::SHORT_LABELS,
  'photo_note' => !empty($photos),
]) ?>

<?= $doc->band('Výsledok') ?>
<table class="verdict">
  <tr>
    <td style="width:50%"><span class="muted" style="font-size:8pt;">Kontrolovaných pracovísk</span><br>
      <strong><?= count($rows) ?></strong></td>
    <td><span class="muted" style="font-size:8pt;">Celkové hodnotenie</span><br>
      <strong class="<?= $overall === 'vyhovujuci' ? 'ok' : 'bad' ?>"><?= $e(BozpRecords::OVERALL[$overall] ?? '—') ?></strong></td>
  </tr>
</table>

<?= $doc->signatures('osamele_pracovisko') ?>
<?= $doc->footer() ?>
