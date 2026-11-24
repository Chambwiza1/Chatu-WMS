<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $d = (int) $item['decimals'] ?>
<div class="page-head"><div><h1><?= sku_plate($item['sku']) ?> <?= esc($item['name']) ?></h1>
<p class="sub"><?= esc(\App\Libraries\Master\Items::KINDS[$item['kind']] ?? $item['kind']) ?> · <?= esc(\App\Libraries\Master\Items::TRACKING[$item['tracking']]) ?><?= $item['expiry_required'] ? ' with expiry dates' : '' ?> · counted in <?= esc($item['uom']) ?><?= $item['category'] ? ' · ' . esc($item['category']) : '' ?></p></div>
<div class="actions"><form method="get" action="<?= site_url('labels/items') ?>" class="inline-form"><input type="hidden" name="ids[]" value="<?= (int) $item['id'] ?>"><label class="sr" for="copies">Copies</label><input id="copies" class="n" type="number" name="copies" value="1" min="1" max="500"><label class="check"><input type="checkbox" name="price" value="1"> price</label><?php if (\App\Libraries\Master\Items::units((int) $item['id'])): ?><label class="check"><input type="checkbox" name="packs" value="1" checked> packs</label><?php endif ?><button class="btn secondary" type="submit">Print labels</button></form>
<?php if (can('item.update')): ?><a class="btn secondary" href="<?= site_url('items/' . $item['id']) ?>">Edit item</a><?php endif ?></div></div>
<?php if ($packs = \App\Libraries\Master\Items::units((int) $item['id'])): ?><p class="muted">Packs: <?= esc(implode(', ', array_map(static fn ($u) => $u['name'] . ' = ' . (float) $u['factor'] . ' ' . $item['uom'] . ($u['barcode'] ? ' (barcode ' . $u['barcode'] . ')' : ''), $packs))) ?></p><?php endif ?>
<?php if ($value !== null && $value): ?><dl class="kpis"><?php foreach ($value as $v): ?><div><dt>Value at <?= esc($v['wh']) ?></dt><dd><?= money($v['value']) ?></dd><dd class="muted small"><?= qty($v['qty'], $d) ?> <?= esc($item['uom']) ?>, <?= money((float) $v['value'] / max(0.001, (float) $v['qty'])) ?> each</dd></div><?php endforeach ?></dl><?php endif ?>
<?php if ($transit > 0): ?><p class="note"><?= qty($transit, $d) ?> <?= esc($item['uom']) ?> on transfers between warehouses (in neither warehouse's stock until received). <a href="<?= site_url('reports/transit') ?>">Goods in transit</a></p><?php endif ?>
<h2>Where it is</h2>
<?php if (! $rows): ?><div class="panel empty">None in stock in your warehouses.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Warehouse</th><th>Bin</th><th>Batch</th><th>Expiry</th><th>Serial</th><th>Status</th><th class="num">Quantity</th><th class="num">Reserved</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= esc($r['wh']) ?></td><td class="mono"><?= esc($r['bin']) ?><?= ! $r['pickable'] ? ' <span class="muted small">(' . esc($r['bin_type']) . ')</span>' : '' ?></td>
<td><?= $r['lot_no'] ? '<a href="' . site_url('lots/' . $r['lot_id']) . '">' . esc($r['lot_no']) . '</a>' . ($r['lot_status'] !== 'active' ? ' ' . badge($r['lot_status']) : '') : '' ?></td>
<td class="<?= $r['expiry_date'] && $r['expiry_date'] < date('Y-m-d') ? 'bad-text' : '' ?>"><?= nice_date($r['expiry_date']) ?></td><td class="mono"><?= $r['serial_no'] ? '<a href="' . site_url('serials/' . $r['serial_id']) . '">' . esc($r['serial_no']) . '</a>' : '' ?></td>
<td><?= badge($r['status']) ?></td><td class="num"><?= qty($r['qty'], $d) ?></td><td class="num"><?= (float) $r['qty_allocated'] > 0 ? qty($r['qty_allocated'], $d) : '' ?></td>
<td><?php if (can('inventory.move', (int) $r['warehouse_id']) && (float) $r['qty'] > (float) $r['qty_allocated']): ?><a class="btn small secondary" href="<?= site_url('moves/new?stock_id=' . $r['id']) ?>">Move</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<h2>Latest movements</h2>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Warehouse</th><th>Movement</th><th>From</th><th>To</th><th>Batch / serial</th><th class="num">Quantity</th><th>By</th></tr></thead><tbody>
<?php foreach ($moves as $m): ?><tr><td class="nowrap"><?= local_time($m['at']) ?></td><td><?= esc($m['wh']) ?></td><td><?= esc(str_replace('_', ' ', $m['type'])) ?> <span class="muted small"><?= esc(($m['doc_type'] ?? '') . ' ' . ($m['doc_id'] ?? '')) ?></span></td>
<td class="mono"><?= esc($m['from_bin']) ?><?= $m['from_status'] && $m['from_status'] !== 'available' ? ' (' . esc($m['from_status']) . ')' : '' ?></td><td class="mono"><?= esc($m['to_bin']) ?><?= $m['to_status'] && $m['to_status'] !== 'available' ? ' (' . esc($m['to_status']) . ')' : '' ?></td>
<td><?= esc(trim(($m['lot_no'] ?? '') . ' ' . ($m['serial_no'] ?? ''))) ?></td><td class="num"><?= $m['to_bin'] && ! $m['from_bin'] ? '+' : ($m['from_bin'] && ! $m['to_bin'] ? '−' : '') ?><?= qty($m['qty'], $d) ?></td><td><?= esc($m['user']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
