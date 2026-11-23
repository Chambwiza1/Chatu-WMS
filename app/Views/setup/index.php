<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Warehouses and bins</h1></div></div>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Kind</th><th>Town</th><th>Picking</th><th class="num">Zones</th><th class="num">Bins</th><th class="num">Units in stock</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $w): ?><tr class="<?= $w['active'] ? '' : 'muted' ?>"><td class="mono"><a href="<?= site_url('warehouses/' . $w['id']) ?>"><?= esc($w['code']) ?></a></td><td><?= esc($w['name']) ?></td><td><?= esc($w['kind']) ?></td><td><?= esc($w['town']) ?></td><td><?= strtoupper($w['pick_policy']) ?></td><td class="num"><?= (int) $w['zones'] ?></td><td class="num"><?= (int) $w['bins'] ?></td><td class="num"><?= qty($w['units']) ?></td><td><?= $w['active'] ? '' : '<span class="badge quiet">Closed</span>' ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php if ($all): ?><section class="panel"><h2>Add a warehouse</h2><form method="post" action="<?= site_url('warehouses') ?>" class="stack"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="code">Code</label><input id="code" class="upper" name="code" required maxlength="20"></div><div class="field"><label for="name">Name</label><input id="name" name="name" required></div></div>
<div class="row2"><div class="field"><label for="kind">Kind</label><select id="kind" name="kind"><option value="warehouse">Warehouse</option><option value="depot">Depot</option><option value="factory">Factory</option><option value="store">Store</option></select></div><div class="field"><label for="town">Town</label><input id="town" name="town"></div></div>
<div class="row2"><div class="field"><label for="address">Address</label><input id="address" name="address"></div><div class="field"><label for="pick_policy">Picking order</label><select id="pick_policy" name="pick_policy"><option value="fefo">Earliest expiry first (FEFO)</option><option value="fifo">Oldest batch first (FIFO)</option></select></div></div>
<div><button class="btn" type="submit">Add warehouse</button></div></form></section><?php endif ?>
<?= $this->endSection() ?>
