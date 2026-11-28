<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $cost = can('stock.view_cost') ?>
<div class="page-head"><div><h1>Run <?= esc($run['number']) ?> <?= badge($run['status']) ?></h1><p class="sub"><?= esc($run['recipe_code'] . ' ' . $run['recipe_name']) ?> · <?= esc($run['wh_code']) ?> · <?= nice_date($run['produced_on']) ?></p></div><div class="actions no-print"><?php if ($run['lot_no']): ?><a class="btn secondary" href="<?= site_url('labels/run/' . $run['id']) ?>">Batch labels</a><?php endif ?><button class="btn secondary" data-print>Print</button></div></div>
<dl class="kpis"><div><dt>Made</dt><dd><?= qty($run['actual_qty'], (int) $run['decimals']) ?> <?= esc($run['uom']) ?></dd></div><div><dt>Expected</dt><dd><?= qty($run['expected_qty'], (int) $run['decimals']) ?></dd></div><div><dt>Yield</dt><dd><?= round(100 * (float) $run['actual_qty'] / max(0.001, (float) $run['expected_qty']), 1) ?>%</dd></div>
<?php if ($cost): ?><div><dt>Materials</dt><dd><?= money($run['material_cost']) ?></dd></div><div><dt>Other cost</dt><dd><?= money($run['other_cost']) ?></dd></div><div class="hero"><dt>Cost each</dt><dd><?= money($run['unit_cost']) ?></dd></div><?php endif ?></dl>
<section class="panel"><dl class="facts"><dt>Output</dt><dd><?= sku_plate($run['sku']) ?> <?= esc($run['item_name']) ?> into <span class="mono"><?= esc($run['bin_code']) ?></span><?= $run['output_status'] !== 'available' ? ' ' . badge($run['output_status']) : '' ?></dd>
<?php if ($run['lot_no']): ?><dt>Batch</dt><dd><a href="<?= site_url('lots/' . $run['lot_id']) ?>"><?= esc($run['lot_no']) ?></a><?= $run['expiry_date'] ? ', expires ' . nice_date($run['expiry_date']) : '' ?></dd><?php endif ?>
<dt>Posted</dt><dd><?= local_time($run['posted_at']) ?> by <?= esc($run['posted_name']) ?></dd><?php if ($run['notes']): ?><dt>Notes</dt><dd><?= esc($run['notes']) ?></dd><?php endif ?>
<?php if ($run['status'] === 'voided'): ?><dt>Voided</dt><dd><?= local_time($run['voided_at']) ?>: <?= esc($run['void_reason']) ?></dd><?php endif ?></dl></section>
<h2>Materials used</h2>
<div class="table-wrap"><table><thead><tr><th>Material</th><th>From bin</th><th>Batch</th><th>Expiry</th><th class="num">Recipe said</th><th class="num">Used</th><?php if ($cost): ?><th class="num">Cost (FIFO)</th><?php endif ?></tr></thead><tbody>
<?php foreach ($run['inputs'] as $i): ?><tr><td><?= esc($i['sku'] . ' ' . $i['name']) ?></td><td class="mono"><?= esc($i['bin_code']) ?></td><td><?= $i['lot_id'] ? '<a href="' . site_url('lots/' . $i['lot_id']) . '">' . esc($i['lot_no']) . '</a>' : '' ?><?= $i['serial_no'] ? ' <span class="mono">' . esc($i['serial_no']) . '</span>' : '' ?></td><td><?= nice_date($i['expiry_date']) ?></td>
<td class="num"><?= (float) $i['planned_qty'] ? qty($i['planned_qty'], (int) $i['decimals']) : '' ?></td><td class="num"><?= qty($i['qty'], (int) $i['decimals']) ?> <?= esc($i['uom']) ?></td><?php if ($cost): ?><td class="num"><?= money($i['value'], false) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div>
<?php if ($run['status'] === 'posted' && can('production.manage', (int) $run['warehouse_id'])): ?>
<details class="no-print"><summary>Void this run</summary><p class="muted small">Possible only while everything it made is still free in this warehouse. The output leaves at its own cost; the materials go back to their bins and batches at the cost they were used at.</p>
<form method="post" action="<?= site_url('production/runs/' . $run['id'] . '/void') ?>" class="inline-form"><?= csrf_field() ?><?= token_field() ?><input name="reason" placeholder="Why" required><button class="btn danger small" type="submit">Void run</button></form></details><?php endif ?>
<?= view('partials/activity', ['type' => 'production_run', 'id' => $run['id']]) ?>
<?= $this->endSection() ?>
