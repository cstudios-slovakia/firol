<?php
/**
 * Training (Školenie) protocol template.
 *
 * @var string $number
 * @var string $generated_at
 * @var array  $brand           name, color, logo_data_uri
 * @var array  $training        type, training_type_label, date, duration_min, topics, status
 * @var array  $company         name, ico, address
 * @var array  $facility        name, address
 * @var array  $trainer         fullname, certification_number, signature_data_uri
 * @var list<array> $trainees   id, fullname, position, signature_data_uri, signed_at
 */
$h = static fn (?string $v): string => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

$formatDate = static function (?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('j. n. Y', $ts) : $iso;
};

$type = (string) ($training['type'] ?? '');

// Fixed topic lists keyed by training type. Each item: [text, duration_min] or just [text] for two-part types.
$simpleTopics = [
    'vstupne' => [
        ['Oboznámenie sa so všeobecnými požiadavkami ochrany pred požiarmi v objektoch a priestoroch právnickej osoby.', 10],
        ['Výklad o nebezpečenstve vzniku požiaru charakteristickom pre príslušné pracovisko a činnosť, najmä o protipožiarnych opatreniach na pracoviskách, požiarnom poriadku pracoviska, zabezpečení ochrany pred požiarmi pri technologických procesoch, skladovaní horľavých látok a o pracovnej disciplíne vo vzťahu k ochrane pred požiarmi.', 10],
        ['Oboznámenie sa s rozmiestnením hasiacich zariadení, hasiacich prístrojov, spojovacích prostriedkov a ďalších vecných prostriedkov ochrany pred požiarmi na pracovisku a so spôsobom ich použitia, ako aj s rozmiestnením hlavných vypínačov elektrickej energie a uzáverov vody a plynu.', 20],
        ['Spôsob vyhlásenia požiarneho poplachu a povinnosti zamestnancov pri vzniku požiaru vyplývajúce z požiarnych poplachových smerníc a z požiarneho evakuačného plánu.', 10],
        ['Oboznámenie sa s požiarnou dokumentáciou spoločnosti a predpismi na zabezpečenie ochrany pred požiarmi.', 20],
        ['Oboznámenie sa s nebezpečenstvom vzniku požiaru vyplývajúcim z činnosti právnickej osoby.', 10],
        ['Oboznámenie sa so základmi procesov horenia a hasenia.', 10],
        ['Oboznámenie sa so základnými požiadavkami protipožiarnej bezpečnosti stavieb.', 10],
        ['Oboznámenie sa so zásadami protipožiarnej bezpečnosti pri skladovaní horľavých látok a pri manipulácii s nimi a pri činnosti spojenej so zvýšeným nebezpečenstvom vzniku požiaru a s činnosťou a parametrami zariadení, hasičskej techniky a iných vecných prostriedkov ochrany pred požiarmi.', 20],
    ],
    'opakovane' => [
        ['Oboznámenie sa so všeobecnými požiadavkami ochrany pred požiarmi v objektoch a priestoroch právnickej osoby.', 10],
        ['Výklad o nebezpečenstve vzniku požiaru charakteristickom pre príslušné pracovisko a činnosť, najmä o protipožiarnych opatreniach na pracoviskách, požiarnom poriadku pracoviska, zabezpečení ochrany pred požiarmi pri technologických procesoch, skladovaní horľavých látok a o pracovnej disciplíne vo vzťahu k ochrane pred požiarmi.', 10],
        ['Oboznámenie sa s rozmiestnením hasiacich zariadení, hasiacich prístrojov, spojovacích prostriedkov a ďalších vecných prostriedkov ochrany pred požiarmi na pracovisku a so spôsobom ich použitia, ako aj s rozmiestnením hlavných vypínačov elektrickej energie a uzáverov vody a plynu.', 20],
        ['Spôsob vyhlásenia požiarneho poplachu a povinnosti zamestnancov pri vzniku požiaru vyplývajúce z požiarnych poplachových smerníc a z požiarneho evakuačného plánu.', 10],
        ['Oboznámenie sa s požiarnou dokumentáciou spoločnosti a predpismi na zabezpečenie ochrany pred požiarmi.', 20],
        ['Oboznámenie sa s nebezpečenstvom vzniku požiaru vyplývajúcim z činnosti právnickej osoby.', 10],
        ['Oboznámenie sa so základmi procesov horenia a hasenia.', 10],
        ['Oboznámenie sa so základnými požiadavkami protipožiarnej bezpečnosti stavieb.', 10],
        ['Oboznámenie sa so zásadami protipožiarnej bezpečnosti pri skladovaní horľavých látok a pri manipulácii s nimi a pri činnosti spojenej so zvýšeným nebezpečenstvom vzniku požiaru a s činnosťou a parametrami zariadení, hasičskej techniky a iných vecných prostriedkov ochrany pred požiarmi.', 20],
    ],
    'opp_mimo' => [
        ['Oboznámenie sa s úlohami a povinnosťami osôb zabezpečujúcich ochranu pred požiarmi v mimopracovnom čase a po skončení prevádzky podľa § 9 ods. 5 vyhlášky MV SR č. 121/2002 Z. z.', 10],
        ['Oboznámenie sa s objektom, prevádzkou, technologickými zariadeniami a s nebezpečenstvom vzniku požiaru charakteristickým pre objekt a priestory, v ktorých budú činnosť vykonávať.', 15],
        ['Oboznámenie sa s rozmiestnením hasiacich zariadení, hasiacich prístrojov, spojovacích prostriedkov, hlavných vypínačov elektrickej energie a uzáverov vody a plynu a so spôsobom ich obsluhy a použitia.', 15],
        ['Spôsob vyhlásenia požiarneho poplachu, privolania Hasičského a záchranného zboru, povinnosti pri vzniku požiaru a postup podľa požiarnych poplachových smerníc a požiarneho evakuačného plánu.', 20],
    ],
    'zdrzujuca_sa' => [
        ['Oboznámenie sa so všeobecnými požiadavkami ochrany pred požiarmi v objektoch a priestoroch právnickej osoby.', 15],
        ['Výklad o nebezpečenstve vzniku požiaru charakteristickom pre príslušné pracovisko a činnosť, najmä o protipožiarnych opatreniach, požiarnom poriadku pracoviska, zabezpečení ochrany pred požiarmi pri skladovaní horľavých látok a o pracovnej disciplíne vo vzťahu k ochrane pred požiarmi.', 15],
        ['Oboznámenie sa s rozmiestnením hasiacich zariadení, hasiacich prístrojov, spojovacích prostriedkov a ďalších vecných prostriedkov ochrany pred požiarmi na pracovisku a so spôsobom ich použitia, ako aj s rozmiestnením hlavných vypínačov elektrickej energie a uzáverov vody a plynu.', 15],
        ['Spôsob vyhlásenia požiarneho poplachu a povinnosti osôb pri vzniku požiaru vyplývajúce z požiarnych poplachových smerníc a z požiarneho evakuačného plánu.', 15],
    ],
];

