<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $one = rtrim($t, 's') ?>
<div class="page-head"><div><h1><?= ucfirst($t) ?></h1></div><div class="actions"><?php if (can(($t === 'suppliers' ? 'supplier' : 'customer') . '.create')): ?><a class="btn" href="<?= site_url($t . '/new') ?>">New <?= $one ?></a><?php endif ?></div></div>
<form method="get" class="filters"><div class="field"><label for="q">Find</label><input id="q" name="q" type="search" value="<?= esc($q) ?>"></div><button class="btn secondary" type="submit">Filter</button></form>
<?= view('partials/saved_filters') ?>
<?php if (! $rows): ?><div class="panel empty">No <?= $t ?> yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Contact</th><th>Phone</th><th><?= $t === 'suppliers' ? 'Lead time' : 'Town' ?></th><th class="num"><?= $t === 'suppliers' ? 'Open orders' : 'Open orders' ?></th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="<?= $r['active'] ? '' : 'muted' ?>"><td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['name']) ?></td><td><?= esc($r['contact']) ?></td><td><?= esc($r['phone']) ?></td>
<td><?= $t === 'suppliers' ? ((int) $r['lead_days'] ? (int) $r['lead_days'] . ' days' : '') : esc($r['town']) ?></td><td class="num"><?= (int) $r['open_docs'] ?: '' ?></td>
<td><?= $r['active'] ? '' : '<span class="badge quiet">Inactive</span>' ?> <?php if (can(($t === 'suppliers' ? 'supplier' : 'customer') . '.update')): ?><a href="<?= site_url($t . '/' . $r['id']) ?>">Edit</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
