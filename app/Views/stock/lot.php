<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $l = $t['lot'] ?>
<div class="page-head"><div><h1>Batch <?= esc($l['lot_no']) ?> <?= badge($l['status']) ?></h1><p class="sub"><?= sku_plate($l['sku']) ?> <?= esc($l['item_name']) ?></p></div><div class="actions"><a class="btn secondary" href="<?= site_url('labels/lot/' . $l['id']) ?>">Batch labels</a><button class="btn secondary" data-print>Print</button></div></div>
<section class="panel"><dl class="facts">
<dt>Source</dt><dd><?= esc(ucfirst($l['source'])) ?><?= $l['supplier'] ? ', ' . esc($l['supplier']) : '' ?><?= $l['supplier_lot'] ? ' (their batch ' . esc($l['supplier_lot']) . ')' : '' ?></dd>
<?php if ($l['mfg_date']): ?><dt>Made</dt><dd><?= nice_date($l['mfg_date']) ?></dd><?php endif ?>
<dt>Expiry</dt><dd><?= $l['expiry_date'] ? nice_date($l['expiry_date']) . ' (' . esc(days_left($l['expiry_date'])) . ')' : 'none' ?></dd>
<?php if ($l['hold_reason']): ?><dt>Why held</dt><dd><?= esc($l['hold_reason']) ?></dd><?php endif ?></dl>
<?php if (can('lot.hold')): ?><form method="post" action="<?= site_url('lots/' . $l['id'] . '/status') ?>" class="inline-form gap-top"><?= csrf_field() ?>
<select name="status" aria-label="Batch status"><option value="on_hold">Put on hold</option><option value="recalled">Recall</option><option value="active">Release (active)</option></select>
<input type="text" name="reason" placeholder="Reason (needed to hold or recall)" maxlength="255"><button class="btn small" type="submit">Save</button></form>
<p class="muted small">Held and recalled batches cannot be picked, transferred or used in production. Reservations already made stay until released.</p><?php endif ?></section>

<div class="cols">
<section class="panel"><h2>Where it came from</h2>
<?php foreach ($t['received'] as $r): ?><p><?= can('stock.view', (int) $r['warehouse_id']) || can('receive.post', (int) $r['warehouse_id']) ? '<a href="' . site_url('receipts/' . $r['id']) . '">' . esc($r['number']) . '</a>' : esc($r['number']) ?> <?= nice_date($r['received_on']) ?> at <?= esc($r['wh']) ?><?= $r['supplier'] ? ' from ' . esc($r['supplier']) : '' ?>: <?= qty((float) $r['qty_accepted'] + (float) $r['qty_quarantine']) ?> received<?= (float) $r['qty_rejected'] > 0 ? ', ' . qty($r['qty_rejected']) . ' rejected' : '' ?></p><?php endforeach ?>
<?php foreach ($t['made_by'] as $p): ?><p>Made by <?= can('stock.view', (int) $p['warehouse_id']) || can('production.manage', (int) $p['warehouse_id']) ? '<a href="' . site_url('production/runs/' . $p['id']) . '">' . esc($p['number']) . '</a>' : esc($p['number']) ?> on <?= nice_date($p['produced_on']) ?> at <?= esc($p['wh']) ?> (<?= qty($p['actual_qty']) ?>) <?= $p['status'] !== 'posted' ? badge($p['status']) : '' ?></p><?php endforeach ?>
<?php if ($t['made_from']): ?><h3>Made from</h3><ul><?php foreach ($t['made_from'] as $m): ?><li><a href="<?= site_url('lots/' . $m['id']) ?>"><?= esc($m['lot_no']) ?></a> <?= esc($m['sku'] . ' ' . $m['name']) ?><?= $m['supplier'] ? ' from ' . esc($m['supplier']) : '' ?></li><?php endforeach ?></ul><?php endif ?>
<?php if (! $t['received'] && ! $t['made_by']): ?><p class="muted">Recorded by <?= esc($l['source']) ?>.</p><?php endif ?></section>
<section class="panel"><h2>Where it is now</h2>
<?php if (! $t['in_stock'] && ! $t['in_transit']): ?><p class="muted">None left in stock.</p><?php endif ?>
<?php foreach ($t['in_stock'] as $s): ?><p><?= esc($s['wh']) ?> <span class="mono"><?= esc($s['bin']) ?></span> <?= esc($s['lot_no'] !== $l['lot_no'] ? '(' . $s['sku'] . ' ' . $s['lot_no'] . ')' : '') ?>: <?= qty($s['qty']) ?> <?= badge($s['status']) ?><?= (float) $s['reserved'] > 0 ? ' · ' . qty($s['reserved']) . ' reserved' : '' ?></p><?php endforeach ?>
<?php foreach ($t['in_transit'] as $s): ?><p>On transfer <a href="<?= site_url('transfers/' . $s['id']) ?>"><?= esc($s['number']) ?></a>: <?= qty($s['qty']) ?></p><?php endforeach ?></section>
</div>
<?php if ($t['used_in']): ?><h2>Used to make</h2><div class="table-wrap"><table><thead><tr><th>Batch made</th><th>Item</th><th>Run</th></tr></thead><tbody>
<?php foreach ($t['used_in'] as $u): ?><tr><td><a href="<?= site_url('lots/' . $u['id']) ?>"><?= esc($u['lot_no']) ?></a></td><td><?= esc($u['sku'] . ' ' . $u['name']) ?></td><td><?= can('stock.view', (int) $u['warehouse_id']) || can('production.manage', (int) $u['warehouse_id']) ? '<a href="' . site_url('production/runs/' . $u['run_id']) . '">' . esc($u['run']) . '</a>' : esc($u['run']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<h2>Customers who received it<?= $t['used_in'] ? ' (or anything made from it)' : '' ?></h2>
<?php if (! $t['customers']): ?><div class="panel empty">Not delivered to any customer.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>Town</th><th>Shipment</th><th>Order</th><th>Dispatched</th><th>Batch</th><th class="num">Quantity</th></tr></thead><tbody>
<?php foreach ($t['customers'] as $c): ?><tr><td><?= esc($c['customer']) ?></td><td><?= esc($c['phone']) ?></td><td><?= esc($c['town']) ?></td><td><?= can('stock.view', (int) $c['warehouse_id']) || can('sales.manage', (int) $c['warehouse_id']) ? '<a href="' . site_url('shipments/' . $c['shipment_id']) . '">' . esc($c['shipment']) . '</a>' : esc($c['shipment']) ?></td><td><?= esc($c['order_no']) ?></td><td><?= local_time($c['dispatched_at'], 'j M Y') ?></td><td><?= esc($c['sku'] . ' ' . $c['lot_no']) ?></td><td class="num"><?= qty($c['qty']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
