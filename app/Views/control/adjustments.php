<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Adjustments</h1></div><div class="actions"><?php if (can('adjust.request')): ?><a class="btn" href="<?= site_url('adjustments/new') ?>">New adjustment</a><?php endif ?></div></div>
<div class="pill-filter"><?php foreach (['' => 'All', 'pending' => 'Waiting for approval', 'posted' => 'Posted', 'rejected' => 'Rejected'] as $k => $l): ?><a href="<?= site_url('adjustments' . ($k !== '' ? '?status=' . $k : '')) ?>" class="<?= $st === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></div>
<?php if (! $rows): ?><div class="panel empty">No adjustments here.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Number</th><th>Warehouse</th><th>Reason</th><th>Raised by</th><th>Decided by</th><th>Status</th><?php if (can('stock.view_cost')): ?><th class="num">Value</th><?php endif ?></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr><td><a href="<?= site_url('adjustments/' . $a['id']) ?>"><?= esc($a['number']) ?></a></td><td><?= esc($a['wh']) ?></td><td><?= esc($a['reason']) ?></td><td><?= esc($a['created_name']) ?> <span class="muted small"><?= local_time($a['created_at'], 'j M') ?></span></td><td><?= esc($a['decided_name']) ?></td><td><?= badge($a['status']) ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($a['value']) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
