<?php
/**
 * Záznam o kontrole regálov — block 2, REG-RRRR-NNN.
 *
 * Section order is the binding mockup's (`02_NAHLADY/bozp_protokoly.html`,
 * REG-2026-004): Základné informácie → legal sentence → Zoznam kontrolovaných
 * regálov → Súhrn výsledkov → Opatrenia → Podpisy → pätička. „Zistené
 * nedostatky" (chapter 7) sits before Opatrenia; both are left out when empty
 * (chapter 26). The mockup's Súhrn has no Vyradené cell because none of its
 * regály was vyradený — it is printed only when one is.
 *
 * @var array $items   rows — Firol\Support\BozpItems (regale)
 * @var array $stats   BozpItems::stats()
 * @var array $inspection  incl. details.measures_text
 * @var array $photos
 */
use Firol\Pdf\DefectsTable;
use Firol\Pdf\ProtocolLayout;
use Firol\Support\Defects;

$doc = new ProtocolLayout(get_defined_vars());
$e = [ProtocolLayout::class, 'esc'];
$cell = static fn(mixed $v): string => ($v === null || trim((string) $v) === '') ? '—' : nl2br($e((string) $v));
$details = (array) ($inspection['details'] ?? []);
$measures = trim((string) ($details['measures_text'] ?? ''));

$resultCell = static function (?string $r): string {
  return match ($r) {
    'vyhovuje'   => '<td class="ok">Vyhovuje</td>',
    'nevyhovuje' => '<td class="bad">Nevyhovuje</td>',
    'vyradene'   => '<td class="bad">Vyradený</td>',
    default      => '<td>—</td>',
  };
};
$yesNoCell = static function (mixed $v): string {
  return match ($v) {
    true    => '<td class="ok">áno</td>',
    false   => '<td class="bad">nie</td>',
    default => '<td>—</td>',
  };
};
$total = (int) ($stats['total'] ?? count($items));
$disposed = (int) ($stats['vyradene'] ?? 0);
?>
<?= $doc->styles() ?>
<?= $doc->header('Záznam o kontrole regálov') ?>
<?= $doc->basicInfo() ?>
<?= $doc->legalWarn('Kontrola vykonaná v rozsahu podľa STN EN 15635 a § 5 nariadenia vlády SR č. 392/2006 Z. z.') ?>

<?= $doc->band('Zoznam kontrolovaných regálov') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:11%">Označenie</th>
      <th style="width:12%">Typ</th>
      <th style="width:12%">Nosnosť</th>
      <th style="width:14%">Umiestnenie</th>
      <th style="width:12%">Označenie nosnosti</th>
      <th style="width:20%">Zistené závady</th>
      <th style="width:15%">Výsledok</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): $f = (array) ($item['fields'] ?? []); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $cell($f['label'] ?? null) ?></td>
        <td><?= $cell($f['type'] ?? null) ?></td>
        <td><?= $cell($f['capacity'] ?? null) ?></td>
        <td><?= $cell($f['location'] ?? null) ?></td>
        <?= $yesNoCell($f['capacity_marked'] ?? null) ?>
        <td><?= $cell($f['faults'] ?? null) ?></td>
        <?= $resultCell(isset($f['result']) ? (string) $f['result'] : null) ?>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?= $doc->band('Súhrn výsledkov') ?>
<table class="verdict">
  <tr>
    <td style="text-align:center;width:<?= $disposed > 0 ? '25' : '34' ?>%"><span class="muted" style="font-size:8pt;">Kontrolovaných</span><br><strong><?= $total ?></strong></td>
    <td style="text-align:center;width:<?= $disposed > 0 ? '25' : '33' ?>%"><span class="muted" style="font-size:8pt;">Vyhovuje</span><br><strong class="ok"><?= (int) ($stats['vyhovuje'] ?? 0) ?></strong></td>
    <td style="text-align:center<?= $disposed > 0 ? ';width:25%' : '' ?>"><span class="muted" style="font-size:8pt;">Nevyhovuje</span><br><strong class="<?= ($stats['nevyhovuje'] ?? 0) > 0 ? 'bad' : '' ?>"><?= (int) ($stats['nevyhovuje'] ?? 0) ?></strong></td>
    <?php if ($disposed > 0): ?>
      <td style="text-align:center"><span class="muted" style="font-size:8pt;">Vyradené</span><br><strong><?= $disposed ?></strong></td>
    <?php endif ?>
  </tr>
</table>

<?= DefectsTable::render(Defects::collect($items), [
  'labels'     => DefectsTable::SHORT_LABELS,
  'photo_note' => !empty($photos),
]) ?>

<?php if ($measures !== ''): ?>
  <?= $doc->band('Opatrenia') ?>
  <div class="note-box"><?= nl2br($e($measures)) ?></div>
<?php endif ?>

<?= $doc->signatures('regale') ?>
<?= $doc->footer('Kontrolovaných regálov: ' . $total) ?>
