<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Modules</h1><p class="sub">Switch off the parts you do not use: they leave the menu and nothing new can be started in them. What was recorded stays, and switching a module on again brings it all back. A module that holds work in progress cannot be switched off.</p></div></div>
<?php if (! $rows): ?><p class="panel">This product has no optional modules.</p><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>Module</th><th>What it covers</th><th>Now</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $k => $r): ?><tr><td><?= esc($r['name']) ?></td><td class="small"><?= esc($r['what']) ?></td><td><?= $r['on'] ? badge('active') : '<span class="muted">Off</span>' ?></td><td>
<?php if ($r['rule'] === 'allow'): ?><form method="post" action="<?= site_url('admin/modules') ?>"><?= csrf_field() ?><input type="hidden" name="module" value="<?= esc($k) ?>"><input type="hidden" name="on" value="<?= $r['on'] ? '0' : '1' ?>">
<button class="btn small <?= $r['on'] ? 'secondary' : '' ?>" type="submit" <?= $r['on'] && $r['busy'] ? 'disabled title="' . esc($r['busy'], 'attr') . '"' : '' ?>><?= $r['on'] ? 'Switch off' : 'Switch on' ?></button></form><?= $r['on'] && $r['busy'] ? '<span class="hint">In use: ' . esc($r['busy']) . '</span>' : '' ?>
<?php else: ?><span class="small muted"><?= $r['rule'] === 'on' ? 'Always on in your plan' : 'Not in your plan' ?></span><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
