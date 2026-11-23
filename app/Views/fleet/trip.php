<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Trip <?= esc($t['number']) ?> <?= badge($t['status']) ?></h1><p class="sub"><?= esc($t['reg_no']) ?> · <?= esc($t['driver']) ?><?= $t['driver_phone'] ? ' (' . esc($t['driver_phone']) . ')' : '' ?> · from <?= esc($t['wh']) ?> · <?= nice_date($t['planned_date']) ?></p></div><div class="actions no-print"><button class="btn secondary" data-print>Print trip sheet</button></div></div>
<?php if ($blockers): ?><div class="flash err"><?= esc(implode(' ', $blockers)) ?></div><?php endif ?>
<dl class="kpis"><div><dt>Load</dt><dd><?= number_format($load, 1) ?> kg</dd><?php if ($t['capacity_kg']): ?><dd class="muted small">of <?= number_format((float) $t['capacity_kg']) ?> kg</dd><?php endif ?></div><div><dt>Stops</dt><dd><?= count($stops) ?></dd></div>
<?php if ($t['odometer_out']): ?><div><dt>Left</dt><dd><?= local_time($t['departed_at'], 'H:i') ?></dd><dd class="muted small"><?= number_format((int) $t['odometer_out']) ?> km</dd></div><?php endif ?>
<?php if ($t['odometer_in']): ?><div><dt>Back</dt><dd><?= local_time($t['returned_at'], 'H:i') ?></dd><dd class="muted small"><?= number_format((int) $t['odometer_in'] - (int) $t['odometer_out']) ?> km driven</dd></div><?php endif ?></dl>
<?php if ($t['route_note']): ?><p><?= esc($t['route_note']) ?></p><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>#</th><th>What</th><th>To</th><th>Packages</th><th>Document</th><th>Stop</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($stops as $s): ?><tr><td><?= (int) $s['seq'] ?></td><td><?= $s['kind'] === 'shipment' ? ((can('dispatch.manage', (int) $t['warehouse_id']) || can('sales.manage', (int) $t['warehouse_id']) || can('stock.view', (int) $t['warehouse_id'])) ? '<a href="' . site_url('shipments/' . $s['doc_id']) . '">' . esc($s['number']) . '</a>' : esc($s['number'])) : '<a href="' . site_url('transfers/' . $s['doc_id']) . '">' . esc($s['number']) . '</a>' ?></td>
<td><?= $s['kind'] === 'shipment' ? esc($s['customer']) . ' <span class="muted small">' . esc($s['delivery_address'] ?: $s['town']) . '</span>' : 'Warehouse ' . esc($s['to_code']) ?></td><td><?= (int) $s['packages'] ?: '' ?></td><td><?= badge($s['doc_status']) ?></td><td><?= badge($s['status']) ?></td>
<td class="no-print"><?php if ($t['status'] === 'planned' && can('fleet.manage')): ?><form method="post" action="<?= site_url('fleet/trips/' . $t['id'] . '/unstop') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="stop_id" value="<?= $s['id'] ?>"><button class="btn small secondary" type="submit">Remove</button></form><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php if ($t['status'] === 'planned'): ?>
<?php if ($more && can('fleet.manage')): ?><form method="post" action="<?= site_url('fleet/trips/' . $t['id'] . '/stop') ?>" class="inline-form no-print"><?= csrf_field() ?><select name="shipment_id" aria-label="Shipment"><?php foreach ($more as $m): ?><option value="<?= $m['id'] ?>"><?= esc($m['number'] . ' ' . $m['customer']) ?></option><?php endforeach ?></select><button class="btn small secondary" type="submit">Add shipment</button></form><?php endif ?>
<div class="cols no-print"><?php if (can('dispatch.manage', (int) $t['warehouse_id'])): ?><form method="post" action="<?= site_url('fleet/trips/' . $t['id'] . '/depart') ?>" class="panel stack"><?= csrf_field() ?><?= token_field() ?><h2>Departure (at the gate)</h2>
<p class="muted small">Every shipment must be packed and every transfer allocated. Departing dispatches them all.</p>
<div class="field"><label for="odo">Odometer now</label><input id="odo" type="number" min="<?= (int) $t['odometer_km'] ?>" name="odometer" value="<?= (int) $t['odometer_km'] ?>" required></div>
<div><button class="btn" type="submit" data-confirm="The vehicle leaves now and everything on it is dispatched. Go ahead?">Record departure</button></div></form><?php endif ?>
<?php if (can('fleet.manage')): ?><form method="post" action="<?= site_url('fleet/trips/' . $t['id'] . '/cancel') ?>" class="panel stack"><?= csrf_field() ?><h2>Cancel the trip</h2><div class="field"><label for="why">Why</label><input id="why" name="why" required></div><div><button class="btn danger" type="submit">Cancel trip</button></div></form><?php endif ?></div>
<?php elseif ($t['status'] === 'on_road' && can('fleet.manage')): ?>
<form method="post" action="<?= site_url('fleet/trips/' . $t['id'] . '/complete') ?>" class="panel stack no-print"><?= csrf_field() ?><h2>Back at base</h2>
<p class="muted small">Record each delivery (delivered or failed) on its shipment first.</p>
<div class="row2"><div class="field"><label for="odoi">Odometer now</label><input id="odoi" type="number" min="<?= (int) $t['odometer_out'] ?>" name="odometer" required></div><div class="field"><label for="litres">Fuel bought (litres)</label><input id="litres" type="number" step="any" min="0" name="litres"></div></div>
<div class="field"><label for="fuel_cost">Fuel cost</label><input id="fuel_cost" type="number" step="0.01" min="0" name="fuel_cost"></div>
<div><button class="btn" type="submit">Close trip</button></div></form>
<?php endif ?>
<p class="print-only">Driver: ______________________ &nbsp; Gate: ______________________ &nbsp; Km out: ________ &nbsp; Km in: ________</p>
<?= $this->endSection() ?>
