<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Search</h1><p class="sub">Items, warehouses and bins, batches and serial numbers, customers, suppliers, vehicles and drivers, and documents by their number. Only what you may open is shown.</p></div></div>
<form method="get" action="<?= site_url('search') ?>" class="msg-filter"><label class="sr" for="q2">Search for</label><input id="q2" name="q" type="search" value="<?= esc($q, 'attr') ?>" minlength="2" autofocus><button class="btn" type="submit">Search</button></form>
<?php if ($q !== '' && mb_strlen(trim($q)) < 2): ?><p class="muted">Type at least two letters or digits.</p>
<?php elseif ($q !== '' && ! $groups): ?><div class="panel empty">Nothing found for "<?= esc($q) ?>".</div>
<?php else: foreach ($groups as $g): ?>
<section><h2><?= esc($g['group']) ?></h2><ul class="results"><?php foreach ($g['rows'] as $r): ?><li><a href="<?= site_url($r['url']) ?>"><?= esc($r['title']) ?></a><?= $r['sub'] !== '' ? ' <span class="muted small">' . esc($r['sub']) . '</span>' : '' ?></li><?php endforeach ?></ul></section>
<?php endforeach; endif ?>
<?= $this->endSection() ?>
