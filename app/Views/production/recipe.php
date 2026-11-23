<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $v = static fn ($k, $d = '') => old($k, $rec[$k] ?? $d); $rows = old('lines') ?: array_map(static fn ($l) => ['sku' => $l['sku'] . ' — ' . $l['name'], 'qty' => (float) $l['qty']], $rec['lines'] ?? []) ?: [[]]; ?>
<div class="page-head"><div><h1><?= $rec ? 'Recipe ' . esc($rec['code']) : 'New recipe' ?></h1></div></div>
<?= view('partials/item_list', ['kinds' => null]) ?>
<form method="post" action="<?= site_url($rec ? 'production/recipes/' . $rec['id'] : 'production/recipes') ?>" class="stack wide"><?= csrf_field() ?>
<section class="panel"><div class="row2"><div class="field"><label for="code">Code</label><input id="code" class="upper" name="code" value="<?= esc($v('code')) ?>" required maxlength="30"></div><div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= esc($v('name')) ?>"></div></div>
<div class="row2"><div class="field"><label for="output_item_id">Makes</label><select id="output_item_id" name="output_item_id" required><option value="">Choose the finished item</option><?php foreach ($outputs as $o): ?><option value="<?= $o['id'] ?>" <?= (string) $v('output_item_id') === (string) $o['id'] ? 'selected' : '' ?>><?= esc($o['sku'] . ' ' . $o['name'] . ' (' . $o['uom'] . ')') ?></option><?php endforeach ?></select><span class="hint">Batch-tracked finished items get a new batch number and expiry date on every run.</span></div>
<div class="field"><label for="output_qty">One batch makes</label><input id="output_qty" type="number" step="any" min="0" name="output_qty" value="<?= esc($v('output_qty')) ?>" required></div></div>
<div class="row2"><div class="field"><label for="other_cost">Other cost per batch</label><input id="other_cost" type="number" step="0.01" min="0" name="other_cost" value="<?= esc($v('other_cost', 0)) ?>"><span class="hint">Wages, power, gas: added to the materials' FIFO cost.</span></div><div class="field"><label for="notes">Notes</label><input id="notes" name="notes" value="<?= esc($v('notes')) ?>"></div></div></section>
<h2>One batch uses</h2>
<div class="table-wrap"><table class="lines" id="rec-lines"><thead><tr><th>Material</th><th>Quantity</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $n => $l): ?><tr><td><input class="sku" list="item-list" name="lines[<?= $n ?>][sku]" value="<?= esc($l['sku'] ?? '') ?>" aria-label="Material"></td><td><input class="n" type="number" step="any" min="0" name="lines[<?= $n ?>][qty]" value="<?= esc($l['qty'] ?? '') ?>" aria-label="Quantity"></td><td><button class="btn small secondary" data-remove-line type="button">Remove</button></td></tr><?php endforeach ?>
</tbody><template><tr><td><input class="sku" list="item-list" name="lines[__N__][sku]" aria-label="Material"></td><td><input class="n" type="number" step="any" min="0" name="lines[__N__][qty]" aria-label="Quantity"></td><td><button class="btn small secondary" data-remove-line type="button">Remove</button></td></tr></template></table></div>
<div class="line-actions"><button class="btn small secondary" type="button" data-add-line="#rec-lines">Add a material</button></div>
<?php if ($rec): ?><label class="check"><input type="checkbox" name="active" value="1" <?= $rec['active'] ? 'checked' : '' ?>> Active</label><?php endif ?>
<div><button class="btn" type="submit">Save recipe</button> <a class="btn secondary" href="<?= site_url('production') ?>">Cancel</a></div></form>
<?= $this->endSection() ?>
