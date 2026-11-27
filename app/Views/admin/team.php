<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $canEdit = can('user.update'); $me = (int) \App\Libraries\Core\Auth::id(); ?>
<div class="page-head"><div><h1>Team at <?= esc($w['code']) ?></h1><p class="sub"><?= esc($w['name']) ?><?= (int) $w['active'] ? '' : ' · <span class="badge quiet">Off</span>' ?></p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('admin/where') ?>">Who works where</a></div></div>
<div class="grid2">
<section class="panel"><h2>Works here (<?= count($here) ?>)</h2>
<?php if (! $here): ?><p class="muted">Nobody has this as a usual warehouse yet.</p><?php endif ?>
<div class="table-wrap"><table><tbody>
<?php foreach ($here as $p): ?><tr><td><a href="<?= site_url('admin/users/' . $p['id']) ?>"><?= esc($p['name']) ?></a><div class="small muted"><?= esc((string) $p['roles']) ?: 'no role' ?></div></td>
<td class="r"><?php if ($canEdit && (int) $p['id'] !== $me): ?><form method="post" action="<?= site_url('admin/where/' . $w['id']) ?>"><?= csrf_field() ?><input type="hidden" name="remove" value="<?= (int) $p['id'] ?>"><button class="btn secondary small" type="submit" aria-label="Take <?= esc($p['name'], 'attr') ?> off this team">Take off</button></form><?php elseif ((int) $p['id'] === $me): ?><span class="small muted">You</span><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php if ($every): ?><h3>Also here: every warehouse</h3><p class="small"><?= esc(implode(', ', array_map(static fn ($p) => $p['name'] . ($p['is_account_owner'] ? ' (account owner)' : ''), $every))) ?></p><p class="small muted">Change "every warehouse" on the person's own page.</p><?php endif ?>
</section>
<?php if ($canEdit): ?><section class="panel"><h2>Add people</h2>
<?php $pick = array_filter($others, static fn ($p) => (int) $p['id'] !== $me); if (! $pick): ?><p class="muted">Everyone already works here.</p><?php else: ?>
<form method="post" action="<?= site_url('admin/where/' . $w['id']) ?>" class="stack"><?= csrf_field() ?>
<div class="loc-picks"><?php foreach ($pick as $p): ?><label class="check"><input type="checkbox" name="add[]" value="<?= (int) $p['id'] ?>"> <b><?= esc($p['name']) ?></b> <span class="small muted"><?= esc((string) $p['roles']) ?: 'no role' ?><?= $p['at'] ? '' : ' · no warehouse yet' ?></span></label><?php endforeach ?></div>
<p class="small muted">They keep their other warehouses and their roles. What they may do here comes from their roles.</p>
<div><button class="btn" type="submit">Add to the team</button></div></form><?php endif ?>
</section><?php endif ?>
</div>
<?= $this->endSection() ?>
