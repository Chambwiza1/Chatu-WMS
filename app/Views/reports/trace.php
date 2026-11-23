<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Trace a batch or serial</h1><p class="sub">For a recall or a complaint: where it came from, what it went into, which customers received it, and where the rest is.</p></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Batch number (ours or the supplier's) or serial number</label><input id="q" name="q" value="<?= esc($q) ?>" required autofocus></div><button class="btn" type="submit">Trace</button></form>
<?php if ($q !== ''): ?><?php if (! $lots && ! $serials): ?><div class="panel empty">Nothing found for "<?= esc($q) ?>".</div><?php endif ?>
<?php if ($lots): ?><h2>Batches</h2><ul><?php foreach ($lots as $l): ?><li><a href="<?= site_url('lots/' . $l['id']) ?>"><?= esc($l['lot_no']) ?></a> <?= esc($l['sku'] . ' ' . $l['name']) ?></li><?php endforeach ?></ul><?php endif ?>
<?php if ($serials): ?><h2>Serial numbers</h2><ul><?php foreach ($serials as $s): ?><li><a href="<?= site_url('serials/' . $s['id']) ?>"><?= esc($s['serial_no']) ?></a> <?= esc($s['sku']) ?></li><?php endforeach ?></ul><?php endif ?><?php endif ?>
<?= $this->endSection() ?>
