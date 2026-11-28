<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Stock</h1><p class="sub"><?= esc(wh_name($wh)) ?></p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('stock?status=quarantine') ?>">Quarantine</a><a class="btn secondary" href="<?= site_url('stock?status=damaged') ?>">Damaged</a><a class="btn secondary" href="<?= site_url('stock?status=hold') ?>">On hold</a><a class="btn secondary" href="<?= site_url('reports/by_bin?warehouse_id=' . $wh) ?>">By bin</a></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Item</label><input id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Code or name"></div><button class="btn secondary" type="submit">Filter</button></form>
<?= view('partials/saved_filters') ?>
<?php if (! $rows): ?><div class="panel empty">No stock<?= $q !== '' ? ' matching that' : ' in this warehouse yet' ?>.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Item</th><th>Description</th><th class="num">Free</th><th class="num">Reserved</th><th class="num">To put away</th><th class="num">Held</th><th class="num">Expired</th><th class="num">Damaged</th><th class="num">On hand</th><th>Unit</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><?= sku_plate($r['sku'], site_url('stock/item/' . ($ids[$r['sku']] ?? 0))) ?></td><td><?= esc($r['name']) ?></td>
<td class="num"><b><?= qty($r['free_qty']) ?></b></td><td class="num"><?= qty($r['reserved']) ?></td><td class="num<?= (float) $r['to_put_away'] > 0 ? ' warn-text' : '' ?>"><?= qty($r['to_put_away']) ?></td>
<td class="num<?= (float) $r['held'] > 0 ? ' warn-text' : '' ?>"><?= qty($r['held']) ?></td><td class="num<?= (float) $r['expired'] > 0 ? ' bad-text' : '' ?>"><?= qty($r['expired']) ?></td><td class="num"><?= qty($r['damaged']) ?></td><td class="num"><?= qty($r['on_hand']) ?></td><td><?= esc($r['uom']) ?></td></tr>
<?php endforeach ?></tbody></table></div>
<p class="muted small">Free = available in an active pick bin, of a batch that is neither held nor expired, less what is reserved. Goods still in RECEIVING are not free until they are put away.</p>
<?php endif ?>
<?= $this->endSection() ?>
