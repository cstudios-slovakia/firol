<?php

declare(strict_types=1);

/**
 * Print of book entries (spec 9.5 & 02.11 spec):
 *   - mode = 'unsigned': the unsigned entries on one sheet with one signature block
 *   - mode = 'entry': a single entry protocol (prototype docZapis)
 *   - mode = 'all': full book print with all entries and per-entry signature status (prototype docKniha)
 *
 * Payload:
 *   brand, contractor, company{name, ico, street?, postal_code?, city?},
 *   facility{name, street?, postal_code?, city?}|null, facilities[], scope,
 *   book (po|bozp), mode (unsigned|entry|all), printed_on (Y-m-d), entries[],
 *   technicians[], signer{name, role}, unsigned_count
 *
 * @var array<string, mixed> $company
 * @var array<string, mixed>|null $facility
 * @var list<array<string, mixed>>|null $facilities
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
$brand = (array) ($brand ?? []);
$primary = !empty($brand['color'])
    ? (string) $brand['color']
    : (!empty($theme['primary']) ? (string) $theme['primary'] : '#2B579A');
$color = $isBozp ? ProtocolLayout::BOZP_COLOR : $primary;
$tag = $isBozp ? 'BOZP' : 'OPP';
$bookLabel = BookMapping::label($book ?? 'po');
$esc = static fn (?string $v): string => ProtocolLayout::esc($v);

$mode = $mode ?? 'unsigned';
if ($mode === 'entry') {
    $title = 'Zápis do požiarnej knihy';
} elseif ($mode === 'all') {
    $title = $bookLabel;
} else {
    $title = $bookLabel . ' — zápisy na podpis';
}

$doc = new ProtocolLayout(get_defined_vars(), $color, $tag);

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
      <?php if (!empty($company['street']) || !empty($company['city'])): ?>
        <div class="hdr-sub"><?= $esc(trim(($company['street'] ?? '') . ', ' . ($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? ''), ', ')) ?></div>
      <?php endif; ?>
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

<?php if ($mode === 'entry'): ?>
  <?php $entry = $entries[0]; ?>
  <?= $doc->band('Základné údaje') ?>
  <table class="bi">
    <tr>
      <td class="bl">Dátum</td>
      <td class="bv"><?= $esc(ProtocolLayout::date($entry['entry_date'])) ?></td>
      <td class="bl">Vykonal</td>
      <td class="bv"><?= $esc((string) ($technicians[0]['fullname'] ?? 'Technik PO')) ?></td>
    </tr>
    <tr>
      <td class="bl">Zápis č.</td>
      <td class="bv"><?= $esc((string) $entry['seq']) ?></td>
      <td class="bl">Protokol č.</td>
      <td class="bv"><?= $esc(!empty($entry['protocol_numbers']) ? implode(', ', $entry['protocol_numbers']) : '—') ?></td>
    </tr>
    <?php if (!empty($entry['facility_name'])): ?>
    <tr>
      <td class="bl">Prevádzka</td>
      <td class="bv" colspan="3"><?= $esc((string) $entry['facility_name']) ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <?= $doc->band('Zápis') ?>
  <div class="text-box" style="margin-top:4pt; line-height:1.4;">
    <?= nl2br($esc((string) $entry['text'])) ?>
    <?php foreach ($entry['additions'] ?? [] as $addition): ?>
      <div class="entry-add" style="margin-top:6pt; border-top:1pt dashed #d6d6dc; padding-top:4pt;">
        <b>Dopísané <?= $esc(ProtocolLayout::date(substr((string) $addition['created_at'], 0, 10))) ?>:</b><br>
        <?= nl2br($esc((string) $addition['text'])) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($entry['defects_text']) || !empty($entry['defect_lines'])): ?>
    <?= $doc->band('Zistené závady a nedostatky') ?>
    <div class="text-box" style="margin-top:4pt; line-height:1.4;">
      <?php if (!empty($entry['defects_text'])): ?>
        <?= nl2br($esc((string) $entry['defects_text'])) ?>
      <?php else: ?>
        <?php foreach ($entry['defect_lines'] as $line): ?>
          <?= $esc((string) $line) ?><br>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php elseif (!empty($entry['no_defects_statement'])): ?>
    <?= $doc->band('Zistenia') ?>
    <div class="text-box" style="margin-top:4pt; line-height:1.4;"><?= $esc(BookSentences::NO_DEFECTS) ?>.</div>
  <?php else: ?>
    <?= $doc->band('Zistené závady a nedostatky') ?>
    <div class="text-box" style="margin-top:4pt; line-height:1.4;">V čase zápisu neboli zistené žiadne závady ani nedostatky.</div>
  <?php endif; ?>

  <?= $doc->band('Podpisy') ?>
  <table class="sig-tbl">
    <tr>
      <th width="50%">Zápis vyhotovil</th>
      <th width="50%">Vedúci zamestnanec organizačnej zložky</th>
    </tr>
    <tr>
      <td>
        <?php foreach ($technicians as $tech): ?>
          <div style="margin-bottom:4pt;">
            <?= $esc((string) ($tech['fullname'] ?? '')) ?>
            <?php if (!empty($tech['certification_number'])): ?>
              <br><span style="font-size:8pt; color:#555;">č. opr. <?= $esc((string) $tech['certification_number']) ?></span>
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
        <div class="sig-line">podpísané</div>
      </td>
      <td>
        <?php if (!empty($entry['signed_manager'])): ?>
          <div class="sig-line ok">podpísané</div>
        <?php else: ?>
          <div class="sig-line">podpis po vytlačení</div>
        <?php endif; ?>
      </td>
    </tr>
  </table>

<?php elseif ($mode === 'all'): ?>
  <?= $doc->band('Základné údaje') ?>
  <table class="bi">
    <tr>
      <td class="bl">Vedie</td>
      <td class="bv"><?= $esc((string) ($technicians[0]['fullname'] ?? 'Technik PO')) ?></td>
      <td class="bl">Vytlačené</td>
      <td class="bv"><?= $esc(ProtocolLayout::date($printed_on ?? null)) ?></td>
    </tr>
    <tr>
      <td class="bl">Platí pre</td>
      <td class="bv" colspan="3">
        <?php
          if (($scope ?? 'firma') === 'firma') {
              $names = array_filter(array_map(static fn (array $f): string => (string) ($f['name'] ?? ''), $facilities ?? []));
              echo 'všetky prevádzky firmy' . ($names !== [] ? ': ' . $esc(implode(', ', $names)) : '');
          } else {
              echo 'prevádzku ' . $esc((string) ($facility['name'] ?? '—'));
          }
        ?>
      </td>
    </tr>
    <tr>
      <td class="bl">Zápisov</td>
      <td class="bv"><?= count($entries) ?></td>
      <td class="bl">Nepodpísaných</td>
      <td class="bv"><?= (int) ($unsigned_count ?? 0) ?></td>
    </tr>
  </table>

  <?= $doc->band('Zápisy') ?>
  <?php foreach ($entries as $i => $entry): ?>
    <div style="margin-top:<?= $i > 0 ? '10pt' : '4pt' ?>; padding-bottom:8pt; border-bottom:1pt solid #dde0e6; page-break-inside:avoid;">
      <div style="font-size:9.5pt; font-weight:bold; color:#1a1a1f;">
        Zápis č. <?= $esc((string) $entry['seq']) ?> — <?= $esc(ProtocolLayout::date($entry['entry_date'])) ?>
        <?php if (!empty($entry['protocol_numbers'])): ?>
          <span style="font-weight:normal; font-size:8.5pt; color:#6b6b75;"> &bull; Protokol č. <?= $esc(implode(', ', $entry['protocol_numbers'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($entry['facility_name'])): ?>
          <span style="font-weight:normal; font-size:8.5pt; color:#6b6b75;"> &bull; Prevádzka: <?= $esc((string) $entry['facility_name']) ?></span>
        <?php endif; ?>
      </div>

      <div style="margin-top:3pt; font-size:9pt; line-height:1.4;">
        <?= nl2br($esc((string) $entry['text'])) ?>
      </div>

      <?php if (!empty($entry['defects_text'])): ?>
        <div class="zistenia"><b>ZISTENÉ NEDOSTATKY:</b><br><?= nl2br($esc((string) $entry['defects_text'])) ?></div>
      <?php elseif (!empty($entry['defect_lines'])): ?>
        <div class="zistenia"><b>ZISTENÉ NEDOSTATKY:</b>
          <?php foreach ($entry['defect_lines'] as $line): ?>
            <br><?= $esc((string) $line) ?>
          <?php endforeach; ?>
        </div>
      <?php elseif (!empty($entry['no_defects_statement'])): ?>
        <div class="zistenia"><b>ZISTENIA:</b> <?= $esc(BookSentences::NO_DEFECTS) ?></div>
      <?php endif; ?>

      <?php foreach ($entry['additions'] ?? [] as $addition): ?>
        <div class="entry-add" style="margin-top:3pt;">
          <b>Dopísané <?= $esc(ProtocolLayout::date(substr((string) $addition['created_at'], 0, 10))) ?>:</b>
          <?= nl2br($esc((string) $addition['text'])) ?>
        </div>
      <?php endforeach; ?>

      <table class="sig-tbl" style="margin-top:6pt; font-size:8pt;">
        <tr>
          <th width="50%">Zápis vyhotovil</th>
          <th width="50%">Vedúci zamestnanec organizačnej zložky</th>
        </tr>
        <tr>
          <td style="padding:3pt 6pt;">
            <?= $esc((string) ($technicians[0]['fullname'] ?? 'Technik PO')) ?><br>
            <i class="ok" style="font-size:7.5pt;">podpísané</i>
          </td>
          <td style="padding:3pt 6pt;">
            <?= $esc((string) ($signer['name'] ?? '—')) ?><br>
            <?php if (!empty($entry['signed_manager'])): ?>
              <i class="ok" style="font-size:7.5pt;">podpísané (<?= $esc(ProtocolLayout::date($entry['signed_at'] ?? null)) ?>)</i>
            <?php else: ?>
              <i class="bad" style="font-size:7.5pt;">čaká na podpis</i>
            <?php endif; ?>
          </td>
        </tr>
      </table>
    </div>
  <?php endforeach; ?>

<?php else: ?>
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
          <?php if (!empty($entry['defects_text'])): ?>
            <div class="zistenia"><b>ZISTENÉ NEDOSTATKY:</b><br><?= nl2br($esc((string) $entry['defects_text'])) ?></div>
          <?php elseif (!empty($entry['defect_lines'])): ?>
            <div class="zistenia"><b>ZISTENÉ NEDOSTATKY</b>
              <?php foreach ($entry['defect_lines'] as $line): ?>
                <br><?= $esc((string) $line) ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
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
      <th width="50%">Vedúci zamestnanec organizačnej zložky</th>
    </tr>
    <tr>
      <td>
        <?php foreach ($technicians as $tech): ?>
          <div style="margin-bottom:4pt;">
            <?= $esc((string) ($tech['fullname'] ?? '')) ?>
            <?php if (!empty($tech['certification_number'])): ?>
              <br><span style="font-size:8pt; color:#555;">č. opr. <?= $esc((string) $tech['certification_number']) ?></span>
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
        <div class="sig-line">podpísané</div>
      </td>
      <td><div class="sig-line">podpis po vytlačení</div></td>
    </tr>
  </table>
<?php endif; ?>

<?php if ($mode === 'unsigned'): ?>
  <div class="footer">Podpísaný papier zostáva u klienta; aplikácia vedie len evidenciu.</div>
<?php else: ?>
  <div class="footer">Tento dokument bol vytvorený pomocou softvéru poapp.sk</div>
<?php endif; ?>
