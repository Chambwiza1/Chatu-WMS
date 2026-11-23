<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $from = (int) $t['from_warehouse_id']; $to = (int) $t['to_warehouse_id']; $flow = ['draft', 'allocated', 'in_transit', 'partly_received', 'received']; $at = array_search($t['status'], $flow, true) ?>
<div class="page-head"><div><h1>Transfer <?= esc($t['number']) ?> <?= badge($t['status']) ?></h1><p class="sub"><?= esc($t['from_code'] . ' ' . $t['from_name']) ?> → <?= esc($t['to_code'] . ' ' . $t['to_name']) ?></p></div>
<div class="actions no-print"><button class="btn secondary" data-print>Print</button></div></div>
<?php if ($t['status'] !== 'cancelled'): ?><ol class="steps-inline"><?php foreach (['draft' => 'Draft', 'allocated' => 'Stock reserved', 'in_transit' => 'In transit', 'received' => 'Received'] as $k => $l): $i = array_search($k, $flow, true) ?><li class="<?= $at !== false && $i < $at ? 'done' : ($k === $t['status'] || ($k === 'in_transit' && $t['status'] === 'partly_received') ? 'on' : '') ?>"><?= $l ?></li><?php endforeach ?></ol><?php endif ?>
<section class="panel"><dl class="facts"><dt>Raised</dt><dd><?= local_time($t['created_at']) ?> by <?= esc($t['created_name']) ?></dd><?php if ($t['needed_by']): ?><dt>Needed by</dt><dd><?= nice_date($t['needed_by']) ?></dd><?php endif ?>
<?php if ($t['dispatched_at']): ?><dt>Left</dt><dd><?= local_time($t['dispatched_at']) ?> by <?= esc($t['dispatched_name']) ?><?= $t['trip_no'] ? ' on trip <a href="' . site_url('fleet/trips/' . $t['trip_id']) . '">' . esc($t['trip_no']) . '</a>' : '' ?></dd><?php elseif ($t['trip_no']): ?><dt>Trip</dt><dd><a href="<?= site_url('fleet/trips/' . $t['trip_id']) ?>"><?= esc($t['trip_no']) ?></a></dd><?php endif ?>
<?php if ($t['notes']): ?><dt>Notes</dt><dd><?= nl2br(esc($t['notes'])) ?></dd><?php endif ?></dl></section>
<div class="table-wrap"><table><thead><tr><th>Item</th><th>Description</th><th class="num">Asked</th><th class="num">Sent</th><th class="num">Received</th><th class="num">Damaged</th><th class="num">Rejected</th><th class="num">Missing</th><?php if (can('stock.view_cost')): ?><th class="num">Value sent</th><?php endif ?></tr></thead><tbody>
<?php foreach ($lines as $l): $d = (int) $l['decimals'] ?><tr><td class="mono"><?= esc($l['sku']) ?></td><td><?= esc($l['name']) ?></td><td class="num"><?= qty($l['qty'], $d) ?> <?= esc($l['uom']) ?><?= pack_hint($l['qty'], $l['pack_unit'], $l['pack_factor']) ?></td><td class="num"><?= qty($l['qty_dispatched'], $d) ?></td><td class="num"><?= qty($l['qty_received'], $d) ?></td><td class="num"><?= (float) $l['qty_damaged'] ? qty($l['qty_damaged'], $d) : '' ?></td><td class="num"><?= (float) $l['qty_rejected'] ? qty($l['qty_rejected'], $d) : '' ?></td><td class="num<?= (float) $l['qty_missing'] ? ' bad-text' : '' ?>"><?= (float) $l['qty_missing'] ? qty($l['qty_missing'], $d) : '' ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($l['value_sent'], false) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div>

<?php if ($t['status'] === 'draft' && can('transfer.manage', $from)): ?>
<div class="actions no-print"><form method="post" action="<?= site_url('transfers/' . $t['id'] . '/allocate') ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit">Reserve stock</button></form>
<details><summary>Cancel</summary><form method="post" action="<?= site_url('transfers/' . $t['id'] . '/cancel') ?>" class="inline-form"><?= csrf_field() ?><input name="why" placeholder="Why" required><button class="btn danger small" type="submit">Cancel transfer</button></form></details></div>
<?php endif ?>

