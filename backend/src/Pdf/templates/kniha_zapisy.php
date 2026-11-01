<?php

declare(strict_types=1);

/**
 * Print of book entries (spec 9.5): the unsigned entries on one sheet with one
 * signature block, or a single entry. Rendered on the fly — an entry is a row
 * of the book, not a numbered document, so there is no document number here.
 *
 * Payload:
 *   brand, contractor, company{name, ico}, facility{name}|null, book (po|bozp),
 *   mode (unsigned|entry), printed_on (Y-m-d), entries[], technicians[],
 *   signer{name, role}
 *
 * The signature column reads „Za organizáciu — vedúci zamestnanec": the
 * statutory body does not sign or approve the book (§ 29), the vedúci
 * zamestnanec signs as the one who was made familiar with the entry — so the
 * word „schvaľuje" appears nowhere.
 *
 * @var array<string, mixed> $company
 * @var array<string, mixed>|null $facility
 * @var array<string, mixed> $contractor
 * @var list<array<string, mixed>> $entries
 * @var list<array<string, mixed>> $technicians
 * @var array<string, mixed> $signer
 */

use Firol\Pdf\ProtocolLayout;
use Firol\Support\BookMapping;
use Firol\Support\BookSentences;
use Firol\Support\Contractor;

$isBozp = ($book ?? 'po') === BookMapping::BOZP;
$color = $isBozp ? ProtocolLayout::BOZP_COLOR : \Firol\Support\Sections::COLORS[\Firol\Support\Sections::OPP];
$tag = $isBozp ? 'BOZP' : 'OPP';
$bookLabel = BookMapping::label($book ?? 'po');
$esc = static fn (?string $v): string => ProtocolLayout::esc($v);

$doc = new ProtocolLayout(get_defined_vars(), $color, $tag);
$title = ($mode ?? 'unsigned') === 'entry'
    ? $bookLabel . ' — zápis č. ' . (string) ($entries[0]['seq'] ?? '')
    : $bookLabel . ' — zápisy na podpis';

$brand = (array) ($brand ?? []);
$logo = !empty($brand['logo_data_uri'])
    ? '<img class="logo-img" src="' . $esc((string) $brand['logo_data_uri']) . '" alt="">'
    : '<div class="logo-box">LOGO<br>FIRMY</div>';
$facilityName = is_array($facility ?? null) ? (string) ($facility['name'] ?? '') : '';

echo $doc->styles();
?>
<style>
  .entry-no { font-weight: bold; font-size: 10pt; text-align: center; }
  .entry-add { margin-top: 3pt; font-size: 8.5pt; color: #444; }
  .entry-meta { margin-top: 3pt; font-size: 7.5pt; color: #6b6b75; }
  .zistenia { margin-top: 4pt; font-size: 8.5pt; }
  .zistenia b { color: <?= $esc($color) ?>; letter-spacing: .4pt; font-size: 7.5pt; }
</style>

<table class="hdr"><tr>
  <td width="48%"><table class="hdr-inner"><tr>
    <td width="50pt" style="padding-right:7pt;"><?= $logo ?></td>
    <td>
      <div class="hdr-company"><?= $esc((string) ($company['name'] ?? '')) ?></div>
      <div class="hdr-sub">IČO: <?= $esc((string) ($company['ico'] ?? '—')) ?></div>
      <?php if ($facilityName !== ''): ?>
        <div class="hdr-sub">Prevádzka: <?= $esc($facilityName) ?></div>
      <?php endif; ?>
    </td>
  </tr></table></td>
  <td width="52%" class="hdr-right">
    <div><span class="tag"><?= $esc($tag) ?></span></div>
    <div class="hdr-title"><?= $esc($title) ?></div>
    <div class="hdr-meta">Vytlačené dňa: <?= $esc(ProtocolLayout::date($printed_on ?? null)) ?></div>
  </td>
</tr></table>
<hr style="border:none; border-top:2pt solid <?= $esc($color) ?>; margin:4pt 0 6pt;">

<?php if (!empty($contractor['name'])): ?>
<table class="bi">
  <tr><td class="bl">Zhotoviteľ</td><td class="bv" colspan="3"><?= Contractor::cellHtml($contractor) ?></td></tr>
</table>
<?php endif; ?>

<?= $doc->band('Zápisy') ?>
<table class="grid">
  <tr>
    <th width="7%">Č.</th>
    <th width="13%">Dátum</th>
    <th>Zápis</th>
  </tr>
  <?php foreach ($entries as $entry): ?>
    <tr>
      <td class="entry-no"><?= $esc((string) $entry['seq']) ?></td>
      <td><?= $esc(ProtocolLayout::date($entry['entry_date'])) ?></td>
      <td>
        <?= nl2br($esc((string) $entry['text'])) ?>
        <?php if (!empty($entry['no_defects_statement'])): ?>
          <div class="zistenia"><b>ZISTENIA</b><br><?= $esc(BookSentences::NO_DEFECTS) ?></div>
        <?php endif; ?>
        <?php foreach ($entry['additions'] ?? [] as $addition): ?>
          <div class="entry-add">
            <b>Dopísané <?= $esc(ProtocolLayout::date(substr((string) $addition['created_at'], 0, 10))) ?>:</b>
            <?= nl2br($esc((string) $addition['text'])) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($entry['facility_name'])): ?>
          <div class="entry-meta">Kniha prevádzky: <?= $esc((string) $entry['facility_name']) ?></div>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

<?= $doc->band('Podpisy') ?>
<table class="sig-tbl">
  <tr>
    <th width="50%">Zápisy vyhotovil</th>
    <th width="50%">Za organizáciu — vedúci zamestnanec</th>
  </tr>
  <tr>
    <td>
      <?php foreach ($technicians as $tech): ?>
        <div style="margin-bottom:4pt;">
          <?= $esc((string) ($tech['fullname'] ?? '')) ?>
          <?php if (!empty($tech['certification_number'])): ?>
            <br><span style="font-size:8pt; color:#555;"><?= $isBozp ? 'bezpečnostný technik' : 'technik ochrany pred požiarmi' ?>, č. opr.: <?= $esc((string) $tech['certification_number']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </td>
    <td>
      <?= $esc((string) ($signer['name'] ?? '')) ?>
      <?php if (!empty($signer['role'])): ?>
        <br><span style="font-size:8pt; color:#555;"><?= $esc((string) $signer['role']) ?></span>
      <?php endif; ?>
    </td>
  </tr>
  <tr class="sig-row">
    <td>
      <?php foreach ($technicians as $tech): ?>
        <?php if (!empty($tech['signature_data_uri'])): ?>
          <img class="sig-img" src="<?= $esc((string) $tech['signature_data_uri']) ?>" alt="">
        <?php endif; ?>
      <?php endforeach; ?>
      <div class="sig-line">Podpísané technikom</div>
    </td>
    <td><div class="sig-line">Oboznámený — podpis po vytlačení</div></td>
  </tr>
</table>

<div class="footer">Podpísaný papier zostáva u klienta; aplikácia vedie len evidenciu.</div>