$twoPartTopics = [
    'hliadka_oph' => [
        'teoreticka' => ['rozsah' => 25, 'items' => [
            'Úlohy protipožiarnej hliadky pracoviska.',
            'Oboznámenie s nebezpečenstvom vzniku požiaru na pracoviskách právnickej osoby, fyzickej osoby – podnikateľa.',
            'Oboznámenie s dokumentáciou ochrany pred požiarmi na pracovisku (požiarne poplachové smernice, požiarny evakuačný plán a pod.).',
            'Oboznámenie sa so spôsobom vyhlásenia požiarneho poplachu a privolanie pomoci.',
        ]],
        'prakticka' => ['rozsah' => 35, 'items' => [
            'Oboznámenie sa s rozmiestnením a použitím hasiacich prístrojov, požiarnych vodovodov, hasiacich zariadení, požiarnotechnických zariadení a spojovacích prostriedkov, zariadení na zabránenie šírenia požiaru.',
            'Spôsoby a cesty evakuácie a súčinnosť s hasičskou jednotkou.',
            'Oboznámenie sa so spojovacími prostriedkami.',
            'Hlavné uzávery plynu, elektrickej energie, vody – spôsob uzatvárania.',
        ]],
    ],
    'hliadka_opah' => [
        'teoreticka' => ['rozsah' => 25, 'items' => [
            'Oboznámenie sa s pokynom vydaným právnickou osobou, fyzickou osobou – podnikateľom k akcii.',
            'Oboznámenie sa s úlohami protipožiarnej asistenčnej hliadky.',
            'Oboznámenie sa s nebezpečenstvom vzniku požiaru počas akcie.',
            'Oboznámenie sa so spôsobom vyhlásenia požiarneho poplachu a privolanie pomoci podľa požiarnych poplachových smerníc.',
            'Oboznámenie sa s požiarnym evakuačným plánom.',
        ]],
        'prakticka' => ['rozsah' => 35, 'items' => [
            'Oboznámenie sa s rozmiestnením a s použitím hasiacich prístrojov.',
            'Oboznámenie sa s hasiacim zariadením.',
            'Oboznámenie sa so spojovacími prostriedkami.',
            'Oboznámenie sa s požiarnotechnickými zariadeniami na zabránenie šírenia požiaru.',
            'Oboznámenie sa so spôsobom a cestami evakuácie a so súčinnosťou s hasičskou jednotkou.',
            'Oboznámenie sa s hlavnými uzávermi vody, plynu a hlavným vypínačom elektrickej energie.',
        ]],
    ],
];

