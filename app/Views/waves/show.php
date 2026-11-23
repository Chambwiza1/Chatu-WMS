<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Wave <?= esc($w['number']) ?> <?= badge($w['status']) ?></h1><p class="sub"><?= esc($w['wh_code'] . ' ' . $w['wh_name']) ?> · <?= count($shipments) ?> shipment(s) · created <?= local_time($w['created_at']) ?> by <?= esc($w['created_name']) ?><?= $w['picked_at'] ? ' · picked ' . local_time($w['picked_at']) . ' by ' . esc($w['picked_name']) : '' ?><?= $w['note'] ? ' · ' . esc($w['note']) : '' ?></p></div>
<div class="actions no-print"><button class="btn secondary" data-print>Print pick list</button><?php if ($w['status'] === 'open'): ?><form method="post" action="<?= site_url('waves/' . $w['id'] . '/cancel') ?>" class="inline-form"><?= csrf_field() ?><button class="btn secondary" type="submit" data-confirm="Cancel this wave? The shipments stay allocated and can be picked one by one or in another wave.">Cancel wave</button></form><?php endif ?></div></div>

<?php if ($totals): ?><section class="panel"><h2>Walk: take from each bin</h2>
<div class="table-wrap"><table><thead><tr><th>Bin</th><th>Item</th><th>Batch</th><th class="num">Take</th><th class="num">For shipments</th><th class="print-only">✓</th></tr></thead><tbody>
<?php foreach ($totals as $t): ?><tr><td class="mono"><?= esc($t['bin_code']) ?></td><td><?= esc($t['sku'] . ' ' . $t['name']) ?></td><td><?= esc($t['lot_no']) ?></td><td class="num"><b><?= qty($t['qty'], (int) $t['decimals']) ?></b> <?= esc($t['uom']) ?></td><td class="num"><?= (int) $t['shipments'] ?></td><td class="print-only">☐</td></tr><?php endforeach ?>
</tbody></table></div></section><?php endif ?>

<section class="panel"><h2><?= $w['status'] === 'open' ? 'Sort into shipments and confirm' : 'Shipments' ?></h2>
<?php if ($w['status'] === 'open' && $picks): ?>
<form method="post" action="<?= site_url('waves/' . $w['id'] . '/confirm') ?>"><?= csrf_field() ?><?= token_field() ?>
<p class="muted small">Leave "Found" empty when the full quantity was picked. Enter what you found when a bin was short; that shipment is picked short. A shipment where nothing was found leaves the wave and stays allocated.</p>
<div class="table-wrap"><table><thead><tr><th>Bin</th><th>Item</th><th>Batch / serial</th><th>Shipment</th><th class="num">Reserved</th><th class="num no-print">Found</th></tr></thead><tbody>
<?php foreach ($picks as $p): ?><tr><td class="mono"><?= esc($p['bin_code']) ?></td><td><?= esc($p['sku'] . ' ' . $p['name']) ?></td><td><?= esc($p['lot_no']) ?><?= $p['serial_no'] ? ' <span class="mono">' . esc($p['serial_no']) . '</span>' : '' ?><?= $p['expiry_date'] ? ' <span class="muted small">exp ' . nice_date($p['expiry_date']) . '</span>' : '' ?></td>
<td><a href="<?= site_url('shipments/' . $p['shipment_id']) ?>"><?= esc($p['shipment_no']) ?></a></td><td class="num"><?= qty($p['qty'], (int) $p['decimals']) ?> <?= esc($p['uom']) ?></td>
<td class="num no-print"><input class="n" type="number" step="any" min="0" max="<?= esc((string) (float) $p['qty'], 'attr') ?>" name="picked[<?= (int) $p['id'] ?>]" aria-label="Found"></td></tr><?php endforeach ?>
</tbody></table></div>
<div class="gap-top no-print"><button class="btn" type="submit" data-confirm="Confirm the picks? The goods move to the DISPATCH bin.">Confirm picks</button></div></form>
<?php endif ?>
<div class="table-wrap gap-top"><table><thead><tr><th>Shipment</th><th>Order</th><th>Customer</th><th>Town</th><th>Status</th></tr></thead><tbody>
<?php foreach ($shipments as $s): ?><tr><td><a href="<?= site_url('shipments/' . $s['id']) ?>"><?= esc($s['number']) ?></a></td><td><?= esc($s['order_no']) ?></td><td><?= esc($s['customer']) ?></td><td><?= esc($s['town']) ?></td><td><?= badge($s['status']) ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<?= $this->endSection() ?>
