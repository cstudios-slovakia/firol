<?php
/**
 * Záznam o vykonaní preventívnej protipožiarnej prehliadky (PPP-RRRR-NNN) —
 * POapp 26. 10. 2026, phase 8a, § 14 ods. 3 vyhl. MV SR č. 121/2002 Z. z.
 *
 * Section order and the fixed sentences are the sample record's
 * (2_Vzor_hotoveho_zaznamu.pdf, acceptance criterion 19):
 *
 *   title → Správca / Právnická osoba → Adresa → Dátum PPP → Popis (+ the scope
 *   sentence) → Hlavné vypínače a uzávery → Vykonané činnosti → Zistené
 *   nedostatky → Nedostatky z predchádzajúcej prehliadky → Termíny → záver →
 *   signatures.
 *
 * Empty sections are left out: a prevádzka without main switches prints none, a
 * prehliadka with nothing ticked no „Vykonané činnosti". One prevádzka is ONE
 * document, whatever number of entrances it has (spec 7.2) — the entrance is only
 * the place of a defect.
 *
 * @var string $number
 * @var array  $brand
 * @var array  $inspection    executed_on, …
 * @var array  $company       name, ico, address, city
 * @var array  $facility      name, address, city
 * @var array  $inspector     fullname, certification_number, signature_data_uri
 * @var array|null $handover  the client's on-screen signature, when there is one
 * @var array  $photos
 * @var array  $prehliadka    Firol\Support\PrehliadkaRecord::poBlock()
 */
use Firol\Pdf\PrehliadkaDefectsBlock;
use Firol\Pdf\ProtocolLayout;
use Firol\Pdf\SignatureBlock;
use Firol\Support\Contractor;
use Firol\Support\PrehliadkaRecord;
use Firol\Support\Sections;

// The PO documents follow the account's brand colour (the photo appendix does too).
$doc = new ProtocolLayout(
    get_defined_vars(),
    (string) (($brand['color'] ?? null) ?: Sections::COLORS[Sections::OPP]),
    'OPP',
);
$e = [ProtocolLayout::class, 'esc'];

$p = (array) $prehliadka;
$isDom = ($p['kind'] ?? 'firma') === 'dom';
$points = (array) ($p['points'] ?? []);
$activities = (array) ($p['activities'] ?? []);
$terms = (array) ($p['terms'] ?? []);
$present = (array) ($p['present'] ?? []);
$handedOver = is_array($p['handed_over'] ?? null) ? $p['handed_over'] : null;
$bookSeq = isset($p['book_seq']) && is_int($p['book_seq']) ? $p['book_seq'] : null;
$ho = is_array($handover ?? null) ? $handover : null;

$title = PrehliadkaRecord::TITLE . ($isDom ? "\n" . PrehliadkaRecord::TITLE_DOM_SUFFIX : '');

$companyLine = (string) ($company['name'] ?? '');
if ($isDom) {
    // Správca: name and seat, as the sample prints it.
    if (!empty($company['address'])) {
        $companyLine .= ', ' . $company['address'];
    }
} elseif (!empty($company['ico'])) {
    $companyLine .= ', IČO ' . $company['ico'];
}
$facilityLine = (string) ($facility['name'] ?? '');
if (!empty($facility['address'])) {
    $facilityLine .= ', ' . $facility['address'];
}

// „…prekonzultované s prítomnými: Ing. Peter Správca (predseda družstva), …" — a name
// cannot be declined by machine, so the people are listed after a colon.
$presentText = implode(', ', array_map(
    static fn (array $x): string => (string) $x['name'] . (!empty($x['role']) ? ' (' . $x['role'] . ')' : ''),
    array_filter($present, 'is_array'),
));

$city = ($facility['city'] ?? '') ?: ($company['city'] ?? '');
$place = ($city ? $city . ', ' : '') . ProtocolLayout::date($inspection['executed_on'] ?? null);

$certLine = !empty($inspector['certification_number'])
    ? '<br><span style="font-size:8pt; color:#555;">technik ochrany pred požiarmi, osv. č. ' . $e((string) $inspector['certification_number']) . '</span>'
    : '<br><span style="font-size:8pt; color:#555;">technik ochrany pred požiarmi</span>';

if ($ho !== null) {
    $clientName = SignatureBlock::nameCell($ho);
} elseif ($handedOver !== null) {
    $clientName = $e((string) $handedOver['name'])
        . (!empty($handedOver['role']) ? '<br><span style="font-size:8pt; color:#555;">' . $e((string) $handedOver['role']) . '</span>' : '');
} else {
    $clientName = 'Zodpovedná osoba organizácie';
}
?>
<?= $doc->styles() ?>
<?= $doc->header($title) ?>

<table class="bi">
  <tr><td class="bl"><?= $isDom ? 'Správca' : 'Právnická osoba' ?></td><td class="bv" colspan="3"><?= $e($companyLine) ?></td></tr>
  <tr><td class="bl">Adresa</td><td class="bv" colspan="3"><?= $e($facilityLine) ?></td></tr>
  <tr><td class="bl">Dátum PPP</td><td class="bv" colspan="3"><strong><?= $e(ProtocolLayout::date($inspection['executed_on'] ?? null)) ?></strong></td></tr>
  <?= Contractor::basicInfoRow(is_array($contractor ?? null) ? $contractor : []) ?>
