<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Post a production run</h1><p class="sub"><?= esc($w['code'] . ' ' . $w['name']) ?></p></div></div>
<form method="get" class="filters"><div class="field"><label for="recipe_id">Recipe</label><select id="recipe_id" name="recipe_id" required><option value="">Choose</option><?php foreach ($recipes as $r): ?><option value="<?= $r['id'] ?>" <?= $rec && (int) $rec['id'] === (int) $r['id'] ? 'selected' : '' ?>><?= esc($r['code'] . ' ' . $r['name']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="batches">Batches</label><input id="batches" type="number" step="any" min="0" name="batches" value="<?= esc($batches) ?>"></div>
<?php if (count($warehouses) > 1): ?><div class="field"><label for="wsel">Where</label><select id="wsel" name="warehouse_id"><?php foreach ($warehouses as $x): ?><option value="<?= $x['id'] ?>" <?= (int) $x['id'] === $wh ? 'selected' : '' ?>><?= esc($x['code']) ?></option><?php endforeach ?></select></div><?php endif ?>
<button class="btn secondary" type="submit">Work out materials</button></form>
<?php if ($rec): $expected = (float) $rec['output_qty'] * $batches; $short = false ?>
<form method="post" action="<?= site_url('production/runs') ?>" class="stack wide"><?= csrf_field() ?><?= token_field() ?>
<input type="hidden" name="recipe_id" value="<?= $rec['id'] ?>"><input type="hidden" name="warehouse_id" value="<?= $wh ?>"><input type="hidden" name="batches" value="<?= esc($batches) ?>">
<div class="table-wrap"><table class="lines"><thead><tr><th>Material</th><th class="num">Recipe says</th><th class="num">Usable here</th><th>Actually used</th></tr></thead><tbody>
<?php foreach ($rec['lines'] as $l): $need = (float) $l['qty'] * $batches; $has = $avail[$l['item_id']] ?? 0; $short = $short || $has < $need ?><tr><td><?= esc($l['sku'] . ' ' . $l['name']) ?></td><td class="num"><?= qty($need, (int) $l['decimals']) ?> <?= esc($l['uom']) ?></td><td class="num<?= $has < $need ? ' bad-text' : '' ?>"><?= qty($has, (int) $l['decimals']) ?></td>
<td><input class="n" type="number" step="any" min="0" name="used[<?= $l['item_id'] ?>]" value="<?= esc(old('used.' . $l['item_id'], (string) $need)) ?>" aria-label="Used"></td></tr><?php endforeach ?>
</tbody></table></div>
<?php if ($short): ?><p class="flash err">Some materials are short here. Receive or transfer them first; a run that is short is refused.</p><?php endif ?>
<p class="muted small">Materials are taken from the PRODUCTION bin first, then from storage, earliest expiry first. Every batch used is recorded for traceability.</p>
<section class="panel"><div class="row2"><div class="field"><label for="actual_qty">Quantity actually made (<?= esc($rec['uom']) ?>)</label><input id="actual_qty" type="number" step="any" min="0" name="actual_qty" value="<?= esc(old('actual_qty', (string) $expected)) ?>" required><span class="hint">Expected <?= qty($expected) ?>. A lower yield raises the cost of each unit.</span></div>
<div class="field"><label for="produced_on">Made on</label><input id="produced_on" type="date" name="produced_on" value="<?= esc(old('produced_on', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>"></div></div>
<?php if (in_array($rec['tracking'], ['batch', 'batch_serial'], true)): ?><div class="row2"><div class="field"><label for="lot_no">Batch number</label><input id="lot_no" class="upper" name="lot_no" value="<?= esc(old('lot_no')) ?>" placeholder="Automatic if empty"></div>
<div class="field"><label for="expiry_date">Expiry</label><input id="expiry_date" type="date" name="expiry_date" value="<?= esc(old('expiry_date')) ?>"><span class="hint">Empty: from the item's shelf life.</span></div></div><?php endif ?>
<div class="row2"><div class="field"><label for="output_bin_id">Put the output in</label><select id="output_bin_id" name="output_bin_id"><?php foreach ($bins as $b): ?><option value="<?= $b['id'] ?>" <?= (int) $b['id'] === (int) $w['stock_bin_id'] ? 'selected' : '' ?>><?= esc($b['code']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="output_status">Status</label><select id="output_status" name="output_status"><option value="available">Available</option><option value="quarantine">Quarantine (quality check first)</option></select></div></div>
<div class="field"><label for="notes">Notes</label><input id="notes" name="notes" value="<?= esc(old('notes')) ?>"></div>
<div><button class="btn" type="submit" data-confirm="Post this run? Materials leave stock and the finished goods come in.">Post run</button></div></section></form>
<?php endif ?>
<?= $this->endSection() ?>
