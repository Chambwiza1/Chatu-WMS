<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Goods received</h1><p class="sub"><?= esc(wh_name($wh)) ?></p></div>
<div class="actions"><?php if (can('receive.post', $wh)): ?><a class="btn" href="<?= site_url('receipts/new') ?>">Receive without an order</a><a class="btn secondary" href="<?= site_url('supplier-returns/new') ?>">Return to supplier</a><?php endif ?>
<?php if (can('stock.opening', $wh)): ?><a class="btn secondary" href="<?= site_url('receipts/new?kind=opening') ?>">Opening stock</a><?php endif ?></div></div>
<?php if ($due && can('receive.post', $wh)): ?><section class="panel"><h2>Expected deliveries</h2><ul class="attn"><?php foreach ($due as $p): ?><li><a href="<?= site_url('receipts/new?po=' . $p['id']) ?>"><span><?= esc($p['number']) ?><small><?= esc($p['supplier']) ?><?= $p['expected_date'] ? ', ' . nice_date($p['expected_date']) : '' ?></small></span></a></li><?php endforeach ?></ul></section><?php endif ?>
<?php if (! $rows): ?><div class="panel empty">Nothing received here yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Date</th><th>Supplier</th><th>Order</th><th>Delivery note</th><th>By</th><?php if (can('stock.view_cost')): ?><th class="num">Value</th><?php endif ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="<?= site_url('receipts/' . $r['id']) ?>"><?= esc($r['number']) ?></a><?= $r['kind'] === 'opening' ? ' <span class="badge quiet">Opening</span>' : '' ?></td><td><?= nice_date($r['received_on']) ?></td><td><?= esc($r['supplier']) ?></td><td><?= esc($r['po_number']) ?></td><td><?= esc($r['delivery_note']) ?></td><td><?= esc($r['posted_name']) ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($r['value']) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?php if ($returns): ?><h2>Returns to suppliers</h2><div class="table-wrap"><table><thead><tr><th>Number</th><th>Date</th><th>Supplier</th><th>Reason</th><?php if (can('stock.view_cost')): ?><th class="num">Value</th><?php endif ?></tr></thead><tbody>
<?php foreach ($returns as $r): ?><tr><td><?= esc($r['number']) ?></td><td><?= local_time($r['posted_at'], 'j M Y') ?></td><td><?= esc($r['supplier']) ?></td><td><?= esc($r['reason_code']) ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($r['value']) ?></td><?php endif ?></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
