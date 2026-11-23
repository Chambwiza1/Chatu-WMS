<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Wave picking</h1><p class="sub"><?= esc(wh_name($wh)) ?> · pick several shipments in one walk through the bins</p></div><div class="actions"><a class="btn secondary" href="<?= site_url('shipments') ?>">Shipments</a></div></div>
<section class="panel"><h2>Start a wave</h2>
<?php if (count($candidates) < 2): ?><p class="muted">A wave needs at least two allocated shipments that are not already in an open wave. There <?= count($candidates) === 1 ? 'is one' : 'are none' ?> now.</p><?php else: ?>
<form method="post" action="<?= site_url('waves') ?>"><?= csrf_field() ?><input type="hidden" name="warehouse_id" value="<?= (int) $wh ?>">
<div class="table-wrap"><table><thead><tr><th><span class="sr">Include</span></th><th>Shipment</th><th>Order</th><th>Customer</th><th>Town</th><th>Needed by</th><th class="num">Lines</th></tr></thead><tbody>
<?php foreach ($candidates as $c): ?><tr><td><input type="checkbox" name="shipments[]" value="<?= (int) $c['id'] ?>" checked aria-label="Include <?= esc($c['number'], 'attr') ?>"></td><td><a href="<?= site_url('shipments/' . $c['id']) ?>"><?= esc($c['number']) ?></a></td><td><?= esc($c['order_no']) ?></td><td><?= esc($c['customer']) ?></td><td><?= esc($c['town']) ?></td><td><?= nice_date($c['required_date']) ?></td><td class="num"><?= (int) $c['n_lines'] ?></td></tr><?php endforeach ?>
</tbody></table></div>
<div class="row2 gap-top"><div class="field"><label for="note">Note (picker, area, truck)</label><input id="note" name="note" maxlength="250"></div><div><button class="btn" type="submit">Create wave</button></div></div></form><?php endif ?></section>
<section class="panel"><h2>Waves</h2><?php if (! $waves): ?><p class="muted">No waves yet.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Wave</th><th>Status</th><th class="num">Shipments</th><th>Note</th><th>Created</th></tr></thead><tbody>
<?php foreach ($waves as $v): ?><tr><td><a href="<?= site_url('waves/' . $v['id']) ?>"><?= esc($v['number']) ?></a></td><td><?= badge($v['status']) ?></td><td class="num"><?= (int) $v['n'] ?></td><td><?= esc($v['note']) ?></td><td><?= local_time($v['created_at']) ?> · <?= esc($v['created_name']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section>
<?= $this->endSection() ?>
