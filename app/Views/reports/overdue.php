<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Late and overdue</h1><p class="sub">Work waiting <?= $limit ?> working days or more at your warehouses. The people who act are reminded after <?= $limit ?> working days; their managers are told after <?= 2 * $limit ?>. Working days and public holidays are set under Settings.</p></div></div>
<?php if (! $rows): ?><div class="panel empty">Nothing is late.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>What</th><th>Number</th><th>Warehouse</th><th>Waiting since</th><th class="num">Working days</th><th>Told</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="<?= $r['due'] === 2 ? 'late' : '' ?>"><td><?= esc($r['label']) ?></td><td><a href="<?= site_url(ltrim($r['link'], '/')) ?>"><?= esc($r['number']) ?></a></td><td><?= esc($r['warehouse']) ?></td><td><?= esc($r['since']) ?></td>
<td class="num"><?= (int) $r['age'] ?></td><td><?= $r['sent'] === 2 ? '<span class="badge bad">Managers</span>' : ($r['sent'] === 1 ? '<span class="badge warn">Reminded</span>' : '<span class="small muted">At the next daily run</span>') ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
