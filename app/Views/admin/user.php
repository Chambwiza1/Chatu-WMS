<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php
$self    = (int) $u['id'] === (int) \App\Libraries\Core\Auth::id();
$canEdit = can('user.update') && ! $self && ! $u['is_account_owner'];
$fromRole = [];
foreach ($allRoles as $r) {
    if (in_array((int) $r['id'], $access['roles'], true)) {
        foreach ($r['perms'] as $p) {
            $fromRole[$p][] = $r['name'];
        }
    }
}
?>
<div class="page-head"><div><h1><?= esc($u['name']) ?> <?= $u['member_status'] === 'active' ? '' : '<span class="badge quiet">Off</span>' ?><?= $u['is_account_owner'] ? ' <span class="badge info">Account owner</span>' : '' ?></h1><p class="sub"><?= esc($u['username']) ?><?= $u['position'] ? ' · ' . esc($u['position']) : '' ?></p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('admin/users') ?>">People</a></div></div>
<?php if ($conflicts): ?><div class="flash err">Segregation of duties: <?= esc(implode('; ', array_map(static fn ($c) => $c['description'] . ' (' . $c['a_name'] . ' and ' . $c['b_name'] . ')', $conflicts))) ?></div><?php endif ?>
<?php if ($u['is_account_owner']): ?><div class="flash info">The account owner may always do everything, at every warehouse.</div><?php elseif ($self): ?><div class="flash info">This is you. Nobody changes their own access: another administrator does.</div><?php endif ?>

<form method="post" action="<?= site_url('admin/users/' . $u['id'] . '/access') ?>" class="stack" data-access-form><?= csrf_field() ?>
<div class="access-top">
<section class="panel"><h2>Roles</h2><p class="small muted">What a role may do shows ticked and greyed under Permissions. <a href="<?= site_url('admin/roles') ?>">See and change the roles</a></p>
<?php if (! $allRoles): ?><p class="muted">No roles yet.</p><?php endif ?>
<?php foreach ($allRoles as $r): ?><label class="check role-pick"><input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" data-role-name="<?= esc($r['name'], 'attr') ?>" data-perms="<?= esc(implode(',', $r['perms']), 'attr') ?>" <?= in_array((int) $r['id'], $access['roles'], true) ? 'checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <b><?= esc($r['name']) ?></b><?php if ($r['description']): ?> <span class="small muted"><?= esc($r['description']) ?></span><?php endif ?></label><?php endforeach ?>
</section>
<section class="panel"><h2>Warehouses</h2><p class="small muted">Whatever this person may do at a warehouse, they may do only at these.</p>
<label class="check"><input type="radio" name="all_warehouses" value="1" <?= $access['all_warehouses'] ? 'checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <b>Every warehouse</b> (new ones included)</label>
<label class="check"><input type="radio" name="all_warehouses" value="0" <?= $access['all_warehouses'] ? '' : 'checked' ?><?= $canEdit ? '' : ' disabled' ?>> <b>Only these</b>:</label>
<div class="loc-picks"><?php foreach ($whs as $l): ?><label class="check"><input type="checkbox" name="warehouses[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $access['warehouses'], true) ? 'checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= esc($l['code'] . ' ' . $l['name']) ?><?= $l['active'] ? '' : ' <span class="muted">(off)</span>' ?></label><?php endforeach ?></div>
<?php if (! $access['all_warehouses'] && ! $access['warehouses'] && ! $u['is_account_owner']): ?><p class="small bad-text">No warehouse yet: this person can sign in and use the organisation's lists, but do nothing at a warehouse.</p><?php endif ?>
</section></div>
<section class="panel"><h2>Permissions</h2><p class="small muted">Greyed ticks come from the roles. Tick more to give this person extra permissions on top of their roles. To give less, pick or <a href="<?= site_url('admin/roles') ?>">make</a> a smaller role.</p>
<?= view('admin/_grid', ['on' => $u['is_account_owner'] ? array_keys(\App\Libraries\Core\Rights::all()) : $access['perms'], 'fromRole' => $u['is_account_owner'] ? [] : $fromRole, 'edit' => $canEdit, 'name' => 'perms[]']) ?>
</section>
<?php if ($canEdit): ?><div class="settings-save"><button class="btn" type="submit">Save access</button></div><?php endif ?>
</form>

<div class="grid2">
<form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>" class="panel stack"><?= csrf_field() ?><h2>Details</h2>
<?php if ($shared): ?><p class="small muted">This person also signs in to another organisation, so their name, e-mail and password are theirs to change under My account. You can set their department here.</p><?php endif ?>
<div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= esc($u['name'], 'attr') ?>"<?= $shared ? ' disabled' : '' ?>></div><div class="field"><label for="position">Position</label><input id="position" name="position" value="<?= esc((string) $u['position'], 'attr') ?>"<?= $shared ? ' disabled' : '' ?>></div>
<div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="<?= esc((string) $u['email'], 'attr') ?>"<?= $shared ? ' disabled' : '' ?>></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= esc((string) $u['phone'], 'attr') ?>"<?= $shared ? ' disabled' : '' ?>></div>
<div class="field"><label for="department">Department</label><input id="department" name="department" value="<?= esc((string) $u['department'], 'attr') ?>"></div>
<?php if (can('user.update')): ?><div><button class="btn secondary" type="submit">Save details</button></div><?php endif ?></form>
<div class="stack">
<?php if (! $shared && can('user.update')): ?><form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>" class="panel stack"><?= csrf_field() ?><input type="hidden" name="do" value="password"><h2>New password</h2><div class="field"><label for="password">Password</label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password"></div><div><button class="btn secondary" type="submit">Set password</button></div></form><?php endif ?>
<?php if (can($u['member_status'] === 'active' ? 'user.delete' : 'user.update')): ?><form method="post" action="<?= site_url('admin/users/' . $u['id']) ?>" class="panel"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $u['member_status'] === 'active' ? 'deactivate' : 'activate' ?>"><button class="btn <?= $u['member_status'] === 'active' ? 'danger' : '' ?>" type="submit"><?= $u['member_status'] === 'active' ? 'Switch off in this organisation' : 'Switch on again' ?></button><p class="muted small">Switched off, they cannot sign in to <?= esc($org['name']) ?>. Their history stays.</p></form><?php endif ?>
</div></div>
<?= $this->endSection() ?>
