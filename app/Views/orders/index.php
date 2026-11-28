<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Sales orders</h1></div><div class="actions"><?php if (can('sales.manage')): ?><a class="btn" href="<?= site_url('orders/new') ?>">New order</a><?php endif ?><?php if (can('data.export')): ?><a class="btn secondary" href="<?= site_url('reports/dispatches') ?>">Export for invoicing</a><?php endif ?></div></div>
<div class="pill-filter"><?php foreach (['' => 'All', 'open' => 'Open', 'draft' => 'Drafts', 'to_pick' => 'To pick', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $k => $l): ?><a href="<?= site_url('orders' . ($k !== '' ? '?status=' . $k : '')) ?>" class="<?= $st === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></div>
<form method="get" class="filters"><input type="hidden" name="status" value="<?= esc($st) ?>"><div class="field"><label for="q">Find</label><input id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Order, customer or their reference"></div><button class="btn secondary" type="submit">Find</button></form>
<?= view('partials/saved_filters') ?>
<?php if (! $rows): ?><div class="panel empty">No orders here.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>From</th><th>Date</th><th>Required</th><th>Shipped</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
<?php foreach ($rows as $o): ?><tr><td><a href="<?= site_url('orders/' . $o['id']) ?>"><?= esc($o['number']) ?></a><?= $o['customer_ref'] ? ' <span class="muted small">' . esc($o['customer_ref']) . '</span>' : '' ?></td><td><?= esc($o['customer']) ?></td><td><?= esc($o['wh']) ?></td><td><?= nice_date($o['order_date']) ?></td>
<td class="<?= $o['required_date'] && $o['required_date'] < date('Y-m-d') && in_array($o['status'], ['confirmed', 'in_progress'], true) ? 'bad-text' : '' ?>"><?= nice_date($o['required_date']) ?></td><td><?= (float) $o['q'] > 0 ? round(100 * (float) $o['s'] / (float) $o['q']) . '%' : '' ?></td><td><?= badge($o['status']) ?></td><td class="num"><?= money($o['amount']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
