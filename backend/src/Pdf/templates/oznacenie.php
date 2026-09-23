<?php
/**
 * Záznam o kontrole bezpečnostného a zdravotného označenia — block 2,
 * OZN-RRRR-NNN.
 *
 * Section order is the binding mockup's (`02_NAHLADY/bozp_protokoly.html`,
 * OZN-2026-002): Základné informácie → legal sentence → Výsledok kontroly →
 * Opatrenia → Podpisy → pätička. „Zistené nedostatky" (chapter 7) sits before
 * Opatrenia; both are left out when empty (chapter 26). The mockup has no
 * Súhrn výsledkov here and none is added.
 *
 * @var array $items   rows — Firol\Support\BozpItems (oznacenie)
 * @var array $inspection  incl. details.measures (opatrenie, termín)
 * @var array $photos
 */
use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Support\Defects;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];
$cell = static fn(mixed $v): string => ($v === null || trim((string) $v) === '') ? '—' : nl2br($e((string) $v));
$details = (array) ($inspection['details'] ?? []);
$measures = array_values(array_filter((array) ($details['measures'] ?? []), 'is_array'));

$resultCell = static function (?string $r): string {
  return match ($r) {
    'vyhovuje'   => '<td class="ok">Vyhovuje</td>',
    'nevyhovuje' => '<td class="bad">Nevyhovuje</td>',
    default      => '<td>—</td>',
  };
};
$deadline = static function (array $m): string {
  if (!empty($m['immediately'])) {
    return 'ihneď';
  }
  return !empty($m['deadline']) ? ProtocolLayout::date((string) $m['deadline']) : '—';
};
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole bezpečnostného\na zdravotného označenia") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola bezpečnostného a zdravotného označenia pri práci podľa nariadenia vlády SR č. 387/2006 Z. z. Označenie sa udržiava a kontroluje v pravidelných intervaloch podľa bodu 6 prílohy k nariadeniu.') ?>

<?= $doc->band('Výsledok kontroly') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:43%">Druh označenia</th>
      <th style="width:15%">Umiestnenie</th>
      <th style="width:15%">Stav</th>
      <th style="width:23%">Poznámka</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): $f = (array) ($item['fields'] ?? []); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $cell($f['kind'] ?? null) ?></td>
        <td><?= $cell($f['location'] ?? null) ?></td>
        <?= $resultCell(isset($f['result']) ? (string) $f['result'] : null) ?>
        <td><?= $cell($f['notes'] ?? null) ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= DefectsTable::render(Defects::collect($items), [
  'labels'     => DefectsTable::SHORT_LABELS,
  'photo_note' => !empty($photos),
]) ?>

<?php if ($measures !== []): ?>
  <?= $doc->band('Opatrenia') ?>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:5%">Č.</th>
        <th style="width:70%">Opatrenie</th>
        <th>Termín</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($measures as $i => $m): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= $cell($m['measure'] ?? null) ?></td>
          <td><?= $e($deadline($m)) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<?= $doc->signatures('oznacenie') ?>
<?= $doc->footer() ?>