<?php if ($t['status'] === 'allocated'): ?>
<h2>Pick list</h2>
<div class="table-wrap"><table><thead><tr><th>Bin</th><th>Item</th><th>Batch</th><th>Expiry</th><th>Serial</th><th class="num">Quantity</th><th class="print-only">Picked</th></tr></thead><tbody>
<?php foreach ($alloc as $a): ?><tr><td class="mono"><?= esc($a['bin_code']) ?></td><td class="mono"><?= esc(\App\Libraries\Master\Items::get((int) $a['item_id'])['sku']) ?></td><td><?= esc($a['lot_no']) ?></td><td><?= nice_date($a['expiry_date']) ?></td><td class="mono"><?= esc($a['serial_no']) ?></td><td class="num"><?= qty($a['qty']) ?></td><td class="print-only">______</td></tr><?php endforeach ?>
</tbody></table></div>
<?php if (can('transfer.manage', $from)): ?><div class="actions no-print">
<form method="post" action="<?= site_url('transfers/' . $t['id'] . '/dispatch') ?>" class="inline"><?= csrf_field() ?><?= token_field() ?><button class="btn" type="submit" data-confirm="Dispatch now? The goods leave <?= esc($t['from_code'], 'attr') ?> and are in transit.">Dispatch</button></form>
<form method="post" action="<?= site_url('transfers/' . $t['id'] . '/unallocate') ?>" class="inline"><?= csrf_field() ?><button class="btn secondary" type="submit">Release the reservation</button></form>
<details><summary>Cancel</summary><form method="post" action="<?= site_url('transfers/' . $t['id'] . '/cancel') ?>" class="inline-form"><?= csrf_field() ?><input name="why" placeholder="Why" required><button class="btn danger small" type="submit">Cancel transfer</button></form></details></div><?php endif ?>
<?php if ($t['trip_id']): ?><p class="muted">On trip <?= esc($t['trip_no']) ?>: it is dispatched when the vehicle leaves.</p><?php endif ?>
<?php endif ?>

<?php if (in_array($t['status'], ['in_transit', 'partly_received'], true)): ?>
<h2>What is on the way</h2>
<?php if (can('transfer.receive', $to)): ?>
<form method="post" action="<?= site_url('transfers/' . $t['id'] . '/receive') ?>" class="stack wide"><?= csrf_field() ?><?= token_field() ?>
<p class="muted small">Count what arrived. Good units go to the bin you choose; damaged units to DAMAGED; rejected units to QUARANTINE for a decision. Tick "final" when nothing more will come: what is still open is then recorded as missing and written off at the cost it left with.</p>
<div class="table-wrap"><table class="lines"><thead><tr><th>Item</th><th>Batch</th><th>Serial</th><th class="num">Still on the way</th><th>Good</th><th>Damaged</th><th>Rejected</th><th>Put good units in</th></tr></thead><tbody>
<?php foreach ($transit as $x): if ((float) $x['qty_open'] <= 0) continue ?><tr><td><?= esc($x['sku'] . ' ' . $x['name']) ?></td><td><?= esc($x['lot_no']) ?><?= $x['expiry_date'] ? ' <span class="muted small">' . nice_date($x['expiry_date']) . '</span>' : '' ?></td><td class="mono"><?= esc($x['serial_no']) ?></td><td class="num"><?= qty($x['qty_open'], (int) $x['decimals']) ?></td>
<td><input class="n good" type="number" step="any" min="0" max="<?= (float) $x['qty_open'] ?>" data-max="<?= (float) $x['qty_open'] ?>" name="rows[<?= $x['id'] ?>][good]" aria-label="Good"></td><td><input class="n" type="number" step="any" min="0" name="rows[<?= $x['id'] ?>][damaged]" aria-label="Damaged"></td><td><input class="n" type="number" step="any" min="0" name="rows[<?= $x['id'] ?>][rejected]" aria-label="Rejected"></td>
<td><select name="rows[<?= $x['id'] ?>][bin_id]" aria-label="Bin"><?php foreach ($bins as $b): ?><option value="<?= $b['id'] ?>" <?= (int) $b['id'] === (int) $toW['receiving_bin_id'] ? 'selected' : '' ?>><?= esc($b['code']) ?></option><?php endforeach ?></select></td></tr><?php endforeach ?>
</tbody></table></div>
<div class="line-actions"><button class="btn small secondary" type="button" data-fill="input.good">Everything arrived in good order</button></div>
<div class="row2"><div class="field"><label for="notes">Notes</label><input id="notes" name="notes" placeholder="Needed if anything is missing"></div><div class="field"><label class="check"><input type="checkbox" name="final" value="1"> Final: nothing more will come</label></div></div>
<div><button class="btn" type="submit">Record receipt</button></div></form>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Item</th><th>Batch</th><th>Serial</th><th class="num">On the way</th></tr></thead><tbody><?php foreach ($transit as $x): if ((float) $x['qty_open'] <= 0) continue ?><tr><td><?= esc($x['sku']) ?></td><td><?= esc($x['lot_no']) ?></td><td class="mono"><?= esc($x['serial_no']) ?></td><td class="num"><?= qty($x['qty_open']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php endif ?>
<?php if ($receipts): ?><h2>Receipts</h2><ul><?php foreach ($receipts as $r): ?><li><?= local_time($r['received_at']) ?> by <?= esc($r['user']) ?><?= $r['is_final'] ? ' (final)' : '' ?><?= $r['notes'] ? ': ' . esc($r['notes']) : '' ?></li><?php endforeach ?></ul><?php endif ?>
<?= $this->endSection() ?>
