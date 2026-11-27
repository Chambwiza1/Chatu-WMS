<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1><?= esc($w['code']) ?> <?= esc($w['name']) ?></h1><p class="sub"><?= esc(ucfirst($w['kind'])) ?><?= $w['town'] ? ' · ' . esc($w['town']) : '' ?></p></div><div class="actions"><?php if (can('user.view')): ?><a class="btn secondary" href="<?= site_url('admin/where/' . $w['id']) ?>">Team</a><?php endif ?><a class="btn secondary" href="<?= site_url('labels/bins/' . $w['id']) ?>">Print bin labels</a></div></div>
<div class="split2"><div>
<h2>Bins</h2>
<div class="table-wrap"><table><thead><tr><th>Bin</th><th>Zone</th><th>Type</th><th>Pick from</th><th class="num">Units</th><th>Status</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($bins as $b): ?><tr><td class="mono"><?= esc($b['code']) ?><?= $b['is_system'] ? ' <span class="badge quiet">system</span>' : '' ?></td><td><?= esc($b['zone']) ?></td><td><?= esc(\App\Libraries\Master\Warehouses::BIN_TYPES[$b['type']]) ?></td><td><?= $b['pickable'] ? 'yes' : '' ?></td><td class="num"><?= (float) $b['units'] ? qty($b['units']) : '' ?></td><td><?= badge($b['status']) ?><?= $b['note'] ? ' <span class="muted small">' . esc($b['note']) . '</span>' : '' ?></td>
<td class="no-print"><?php if (! $b['is_system']): ?><form method="post" action="<?= site_url('bins/' . $b['id'] . '/status') ?>" class="inline-form"><?= csrf_field() ?><select name="status" aria-label="Status"><?php foreach (['active', 'blocked', 'inactive'] as $s): ?><option value="<?= $s ?>" <?= $b['status'] === $s ? 'selected' : '' ?>><?= esc(state_label($s)) ?></option><?php endforeach ?></select><input name="note" placeholder="Note" aria-label="Note"><button class="btn small secondary" type="submit">Set</button></form><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<p class="muted small">A blocked bin takes nothing in and is not picked from (racking damaged, pest control). An inactive bin must be empty.</p></div>
<div class="no-print">
<form method="post" action="<?= site_url('warehouses/' . $w['id']) ?>" class="panel stack"><?= csrf_field() ?><h2>Warehouse</h2>
<div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= esc($w['name']) ?>"></div>
<div class="field"><label for="kind">Kind</label><select id="kind" name="kind"><?php foreach (['warehouse', 'depot', 'factory', 'store'] as $k): ?><option <?= $w['kind'] === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach ?></select></div>
<div class="field"><label for="town">Town</label><input id="town" name="town" value="<?= esc($w['town']) ?>"></div><div class="field"><label for="address">Address</label><input id="address" name="address" value="<?= esc($w['address']) ?>"></div>
<div class="field"><label for="pick_policy">Picking order</label><select id="pick_policy" name="pick_policy"><option value="fefo" <?= $w['pick_policy'] === 'fefo' ? 'selected' : '' ?>>Earliest expiry first (FEFO)</option><option value="fifo" <?= $w['pick_policy'] === 'fifo' ? 'selected' : '' ?>>Oldest batch first (FIFO)</option></select></div>
<label class="check"><input type="checkbox" name="active" value="1" <?= $w['active'] ? 'checked' : '' ?>> Open</label><div><button class="btn secondary" type="submit">Save</button></div></form>
<form method="post" action="<?= site_url('warehouses/' . $w['id'] . '/zones') ?>" class="panel stack"><?= csrf_field() ?><h2>Add a zone</h2>
<div class="row2"><div class="field"><label for="zcode">Code</label><input id="zcode" class="upper" name="code" required></div><div class="field"><label for="zname">Name</label><input id="zname" name="name" placeholder="Cold room, racking A ..."></div></div><div><button class="btn secondary" type="submit">Add zone</button></div></form>
<form method="post" action="<?= site_url('warehouses/' . $w['id'] . '/bins') ?>" class="panel stack"><?= csrf_field() ?><h2>Add bins</h2>
<p class="muted small">One bin by its code, or a whole rack: prefix A, aisles 1-3, bays 1-10, levels 1-4 makes A-01-01-1 to A-03-10-4.</p>
<div class="field"><label for="bcode">One bin</label><input id="bcode" class="upper" name="code" placeholder="e.g. COLD-1"></div>
<div class="row2"><div class="field"><label for="prefix">or rack prefix</label><input id="prefix" class="upper" name="prefix"></div><div class="field"><label for="aisles">Aisles</label><input id="aisles" name="aisles" placeholder="1-3"></div></div>
<div class="row2"><div class="field"><label for="bays">Bays</label><input id="bays" name="bays" placeholder="1-10"></div><div class="field"><label for="levels">Levels</label><input id="levels" name="levels" placeholder="1-4"></div></div>
<div class="row2"><div class="field"><label for="btype">Type</label><select id="btype" name="type"><?php foreach (\App\Libraries\Master\Warehouses::BIN_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'storage' ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="bzone">Zone</label><select id="bzone" name="zone_id"><option value="">None</option><?php foreach ($zones as $z): ?><option value="<?= $z['id'] ?>"><?= esc($z['code'] . ' ' . $z['name']) ?></option><?php endforeach ?></select></div></div>
<div><button class="btn secondary" type="submit">Add bins</button></div></form></div></div>
<?= $this->endSection() ?>
