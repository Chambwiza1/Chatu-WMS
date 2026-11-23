<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Stock: <?= esc(strtolower(state_label($status))) ?></h1><p class="sub"><?= esc(wh_name($wh)) ?></p></div><div class="actions"><a class="btn secondary" href="<?= site_url('stock') ?>">All stock</a></div></div>
<?php if ($status === 'quarantine'): ?><p class="note">Goods held for inspection. Release them to available stock (needs approval rights), move them to DAMAGED, or return them to the supplier.</p><?php endif ?>
<?php if (! $rows): ?><div class="panel empty">Nothing here.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Item</th><th>Description</th><th>Bin</th><th>Batch</th><th>Expiry</th><th>Serial</th><th class="num">Quantity</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= sku_plate($r['sku'], site_url('stock/item/' . $r['item_id'])) ?></td><td><?= esc($r['name']) ?></td><td class="mono"><?= esc($r['bin']) ?></td><td><?= esc($r['lot_no']) ?></td><td><?= nice_date($r['expiry_date']) ?></td><td class="mono"><?= esc($r['serial_no']) ?></td>
<td class="num"><?= qty($r['qty'], (int) $r['decimals']) ?> <?= esc($r['uom']) ?></td>
<td class="actions-cell"><?php if (can('inventory.move', $wh)): ?><a class="btn small secondary" href="<?= site_url('moves/new?stock_id=' . $r['id']) ?>">Move or release</a><?php endif ?>
<?php if (can('receive.post', $wh)): ?> <a class="btn small secondary" href="<?= site_url('supplier-returns/new?stock_id=' . $r['id']) ?>">Return to supplier</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
