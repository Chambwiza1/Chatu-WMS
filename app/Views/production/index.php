<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Production</h1></div><div class="actions"><a class="btn" href="<?= site_url('production/runs/new') ?>">Post a production run</a><a class="btn secondary" href="<?= site_url('production/recipes/new') ?>">New recipe</a><a class="btn secondary" href="<?= site_url('reports/production') ?>">Production report</a></div></div>
<h2>Recipes</h2>
<?php if (! $recipes): ?><div class="panel empty">No recipes yet. A recipe says what one batch uses and how much it makes.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Makes</th><th class="num">Materials</th><?php if (can('stock.view_cost')): ?><th class="num">Other cost per batch</th><?php endif ?><th></th></tr></thead><tbody>
<?php foreach ($recipes as $r): ?><tr class="<?= $r['active'] ? '' : 'muted' ?>"><td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['name']) ?></td><td><?= qty($r['output_qty']) ?> <?= esc($r['uom']) ?> <?= esc($r['sku']) ?></td><td class="num"><?= (int) $r['materials'] ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($r['other_cost']) ?></td><?php endif ?>
<td><a href="<?= site_url('production/recipes/' . $r['id']) ?>">Edit</a><?php if ($r['active']): ?> · <a href="<?= site_url('production/runs/new?recipe_id=' . $r['id']) ?>">Run</a><?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<h2>Latest runs</h2>
<?php if (! $runs): ?><div class="panel empty">No runs yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Run</th><th>Date</th><th>Where</th><th>Recipe</th><th>Batch made</th><th class="num">Expected</th><th class="num">Made</th><th class="num">Yield</th><?php if (can('stock.view_cost')): ?><th class="num">Cost each</th><?php endif ?><th>Status</th></tr></thead><tbody>
<?php foreach ($runs as $r): ?><tr><td><a href="<?= site_url('production/runs/' . $r['id']) ?>"><?= esc($r['number']) ?></a></td><td><?= nice_date($r['produced_on']) ?></td><td><?= esc($r['wh']) ?></td><td><?= esc($r['recipe']) ?></td><td><?= $r['lot_id'] ? '<a href="' . site_url('lots/' . $r['lot_id']) . '">' . esc($r['lot_no']) . '</a>' : '' ?></td>
<td class="num"><?= qty($r['expected_qty']) ?></td><td class="num"><?= qty($r['actual_qty']) ?></td><td class="num<?= (float) $r['actual_qty'] < 0.95 * (float) $r['expected_qty'] ? ' warn-text' : '' ?>"><?= round(100 * (float) $r['actual_qty'] / max(0.001, (float) $r['expected_qty']), 1) ?>%</td><?php if (can('stock.view_cost')): ?><td class="num"><?= money($r['unit_cost']) ?></td><?php endif ?><td><?= badge($r['status']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
