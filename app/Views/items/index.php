<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Items</h1></div><div class="actions"><?php if (can('item.create')): ?><a class="btn" href="<?= site_url('items/new') ?>">New item</a><?php endif ?></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Find</label><input id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Code, name or barcode"></div>
<div class="field"><label for="kind">Kind</label><select id="kind" name="kind" data-autosubmit><option value="">All</option><?php foreach (\App\Libraries\Master\Items::KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
<button class="btn secondary" type="submit">Filter</button></form>
<?php if (! $rows): ?><div class="panel empty">No items<?= $q !== '' ? ' match that' : ' yet' ?>.</div><?php else: ?>
<form method="get" action="<?= site_url('labels/items') ?>" id="label-form"><div class="filters"><span class="muted small">Tick items, then</span><label for="lcopies" class="small">copies</label><input id="lcopies" class="n" type="number" name="copies" value="1" min="1" max="500"><label class="check"><input type="checkbox" name="price" value="1"> with price</label><label class="check"><input type="checkbox" name="packs" value="1"> pack barcodes</label><button class="btn secondary small" type="submit">Print labels</button></div>
<div class="table-wrap"><table><thead><tr><th><span class="sr">Tick</span></th><th>Code</th><th>Name</th><th>Kind</th><th>Tracking</th><th>Unit</th><th class="num">Reorder at</th><th class="num">In stock</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $i): ?><tr class="<?= $i['active'] ? '' : 'muted' ?>"><td><input type="checkbox" name="ids[]" value="<?= (int) $i['id'] ?>" aria-label="Tick <?= esc($i['sku'], 'attr') ?>"></td><td><?= sku_plate($i['sku'], site_url('stock/item/' . $i['id'])) ?></td><td><?= esc($i['name']) ?><?= $i['category'] ? ' <span class="muted small">' . esc($i['category']) . '</span>' : '' ?></td>
<td><?= esc(\App\Libraries\Master\Items::KINDS[$i['kind']]) ?></td><td><?= esc(\App\Libraries\Master\Items::TRACKING[$i['tracking']]) ?><?= $i['expiry_required'] ? ', expiry' : '' ?></td><td><?= esc($i['uom']) ?></td>
<td class="num"><?= (float) $i['reorder_level'] > 0 ? qty($i['reorder_level']) : '' ?></td><td class="num"><?= qty($i['on_hand'], (int) $i['decimals']) ?></td>
<td><?= $i['active'] ? '' : '<span class="badge quiet">Inactive</span>' ?> <?php if (can('item.update')): ?><a href="<?= site_url('items/' . $i['id']) ?>">Edit</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div></form><?php endif ?>
<?php if ($rows && can('item.update')): $cats = \App\Libraries\Master\Items::categories(); ?>
<details class="panel bulk" id="bulk"><summary><h2>Change the ticked items</h2></summary>
<form method="post" action="<?= site_url('items/bulk') ?>" class="stack" data-bulk-for="label-form"><?= csrf_field() ?><input type="hidden" name="back" value="<?= esc((string) service('request')->getUri()->getQuery(), 'attr') ?>">
<p class="small muted">Tick items in the list above. Leave a field empty to keep each item's own value. Every item is checked as if edited on its own page; if one is refused, none changes.</p>
<div class="row2"><div class="field"><label for="b_cat">Category</label><select id="b_cat" name="category_id"><option value="">Keep</option><option value="none">No category</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= esc($c['name']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="b_act">On or off</label><select id="b_act" name="active"><option value="">Keep</option><option value="1">Switched on</option><?php if (can('item.delete')): ?><option value="0">Switched off</option><?php endif ?></select></div></div>
<div class="row2"><div class="field"><label for="b_rl">Reorder at</label><input id="b_rl" name="reorder_level" inputmode="decimal"></div><div class="field"><label for="b_rq">Reorder quantity</label><input id="b_rq" name="reorder_qty" inputmode="decimal"></div></div>
<div class="row2"><div class="field"><label for="b_pm">Selling price</label><select id="b_pm" name="price_mode"><option value="">Keep</option><option value="set">Set to</option><option value="up_pct">Up by %</option><option value="down_pct">Down by %</option><option value="up_amt">Up by an amount</option><option value="down_amt">Down by an amount</option></select></div>
<div class="field"><label for="b_pv">By or to</label><input id="b_pv" name="price_value" inputmode="decimal"></div></div>
<div class="row2"><div class="field"><label for="b_ms">Minimum days of shelf life on receipt</label><input id="b_ms" name="min_shelf_days" inputmode="numeric"></div><div class="field"></div></div>
<div class="btn-row"><button class="btn" type="submit">Change the ticked items</button> <span class="small muted" data-bulk-count></span></div></form></details>
<?php endif ?>
<?= $this->endSection() ?>
