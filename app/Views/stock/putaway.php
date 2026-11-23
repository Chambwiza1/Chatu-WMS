<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Put away</h1><p class="sub"><?= esc(wh_name($wh)) ?>: goods received and waiting in RECEIVING</p></div><div class="actions"><a class="btn secondary" href="<?= site_url('stock') ?>">Move other stock</a></div></div>
<?php if (! $rows): ?><div class="panel empty">Nothing waiting to be put away.</div><?php else: ?>
<div class="table-wrap"><table class="lines"><thead><tr><th>Item</th><th>Batch / serial</th><th>Status</th><th class="num">Waiting</th><th>Put in bin</th><th>Quantity</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $free = (float) $r['qty'] - (float) $r['qty_allocated'] ?><tr>
<td><?= sku_plate($r['sku'], site_url('stock/item/' . $r['item_id'])) ?> <?= esc($r['name']) ?></td><td><?= esc(trim($r['lot_no'] . ($r['expiry_date'] ? ' (exp ' . nice_date($r['expiry_date']) . ')' : '') . ' ' . $r['serial_no'])) ?></td><td><?= badge($r['status']) ?></td>
<td class="num"><?= qty($free, (int) $r['decimals']) ?> <?= esc($r['uom']) ?></td>
<td colspan="3"><form method="post" action="<?= site_url('moves') ?>" class="inline-form"><?= csrf_field() ?><?= token_field() ?><input type="hidden" name="return_to" value="putaway">
<input type="hidden" name="lines[0][stock_id]" value="<?= $r['id'] ?>">
<select name="lines[0][to_bin_id]" aria-label="Bin" required><?php foreach ($bins as $b): ?><option value="<?= $b['id'] ?>" <?= (int) $b['id'] === (int) ($suggest[$r['id']] ?? 0) ? 'selected' : '' ?>><?= esc($b['code']) ?><?= $b['zone_code'] ? ' · ' . esc($b['zone_code']) : '' ?></option><?php endforeach ?></select>
<input class="n" type="number" name="lines[0][qty]" step="any" min="0" max="<?= $free ?>" value="<?= $free ?>" aria-label="Quantity">
<button class="btn small" type="submit">Put away</button></form></td></tr><?php endforeach ?>
</tbody></table></div>
<p class="muted small">The suggested bin is where the item already sits, or else the first empty storage bin.</p><?php endif ?>
<?= $this->endSection() ?>
