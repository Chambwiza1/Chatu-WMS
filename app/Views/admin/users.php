<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Users and roles</h1></div><div class="actions"><a class="btn secondary" href="<?= site_url('admin/roles') ?>">What each role may do</a></div></div>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Position</th><th>Roles</th><th>Last sign-in</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $u): ?><tr><td><a href="<?= site_url('admin/users/' . $u['id']) ?>"><?= esc($u['name']) ?></a></td><td><?= esc($u['username']) ?></td><td><?= esc($u['position']) ?></td><td><?= esc($u['roles']) ?: '<span class="muted">none</span>' ?></td><td><?= local_time($u['last_login_at']) ?></td>
<td><?= $u['active'] ? ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time() ? '<span class="badge warn">Locked</span>' : badge('active')) : '<span class="badge quiet">Off</span>' ?></td></tr><?php endforeach ?>
</tbody></table></div>
<section class="panel"><h2>Add a user</h2>
<form method="post" action="<?= site_url('admin/users') ?>" class="stack"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="name">Name</label><input id="name" name="name" required></div><div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="off"></div></div>
<div class="row2"><div class="field"><label for="position">Position</label><input id="position" name="position"></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone"></div></div>
<div class="row2"><div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email"><span class="hint">For copies of messages.</span></div><div class="field"><label for="password">First password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><span class="hint">10 characters or more, letters and numbers.</span></div></div>
<div><button class="btn" type="submit">Add user</button></div></form></section>
<?= $this->endSection() ?>
