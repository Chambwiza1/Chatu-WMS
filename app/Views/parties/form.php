<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $v = static fn ($k, $d = '') => old($k, $row[$k] ?? $d); $one = rtrim($t, 's') ?>
<div class="page-head"><div><h1><?= $row ? esc($row['name']) : 'New ' . $one ?></h1></div></div>
<form method="post" action="<?= site_url($row ? $t . '/' . $row['id'] : $t) ?>" class="stack panel"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="code">Code</label><input id="code" name="code" value="<?= esc($v('code')) ?>" required maxlength="30" class="upper"></div>
<div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= esc($v('name')) ?>" required maxlength="150"></div></div>
<div class="row2"><div class="field"><label for="contact">Contact person</label><input id="contact" name="contact" value="<?= esc($v('contact')) ?>"></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= esc($v('phone')) ?>"></div></div>
<div class="row2"><div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" value="<?= esc($v('email')) ?>"></div><div class="field"><label for="tax_no">TPIN</label><input id="tax_no" name="tax_no" value="<?= esc($v('tax_no')) ?>"></div></div>
<div class="field"><label for="address"><?= $t === 'customers' ? 'Delivery address' : 'Address' ?></label><textarea id="address" name="address"><?= esc($v('address')) ?></textarea></div>
<?php if ($t === 'customers'): ?><div class="row2"><div class="field"><label for="town">Town</label><input id="town" name="town" value="<?= esc($v('town')) ?>"></div>
<div class="field"><label for="min_shelf_days">Minimum days of shelf life they accept</label><input id="min_shelf_days" name="min_shelf_days" type="number" min="0" value="<?= esc($v('min_shelf_days', 0)) ?>"></div></div>
<?php else: ?><div class="field"><label for="lead_days">Lead time (days)</label><input id="lead_days" name="lead_days" type="number" min="0" value="<?= esc($v('lead_days', 0)) ?>"></div><?php endif ?>
<div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= esc($v('notes')) ?></textarea></div>
<?php if ($row): ?><label class="check"><input type="checkbox" name="active" value="1" <?= $row['active'] ? 'checked' : '' ?>> Active</label><?php endif ?>
<div><button class="btn" type="submit">Save</button> <a class="btn secondary" href="<?= site_url($t) ?>">Cancel</a></div></form>
<?= view('partials/activity', ['type' => rtrim($t, 's'), 'id' => $row['id'] ?? null]) ?>
<?= $this->endSection() ?>
