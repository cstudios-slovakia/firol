<?php
/**
 * Záznam o kontrole stavu BOZP (kniha kontrol BOZP) — block 2, BOZP-RRRR-NNN.
 *
 * Section order is the binding mockup's (`02_NAHLADY/bozp_protokoly.html`,
 * document 1): Základné informácie → legal sentence → Prehliadnuté pracoviská
 * → Vykonané činnosti → Termíny a kontroly → Zistené nedostatky → Výsledok →
 * Podpisy → pätička. Empty sections are left out (chapter 26): a record without
 * nedostatky has no nedostatky table, a client with nothing on record no
 * Termíny a kontroly table.
 *
 * @var string $number
 * @var array  $items         one record — Firol\Support\BozpRecords
 * @var array  $stats
 * @var array  $client_terms  Firol\Support\ClientTerms rows
 * @var array  $photos
 */
use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Support\BozpRecords;
use Firol\Support\Defects;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];
$f = (array) ($items[0]['fields'] ?? []);

$workspaces = array_values(array_filter((array) ($f['workspaces'] ?? []), 'is_string'));
$activities = array_merge(
    array_values(array_filter((array) ($f['activities'] ?? []), 'is_string')),
    array_values(array_filter((array) ($f['custom_activities'] ?? []), 'is_string')),
);
$defects = Defects::collect($items);
$result = (string) ($f['result'] ?? '');
$terms = isset($client_terms) && is_array($client_terms) ? $client_terms : [];
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole stavu\nbezpečnosti a ochrany zdravia pri práci") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola vykonaná podľa § 9 ods. 1 zákona č. 124/2006 Z. z. o bezpečnosti a ochrane zdravia pri práci v znení neskorších predpisov.') ?>

<?= $doc->band('Prehliadnuté pracoviská') ?>
<?= $doc->textBox(implode(', ', $workspaces)) ?>

<?= $doc->band('Vykonané činnosti') ?>
<table class="grid">
  <?php foreach ($activities as $activity): ?>
    <tr><td style="width:5%" class="check">✓</td><td><?= $e($activity) ?></td></tr>
  <?php endforeach ?>
</table>

<?php if ($terms !== []): ?>
  <?= $doc->band('Termíny a kontroly') ?>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:44%">Kontrola / revízia / školenie</th>
        <th style="width:18%">Vykonané</th>
        <th style="width:18%">Platnosť do</th>
        <th>Stav</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($terms as $t): ?>
        <tr>
          <td><?= $e($t['label']) ?></td>
          <td><?= $e($t['done']) ?></td>
          <td><?= $e($t['valid_until']) ?></td>
          <td class="<?= $e((string) ($t['state_tone'] ?? '')) ?>"><?= $e($t['state'] ?? '—') ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<?= DefectsTable::render($defects, ['photo_note' => !empty($photos)]) ?>

<?= $doc->band('Výsledok') ?>
<table class="verdict">
  <tr>
    <td style="width:33%"><span class="muted" style="font-size:8pt;">Prehliadnutých pracovísk</span><br>
      <strong><?= count($workspaces) ?></strong></td>
    <td style="width:33%"><span class="muted" style="font-size:8pt;">Zistených nedostatkov</span><br>
      <strong class="<?= $defects !== [] ? 'bad' : 'ok' ?>"><?= count($defects) ?></strong></td>
    <td><span class="muted" style="font-size:8pt;">Celkové hodnotenie</span><br>
      <strong class="<?= $result === 'zistene_nedostatky' ? 'bad' : 'ok' ?>"><?= $e(BozpRecords::KNIHA_RESULTS[$result] ?? '—') ?></strong></td>
  </tr>
</table>

<?= $doc->signatures('kniha_bozp') ?>
<?= $doc->footer() ?>
