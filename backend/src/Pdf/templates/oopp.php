<?php
/**
 * Záznam o kontrole osobných ochranných pracovných prostriedkov — block 2,
 * OOPP-RRRR-NNN.
 *
 * Section order is the binding mockup's (`02_NAHLADY/bozp_protokoly.html`,
 * OOPP-2026-005): Základné informácie → legal sentence → Kontrola poskytovania
 * a používania OOPP → Zistené nedostatky a opatrenia → Záver → Podpisy →
 * pätička. The mockup has no Súhrn výsledkov here and none is added. Empty
 * sections are left out (chapter 26).
 *
 * @var array $items   rows — Firol\Support\BozpItems (oopp)
 * @var array $inspection  incl. details.conclusion
 * @var array $photos
 */
use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Support\Defects;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];
$cell = static fn(mixed $v): string => ($v === null || trim((string) $v) === '') ? '—' : nl2br($e((string) $v));
$details = (array) ($inspection['details'] ?? []);
$conclusion = trim((string) ($details['conclusion'] ?? ''));

$yesNoCell = static function (mixed $v): string {
  return match ($v) {
    true    => '<td class="ok">áno</td>',
    false   => '<td class="bad">nie</td>',
    default => '<td>—</td>',
  };
};
$conditionCell = static function (mixed $v): string {
  return match ($v) {
    'vyhovujuci' => '<td class="ok">vyhovujúci</td>',
    'opotrebeny' => '<td class="bad">opotrebený</td>',
    'chyba'      => '<td class="bad">chýba</td>',
    default      => '<td>—</td>',
  };
};
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole osobných ochranných\npracovných prostriedkov") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola poskytovania a používania osobných ochranných pracovných prostriedkov podľa nariadenia vlády SR č. 395/2006 Z. z. a § 9 ods. 1 písm. d) zákona č. 124/2006 Z. z.') ?>

<?= $doc->band('Kontrola poskytovania a používania OOPP') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:17%">Pracovná pozícia</th>
      <th style="width:23%">Pridelené OOPP</th>
      <th style="width:12%">Poskytnuté</th>
      <th style="width:12%">Používané</th>
      <th style="width:14%">Stav</th>
      <th style="width:18%">Poznámka</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): $f = (array) ($item['fields'] ?? []); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $cell($f['position'] ?? null) ?></td>
        <td><?= $cell($f['equipment'] ?? null) ?></td>
        <?= $yesNoCell($f['provided'] ?? null) ?>
        <?= $yesNoCell($f['used'] ?? null) ?>
        <?= $conditionCell($f['condition'] ?? null) ?>
        <td><?= $cell($f['notes'] ?? null) ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= DefectsTable::render(Defects::collect($items), [
  'title'      => 'Zistené nedostatky a opatrenia',
  'labels'     => DefectsTable::SHORT_LABELS,
  'photo_note' => !empty($photos),
]) ?>

<?php if ($conclusion !== ''): ?>
  <?= $doc->band('Záver') ?>
  <div class="note-box"><?= nl2br($e($conclusion)) ?></div>
<?php endif ?>

<?= $doc->signatures('oopp') ?>
<?= $doc->footer() ?>
