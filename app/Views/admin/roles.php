<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Roles</h1><p class="sub">Your organisation's roles. Change what one may do, copy one to make a variant, or add your own. A person may hold several roles; permissions can also be given to one person directly.</p></div>
<div class="actions"><?php if (can('role.create')): ?><a class="btn" href="<?= site_url('admin/roles/new') ?>">New role</a><?php endif ?><button class="btn secondary" data-print>Print</button></div></div>
<div class="table-wrap"><table><thead><tr><th>Role</th><th>What it is for</th><th class="num">People</th><th class="num">Permissions</th><th></th></tr></thead><tbody>
<?php foreach ($roles as $r): ?><tr><td><a href="<?= site_url('admin/roles/' . $r['id']) ?>"><b><?= esc($r['name']) ?></b></a></td><td class="small"><?= esc((string) $r['description']) ?></td><td class="num"><?= (int) $r['members'] ?></td><td class="num"><?= count($r['perms']) ?></td>
<td><?php if (can('role.create')): ?><form method="post" action="<?= site_url('admin/roles/' . $r['id'] . '/copy') ?>" class="inline"><?= csrf_field() ?><button class="btn small secondary" type="submit">Copy</button></form><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<h2>Duties kept apart</h2><ul><?php foreach ($sod as $s): ?><li><b><?= esc($s['a']) ?></b> and <b><?= esc($s['b']) ?></b>: <?= esc($s['description']) ?></li><?php endforeach ?></ul>
<p class="muted">Whatever the roles: nobody changes their own access or a role they hold, nobody approves their own adjustment, count or release from quarantine, the last person who can change people's access keeps it, and the account owner may always do everything.</p>
<?= $this->endSection() ?>
