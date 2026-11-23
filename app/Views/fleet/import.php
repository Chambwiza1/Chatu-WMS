<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Vehicles from Chatu Assets</h1><p class="sub">Link your fleet to the asset register: matched on registration number</p></div><div class="actions"><a class="btn secondary" href="<?= site_url('fleet') ?>">Fleet</a></div></div>
<section class="panel"><h2><?= $plan === null ? '1. Download the register' : 'Check again or apply' ?></h2>
<?php if ($plan === null): ?><p>In Chatu Assets open <b>Reports → Vehicle register</b> and download the CSV. Choose that file here and press <b>Check</b>: nothing changes until you apply.</p>
<ul class="muted small"><li>A vehicle already in the fleet with the same registration gets its asset number and VIN, and make, model and insurance date where those are empty here.</li><li>Vehicles not yet in the fleet can be added.</li><li>Retired or disposed assets are skipped.</li></ul><?php endif ?>
<form method="post" action="<?= site_url('fleet/import') ?>" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="file">Vehicle register CSV</label><input id="file" type="file" name="file" accept=".csv,text/csv" required></div>
<div class="field"><label for="home_warehouse_id">Home warehouse for added vehicles</label><select id="home_warehouse_id" name="home_warehouse_id"><option value="">None</option><?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= esc($w['code'] . ' ' . $w['name']) ?></option><?php endforeach ?></select></div></div>
<label class="check"><input type="checkbox" name="add_new" value="1" checked> Add vehicles that are not in the fleet yet</label>
<div class="gap-top"><button class="btn secondary" type="submit">Check</button> <button class="btn" type="submit" name="apply" value="1" data-confirm="Apply the file to the fleet?">Apply</button></div></form></section>
<?php if ($plan !== null): $count = array_count_values(array_column($plan, 'action')) ?>
<section class="panel"><h2>What the file would do</h2><p><?= (int) ($count['match'] ?? 0) ?> to link · <?= (int) ($count['add'] ?? 0) ?> to add · <?= (int) ($count['skip'] ?? 0) ?> skipped. Choose the file again and press Apply to go ahead.</p>
<?php if (! $plan): ?><p class="muted">The file has no vehicles.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Asset</th><th>Registration</th><th>Description</th><th>Make and model</th><th>VIN</th><th>Action</th></tr></thead><tbody>
<?php foreach ($plan as $r): ?><tr><td class="mono"><?= esc($r['asset']) ?></td><td class="mono"><?= esc($r['reg']) ?></td><td><?= esc($r['desc']) ?></td><td><?= esc(trim($r['make'] . ' ' . $r['model'])) ?></td><td class="mono"><?= esc($r['vin']) ?></td>
<td><span class="badge <?= ['match' => 'good', 'add' => 'warn', 'skip' => 'quiet'][$r['action']] ?>"><?= ['match' => 'Link', 'add' => 'Add', 'skip' => 'Skip'][$r['action']] ?></span> <span class="muted small"><?= esc($r['why']) ?></span></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section><?php endif ?>
<?= $this->endSection() ?>