// Fixed total duration per type derived from the topic lists — not stored in DB.
$totalMin = 0;
if (isset($simpleTopics[$type])) {
    $totalMin = array_sum(array_column($simpleTopics[$type], 1));
} elseif (isset($twoPartTopics[$type])) {
    $parts    = $twoPartTopics[$type];
    $totalMin = $parts['teoreticka']['rozsah'] + $parts['prakticka']['rozsah'];
}

$trainerLine = $h($trainer['fullname']);
if (!empty($trainer['certification_number'])) {
    $trainerLine .= ' | č. oprávnenia: ' . $h($trainer['certification_number']);
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
  h2 { background: <?= $h($brandColor) ?>; color: #fff; font-size: 9pt; font-weight: bold; margin: 8pt 0 0; text-transform: uppercase; letter-spacing: .3pt; padding: 3pt 6pt; }
  .bi { border-collapse: collapse; width: 100%; font-size: 9pt; }
  .bi td { padding: 3pt 6pt; border: 1pt solid #dde0e6; vertical-align: top; }
  .bl { background: #f7f7f9; font-weight: bold; color: #6b6b75; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; white-space: nowrap; width: 14%; }
  .bv { width: 36%; }
  table.topics { border-collapse: collapse; width: 100%; font-size: 9pt; margin-top: 4pt; }
  table.topics th { background: #f3f3f6; color: #2a2a32; padding: 4pt 5pt; border: 1pt solid #d6d6dc; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.topics td { padding: 4pt 5pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  table.topics td.tnum { width: 6%; text-align: center; white-space: nowrap; }
  table.topics td.ttime { width: 12%; text-align: center; white-space: nowrap; }
  .section-hdr { font-size: 9pt; font-weight: bold; margin: 5pt 0 2pt; color: #2a2a32; }
  table.topics-part { border-collapse: collapse; width: 100%; font-size: 9pt; margin-top: 2pt; margin-bottom: 4pt; }
  table.topics-part th { background: <?= $h($brandColor) ?>; color: #fff; padding: 4pt 5pt; border: 1pt solid <?= $h($brandColor) ?>; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.topics-part td { padding: 4pt 5pt; border: 1pt solid #e5e5ea; vertical-align: top; }
  table.topics-part td.tnum { width: 6%; text-align: center; white-space: nowrap; }
  table.attendees { border-collapse: collapse; width: 100%; font-size: 9pt; margin-top: 4pt; }
  table.attendees th { background: <?= $h($brandColor) ?>; color: #fff; padding: 4pt 6pt; border: 1pt solid <?= $h($brandColor) ?>; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.attendees td { padding: 6pt; border: 1pt solid #e5e5ea; vertical-align: middle; }
  table.attendees td.sig-cell { text-align: center; }
  table.attendees .sig-img { max-height: 36pt; max-width: 140pt; }
  .footer { border-top: 1pt solid #e5e5ea; margin-top: 12pt; padding-top: 4pt; font-size: 8pt; color: #1a1a1f; }
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
            <div class="hdr-sub">IČO: <?= $h($company['ico']) ?> | Sídlo: <?= $h($company['address']) ?></div>
            <div class="hdr-sub">Prevádzka: <?= $h($facility['name']) ?><?= !empty($facility['address']) ? ' | ' . $h($facility['address']) : '' ?></div>
          </td>
        </tr>
      </table>
    </td>
    <td width="43%" class="hdr-right">
      <div class="hdr-title"><?= in_array($type, ['hliadka_oph', 'hliadka_opah'], true) ? 'Odborná príprava' : 'Školenie PO' ?></div>
      <div class="hdr-meta">Č. dokumentu: <?= $h($number) ?></div>
      <div class="hdr-meta">Dátum: <?= $formatDate($training['date'] ?? null) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $h($brandColor) ?>; margin:4pt 0 8pt;">

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Spoločnosť</td>
    <td class="bv"><?= $h($company['name']) ?></td>
    <td class="bl"><?= in_array($type, ['hliadka_oph', 'hliadka_opah'], true) ? 'Druh' : 'Druh školenia' ?></td>
    <td class="bv"><?= $h($training['training_type_label'] ?? '') ?></td>
  </tr>
  <tr>
    <td class="bl">IČO</td>
    <td class="bv"><?= $h($company['ico']) ?></td>
    <td class="bl">Dátum školenia</td>
    <td class="bv"><strong><?= $formatDate($training['date'] ?? null) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <td class="bv"><?= $h($facility['name']) ?><?= !empty($facility['address']) ? '<br><span style="font-weight:normal;color:#555;">' . $h($facility['address']) . '</span>' : '' ?></td>
    <td class="bl">Časový rozsah</td>
    <td class="bv"><?= $totalMin ?> minút</td>
  </tr>
  <tr>
    <td class="bl">Školenie vykonal</td>
    <td colspan="3"><?= $trainerLine ?></td>
  </tr>
</table>

<h2>Tematický plán školenia</h2>
<?php if (isset($simpleTopics[$type])): ?>
<table class="topics">
  <thead>
    <tr>
      <th style="width:6%">Č.</th>
      <th>Obsah školenia</th>
      <th style="width:12%">Čas</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($simpleTopics[$type] as $i => [$text, $min]): ?>
    <tr>
      <td class="tnum"><?= $i + 1 ?></td>
      <td><?= $h($text) ?></td>
      <td class="ttime"><?= $min ?> min</td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php elseif (isset($twoPartTopics[$type])): $parts = $twoPartTopics[$type]; ?>
<div class="section-hdr">Teoretická časť (rozsah: <?= $parts['teoreticka']['rozsah'] ?> min)</div>
<table class="topics-part">
  <thead>
    <tr>
      <th style="width:6%">Č.</th>
      <th>Obsah</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($parts['teoreticka']['items'] as $i => $text): ?>
    <tr>
      <td class="tnum"><?= $i + 1 ?></td>
      <td><?= $h($text) ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<div class="section-hdr">Praktická časť (rozsah: <?= $parts['prakticka']['rozsah'] ?> min)</div>
<table class="topics-part">
  <thead>
    <tr>
      <th style="width:6%">Č.</th>
      <th>Obsah</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($parts['prakticka']['items'] as $i => $text): ?>
    <tr>
      <td class="tnum"><?= $i + 1 ?></td>
      <td><?= $h($text) ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>

<h2>Prezenčná listina účastníkov</h2>
<table class="attendees">
  <thead>
    <tr>
      <th style="width:6%">Por. č.</th>
      <th style="width:28%">Meno, priezvisko, titul</th>
      <th style="width:22%">Pracovné zaradenie</th>
      <th style="width:15%">Dátum školenia</th>
      <th style="width:29%">Podpis</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($trainees as $idx => $tr): ?>
    <tr style="height:30pt;">
      <td><?= $idx + 1 ?></td>
      <td><?= $h($tr['fullname']) ?></td>
      <td><?= $h($tr['position'] ?? null) ?></td>
      <td><?= $formatDate($training['date'] ?? null) ?></td>
      <td class="sig-cell">
        <?php if (!empty($tr['signature_data_uri'])): ?>
          <img class="sig-img" src="<?= $h($tr['signature_data_uri']) ?>" alt="">
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>

<div class="footer">
  <table style="border-collapse:collapse; width:100%;">
    <tr>
      <td style="width:60%; vertical-align:bottom; padding:0;">
        <strong>Školenie vykonal:</strong> <?= $trainerLine ?>
      </td>
      <td style="width:40%; text-align:right; vertical-align:bottom; padding:0;">
        <span>Podpis školiteľa:</span>
        <?php if (!empty($trainer['signature_data_uri'])): ?>
          <br><img style="max-height:28pt; max-width:110pt; vertical-align:bottom;" src="<?= $h($trainer['signature_data_uri']) ?>" alt="">
        <?php else: ?>
          &nbsp;&nbsp;______________________
        <?php endif ?>
      </td>
    </tr>
  </table>
</div>
