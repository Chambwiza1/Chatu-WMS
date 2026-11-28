<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $val = static fn ($k, $x = '') => old($k, $d[$k] ?? $x); $edit = can('fleet.manage') ?>
<div class="page-head"><div><h1><?= $d ? esc($d['name']) : 'New driver' ?></h1></div></div>
<form method="post" action="<?= site_url($d ? 'fleet/drivers/' . $d['id'] : 'fleet/drivers') ?>" class="stack panel"><?= csrf_field() ?>
<fieldset <?= $edit ? '' : 'disabled' ?>><div class="row2"><div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= esc($val('name')) ?>" required></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= esc($val('phone')) ?>"></div></div>
<div class="row2"><div class="field"><label for="licence_no">Licence number</label><input id="licence_no" name="licence_no" value="<?= esc($val('licence_no')) ?>"></div><div class="field"><label for="licence_class">Class</label><input id="licence_class" name="licence_class" value="<?= esc($val('licence_class')) ?>"></div></div>
<div class="row2"><div class="field"><label for="licence_expiry">Licence expires</label><input id="licence_expiry" type="date" name="licence_expiry" value="<?= esc($val('licence_expiry')) ?>"><span class="hint">A driver without a valid licence cannot be put on a trip.</span></div>
<div class="field"><label for="user_id">Sign-in (optional)</label><select id="user_id" name="user_id"><option value="">None</option><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (string) $val('user_id') === (string) $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select></div></div>
<?php if ($d): ?><div class="field"><label for="status">Status</label><select id="status" name="status"><option value="active" <?= $d['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $d['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div><?php endif ?>
<div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= esc($val('notes')) ?></textarea></div></fieldset>
<?php if ($edit): ?><div><button class="btn" type="submit">Save driver</button></div><?php endif ?></form>
<?php if ($trips): ?><h2>Trips</h2><ul><?php foreach ($trips as $t): ?><li><a href="<?= site_url('fleet/trips/' . $t['id']) ?>"><?= esc($t['number']) ?></a> <?= nice_date($t['planned_date']) ?> in <?= esc($t['reg_no']) ?> <?= badge($t['status']) ?></li><?php endforeach ?></ul><?php endif ?>
<?= view('partials/activity', ['type' => 'driver', 'id' => $d['id'] ?? null]) ?>
<?= $this->endSection() ?>
