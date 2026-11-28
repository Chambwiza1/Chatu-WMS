<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Batches and serial numbers</h1></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Batch, serial or item</label><input id="q" name="q" type="search" value="<?= esc($q) ?>"></div><button class="btn secondary" type="submit">Find</button></form>
<?= view('partials/saved_filters') ?>
<?php if ($serials): ?><h2>Serial numbers</h2><div class="table-wrap"><table><thead><tr><th>Serial</th><th>Item</th><th>Status</th><th>Warehouse</th></tr></thead><tbody>
<?php foreach ($serials as $s): ?><tr><td class="mono"><a href="<?= site_url('serials/' . $s['id']) ?>"><?= esc($s['serial_no']) ?></a></td><td><?= esc($s['sku']) ?></td><td><?= badge($s['status']) ?></td><td><?= esc($s['wh']) ?></td></tr><?php endforeach ?></tbody></table></div><h2>Batches</h2><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>Batch</th><th>Item</th><th>Made / received</th><th>Expiry</th><th>Status</th><th class="num">In stock</th></tr></thead><tbody>
<?php foreach ($rows as $l): ?><tr><td><a href="<?= site_url('lots/' . $l['id']) ?>"><?= esc($l['lot_no']) ?></a></td><td><?= esc($l['sku'] . ' ' . $l['name']) ?></td><td><?= esc(ucfirst($l['source'])) ?> <?= nice_date($l['mfg_date'] ?: substr($l['created_at'], 0, 10)) ?></td>
<td class="<?= $l['expiry_date'] && $l['expiry_date'] < date('Y-m-d') ? 'bad-text' : '' ?>"><?= nice_date($l['expiry_date']) ?> <span class="muted small"><?= esc(days_left($l['expiry_date'])) ?></span></td><td><?= badge($l['status']) ?></td><td class="num"><?= qty($l['on_hand'], (int) $l['decimals']) ?> <?= esc($l['uom']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
