<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Return to supplier</h1><p class="sub"><?= esc(wh_name($wh)) ?></p></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Find stock</label><input id="q" name="q" type="search" value="<?= esc($q) ?>" placeholder="Item, batch or serial"></div><button class="btn secondary" type="submit">Find</button><span class="muted small">Without a search, the list shows quarantined and damaged stock.</span></form>
<?php if (! $rows): ?><div class="panel empty">Nothing found.</div><?php else: ?>
<form method="post" action="<?= site_url('supplier-returns') ?>" class="stack wide"><?= csrf_field() ?><?= token_field() ?><input type="hidden" name="warehouse_id" value="<?= $wh ?>">
<div class="table-wrap"><table class="lines"><thead><tr><th>Item</th><th>Bin</th><th>Batch</th><th>Serial</th><th>Status</th><th class="num">Free</th><th>Return</th></tr></thead><tbody>
<?php foreach ($rows as $r): $free = (float) $r['qty'] - (float) $r['qty_allocated'] ?><tr><td><?= esc($r['sku'] . ' ' . $r['name']) ?></td><td class="mono"><?= esc($r['bin']) ?></td><td><?= esc($r['lot_no']) ?></td><td class="mono"><?= esc($r['serial_no']) ?></td><td><?= badge($r['status']) ?></td><td class="num"><?= qty($free, (int) $r['decimals']) ?></td>
<td><input class="n" type="number" step="any" min="0" max="<?= $free ?>" name="qty[<?= $r['id'] ?>]" value="<?= count($rows) === 1 ? $free : '' ?>" aria-label="Quantity to return"></td></tr><?php endforeach ?></tbody></table></div>
<section class="panel"><div class="row2"><div class="field"><label for="supplier_id">Supplier</label><select id="supplier_id" name="supplier_id" required><option value="">Choose</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" <?= count($rows) === 1 && (int) $rows[0]['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="reason_code">Reason</label><select id="reason_code" name="reason_code" required><?php foreach ($reasons as $x): ?><option value="<?= esc($x['code']) ?>"><?= esc($x['label']) ?></option><?php endforeach ?></select></div></div>
<div class="field"><label for="notes">Notes (their return authorisation, credit note expected ...)</label><input id="notes" name="notes"></div>
<div><button class="btn" type="submit" data-confirm="Send these back to the supplier? They leave stock now.">Return</button></div></section></form><?php endif ?>
<?= $this->endSection() ?>
