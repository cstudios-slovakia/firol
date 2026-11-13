<?php
/**
 * Zápis do požiarnej knihy (PK-RRRR-NNN) — the new format, POapp 7. 11. 2026,
 * spec 9. Old entries keep poziarna_kniha.php.
 *
 * Section order: Základné údaje → Zápis (spôsob 1: Úvod, the fixed sentence,
 * activities, „Ďalej vykonané"; spôsob 2: the entry's text) → Priložené
 * záznamy (paragraphs, never a table) → Doplňujúci text (spôsob 1) → Zistené
 * nedostatky (only when there are any — no sentence otherwise) → Na záver →
 * Podpisy (printed names, blank lines for a handwritten signature; nothing is
 * signed in the app). Photos only in the appendix (PdfRenderer).
 *
 * Nothing is added by the app except the fixed sentence (spec 2 rule 8, 9).
 *
 * @var string $number
 * @var array  $brand
 * @var array  $inspection   executed_on, …
 * @var array  $company      name, ico, …
 * @var array  $facility     name (the selected prevádzky), address
 * @var array  $inspector    fullname, certification_number
 * @var array  $contractor   the Zhotoviteľ block
 * @var array  $kniha        Firol\Support\BookEntry::payload()
 */
use Firol\Pdf\ProtocolLayout;
use Firol\Support\Contractor;
use Firol\Support\Sections;

$doc = new ProtocolLayout(
    get_defined_vars(),
    (string) (($brand['color'] ?? null) ?: Sections::COLORS[Sections::OPP]),
    'OPP',
);
$e = [ProtocolLayout::class, 'esc'];
$k = (array) ($kniha ?? []);
$sposob = (int) ($k['sposob'] ?? 2);
$text = static fn (mixed $v): string => trim((string) $v);
$para = static fn (string $v): string => nl2br($e($v));

$certLine = !empty($inspector['certification_number'])
    ? '<br><span style="font-size:8pt; color:#555;">technik požiarnej ochrany, č. opr.: ' . $e((string) $inspector['certification_number']) . '</span>'
    : '';
$defects = (array) ($k['defects'] ?? []);
$multi = !empty($k['multi']);
?>
<?= $doc->styles() ?>
<?= $doc->header('Zápis do požiarnej knihy') ?>

<?= $doc->band('Základné údaje') ?>
<table class="grid" style="margin-top:0;">
  <tr>
    <th style="width:14%">Dátum</th>
    <td style="width:36%"><strong><?= $e(ProtocolLayout::date($inspection['executed_on'] ?? null)) ?></strong></td>
    <th style="width:14%">Vykonal</th>
    <td style="width:36%"><?= $e((string) ($inspector['fullname'] ?? '')) ?><?= $certLine ?></td>
  </tr>
  <tr>
    <th>Zápis č.</th>
    <td><?= $e((string) ($number ?? '')) ?></td>
    <th>Objekt</th>
    <td><?= $e(implode(', ', (array) ($k['objects'] ?? []))) ?></td>
  </tr>
  <?php if (!empty($contractor['name'])): ?>
    <tr>
      <th>Zhotoviteľ</th>
      <td colspan="3"><?= Contractor::cellHtml($contractor) ?></td>
    </tr>
  <?php endif ?>
</table>

<?php if ($sposob === 1): ?>
  <?= $doc->band('Zápis') ?>
  <?php if ($text($k['uvod'] ?? '') !== ''): ?>
    <p><?= $para((string) $k['uvod']) ?></p>
  <?php endif ?>
  <p><?= $e((string) ($k['fixed_sentence'] ?? '')) ?></p>
  <?php if (!empty($k['cinnosti'])): ?>
    <p><b><?= $e((string) ($k['activities_heading'] ?? '')) ?></b></p>
    <ul>
      <?php foreach ((array) $k['cinnosti'] as $c): ?>
        <li><?= $e((string) $c) ?></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php if (!empty($k['vlastne'])): ?>
    <p><b>Ďalej vykonané:</b></p>
    <ul>
      <?php foreach ((array) $k['vlastne'] as $c): ?>
        <li><?= $e((string) $c) ?></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
<?php elseif ($text($k['text_zapisu'] ?? '') !== ''): ?>
  <?= $doc->band('Zápis') ?>
  <p><?= $para((string) $k['text_zapisu']) ?></p>
<?php endif ?>

<?php if (!empty($k['paragraphs'])): ?>
  <?= $doc->band('Priložené záznamy') ?>
  <?php foreach ((array) $k['paragraphs'] as $p): ?>
    <p><?= $e((string) $p) ?></p>
  <?php endforeach ?>
<?php endif ?>

<?php if ($sposob === 1 && $text($k['doplnujuci_text'] ?? '') !== ''): ?>
  <?= $doc->band('Doplňujúci text') ?>
  <p><?= $para((string) $k['doplnujuci_text']) ?></p>
<?php endif ?>

<?php if ($defects !== []): ?>
  <?= $doc->band('Zistené nedostatky') ?>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:6%">Č.</th>
        <th>Nedostatok</th>
        <?php if ($multi): ?><th style="width:20%">Prevádzka</th><?php endif ?>
        <th style="width:18%">Termín odstránenia</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($defects as $d): ?>
        <?php
        $meta = implode(' · ', array_values(array_filter([
            $text($d['where_text'] ?? ''),
            $text($d['devices_text'] ?? ''),
        ], static fn (string $v): bool => $v !== '')));
        ?>
        <tr>
          <td><?= (int) ($d['n'] ?? 0) ?></td>
          <td>
            <b><?= $e((string) ($d['title'] ?? '')) ?></b>
            <?php if ($text($d['description'] ?? '') !== ''): ?><br><?= $para((string) $d['description']) ?><?php endif ?>
            <?php if ($meta !== ''): ?><br><span class="muted"><?= $e($meta) ?></span><?php endif ?>
            <?php if (!empty($d['removed_on_site'])): ?><br>Zistený a odstránený na mieste.<?php endif ?>
          </td>
          <?php if ($multi): ?><td><?= $e((string) ($d['facility_name'] ?? '')) ?></td><?php endif ?>
          <td><?= $e((string) ($d['deadline_text'] ?? '')) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<?php if ($text($k['zaver'] ?? '') !== ''): ?>
  <p><?= $para((string) $k['zaver']) ?></p>
<?php endif ?>

<?= $doc->band('Podpisy') ?>
<table class="sig-tbl">
  <tr>
    <th width="50%">Vypracoval</th>
    <th width="50%">Schválil za klienta</th>
  </tr>
  <tr>
    <td><?= $e((string) ($inspector['fullname'] ?? '')) ?><?= $certLine ?></td>
    <td><?= $e((string) ($k['schvalil'] ?? '')) ?></td>
  </tr>
  <tr class="sig-row">
    <td><div class="sig-line"></div></td>
    <td><div class="sig-line"></div></td>
  </tr>
</table>

<?= $doc->footer() ?>
