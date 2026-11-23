<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $rows = old('lines') ?: [[]] ?>
<div class="page-head"><div><h1>New transfer</h1></div></div>
<?= view('partials/item_list', ['kinds' => null]) ?>
<form method="post" action="<?= site_url('transfers') ?>" class="stack wide"><?= csrf_field() ?>
<section class="panel"><div class="row2"><div class="field"><label for="from">From</label><select id="from" name="from_warehouse_id"><?php foreach ($from as $w): ?><option value="<?= $w['id'] ?>" <?= (int) old('from_warehouse_id', $wh) === (int) $w['id'] ? 'selected' : '' ?>><?= esc($w['code'] . ' ' . $w['name']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="to">To</label><select id="to" name="to_warehouse_id" required><option value="">Choose</option><?php foreach ($to as $w): ?><option value="<?= $w['id'] ?>" <?= (int) old('to_warehouse_id') === (int) $w['id'] ? 'selected' : '' ?>><?= esc($w['code'] . ' ' . $w['name']) ?></option><?php endforeach ?></select></div></div>
<div class="row2"><div class="field"><label for="needed_by">Needed by</label><input id="needed_by" type="date" name="needed_by" value="<?= esc(old('needed_by')) ?>"></div><div class="field"><label for="notes">Notes</label><input id="notes" name="notes" value="<?= esc(old('notes')) ?>"></div></div></section>
<p class="muted small">Batches and serial numbers are chosen when the stock is reserved (earliest expiry first, or oldest first), never from expired, held or blocked stock.</p>
<div class="table-wrap"><table class="lines" id="tr-lines"><thead><tr><th>Item</th><th>Unit</th><th>Quantity</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $n => $l): ?><tr><td><input class="sku" list="item-list" name="lines[<?= $n ?>][sku]" value="<?= esc($l['sku'] ?? '') ?>" aria-label="Item"></td><td><select name="lines[<?= $n ?>][unit]" data-unit="any" data-selected="<?= esc($l['unit'] ?? '', 'attr') ?>" aria-label="Unit"><option value="">—</option></select></td><td><input class="n" type="number" step="any" min="0" name="lines[<?= $n ?>][qty]" value="<?= esc($l['qty'] ?? '') ?>" aria-label="Quantity"></td><td><button class="btn small secondary" data-remove-line type="button">Remove</button></td></tr><?php endforeach ?>
</tbody><template><tr><td><input class="sku" list="item-list" name="lines[__N__][sku]" aria-label="Item"></td><td><select name="lines[__N__][unit]" data-unit="any" data-selected="" aria-label="Unit"><option value="">—</option></select></td><td><input class="n" type="number" step="any" min="0" name="lines[__N__][qty]" aria-label="Quantity"></td><td><button class="btn small secondary" data-remove-line type="button">Remove</button></td></tr></template></table></div>
<div class="line-actions"><button class="btn small secondary" type="button" data-add-line="#tr-lines">Add a line</button></div>
<div><button class="btn secondary" type="submit">Save draft</button> <button class="btn" type="submit" name="allocate" value="1">Save and reserve stock</button></div></form>
<?= $this->endSection() ?>
