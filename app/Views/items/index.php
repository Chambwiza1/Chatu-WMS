<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Items</h1></div><div class="actions"><?php if (can('item.manage')): ?><a class="btn" href="<?= site_url('items/new') ?>">New item</a><?php endif ?></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Find</label><input id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Code, name or barcode"></div>
<div class="field"><label for="kind">Kind</label><select id="kind" name="kind" data-autosubmit><option value="">All</option><?php foreach (\App\Libraries\Master\Items::KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
<button class="btn secondary" type="submit">Filter</button></form>
<?php if (! $rows): ?><div class="panel empty">No items<?= $q !== '' ? ' match that' : ' yet' ?>.</div><?php else: ?>
<form method="get" action="<?= site_url('labels/items') ?>" id="label-form"><div class="filters"><span class="muted small">Tick items, then</span><label for="lcopies" class="small">copies</label><input id="lcopies" class="n" type="number" name="copies" value="1" min="1" max="500"><label class="check"><input type="checkbox" name="price" value="1"> with price</label><label class="check"><input type="checkbox" name="packs" value="1"> pack barcodes</label><button class="btn secondary small" type="submit">Print labels</button></div>
<div class="table-wrap"><table><thead><tr><th><span class="sr">Label</span></th><th>Code</th><th>Name</th><th>Kind</th><th>Tracking</th><th>Unit</th><th class="num">Reorder at</th><th class="num">In stock</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $i): ?><tr class="<?= $i['active'] ? '' : 'muted' ?>"><td><input type="checkbox" name="ids[]" value="<?= (int) $i['id'] ?>" aria-label="Label for <?= esc($i['sku'], 'attr') ?>"></td><td><?= sku_plate($i['sku'], site_url('stock/item/' . $i['id'])) ?></td><td><?= esc($i['name']) ?><?= $i['category'] ? ' <span class="muted small">' . esc($i['category']) . '</span>' : '' ?></td>
<td><?= esc(\App\Libraries\Master\Items::KINDS[$i['kind']]) ?></td><td><?= esc(\App\Libraries\Master\Items::TRACKING[$i['tracking']]) ?><?= $i['expiry_required'] ? ', expiry' : '' ?></td><td><?= esc($i['uom']) ?></td>
<td class="num"><?= (float) $i['reorder_level'] > 0 ? qty($i['reorder_level']) : '' ?></td><td class="num"><?= qty($i['on_hand'], (int) $i['decimals']) ?></td>
<td><?= $i['active'] ? '' : '<span class="badge quiet">Inactive</span>' ?> <?php if (can('item.manage')): ?><a href="<?= site_url('items/' . $i['id']) ?>">Edit</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div></form><?php endif ?>
<?= $this->endSection() ?>
