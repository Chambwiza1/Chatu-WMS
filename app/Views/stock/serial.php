<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Serial <span class="mono"><?= esc($s['serial_no']) ?></span> <?= badge($s['status']) ?></h1><p class="sub"><?= sku_plate($s['sku']) ?> <?= esc($s['item_name']) ?><?= $s['lot_no'] ? ', batch ' . esc($s['lot_no']) : '' ?><?= $s['customer'] ? ' · with ' . esc($s['customer']) : '' ?></p></div></div>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Warehouse</th><th>Movement</th><th>Document</th><th>From</th><th>To</th><th>By</th></tr></thead><tbody>
<?php foreach ($s['history'] as $m): ?><tr><td class="nowrap"><?= local_time($m['at']) ?></td><td><?= esc($m['wh']) ?></td><td><?= esc(str_replace('_', ' ', $m['type'])) ?></td><td><?= esc($m['doc_type'] . ' ' . $m['doc_id']) ?></td><td class="mono"><?= esc($m['from_bin']) ?></td><td class="mono"><?= esc($m['to_bin']) ?></td><td><?= esc($m['user']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
