<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Bin <span class="mono"><?= esc($bin['code']) ?></span> <?= $bin['status'] !== 'active' ? badge($bin['status']) : '' ?></h1>
<p class="sub"><?= esc($bin['wh_code'] . ' ' . $bin['wh_name']) ?> · <?= esc(\App\Libraries\Master\Warehouses::BIN_TYPES[$bin['type']] ?? $bin['type']) ?><?= $bin['zone'] ? ' · ' . esc($bin['zone_code'] . ' ' . $bin['zone']) : '' ?><?= $bin['pickable'] ? ' · picked from' : '' ?></p></div>
<div class="actions"><button class="btn secondary" data-print>Print</button></div></div>
<?php $mv = can('inventory.move', (int) $bin['warehouse_id']) ?>
<?php if (! $rows): ?><div class="panel empty">This bin is empty.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Item</th><th>Batch</th><th>Expiry</th><th>Serial</th><th>Status</th><th class="num">Quantity</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $s): ?><tr><td><?= sku_plate($s['sku'], site_url('stock/item/' . $s['item_id'])) ?> <?= esc($s['name']) ?></td>
<td><?= $s['lot_id'] ? '<a href="' . site_url('lots/' . $s['lot_id']) . '">' . esc($s['lot_no']) . '</a>' : '' ?></td><td><?= nice_date($s['expiry_date']) ?></td>
<td><?= $s['serial_id'] ? '<a class="mono" href="' . site_url('serials/' . $s['serial_id']) . '">' . esc($s['serial_no']) . '</a>' : '' ?></td>
<td><?= $s['status'] !== 'available' ? badge($s['status']) : '' ?></td><td class="num"><?= qty($s['qty'], (int) $s['decimals']) ?> <?= esc($s['uom']) ?></td><td class="no-print"><?php if ($mv): ?><a href="<?= site_url('moves/new?stock_id=' . $s['id']) ?>">Move</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
