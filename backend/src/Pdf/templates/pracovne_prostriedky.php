<?php
/**
 * Záznam o kontrole pracovných prostriedkov — block 2, PP-RRRR-NNN.
 *
 * Section order is the binding mockup's (`02_NAHLADY/bozp_protokoly.html`,
 * PP-2026-009): Základné informácie → legal sentence → Zoznam kontrolovaných
 * pracovných prostriedkov → Súhrn výsledkov → Opatrenia → Podpisy → pätička.
 * „Zistené nedostatky" (chapter 7) sits before Opatrenia; both are left out
 * when empty (chapter 26).
 *
 * @var array $items   rows — Firol\Support\BozpItems (pracovne_prostriedky)
 * @var array $stats   BozpItems::stats()
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
$total = (int) ($stats['total'] ?? count($items));
?>
<?= $doc->styles() ?>
<?= $doc->header("Záznam o kontrole pracovných\nprostriedkov") ?>
<?= $doc->basicInfo() ?>
<?= $doc->legal('Kontrola pracovných prostriedkov podľa § 5 nariadenia vlády SR č. 392/2006 Z. z. o minimálnych bezpečnostných a zdravotných požiadavkách pri používaní pracovných prostriedkov.') ?>

<?= $doc->band('Zoznam kontrolovaných pracovných prostriedkov') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:21%">Názov</th>
      <th style="width:14%">Výrobca / typ</th>
      <th style="width:11%">Inv. číslo</th>
      <th style="width:13%">Umiestnenie</th>
      <th style="width:22%">Zistené závady</th>
      <th style="width:15%">Výsledok</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): $f = (array) ($item['fields'] ?? []); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $cell($f['name'] ?? null) ?></td>
        <td><?= $cell($f['manufacturer_type'] ?? null) ?></td>
        <td><?= $cell($f['inventory_number'] ?? null) ?></td>
        <td><?= $cell($f['location'] ?? null) ?></td>
        <td><?= $cell($f['faults'] ?? null) ?></td>
        <?= $resultCell(isset($f['result']) ? (string) $f['result'] : null) ?>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= $doc->band('Súhrn výsledkov') ?>
<table class="verdict">
  <tr>
    <td style="text-align:center;width:34%"><span class="muted" style="font-size:8pt;">Kontrolovaných</span><br><strong><?= $total ?></strong></td>
    <td style="text-align:center;width:33%"><span class="muted" style="font-size:8pt;">Vyhovuje</span><br><strong class="ok"><?= (int) ($stats['vyhovuje'] ?? 0) ?></strong></td>
    <td style="text-align:center"><span class="muted" style="font-size:8pt;">Nevyhovuje</span><br><strong class="<?= ($stats['nevyhovuje'] ?? 0) > 0 ? 'bad' : '' ?>"><?= (int) ($stats['nevyhovuje'] ?? 0) ?></strong></td>
  </tr>
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

<?= $doc->signatures('pracovne_prostriedky') ?>
<?= $doc->footer('Kontrolovaných prostriedkov: ' . $total) ?>
