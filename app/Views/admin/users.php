<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>People and access</h1><p class="sub">Everyone who signs in to <?= esc($org['name']) ?>: their roles, the warehouses they work at, and permissions given to them directly.</p></div>
<div class="actions"><?php if (can('role.view')): ?><a class="btn secondary" href="<?= site_url('admin/roles') ?>">Roles</a><?php endif ?></div></div>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Department</th><th>Roles</th><th>Warehouses</th><th>Last sign-in</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $u): ?><tr class="<?= $u['member_status'] === 'active' ? '' : 'muted' ?>"><td><a href="<?= site_url('admin/users/' . $u['id']) ?>"><?= esc($u['name']) ?></a><?= $u['is_account_owner'] ? ' <span class="badge info">Account owner</span>' : '' ?></td><td><?= esc($u['username']) ?></td><td><?= esc($u['department'] ?? $u['position']) ?></td>
<td><?= $u['is_account_owner'] ? 'Everything' : (esc((string) $u['roles']) ?: '<span class="muted">none</span>') ?><?= (int) $u['direct'] ? ' <span class="badge quiet">+' . (int) $u['direct'] . ' direct</span>' : '' ?></td>
<td><?= $u['all_warehouses'] || $u['is_account_owner'] ? 'All' : (esc((string) $u['warehouses']) ?: '<span class="bad-text">none</span>') ?></td><td><?= local_time($u['last_login_at']) ?></td>
<td><?= $u['member_status'] === 'active' ? ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time() ? '<span class="badge warn">Locked</span>' : badge('active')) : '<span class="badge quiet">Off</span>' ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php if (can('user.create')): ?><div class="grid2">
<section class="panel"><h2>Add a person</h2>
<form method="post" action="<?= site_url('admin/users') ?>" class="stack"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="name">Name</label><input id="name" name="name" required></div><div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="off"></div></div>
<div class="row2"><div class="field"><label for="department">Department</label><input id="department" name="department"></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone"></div></div>
<div class="row2"><div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email"><span class="hint">They can sign in with it; messages are copied there.</span></div><div class="field"><label for="password">First password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><span class="hint">10 characters or more, letters and numbers.</span></div></div>
<div><button class="btn" type="submit">Add</button></div></form></section>
<section class="panel"><h2>Add someone who already has a login</h2><p class="small muted">For people who already sign in to Chatu Warehouse for another organisation: they keep one login and switch between organisations.</p>
<form method="post" action="<?= site_url('admin/users/existing') ?>" class="stack"><?= csrf_field() ?>
<div class="field"><label for="login">Their username or e-mail</label><input id="login" name="login" required></div><div><button class="btn secondary" type="submit">Add</button></div></form></section>
</div><?php endif ?>
<?= $this->endSection() ?>