</table>

<?= $doc->band('Popis') ?>
<?php if (!empty($p['description'])): ?>
  <div class="text-box"><?= nl2br($e((string) $p['description'])) ?></div>
<?php endif ?>
<div class="text-box" style="<?= !empty($p['description']) ? 'border-top:none;' : '' ?>"><?= $e($isDom ? PrehliadkaRecord::SCOPE_DOM : PrehliadkaRecord::SCOPE_FIRMA) ?></div>

<?php if (!empty($p['switches'])): ?>
  <?= $doc->band('Hlavné vypínače a uzávery') ?>
  <table class="bi">
    <?php foreach ($p['switches'] as $row): ?>
      <tr><td class="bl" style="width:30%"><?= $e((string) $row['label']) ?></td><td class="bv" colspan="3"><?= $e((string) $row['value']) ?></td></tr>
    <?php endforeach ?>
  </table>
<?php endif ?>

<?php if ($activities !== []): ?>
  <?= $doc->band('Vykonané činnosti') ?>
  <table class="grid">
    <?php foreach ($activities as $a): ?>
      <tr>
        <td style="width:5%" class="check">✓</td>
        <td><?= $e((string) $a['label']) ?><?= !empty($a['custom']) ? ' ' . $e(PrehliadkaRecord::CUSTOM_ACTIVITY_TAG) : '' ?></td>
      </tr>
    <?php endforeach ?>
  </table>
  <div class="small-note"><?= $e(PrehliadkaRecord::CHECKED . ($isDom ? ' ' . PrehliadkaRecord::CHECKED_DOM : '')) ?></div>
<?php endif ?>

<?= PrehliadkaDefectsBlock::render($points, [
    'photos'    => !empty($p['include_photos']),
    'when_none' => PrehliadkaRecord::NO_DEFECTS,
]) ?>
<?php if ($points !== []): ?>
  <div class="small-note" style="font-size:9pt; color:#1a1a1f; margin-top:6pt;"><?= $e(PrehliadkaRecord::NO_OTHER_DEFECTS) ?></div>
<?php endif ?>

<?= PrehliadkaDefectsBlock::previous((array) ($p['previous'] ?? [])) ?>

<?php if ($terms !== []): ?>
  <?= $doc->band('Termíny') ?>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:44%">Kontrola alebo revízia</th>
        <th style="width:18%">Vykonaná</th>
        <th style="width:18%">Platnosť do</th>
        <th>Stav</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($terms as $t): ?>
        <tr>
          <td><?= $e((string) $t['label']) ?><?= !empty($t['overridden']) ? '<br><span class="muted" style="font-size:7.5pt;">prepísané rukou</span>' : '' ?></td>
          <td><?= $e((string) $t['done']) ?></td>
          <td><?= $e((string) $t['valid_until']) ?></td>
          <td class="<?= $e((string) ($t['state_tone'] ?? '')) ?>"><?= $e((string) ($t['state'] ?? '—')) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<div class="text-box" style="margin-top:8pt;">
  <?php if ($presentText !== ''): ?>
    <p style="margin:0 0 4pt;">Výsledky preventívnej protipožiarnej prehliadky a zistené závady boli prekonzultované s prítomnými: <?= $e($presentText) ?>.</p>
  <?php endif ?>
  <?php if ($points !== []): ?>
    <p style="margin:0 0 4pt;">Za odstránenie zistených závad v uvedených termínoch zodpovedá <?= $e($isDom ? PrehliadkaRecord::RESPONSIBLE_DOM : PrehliadkaRecord::RESPONSIBLE_FIRMA) ?>.</p>
  <?php endif ?>
  <?php if ($bookSeq !== null): ?>
    <p style="margin:0;"><?= $points !== []
        ? 'Zistené nedostatky boli zapísané do požiarnej knihy pod zápisom č. ' . $bookSeq . '.'
        : 'Prehliadka bola zapísaná do požiarnej knihy pod zápisom č. ' . $bookSeq . '.' ?></p>
  <?php endif ?>
</div>

<?= $doc->band('Podpisy') ?>
<table class="sig-tbl">
  <tr>
    <th width="34%">Prehliadku vykonal</th>
    <th width="40%"><?= $isDom ? 'Za vlastníka / správcu prevzal' : 'Za právnickú osobu prevzal' ?></th>
    <th width="26%">Miesto a dátum</th>
  </tr>
  <tr>
    <td><?= $e((string) ($inspector['fullname'] ?? '')) ?><?= $certLine ?></td>
    <td><?= $clientName ?></td>
    <td><?= SignatureBlock::placeAndDate($ho, $place) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($inspector['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $e((string) $inspector['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td><?= SignatureBlock::signCell($ho, 'preventivna_prehliadka') ?></td>
    <td></td>
  </tr>
</table>

<?= $doc->footer() ?>
