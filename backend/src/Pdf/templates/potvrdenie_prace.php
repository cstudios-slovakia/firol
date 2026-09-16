<?php
/**
 * Potvrdenie o vykonaní práce — block 1 / chapter 10.
 *
 * A shared document: neutral grey, tagged SPOLOČNÉ, belonging to no odbor.
 * It proves the visit happened; the findings live in the protocols whose
 * numbers it lists, and not one of them is repeated here.
 *
 * @var string     $number
 * @var array      $contractor     name, ico, address, logo_data_uri — the TECHNICIAN's firm
 * @var array      $confirmation   confirmed_on, time_from, time_to, duration, act_count, protocol_count
 * @var array      $company        name, ico, address, city — the client
 * @var array      $facility       name, address, city
 * @var array      $technician     fullname, certification_number, signature_data_uri
 * @var array      $acts           id, type, executed_on, item_count, document_number
 * @var array      $materials      rows from that day's výdajka (block 4; empty until then)
 * @var array|null $handover       client signature, once captured (chapter 13)
 */
$h = static fn(?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$color = \Firol\Support\Sections::SHARED_COLOR;

$formatDate = static function (?string $iso): string {
  if (!$iso)
    return '—';
  $ts = strtotime($iso);
  return $ts ? date('j. n. Y', $ts) : $iso;
};

/** Slovak name of each úkon, so the line reads as work done rather than a slug. */
$actLabels = [
  'php'                => 'Kontrola hasiacich prístrojov',
  'oprava_ts_php'      => 'Oprava, plnenie a tlaková skúška hasiacich prístrojov',
  'vyradenie'          => 'Vyradenie hasiacich prístrojov',
  'hydranty'           => 'Kontrola požiarnych hydrantov',
  'ts_hadic'           => 'Tlaková skúška hadíc',
  'poziarna_kniha'     => 'Zápis do požiarnej knihy',
  'pu_akcieschopnost'  => 'Kontrola akcieschopnosti požiarnych uzáverov',
  'pu_udrzba'          => 'Prevádzková údržba požiarnych uzáverov',
  'nudzove_osvetlenie' => 'Kontrola núdzového osvetlenia',
];

/** "12 prístrojov", "4 pracoviská" — the scope column of each line. */
$scope = static function (string $type, int $n): string {
  [$one, $few, $many] = match ($type) {
    'poziarna_kniha' => ['záznam', 'záznamy', 'záznamov'],
    'hydranty'       => ['hydrant', 'hydranty', 'hydrantov'],
    'ts_hadic'       => ['hadica', 'hadice', 'hadíc'],
    'pu_akcieschopnost', 'pu_udrzba' => ['uzáver', 'uzávery', 'uzáverov'],
    'nudzove_osvetlenie' => ['svietidlo', 'svietidlá', 'svietidiel'],
    default          => ['prístroj', 'prístroje', 'prístrojov'],
  };
  return $n . ' ' . ($n === 1 ? $one : ($n < 5 ? $few : $many));
};

$technicianLine = $h($technician['fullname']);
if (!empty($technician['certification_number'])) {
  $technicianLine .= ', č. osvedčenia: ' . $h($technician['certification_number']);
}

$hasTime = !empty($confirmation['time_from']) && !empty($confirmation['time_to']);
$place = ($facility['city'] ?? '') ?: ($company['city'] ?? '');
$placeAndDate = ($place ? $place . ', ' : '') . $formatDate($confirmation['confirmed_on'] ?? null);
?>
<style>
  body {
    font-family: dejavusans, sans-serif;
    color: #1a1a1f;
    font-size: 10pt;
  }

  .hdr { border-collapse: collapse; width: 100%; }
  .hdr td { vertical-align: middle; padding: 3pt 0; }
  .hdr-inner { border-collapse: collapse; }
  .hdr-inner td { vertical-align: middle; padding: 0; }
  .logo-img { max-height: 36pt; max-width: 80pt; }
  .logo-box { border: 1pt solid #bbb; color: #999; font-size: 7pt; text-align: center; padding: 5pt 6pt; width: 36pt; }
  .hdr-company { font-size: 12.5pt; font-weight: bold; }
  .hdr-sub { font-size: 8.5pt; color: #555; }
  .hdr-right { text-align: right; }
  .hdr-title { font-size: 12.5pt; font-weight: bold; color: <?= $h($color) ?>; }
  .hdr-meta { font-size: 9pt; color: #555; }

  /* Textual tag beside the title. A black-and-white printout loses the
     colour band entirely, so this box is the only reliable marker of which
     kind of document the reader is holding. */
  .tag {
    display: inline-block;
    border: 1pt solid <?= $h($color) ?>;
    color: <?= $h($color) ?>;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: .5pt;
    padding: 1pt 5pt;
    margin-bottom: 2pt;
  }

  h2 {
    background: <?= $h($color) ?>;
    color: #fff;
    font-size: 9pt;
    font-weight: bold;
    margin: 8pt 0 0;
    text-transform: uppercase;
    letter-spacing: .3pt;
    padding: 3pt 6pt;
  }

  .bi { border-collapse: collapse; width: 100%; font-size: 9pt; }
  .bi td { padding: 3pt 6pt; border: 1pt solid #dde0e6; vertical-align: top; }
  .bl {
    background: #f7f7f9;
    font-weight: bold;
    color: #6b6b75;
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
    white-space: nowrap;
    width: 16%;
  }
  .bv { width: 34%; }

  table.items { border-collapse: collapse; width: 100%; font-size: 8.5pt; margin-top: 4pt; }
  table.items th {
    background: #f3f3f6;
    color: #2a2a32;
    padding: 4pt 5pt;
    border: 1pt solid #d6d6dc;
    text-align: left;
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
  }
  table.items td { padding: 4pt 5pt; border: 1pt solid #e5e5ea; vertical-align: top; }

  .stats { border-collapse: collapse; width: 100%; margin-top: 6pt; }
  .stats td { padding: 5pt 8pt; border: 1pt solid #e5e5ea; text-align: center; font-size: 9pt; }
  .stats .num { font-size: 15pt; font-weight: bold; }

  .note {
    margin-top: 6pt;
    padding: 5pt 8pt 5pt 10pt;
    background: #f7f7f9;
    border: 1pt solid #e5e5ea;
    border-left: 3pt solid <?= $h($color) ?>;
    font-size: 8pt;
    color: #6b6b75;
  }

  .sig-tbl { border-collapse: collapse; width: 100%; margin-top: 8pt; font-size: 9pt; }
  .sig-tbl th {
    background: #f3f3f6;
    padding: 4pt 6pt;
    border: 1pt solid #d6d6dc;
    font-size: 8pt;
    text-transform: uppercase;
    letter-spacing: .3pt;
    font-weight: bold;
    color: #2a2a32;
  }
  .sig-tbl td { border: 1pt solid #e5e5ea; padding: 6pt; vertical-align: top; }
  .sig-row td { height: 36pt; vertical-align: bottom; text-align: center; }
  .sig-img { max-height: 38pt; max-width: 150pt; }
  .sig-line { border-top: 1pt solid #2a2a32; margin-top: 4pt; padding-top: 3pt; font-size: 8pt; color: #6b6b75; }

  .footer {
    border-top: 1pt solid #e5e5ea;
    margin-top: 8pt;
    padding-top: 4pt;
    font-size: 8pt;
    color: #6b6b75;
  }
</style>

<table class="hdr">
  <tr>
    <td width="57%">
      <table class="hdr-inner">
        <tr>
          <td width="50pt" style="padding-right:7pt;">
            <?php if (!empty($contractor['logo_data_uri'])): ?>
              <img class="logo-img" src="<?= $h($contractor['logo_data_uri']) ?>" alt="">
            <?php else: ?>
              <div class="logo-box">LOGO<br>FIRMY</div>
            <?php endif ?>
          </td>
          <td>
            <div class="hdr-company"><?= $h($contractor['name']) ?></div>
            <div class="hdr-sub">
              <?php if (!empty($contractor['ico'])): ?>IČO: <?= $h($contractor['ico']) ?><?php endif ?>
              <?php if (!empty($contractor['address'])): ?> | <?= $h($contractor['address']) ?><?php endif ?>
            </div>
            <div class="hdr-sub">zhotoviteľ</div>
          </td>
        </tr>
      </table>
    </td>
    <td width="43%" class="hdr-right">
      <div class="tag">SPOLOČNÉ</div>
      <div class="hdr-title">Potvrdenie o vykonaní práce</div>
      <div class="hdr-meta">Č. dokumentu: <?= $h($number) ?></div>
      <div class="hdr-meta">Dátum: <?= $formatDate($confirmation['confirmed_on'] ?? null) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $h($color) ?>; margin:4pt 0 8pt;">

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Práca vykonaná u</td>
    <td class="bv"><?= $h($company['name']) ?><?= !empty($company['ico']) ? ', IČO: ' . $h($company['ico']) : '' ?></td>
    <td class="bl">Dátum</td>
    <td class="bv"><strong><?= $formatDate($confirmation['confirmed_on'] ?? null) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <?php // Chapter 10: with no times recorded the row is left out entirely
          // rather than printed empty — an employer not tracking hours should
          // not be handed a document with a blank where the hours go. ?>
    <td class="bv"<?= $hasTime ? '' : ' colspan="3"' ?>>
      <?= $h($facility['name'] ?? null) ?><?= !empty($facility['address']) ? '<br><span style="color:#555;">' . $h($facility['address']) . '</span>' : '' ?>
    </td>
    <?php if ($hasTime): ?>
      <td class="bl">Čas od – do</td>
      <td class="bv"><?= $h($confirmation['time_from']) ?> – <?= $h($confirmation['time_to']) ?></td>
    <?php endif ?>
  </tr>
  <tr>
    <td class="bl">Prácu vykonal</td>
    <td colspan="3"><?= $technicianLine ?></td>
  </tr>
</table>

<div class="note">
  Potvrdenie slúži ako doklad o vykonaní práce na uvedenej prevádzke. Odborné výsledky
  jednotlivých úkonov sú uvedené v samostatných protokoloch, ktorých čísla sú nižšie.
</div>

<h2>Vykonané úkony</h2>
<table class="items">
  <thead>
    <tr>
      <th style="width:6%">P. č.</th>
      <th>Úkon</th>
      <th style="width:16%">Rozsah</th>
      <th style="width:22%">Protokol</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($acts as $idx => $act): ?>
      <tr>
        <td><?= $idx + 1 ?>.</td>
        <td><?= $h($actLabels[$act['type']] ?? $act['type']) ?></td>
        <td><?= $h($scope((string) $act['type'], (int) $act['item_count'])) ?></td>
        <td><?= $h($act['document_number'] ?? null) ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<?php // Empty sections are not printed (chapter 26). Material comes from a
      // výdajka, which arrives with the sklad in block 4. ?>
<?php if (!empty($materials)): ?>
  <h2>Odovzdaný materiál</h2>
  <table class="items">
    <thead>
      <tr>
        <th style="width:6%">P. č.</th>
        <th>Položka</th>
        <th style="width:14%">Počet</th>
        <th style="width:22%">Doklad</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($materials as $idx => $m): ?>
        <tr>
          <td><?= $idx + 1 ?>.</td>
          <td><?= $h($m['name'] ?? null) ?></td>
          <td><?= $h($m['quantity'] ?? null) ?></td>
          <td><?= $h($m['document_number'] ?? null) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<h2>Súhrn</h2>
<table class="stats">
  <tr>
    <td>Vykonaných úkonov<br><span class="num"><?= (int) $confirmation['act_count'] ?></span></td>
    <td>Vydaných protokolov<br><span class="num"><?= (int) $confirmation['protocol_count'] ?></span></td>
    <?php if (!empty($confirmation['duration'])): ?>
      <td>Čas na prevádzke<br><span class="num"><?= $h($confirmation['duration']) ?></span></td>
    <?php endif ?>
  </tr>
</table>

<h2>Potvrdenie</h2>
<table class="sig-tbl">
  <tr>
    <th width="38%">Prácu vykonal</th>
    <th width="38%"><?= \Firol\Pdf\SignatureBlock::heading('potvrdenie_prace') ?></th>
    <th width="24%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $h($technician['fullname']) ?><?php if (!empty($technician['certification_number'])): ?><br><span
          style="font-size:8pt; color:#555;">č. osvedčenia:
          <?= $h($technician['certification_number']) ?></span><?php endif ?></td>
    <td><?= \Firol\Pdf\SignatureBlock::nameCell($handover ?? null) ?></td>
    <td><?= \Firol\Pdf\SignatureBlock::placeAndDate($handover ?? null, $placeAndDate) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($technician['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $h($technician['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td>
      <?= \Firol\Pdf\SignatureBlock::signCell($handover ?? null, 'potvrdenie_prace') ?>
    </td>
    <td></td>
  </tr>
</table>

<div class="footer">
  Vystavil: <?= $h($technician['fullname']) ?> | <?= $h($contractor['name']) ?><?php if (!empty($contractor['ico'])): ?>,
    IČO <?= $h($contractor['ico']) ?><?php endif ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Úkonov: <?= (int) $confirmation['act_count'] ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Strana 1
</div>
