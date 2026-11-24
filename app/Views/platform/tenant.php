<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1><?= esc($t['name']) ?> <?= badge($t['status']) ?></h1><p class="sub mono"><?= esc($t['slug']) ?> · <?= esc($t['country']) ?> · <?= esc($t['timezone']) ?></p></div></div>
<div class="grid2"><section class="panel"><h2>At a glance</h2><table class="kv"><tbody>
<tr><th>Account owner</th><td><?= esc((string) $row['owner_name']) ?></td></tr><tr><th>Active people</th><td><?= (int) $row['users'] ?></td></tr>
<tr><th>Active warehouses</th><td><?= (int) $row['locations'] ?></td></tr>
<tr><th>Since</th><td><?= local_time($t['created_at']) ?></td></tr><?php if ($t['trial_ends_at'] && $t['status'] === 'trial'): ?><tr><th>Trial ends</th><td><?= local_time($t['trial_ends_at']) ?></td></tr><?php endif ?></tbody></table></section>
<?php if ($t['status'] !== 'cancelled'): ?><section class="panel"><h2>Change status</h2>
<form method="post" action="<?= site_url('platform/tenants/' . $t['id'] . '/status') ?>" class="stack"><?= csrf_field() ?>
<div class="field"><label for="status">Status</label><select id="status" name="status"><?php foreach (\App\Libraries\Platform\Tenants::STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select><span class="hint">Suspended: read-only. Cancelled: nobody can sign in; cannot be undone here.</span></div>
<div class="field"><label for="reason">Reason (goes into both audit logs)</label><input id="reason" name="reason" required></div>
<div><button class="btn" type="submit" data-confirm="Change the status?">Change status</button></div></form></section><?php endif ?></div>
<?= view('platform/_tenant_billing', ['t' => $t]) ?>
<h2>History</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($log as $e): ?><tr><td><?= local_time($e['at']) ?></td><td><?= esc((string) $e['username']) ?></td><td><?= esc($e['action']) ?></td><td><?= esc((string) $e['detail']) ?> <span class="muted small"><?= esc((string) $e['after_json']) ?></span></td></tr><?php endforeach ?></tbody></table></div>
<?= $this->endSection() ?>
