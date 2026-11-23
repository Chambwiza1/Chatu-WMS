<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Administration</h1><p class="sub">Chatu Warehouse <?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?></p></div></div>
<?php if ($pending): ?><section class="panel warn-panel"><h2>Database update needed</h2><p>The program files are version <?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?> but the database has not been brought up to date (<?= count($pending) ?> step<?= count($pending) > 1 ? 's' : '' ?>). Take a database backup first, then update. Existing data is kept.</p>
<form method="post" action="<?= site_url('admin/upgrade') ?>"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Have you taken a backup of the database? Update now?">Update the database</button></form></section><?php endif ?>
<ul class="attn">
<?php if (can('admin.users')): ?><li><a href="<?= site_url('admin/users') ?>"><span class="n"><?= $users ?></span><span>Users and roles<small>Accounts, and what each person may do where</small></span></a></li>
<li><a href="<?= site_url('admin/roles') ?>"><span>What each role may do<small>Permissions and the duties that must stay apart</small></span></a></li><?php endif ?>
<?php if (can('admin.settings')): ?><li><a href="<?= site_url('admin/settings') ?>"><span>Settings and reason codes<small>Limits, warnings, e-mail</small></span></a></li><?php endif ?>
<li><a href="<?= site_url('admin/reconcile') ?>"><span class="n <?= $last && $last['problems'] ? 'bad' : '' ?>"><?= $last ? (int) $last['problems'] : '–' ?></span><span>Integrity check<small><?= $last ? 'Last run ' . local_time($last['at']) : 'Not run yet' ?></small></span></a></li>
<?php if (can('warehouse.setup')): ?><li><a href="<?= site_url('warehouses') ?>"><span>Warehouses and bins<small>Zones, racks, blocked bins</small></span></a></li><?php endif ?>
</ul>
<section class="panel"><h2>Scheduled job</h2>
<ul class="health"><li><span class="mark <?= $cron > time() - 900 ? '' : 'bad' ?>"></span><span><?= $cron ? 'Last ran ' . local_time(gmdate('Y-m-d H:i:s', $cron)) : 'Has never run' ?><small>It sends e-mail, runs the nightly integrity check and the daily expiry, transfer and fleet reminders. Run it every 5 minutes.</small></span></li></ul>
<?php if ($cronKey): ?><p class="small">Cron command: <code>php <?= esc(ROOTPATH) ?>spark wms:cron</code><br>Or call: <code><?= esc(site_url('cron/run?key=' . $cronKey)) ?></code></p><?php endif ?>
<?php if ($jobs): ?><div class="table-wrap"><table><thead><tr><th>Started</th><th>Finished</th><th>Outcome</th></tr></thead><tbody><?php foreach ($jobs as $j): ?><tr><td><?= local_time($j['started_at']) ?></td><td><?= local_time($j['finished_at']) ?></td><td class="small mono"><?= esc($j['outcome']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section>
<?= $this->endSection() ?>
