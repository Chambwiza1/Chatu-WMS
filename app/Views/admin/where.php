<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $active = array_values(array_filter($warehouses, static fn ($w) => (int) $w['active'])); ?>
<div class="page-head"><div><h1>Who works where</h1><p class="sub">Each person's usual warehouses: whatever they may do at a warehouse, they may do only at these. Open a warehouse to give it its team.</p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('admin/users') ?>">People</a></div></div>
<div class="where-cards">
<?php foreach ($active as $w): $n = 0; foreach ($people as $p) { $n += ($p['every'] || in_array((int) $w['id'], $p['at'], true)) ? 1 : 0; } ?>
<a class="where-card" href="<?= site_url('admin/where/' . $w['id']) ?>"><b><?= esc($w['code']) ?></b> <span><?= esc($w['name']) ?></span><span class="small muted"><?= $n ?> <?= $n === 1 ? 'person' : 'people' ?></span></a>
<?php endforeach ?></div>
<div class="table-wrap"><table class="where-grid"><thead><tr><th>Person</th><th>Roles</th>
<?php foreach ($active as $w): ?><th class="c"><a href="<?= site_url('admin/where/' . $w['id']) ?>" title="<?= esc($w['name'], 'attr') ?>"><?= esc($w['code']) ?></a></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($people as $p): ?><tr>
<td><?= can('user.view') ? '<a href="' . site_url('admin/users/' . $p['id']) . '">' . esc($p['name']) . '</a>' : esc($p['name']) ?><?= $p['is_account_owner'] ? ' <span class="badge info">Account owner</span>' : '' ?></td>
<td class="small"><?= $p['is_account_owner'] ? 'Everything' : (esc((string) $p['roles']) ?: '<span class="muted">none</span>') ?></td>
<?php if ($p['every']): ?><td class="c every" colspan="<?= max(1, count($active)) ?>">Every warehouse</td>
<?php else: foreach ($active as $w): ?><td class="c"><?= in_array((int) $w['id'], $p['at'], true) ? '<span class="tick" aria-label="works here">✓</span>' : '<span class="muted" aria-label="not here">·</span>' ?></td><?php endforeach; endif ?>
</tr><?php endforeach ?>
</tbody></table></div>
<?php $none = array_filter($people, static fn ($p) => ! $p['every'] && ! $p['at']); if ($none): ?>
<p class="small bad-text">Working at no warehouse: <?= esc(implode(', ', array_column($none, 'name'))) ?>. They can sign in but do no warehouse work.</p><?php endif ?>
<?php $off = array_filter($warehouses, static fn ($w) => ! (int) $w['active']); if ($off): ?><p class="small muted">Switched-off warehouses are not shown: <?= esc(implode(', ', array_column($off, 'code'))) ?>.</p><?php endif ?>
<?= $this->endSection() ?>
