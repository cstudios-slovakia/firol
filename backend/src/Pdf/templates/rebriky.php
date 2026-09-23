<?php
/**
 * Záznam o kontrole rebríkov — block 2, REB-RRRR-NNN.
 *
 * Section order is the binding mockup's (`02_NAHLADY/bozp_protokoly.html`,
 * REB-2026-003): Základné informácie → legal sentence → Zoznam kontrolovaných
 * rebríkov → Súhrn výsledkov → Opatrenia → Podpisy → pätička. „Zistené
 * nedostatky" (chapter 7) sits before Opatrenia and, like Opatrenia, is left
 * out when there is nothing in it (chapter 26).
 *
 * @var array $items   rows — Firol\Support\BozpItems (rebriky)
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

// The mockup prints the verdict of a single rebrík in the masculine.
$resultCell = static function (?string $r) use ($e): string {
  return match ($r) {
    'vyhovuje'   => '<td class="ok">Vyhovuje</td>',
    'nevyhovuje' => '<td class="bad">Nevyhovuje</td>',
    'vyradene'   => '<td class="bad">Vyradený</td>',
    default      => '<td>—</td>',
  };
};
$total = (int) ($stats['total'] ?? count($items));
?>
<?= $doc->styles() ?>
<?= $doc->header('Záznam o kontrole rebríkov') ?>
<?= $doc->basicInfo() ?>
<?= $doc->legalWarn('Kontrola vykonaná v rozsahu podľa STN EN 131-3 a § 5 nariadenia vlády SR č. 392/2006 Z. z. o minimálnych bezpečnostných a zdravotných požiadavkách pri používaní pracovných prostriedkov.') ?>

<?= $doc->band('Zoznam kontrolovaných rebríkov') ?>
<table class="grid">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:12%">Inv. číslo</th>
      <th style="width:17%">Typ</th>
      <th style="width:11%">Výrobca</th>
      <th style="width:7%">Rok</th>
      <th style="width:16%">Umiestnenie</th>
      <th style="width:18%">Zistené závady</th>
      <th style="width:15%">Výsledok</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): $f = (array) ($item['fields'] ?? []); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $cell($f['inventory_number'] ?? null) ?></td>
        <td><?= $cell($f['type'] ?? null) ?></td>
        <td><?= $cell($f['manufacturer'] ?? null) ?></td>
        <td><?= $cell(isset($f['year']) ? (string) $f['year'] : null) ?></td>
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
    <td style="text-align:center;width:25%"><span class="muted" style="font-size:8pt;">Kontrolovaných</span><br><strong><?= $total ?></strong></td>
    <td style="text-align:center;width:25%"><span class="muted" style="font-size:8pt;">Vyhovuje</span><br><strong class="ok"><?= (int) ($stats['vyhovuje'] ?? 0) ?></strong></td>
    <td style="text-align:center;width:25%"><span class="muted" style="font-size:8pt;">Nevyhovuje</span><br><strong class="<?= ($stats['nevyhovuje'] ?? 0) > 0 ? 'bad' : '' ?>"><?= (int) ($stats['nevyhovuje'] ?? 0) ?></strong></td>
    <td style="text-align:center"><span class="muted" style="font-size:8pt;">Vyradené</span><br><strong><?= (int) ($stats['vyradene'] ?? 0) ?></strong></td>
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

<?= $doc->signatures('rebriky') ?>
<?= $doc->footer('Kontrolovaných rebríkov: ' . $total) ?>
