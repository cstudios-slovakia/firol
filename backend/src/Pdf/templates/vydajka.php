<?php
/**
 * Výdajka materiálu (VYD-RRRR-NNN) — block 4 / chapter 21.
 *
 * Layout from the binding mockup (02_NAHLADY/bozp_protokoly.html, the
 * „Výdajka materiálu" sheet): a shared document, neutral grey and tagged
 * SPOLOČNÉ. The client is in the header, as on a protocol; the material is
 * listed with its count and note; the signatures are Vydal / Za organizáciu —
 * prevzal. The Zhotoviteľ row is added under the mockup's rows (chapter 1.3.3,
 * decision 23. 9. 2026), as on every other protocol.
 *
 * The date is the date of the movements it lists, never the date the PDF was
 * generated — documents must be back-datable (POKYNY rule 4).
 *
 * @var string     $number
 * @var array      $brand        logo_data_uri — the account's logo
 * @var array      $company      name, ico, address, city — the client (odberateľ)
 * @var array      $facility     name, address, city — may be all null
 * @var array      $issue        issued_on
 * @var array      $issuer       fullname, certification_number, signature_data_uri
 * @var array      $contractor   frozen Zhotoviteľ block
 * @var array      $lines        name, quantity („3 ks"), note
 * @var array      $acts         label, document_number — úkony the material was used in
 * @var array|null $handover     client signature, once captured (chapter 13)
 */

use Firol\Pdf\ProtocolLayout;
use Firol\Pdf\SignatureBlock;
use Firol\Support\Contractor;
use Firol\Support\Sections;

$doc = new ProtocolLayout(get_defined_vars(), Sections::SHARED_COLOR, 'SPOLOČNÉ');
$e = [ProtocolLayout::class, 'esc'];
$color = Sections::SHARED_COLOR;
$date = ProtocolLayout::date($issue['issued_on'] ?? null);

$issuerLine = $e($issuer['fullname'] ?? '');
if (!empty($issuer['certification_number'])) {
  $issuerLine .= '<br><span style="font-size:8pt; color:#555;">č. oprávnenia: '
    . $e($issuer['certification_number']) . '</span>';
}

$facilityName = $facility['name'] ?? null;
$facilityAddress = $facility['address'] ?? null;
$handover = is_array($handover ?? null) ? $handover : null;

echo $doc->styles();
?>
<style>
  .note { margin-top: 6pt; padding: 5pt 8pt 5pt 10pt; background: #f7f7f9; border: 1pt solid #e5e5ea;
          border-left: 3pt solid <?= $e($color) ?>; font-size: 8pt; color: #6b6b75; }
  table.grid th { background: #f3f3f6; }
  .sig-tbl th { background: #f3f3f6; }
</style>

<table class="hdr">
  <tr>
    <td width="48%">
      <table class="hdr-inner">
        <tr>
          <td width="50pt" style="padding-right:7pt;">
            <?php if (!empty($brand['logo_data_uri'])): ?>
              <img class="logo-img" src="<?= $e($brand['logo_data_uri']) ?>" alt="">
            <?php else: ?>
              <div class="logo-box">LOGO<br>FIRMY</div>
            <?php endif ?>
          </td>
          <td>
            <div class="hdr-company"><?= $e($company['name'] ?? '') ?></div>
            <div class="hdr-sub">
              <?php if ($facilityName): ?>Prevádzka: <?= $e($facilityName) ?> | <?php endif ?>IČO: <?= $e(($company['ico'] ?? null) ?: '—') ?>
            </div>
            <?php $headerAddress = $facilityAddress ?: ($company['address'] ?? null); ?>
            <?php if ($headerAddress): ?>
              <div class="hdr-sub"><?= $e($headerAddress) ?></div>
            <?php endif ?>
          </td>
        </tr>
      </table>
    </td>
    <td width="52%" class="hdr-right">
      <div><span class="tag">SPOLOČNÉ</span></div>
      <div class="hdr-title">Výdajka materiálu</div>
      <div class="hdr-meta">Č. dokumentu: <?= $e($number ?? '') ?></div>
      <div class="hdr-meta">Dátum: <?= $e($date) ?></div>
    </td>
  </tr>
</table>
<hr style="border:none; border-top:2pt solid <?= $e($color) ?>; margin:4pt 0 6pt;">

<h2>Základné informácie</h2>
<table class="bi">
  <tr>
    <td class="bl">Odberateľ</td>
    <td class="bv"><?= $e($company['name'] ?? '') ?></td>
    <td class="bl">Dátum výdaja</td>
    <td class="bv"><strong><?= $e($date) ?></strong></td>
  </tr>
  <tr>
    <td class="bl">Prevádzka</td>
    <td class="bv">
      <?php if ($facilityName): ?>
        <?= $e($facilityName) ?><?= $facilityAddress ? '<br><span style="color:#555;">' . $e($facilityAddress) . '</span>' : '' ?>
      <?php else: ?>
        <?= $e(($company['address'] ?? null) ?: '—') ?>
      <?php endif ?>
    </td>
    <td class="bl">Vydal</td>
    <td class="bv"><?= $issuerLine ?></td>
  </tr>
  <?php if (!empty($acts)): ?>
    <tr>
      <td class="bl">Súvisí s úkonom</td>
      <td colspan="3">
        <?php foreach ($acts as $i => $act): ?>
          <?= $i > 0 ? '<br>' : '' ?><?= $e($act['label']) ?><?php if (!empty($act['document_number'])): ?> — protokol <?= $e($act['document_number']) ?><?php endif ?>
        <?php endforeach ?>
      </td>
    </tr>
  <?php endif ?>
  <?= Contractor::basicInfoRow(is_array($contractor ?? null) ? $contractor : []) ?>
</table>

<h2>Vydaný materiál</h2>
<table class="grid">
  <thead>
    <tr>
      <th style="width:6%">P. č.</th>
      <th>Položka</th>
      <th style="width:12%">Počet</th>
      <th style="width:30%">Poznámka</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $i => $line): ?>
      <tr>
        <td><?= $i + 1 ?>.</td>
        <td><?= $e($line['name']) ?></td>
        <td><?= $e($line['quantity']) ?></td>
        <td><?= $e(($line['note'] ?? null) ?: '—') ?></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>

<div class="note">
  Uvedený materiál bol odovzdaný a osadený na mieste. Fakturuje sa podľa dohodnutých cien.
</div>

<h2>Podpisy</h2>
<table class="sig-tbl">
  <tr>
    <th width="50%">Vydal</th>
    <th width="50%"><?= SignatureBlock::heading('vydajka') ?></th>
  </tr>
  <tr>
    <td><?= $issuerLine ?></td>
    <td><?= SignatureBlock::nameCell($handover) ?></td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php if (!empty($issuer['signature_data_uri'])): ?>
        <img class="sig-img" src="<?= $e($issuer['signature_data_uri']) ?>" alt="">
      <?php endif ?>
      <div class="sig-line"></div>
    </td>
    <td><?= SignatureBlock::signCell($handover, 'vydajka') ?></td>
  </tr>
</table>

<div class="footer">
  Vystavil: <?= $e($issuer['fullname'] ?? '') ?><?php if (!empty($issuer['certification_number'])): ?> | č. oprávnenia: <?= $e($issuer['certification_number']) ?><?php endif ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Vydaných položiek: <?= count($lines) ?>
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Strana 1
</div>
