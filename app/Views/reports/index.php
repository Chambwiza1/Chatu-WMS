<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Reports</h1></div></div>
<ul class="attn">
<li><a href="<?= site_url('reports/trace') ?>"><span>Trace a batch or serial<small>For a recall: where it came from and which customers received it</small></span></a></li>
<?php foreach ($list as $k => $r): ?><li><a href="<?= site_url('reports/' . $k) ?>"><span><?= esc($r[0]) ?><small><?= esc($r[1]) ?></small></span></a></li><?php endforeach ?>
</ul>
<?= $this->endSection() ?>
