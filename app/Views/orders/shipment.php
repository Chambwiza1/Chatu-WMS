<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $wh = (int) $s['warehouse_id']; $can = can('dispatch.manage', $wh); $flow = ['allocated' => 'Stock reserved', 'picked' => 'Picked', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered']; $keys = array_keys($flow); $at = array_search($s['status'], $keys, true) ?>
<div class="page-head"><div><h1>Shipment <?= esc($s['number']) ?> <?= badge($s['status']) ?></h1><p class="sub"><?= esc($s['customer']) ?> · order <a href="<?= site_url('orders/' . $s['so_id']) ?>"><?= esc($s['order_no']) ?></a><?= $s['customer_ref'] ? ' (their ref ' . esc($s['customer_ref']) . ')' : '' ?> · from <?= esc($s['wh']) ?><?php if ($s['wave_id'] && ($wv = \App\Libraries\Core\Db::row('SELECT id, number, status FROM {pick_waves} WHERE id = ?', [$s['wave_id']]))): ?> · wave <?php if (can('dispatch.manage', (int) $s['warehouse_id'])): ?><a href="<?= site_url('waves/' . $wv['id']) ?>"><?= esc($wv['number']) ?></a><?php else: ?><?= esc($wv['number']) ?><?php endif ?><?php endif ?></p></div>
<div class="actions no-print"><a class="btn secondary" href="<?= site_url('shipments/' . $s['id'] . '/note') ?>" target="_blank" rel="noopener">Delivery note</a><button class="btn secondary" data-print>Print</button></div></div>
<?php if ($at !== false): ?><ol class="steps-inline"><?php foreach ($flow as $k => $l): $i = array_search($k, $keys, true) ?><li class="<?= $i < $at ? 'done' : ($i === $at ? 'on' : '') ?>"><?= $l ?></li><?php endforeach ?></ol><?php endif ?>
<section class="panel"><dl class="facts"><dt>Deliver to</dt><dd><?= esc($s['delivery_address'] ?: $s['town']) ?><?= $s['phone'] ? ' · ' . esc($s['phone']) : '' ?></dd>
<?php if ($s['trip_no']): ?><dt>Trip</dt><dd><a href="<?= site_url('fleet/trips/' . $s['trip_id']) ?>"><?= esc($s['trip_no']) ?></a></dd><?php endif ?>
<?php if ($s['packages']): ?><dt>Packages</dt><dd><?= (int) $s['packages'] ?><?= $s['weight_kg'] ? ', ' . qty($s['weight_kg']) . ' kg' : '' ?></dd><?php endif ?>
<?php if ($s['dispatched_at']): ?><dt>Dispatched</dt><dd><?= local_time($s['dispatched_at']) ?></dd><?php endif ?>
<?php if ($s['delivered_at']): ?><dt>Delivered</dt><dd><?= local_time($s['delivered_at']) ?>, received by <?= esc($s['pod_name']) ?><?= $s['pod_note'] ? ': ' . esc($s['pod_note']) : '' ?></dd><?php endif ?>
<?php if ($s['failed_reason']): ?><dt><?= $s['status'] === 'cancelled' ? 'Cancelled' : 'Failed' ?></dt><dd><?= esc($s['failed_reason']) ?></dd><?php endif ?>
<?php foreach ($files as $f): ?><dt>Proof of delivery</dt><dd><a href="<?= site_url('files/' . $f['id']) ?>" target="_blank" rel="noopener"><?= esc($f['filename']) ?></a></dd><?php endforeach ?></dl></section>

<?php if (in_array($s['status'], ['allocated', 'picked', 'packed'], true)): ?>
<h2><?= $s['status'] === 'allocated' ? 'Pick list' : 'Picked, in the DISPATCH bin' ?></h2>
<form method="post" action="<?= site_url('shipments/' . $s['id'] . '/pick') ?>"><?= csrf_field() ?><?= token_field() ?>
<div class="table-wrap"><table class="lines"><thead><tr><th>Bin</th><th>Item</th><th>Batch</th><th>Expiry</th><th>Serial</th><th class="num">Quantity</th><?php if ($s['status'] === 'allocated' && $can): ?><th>Picked</th><?php endif ?></tr></thead><tbody>
<?php foreach ($picks as $p): $it = \App\Libraries\Master\Items::get((int) $p['item_id']) ?><tr><td class="mono"><?= esc($p['bin_code']) ?></td><td><?= esc($it['sku'] . ' ' . $it['name']) ?></td><td><?= esc($p['lot_no']) ?></td><td><?= nice_date($p['expiry_date']) ?></td><td class="mono"><?= esc($p['serial_no']) ?></td><td class="num"><?= qty($p['qty'], (int) $it['decimals']) ?> <?= esc($it['uom']) ?></td>
<?php if ($s['status'] === 'allocated' && $can): ?><td><input class="n" type="number" step="any" min="0" max="<?= (float) $p['qty'] ?>" name="picked[<?= $p['id'] ?>]" placeholder="<?= qty($p['qty']) ?>" aria-label="Picked quantity"></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div>
<?php if ($s['status'] === 'allocated' && $can): ?><p class="muted small">Leave "Picked" empty when the full quantity was picked. A smaller number releases the rest, which stays on the order for a later shipment.</p>
<div class="actions"><button class="btn" type="submit">Confirm pick</button></div><?php endif ?></form>
<?php if (in_array($s['status'], ['picked', 'packed'], true) && $can): ?>
<form method="post" action="<?= site_url('shipments/' . $s['id'] . '/pack') ?>" class="panel stack"><?= csrf_field() ?><?= token_field() ?>
<div class="row2"><div class="field"><label for="packages">Packages (cartons, pallets)</label><input id="packages" type="number" name="packages" min="1" value="<?= (int) $s['packages'] ?: 1 ?>" required></div><div class="field"><label for="weight_kg">Weight (kg)</label><input id="weight_kg" type="number" step="any" min="0" name="weight_kg" value="<?= esc($s['weight_kg']) ?>"></div></div>
<div class="actions"><button class="btn secondary" type="submit"><?= $s['status'] === 'packed' ? 'Update packing' : 'Packed' ?></button><?php if (! $s['trip_id']): ?><button class="btn" type="submit" name="dispatch" value="1" data-confirm="Dispatch now? The goods leave stock.">Packed and dispatched</button><?php endif ?></div>
<?php if ($s['trip_id']): ?><p class="muted small">This shipment is on trip <?= esc($s['trip_no']) ?>: it is dispatched when the vehicle leaves.</p><?php endif ?></form><?php endif ?>
<?php if ($can): ?><details><summary>Cancel the shipment</summary><form method="post" action="<?= site_url('shipments/' . $s['id'] . '/cancel') ?>" class="inline-form"><?= csrf_field() ?><input name="why" placeholder="Why" required><button class="btn danger small" type="submit">Cancel shipment</button></form></details><?php endif ?>
<?php endif ?>

<?php if ($units): ?><h2>What left</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>Batch</th><th>Expiry</th><th>Serial</th><th class="num">Quantity</th><th class="num">Returned</th><?php if (can('stock.view_cost')): ?><th class="num">Cost</th><?php endif ?></tr></thead><tbody>
<?php foreach ($units as $u): ?><tr><td><?= esc($u['sku'] . ' ' . $u['name']) ?></td><td><?= $u['lot_id'] ? '<a href="' . site_url('lots/' . $u['lot_id']) . '">' . esc($u['lot_no']) . '</a>' : '' ?></td><td><?= nice_date($u['expiry_date']) ?></td><td class="mono"><?= esc($u['serial_no']) ?></td><td class="num"><?= qty($u['qty'], (int) $u['decimals']) ?></td><td class="num"><?= (float) $u['qty_returned'] ? qty($u['qty_returned'], (int) $u['decimals']) : '' ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($u['value'], false) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>

<?php if ($s['status'] === 'dispatched' && $can): ?>
<div class="cols">
<form method="post" action="<?= site_url('shipments/' . $s['id'] . '/deliver') ?>" enctype="multipart/form-data" class="panel stack"><?= csrf_field() ?><h2>Delivered</h2>
<div class="field"><label for="pod_name">Received by (name)</label><input id="pod_name" name="pod_name" required></div>
<div class="field"><label for="pod_note">Note</label><input id="pod_note" name="pod_note" placeholder="Condition, stamp, anything said"></div>
<div class="field"><label for="pod_file">Signed delivery note (photo or PDF)</label><input id="pod_file" type="file" name="pod_file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></div>
<div><button class="btn" type="submit">Record delivery</button></div></form>
<form method="post" action="<?= site_url('shipments/' . $s['id'] . '/fail') ?>" class="panel stack"><?= csrf_field() ?><?= token_field() ?><h2>Not delivered</h2>
<p class="muted small">Everything comes back into stock at the cost it left with, and the order can go out again.</p>
<div class="field"><label for="reason">Why</label><select id="reason" name="reason"><?php foreach ($failReasons as $r): ?><option value="<?= esc($r['code']) ?>"><?= esc($r['label']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="fstatus">Goods come back as</label><select id="fstatus" name="status"><option value="available">Available (to STOCK)</option><option value="quarantine">Quarantine (check them first)</option><option value="damaged">Damaged</option></select></div>
<div class="field"><label for="fnote">Note</label><input id="fnote" name="note"></div>
<div><button class="btn danger" type="submit">Record failed delivery</button></div></form></div>
<?php endif ?>

<?php if ($s['status'] === 'delivered' && $can && $units): ?>
<details class="panel"><summary>Customer return</summary>
<form method="post" action="<?= site_url('shipments/' . $s['id'] . '/return') ?>" class="stack wide"><?= csrf_field() ?><?= token_field() ?>
<div class="table-wrap"><table class="lines"><thead><tr><th>Item</th><th>Batch / serial</th><th class="num">Delivered</th><th>Coming back</th></tr></thead><tbody>
<?php foreach ($units as $u): $left = (float) $u['qty'] - (float) $u['qty_returned']; if ($left <= 0) continue ?><tr><td><?= esc($u['sku']) ?></td><td><?= esc(trim($u['lot_no'] . ' ' . $u['serial_no'])) ?></td><td class="num"><?= qty($left) ?></td><td><input class="n" type="number" step="any" min="0" max="<?= $left ?>" name="ret[<?= $u['id'] ?>]" aria-label="Returned quantity"></td></tr><?php endforeach ?>
</tbody></table></div>
<div class="row2"><div class="field"><label for="rreason">Reason</label><select id="rreason" name="reason"><?php foreach ($returnReasons as $r): ?><option value="<?= esc($r['code']) ?>"><?= esc($r['label']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="rstatus">Put it in</label><select id="rstatus" name="status"><option value="quarantine">Quarantine (inspect first)</option><option value="damaged">Damaged</option><option value="available">Available stock</option></select></div></div>
<div class="field"><label for="rnote">Note</label><input id="rnote" name="note"></div>
<div><button class="btn" type="submit">Receive the return</button></div></form></details>
<?php endif ?>
<?php if ($returns): ?><h2>Returns</h2><ul><?php foreach ($returns as $r): ?><li><?= esc($r['number']) ?> (<?= esc(str_replace('_', ' ', $r['kind'])) ?>, <?= esc($r['reason_code']) ?>) <?= local_time($r['posted_at']) ?> by <?= esc($r['user']) ?><?= $r['notes'] ? ': ' . esc($r['notes']) : '' ?></li><?php endforeach ?></ul><?php endif ?>
<?= $this->endSection() ?>
