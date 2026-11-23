<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $free = (float) $row['qty'] - (float) $row['qty_allocated'] ?>
<div class="page-head"><div><h1>Move stock</h1><p class="sub"><?= esc(wh_name((int) $row['warehouse_id'])) ?></p></div></div>
<section class="panel"><dl class="facts">
<dt>Item</dt><dd><?= sku_plate($row['sku']) ?> <?= esc($row['name']) ?></dd>
<dt>Now in</dt><dd class="mono"><?= esc($row['bin']) ?></dd><dt>Status</dt><dd><?= badge($row['status']) ?></dd>
<?php if ($row['lot_no']): ?><dt>Batch</dt><dd><?= esc($row['lot_no']) ?><?= $row['expiry_date'] ? ', expires ' . nice_date($row['expiry_date']) : '' ?></dd><?php endif ?>
<?php if ($row['serial_no']): ?><dt>Serial</dt><dd class="mono"><?= esc($row['serial_no']) ?></dd><?php endif ?>
<dt>Free to move</dt><dd><?= qty($free, (int) $row['decimals']) ?> <?= esc($row['uom']) ?><?= (float) $row['qty_allocated'] > 0 ? ' (' . qty($row['qty_allocated']) . ' reserved stays)' : '' ?></dd></dl></section>
<form method="post" action="<?= site_url('moves') ?>" class="stack panel"><?= csrf_field() ?><?= token_field() ?>
<input type="hidden" name="lines[0][stock_id]" value="<?= $row['id'] ?>">
<div class="row2"><div class="field"><label for="to_bin">To bin</label><select id="to_bin" name="lines[0][to_bin_id]"><option value="">Same bin</option><?php foreach ($bins as $b): if ((int) $b['id'] === (int) $row['bin_id']) continue; ?><option value="<?= $b['id'] ?>"><?= esc($b['code']) ?> · <?= esc($b['type']) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="to_status">Status</label><select id="to_status" name="lines[0][to_status]"><?php foreach (\App\Libraries\Stock\Ledger::STATUSES as $s): ?><option value="<?= $s ?>" <?= $s === $row['status'] ? 'selected' : '' ?>><?= esc(state_label($s)) ?></option><?php endforeach ?></select>
<span class="hint">Releasing quarantined, damaged or held stock to available needs approval rights.</span></div></div>
<div class="row2"><div class="field"><label for="qty">Quantity</label><input id="qty" type="number" name="lines[0][qty]" step="any" min="0" max="<?= $free ?>" value="<?= $free ?>" required></div>
<div class="field"><label for="note">Note</label><input id="note" name="note" type="text" maxlength="255"></div></div>
<div><button class="btn" type="submit">Move</button> <a class="btn secondary" href="<?= site_url('stock/item/' . $row['item_id']) ?>">Back</a></div></form>
<?= $this->endSection() ?>
