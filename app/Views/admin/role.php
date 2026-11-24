<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $held = $r && in_array((int) \App\Libraries\Core\Auth::id(), array_map('intval', array_column($r['members'], 'id')), true); $edit = $r ? can('role.update') && ! $held : can('role.create'); ?>
<div class="page-head"><div><h1><?= $r ? esc($r['name']) : 'New role' ?></h1><p class="sub"><?= $r ? (count($r['members']) ? 'Held by ' . esc(implode(', ', array_column($r['members'], 'name'))) . '.' : 'Nobody holds this role yet.') : 'Tick what people with this role may do. Give it to people under People.' ?></p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('admin/roles') ?>">Roles</a></div></div>
<?php if ($held): ?><div class="flash info">You hold this role, so another administrator changes it.</div><?php endif ?>
<form method="post" action="<?= site_url($r ? 'admin/roles/' . $r['id'] : 'admin/roles') ?>" class="stack"><?= csrf_field() ?>
<div class="panel row2"><div class="field"><label for="name">Name</label><input id="name" name="name" required maxlength="100" value="<?= esc((string) ($r['name'] ?? ''), 'attr') ?>" placeholder="Shift supervisor"<?= $edit ? '' : ' disabled' ?>></div>
<div class="field"><label for="description">What it is for</label><input id="description" name="description" maxlength="255" value="<?= esc((string) ($r['description'] ?? ''), 'attr') ?>"<?= $edit ? '' : ' disabled' ?>></div></div>
<section class="panel"><h2>Permissions</h2>
<p class="small muted"><b>Select</b>: pick a record of the module on another screen. <b>View</b>: open the module. <b>Create</b>, <b>Edit</b>, <b>Delete</b> (switch off). <b>Supervisor</b>: approve the work of others (never your own).</p>
<?= view('admin/_grid', ['on' => $r['perms'] ?? [], 'edit' => $edit, 'name' => 'perms[]']) ?></section>
<?php if ($edit): ?><div class="settings-save"><button class="btn" type="submit">Save role</button></div><?php endif ?>
</form>
<?php if ($r && can('role.delete') && ! $r['members']): ?><form method="post" action="<?= site_url('admin/roles/' . $r['id'] . '/delete') ?>" class="panel"><?= csrf_field() ?><button class="btn danger" type="submit" data-confirm="Delete the role <?= esc($r['name'], 'attr') ?>?">Delete this role</button></form><?php endif ?>
<?= $this->endSection() ?>
