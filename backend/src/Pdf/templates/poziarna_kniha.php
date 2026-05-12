<?php
/**
 * Požiarna kniha — záznam o preventívnej protipožiarnej prehliadke.
 *
 * @var string $number
 * @var string $generated_at
 * @var array  $brand          name, color, logo_data_uri
 * @var array  $inspection     executed_on, periodicity_months, notes, status
 * @var array  $company        name, ico, address
 * @var array  $facility       name, address, contact_person
 * @var array  $inspector      fullname, certification_number, valid_from, valid_to, signature_data_uri
 * @var array  $items
 * @var array  $stats
 */
$h = static fn (?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

$formatDate = static function (?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('j. n. Y', $ts) : $iso;
};

$formatPeriodicity = static function (int $m): string {
    $w = $m === 1 ? 'mesiac' : ($m <= 4 ? 'mesiace' : 'mesiacov');
    $s = "$m $w";
    if ($m >= 24 && $m % 12 === 0) {
        $y = intdiv($m, 12);
        $yw = $y === 1 ? 'rok' : ($y <= 4 ? 'roky' : 'rokov');
        $s .= " ($y $yw)";
    }
    return $s;
};

$inspectorLine = $h($inspector['fullname']);
if (!empty($inspector['certification_number'])) {
    $inspectorLine .= ' | č. opr.: ' . $h($inspector['certification_number']);
}

$addrSrc = $facility['address'] ?: $company['address'] ?: '';
$city = '';
if (preg_match('/\d{3}\s\d{2}\s+(.+)$/u', $addrSrc, $m)) {
    $city = trim($m[1]);
}
$miesto = ($city ? $city . ', ' : '') . $formatDate($inspection['executed_on'] ?? null);

$record = $items[0]['fields'] ?? [];
$activeSlugs = is_array($record['activities'] ?? null) ? $record['activities'] : [];
$result = (string) ($record['result'] ?? '');
$workspaces = (string) ($record['workspaces'] ?? '');
$notes = (string) ($record['notes'] ?? '');
$activitiesOther = (string) ($record['activities_other'] ?? '');

$activityLabels = [
    'visual_check'                => 'Vizuálna kontrola priestorov spoločnosti',
    'rphp_check'                  => 'Kontrola stavu, označenia a dostupnosti RPHP',
    'hydranty_check'              => 'Kontrola stavu, označenia a dostupnosti požiarnych hydrantov',
    'escape_routes_check'         => 'Kontrola stavu, označenia a voľnosti únikových ciest',
    'pu_check'                    => 'Kontrola akcieschopnosti požiarnych uzáverov',
    'training_initial'            => 'Vykonané vstupné školenie z predpisov OPP',
    'training_repeated'           => 'Vykonané opakované školenie vedúcich a ostatných zamestnancov',
    'electrical_equipment_check'  => 'Kontrola stavu používaných elektrických zariadení',
    'technical_equipment_check'   => 'Kontrola stavu používaných technických zariadení',
    'electrical_appliances_check' => 'Kontrola stavu používaných elektrických spotrebičov',
    'documentation_check'         => 'Kontrola aktuálnosti dokumentácie požiarnej ochrany',
    'employee_list_check'         => 'Kontrola aktuálneho zoznamu zamestnancov a ich školení',
    'fire_drill'                  => 'Vykonaný cvičný požiarny poplach',
    'fire_cabinet_check'          => 'Kontrola hasičskej skrine na 2. poschodí',
];

$checkedActivities = [];
foreach ($activeSlugs as $slug) {
    if (isset($activityLabels[$slug])) {
        $checkedActivities[] = $activityLabels[$slug];
    }
}
if (!empty($activitiesOther)) {
    $checkedActivities[] = $activitiesOther;
}

$defectLines = array_values(array_filter(array_map('trim', explode("\n", $notes))));

$contactLine = 'Konateľovi spoločnosti';
if (!empty($facility['contact_person'])) {
    $contactLine .= ' – ' . $facility['contact_person'];
}
?>
<style>
  body { font-family: dejavusans, sans-serif; color: #1a1a1f; font-size: 10pt; }
  .hdr { border-collapse: collapse; width: 100%; }
  .hdr td { vertical-align: middle; padding: 3pt 0; }
  .hdr-inner { border-collapse: collapse; }
  .hdr-inner td { vertical-align: middle; padding: 0; }
  .logo-img { max-height: 36pt; max-width: 80pt; }
  .logo-box { border: 1pt solid #bbb; color: #999; font-size: 7pt; text-align: center; padding: 5pt 6pt; width: 36pt; }
  .hdr-company { font-size: 12.5pt; font-weight: bold; }
  .hdr-sub { font-size: 8.5pt; color: #555; }
  .hdr-right { text-align: right; }
  .hdr-title { font-size: 12.5pt; font-weight: bold; color: <?= $h($brandColor) ?>; }
  .hdr-meta { font-size: 9pt; color: #555; }
  h2 { color: <?= $h($brandColor) ?>; font-size: 10pt; font-weight: bold; margin: 10pt 0 3pt; text-transform: uppercase; letter-spacing: .3pt; }
  .bi { border-collapse: collapse; width: 100%; font-size: 9pt; }
  .bi td { padding: 3pt 6pt; border: 1pt solid #dde0e6; vertical-align: top; }
  .bl { background: #f7f7f9; font-weight: bold; color: #6b6b75; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; white-space: nowrap; width: 14%; }
  .bv { width: 36%; }
  .legal-box { margin: 4pt 0; padding: 5pt 8pt; background: #eef5ff; border: 1pt solid #c5d8f5; font-size: 8.5pt; color: #1a3a6b; }
  .workspaces-line { margin-top: 5pt; font-size: 9pt; }
  table.activities { border-collapse: collapse; width: 100%; font-size: 9pt; margin-top: 4pt; }
  table.activities td { padding: 3pt 6pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  table.activities td.chk { width: 20pt; text-align: center; color: #2e7d32; font-weight: bold; font-size: 11pt; }
  table.defects { border-collapse: collapse; width: 100%; font-size: 9pt; margin-top: 4pt; }
  table.defects th { background: #f3f3f6; color: #2a2a32; padding: 4pt 5pt; border: 1pt solid #d6d6dc; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.defects td { padding: 4pt 5pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  table.zaver { border-collapse: collapse; width: 100%; font-size: 9pt; margin-top: 4pt; }
  table.zaver td { padding: 4pt 8pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  table.zaver td.zlbl { background: #f7f7f9; font-weight: bold; color: #6b6b75; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; white-space: nowrap; width: 22%; }
  .sig-tbl { border-collapse: collapse; width: 100%; margin-top: 12pt; font-size: 9pt; }
  .sig-tbl th { background: #f3f3f6; padding: 4pt 6pt; border: 1pt solid #d6d6dc; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; font-weight: bold; color: #2a2a32; }
  .sig-tbl td { border: 1pt solid #e5e5ea; padding: 6pt; vertical-align: top; }
  .sig-row td { height: 50pt; }
  .sig-img { max-height: 38pt; max-width: 150pt; }
  .sig-line { border-top: 1pt solid #2a2a32; margin-top: 4pt; padding-top: 3pt; font-size: 8pt; color: #6b6b75; }
  .footer { border-top: 1pt solid #e5e5ea; margin-top: 12pt; padding-top: 4pt; font-size: 8pt; color: #6b6b75; }
</style>

<table class="hdr">
  <tr>
    <td width="57%">
      <table class="hdr-inner">
        <tr>
          <td width="50pt" style="padding-right:7pt;">
            <?php if (!empty($brand['logo_data_uri'])): ?>
              <img class="logo-img" src="<?= $h($brand['logo_data_uri']) ?>" alt="">
            <?php else: ?>
              <div class="logo-box">LOGO<br>FIRMY</div>
            <?php endif ?>
          </td>
          <td>
            <div class="hdr-company"><?= $h($company['name']) ?></div>
            <div class="hdr-sub">Prevádzka: <?= $h($facility['name']) ?> | IČO: <?= $h($company['ico']) ?></div>
            <div class="hdr-sub"><?= $h($company['address']) ?></div>
          </td>
        </tr>
      </table>
    </td>
    <td width="43%" class="hdr-right">
      <div class="hdr-title">Požiarna kniha</div>
      <div class="hdr-meta">Č. dokumentu: <?= $h($number) ?></div>
      <div class="hdr-meta">Dátum: <?= $formatDate($inspection['executed_on'] ?? null) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $h($brandColor) ?>; margin:4pt 0 8pt;">

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td>
    <td class="bv"><?= $h($company['name']) ?></td>
    <td class="bl">Dátum záznamu</td>
    <td class="bv"><strong><?= $formatDate($inspection['executed_on'] ?? null) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">IČO</td>
    <td class="bv"><?= $h($company['ico']) ?></td>
    <td class="bl">Periodicita</td>
    <td class="bv"><?= $h($formatPeriodicity((int) ($inspection['periodicity_months'] ?? 0))) ?></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <td class="bv"><?= $h($facility['name']) ?></td>
    <td class="bl">Záznam vykonal</td>
    <td class="bv"><?= $inspectorLine ?></td>
  </tr>
</table>

<h2>Úvodný záznam</h2>
<div class="legal-box">Vykonaná preventívna protipožiarna prehliadka všetkých pracovísk v pôsobnosti spoločnosti v zmysle zákona NRSR č. 314/2001 Z. z. v platnom znení a vyhlášky MV SR č. 121/2002 Z. z. v platnom znení.</div>
<?php if ($workspaces): ?>
<div class="workspaces-line">Bola vykonaná vizuálna prehliadka pracovísk: <?= $h($workspaces) ?></div>
<?php endif ?>

<?php if ($checkedActivities): ?>
<h2>Vykonané činnosti</h2>
<table class="activities">
  <?php foreach ($checkedActivities as $label): ?>
  <tr>
    <td class="chk">&#10004;</td>
    <td><?= $h($label) ?></td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<?php if ($result === 'zistene_nedostatky' && $defectLines): ?>
<h2>Zistené nedostatky</h2>
<table class="defects">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:72%">Popis nedostatku</th>
      <th style="width:24%">Termín odstránenia</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($defectLines as $i => $line): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= $h($line) ?></td>
      <td></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php elseif ($result === 'zistene_nedostatky' && $notes): ?>
<h2>Zistené nedostatky</h2>
<table class="defects">
  <thead>
    <tr>
      <th style="width:4%">Č.</th>
      <th style="width:72%">Popis nedostatku</th>
      <th style="width:24%">Termín odstránenia</th>
    </tr>
  </thead>
  <tbody>
    <tr><td>1</td><td><?= $h($notes) ?></td><td></td></tr>
  </tbody>
</table>
<?php endif ?>

<h2>Záver</h2>
<table class="zaver">
  <tr>
    <td class="zlbl">Požiarna kniha predložená</td>
    <td><?= $h($contactLine) ?></td>
  </tr>
  <tr>
    <td class="zlbl">Dátum predloženia</td>
    <td><?= $formatDate($inspection['executed_on'] ?? null) ?></td>
  </tr>
</table>

<h2>Podpisy</h2>
<table class="sig-tbl">
  <tr>
    <th width="38%">Záznam vykonal</th>
    <th width="38%">Predložené na podpis</th>
    <th width="24%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $h($inspector['fullname']) ?><?php if (!empty($inspector['certification_number'])): ?><br><span style="font-size:8pt; color:#555;">č. oprávnenia: <?= $h($inspector['certification_number']) ?></span><?php endif ?></td>
    <td>Konateľovi spoločnosti</td>
    <td><?= $h($miesto) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $h($inspector['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td>
      <div class="sig-line">Podpis zodpovednej osoby</div>
    </td>
    <td></td>
  </tr>
</table>

<div class="footer">
  Vypracoval: <?= $h($inspector['fullname']) ?><?php if (!empty($inspector['certification_number'])): ?> | č. oprávnenia: <?= $h($inspector['certification_number']) ?><?php endif ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Strana 1
</div>
