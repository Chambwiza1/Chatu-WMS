<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Integrity check</h1><p class="sub">Runs every night. It proves that the stock in every bin is explained by the movement ledger, that the FIFO cost layers equal the stock, that reservations add up, that every serial number is where the records say, that goods in transit carry their cost, and that nobody has edited the audit log.</p></div>
<form method="post" action="<?= site_url('admin/reconcile') ?>"><?= csrf_field() ?><button class="btn" type="submit">Run now</button></form></div>
<?php if (! $last): ?><div class="panel empty">Not run yet.</div><?php else: ?>
<p><?= $last['problems'] ? '<span class="badge bad">' . (int) $last['problems'] . ' problem(s)</span>' : '<span class="badge good">Everything agrees</span>' ?> · last run <?= local_time($last['at']) ?></p>
<?php foreach ($last['detail'] as $k => $list): ?><section class="panel"><h2><?= esc(['ledger' => 'Stock = movements', 'valuation' => 'Stock = cost layers', 'allocations' => 'Reservations', 'serials' => 'Serial numbers', 'transit' => 'Goods in transit', 'audit' => 'Audit log'][$k] ?? $k) ?></h2>
<?php if (! $list): ?><p class="ok-line">OK</p><?php else: ?><ul class="problems"><?php foreach ($list as $p): ?><li><?= esc($p) ?></li><?php endforeach ?></ul><?php endif ?></section><?php endforeach ?><?php endif ?>
<?= $this->endSection() ?>
