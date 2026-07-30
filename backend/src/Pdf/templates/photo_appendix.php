<?php
/**
 * Photo documentation appendix (change request 2.2).
 *
 * Appended after the protocol body, never mixed into it — the body must keep
 * rendering exactly as it did before this feature existed. Two photos per page
 * with the caption under each; the appendix grows over as many pages as the
 * photos need. Not rendered at all when the inspection has no photos.
 *
 * @var string $number
 * @var array  $brand    name, color, logo_data_uri
 * @var array  $company  name, ico, address
 * @var array  $facility name, address
 * @var array  $photos   list of { caption, path, width, height }
 */
$h = static fn(?string $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$brandColor = $brand['color'] ?? '#E8433A';

// Two per page. Portrait shots are the common case from a phone, so the box is
// sized for the taller of the two orientations and landscape shots simply sit
// inside it with letterboxing rather than being cropped.
$perPage = 2;
$pages   = array_chunk($photos, $perPage);
?>
<style>
  .pa-hdr {
    border-bottom: 2pt solid <?= $h($brandColor) ?>;
    padding-bottom: 4pt;
    margin-bottom: 8pt;
  }

  .pa-title {
    font-size: 12.5pt;
    font-weight: bold;
    color: <?= $h($brandColor) ?>;
  }

  .pa-meta {
    font-size: 8.5pt;
    color: #555;
  }

  .pa-figure {
    margin-bottom: 10pt;
  }

  .pa-frame {
    border: 1pt solid #dde0e6;
    background: #f7f7f9;
    padding: 4pt;
    text-align: center;
  }

  .pa-img {
    max-height: 300pt;
    max-width: 100%;
  }

  .pa-caption {
    font-size: 8.5pt;
    color: #2a2a32;
    padding: 3pt 2pt 0;
  }

  .pa-caption strong {
    color: #1a1a1f;
  }
</style>

<?php foreach ($pages as $pageIndex => $pagePhotos): ?>
  <?php if ($pageIndex > 0): ?>
    <pagebreak />
  <?php endif ?>

  <div class="pa-hdr">
    <div class="pa-title">Príloha — Fotodokumentácia</div>
    <div class="pa-meta">
      Protokol č. <?= $h($number) ?>
      &nbsp;·&nbsp; <?= $h($company['name'] ?? '') ?>
      <?php if (!empty($facility['name'])): ?>
        &nbsp;·&nbsp; <?= $h($facility['name']) ?>
      <?php endif ?>
    </div>
  </div>

  <?php foreach ($pagePhotos as $photo): ?>
    <div class="pa-figure">
      <div class="pa-frame">
        <img class="pa-img" src="<?= $h($photo['path']) ?>" alt="">
      </div>
      <div class="pa-caption"><?= $h($photo['caption']) ?></div>
    </div>
  <?php endforeach ?>
<?php endforeach ?>
