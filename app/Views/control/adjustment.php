<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $wh = (int) $a['warehouse_id']; $cost = can('stock.view_cost') ?>
<div class="page-head"><div><h1>Adjustment <?= esc($a['number']) ?> <?= badge($a['status']) ?></h1><p class="sub"><?= esc($a['wh']) ?> · <?= esc($a['reason']) ?></p></div></div>
<section class="panel"><dl class="facts"><dt>Raised</dt><dd><?= local_time($a['created_at']) ?> by <?= esc($a['created_name']) ?></dd><dt>What happened</dt><dd><?= esc($a['notes']) ?></dd>
<?php if ($a['decided_at']): ?><dt><?= $a['status'] === 'posted' ? 'Posted' : 'Rejected' ?></dt><dd><?= local_time($a['decided_at']) ?><?= $a['decided_name'] ? ' by ' . esc($a['decided_name']) : ' automatically' ?><?= $a['decision_note'] ? ': ' . esc($a['decision_note']) : '' ?></dd><?php endif ?></dl></section>
<div class="table-wrap"><table><thead><tr><th>Item</th><th>Bin</th><th>Batch</th><th>Serial</th><th>Status</th><th class="num">Change</th><?php if ($cost): ?><th class="num">Value</th><?php endif ?></tr></thead><tbody>
<?php foreach ($lines as $l): ?><tr><td><?= esc($l['sku'] . ' ' . $l['name']) ?></td><td class="mono"><?= esc($l['bin']) ?></td><td><?= esc($l['lot'] ?: $l['lot_no']) ?></td><td class="mono"><?= esc($l['serial_no']) ?></td><td><?= badge($l['status']) ?></td><td class="num <?= (float) $l['qty'] < 0 ? 'bad-text' : '' ?>"><?= (float) $l['qty'] > 0 ? '+' : '' ?><?= qty($l['qty'], (int) $l['decimals']) ?> <?= esc($l['uom']) ?></td><?php if ($cost): ?><td class="num"><?= money($l['value'], false) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody><?php if ($cost): ?><tfoot><tr><td colspan="6">Total (absolute)</td><td class="num"><?= money($a['value']) ?></td></tr></tfoot><?php endif ?></table></div>
<?php if ($a['status'] === 'pending'): ?><div class="cols">
<?php if (! $mine && can('stock.approve', $wh)): ?><form method="post" action="<?= site_url('adjustments/' . $a['id'] . '/approve') ?>" class="panel stack"><?= csrf_field() ?><h2>Approve</h2><div class="field"><label for="anote">Note</label><input id="anote" name="note"></div><div><button class="btn" type="submit">Approve and post</button></div></form><?php endif ?>
<?php if ($mine || can('stock.approve', $wh)): ?><form method="post" action="<?= site_url('adjustments/' . $a['id'] . '/reject') ?>" class="panel stack"><?= csrf_field() ?><h2><?= $mine ? 'Withdraw' : 'Reject' ?></h2><div class="field"><label for="rnote">Why</label><input id="rnote" name="note" required></div><div><button class="btn danger" type="submit"><?= $mine ? 'Withdraw' : 'Reject' ?></button></div></form><?php endif ?>
</div><?php if ($mine): ?><p class="muted">You raised this adjustment, so someone else must approve it.</p><?php endif ?><?php endif ?>
<?= $this->endSection() ?>
