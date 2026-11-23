<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Purchase orders</h1></div><div class="actions"><?php if (can('purchase.manage')): ?><a class="btn" href="<?= site_url('purchase-orders/new') ?>">New purchase order</a><?php endif ?></div></div>
<div class="pill-filter"><?php foreach (['' => 'All', 'open' => 'Open', 'overdue' => 'Overdue', 'draft' => 'Drafts', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled'] as $k => $l): ?><a href="<?= site_url('purchase-orders' . ($k !== '' ? '?status=' . $k : '')) ?>" class="<?= $st === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></div>
<?php if (! $rows): ?><div class="panel empty">No purchase orders here.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Number</th><th>Supplier</th><th>For</th><th>Ordered</th><th>Expected</th><th>Status</th><?php if (can('stock.view_cost')): ?><th class="num">Value</th><?php endif ?></tr></thead><tbody>
<?php foreach ($rows as $p): ?><tr><td><a href="<?= site_url('purchase-orders/' . $p['id']) ?>"><?= esc($p['number']) ?></a></td><td><?= esc($p['supplier']) ?></td><td><?= esc($p['wh']) ?></td><td><?= nice_date($p['order_date']) ?></td>
<td class="<?= $p['expected_date'] && $p['expected_date'] < date('Y-m-d') && in_array($p['status'], ['open', 'partly_received'], true) ? 'bad-text' : '' ?>"><?= nice_date($p['expected_date']) ?></td><td><?= badge($p['status']) ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= \App\Libraries\Master\Fx::money($p['value'], $p['currency']) ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
