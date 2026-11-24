<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Organisations</h1><p class="sub">Chatu Warehouse <?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?> · platform console. You see each organisation's name, status and counts, never its stock, documents or people.</p></div>
<?php if (\App\Libraries\Core\Access::platform('platform.tenants')): ?><div class="actions"><a class="btn" href="<?= site_url('platform/tenants/new') ?>">New organisation</a></div><?php endif ?></div>
<?php if ($pending): ?><section class="panel warn-panel"><h2>Database update needed</h2><p>The program files are version <?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?>; <?= count($pending) ?> database step<?= count($pending) > 1 ? 's are' : ' is' ?> waiting. One update brings every organisation up to date. Take a backup first. Existing data is kept.</p>
<form method="post" action="<?= site_url('platform/upgrade') ?>"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Have you taken a backup of the database? Update now?">Update the database</button></form></section><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>Organisation</th><th>Short name</th><th>Status</th><th>Account owner</th><th class="num">People</th><th class="num">Warehouses</th><th>Since</th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="7" class="empty">No organisations yet.</td></tr><?php endif ?>
<?php foreach ($rows as $t): ?><tr class="<?= $t['status'] === 'cancelled' ? 'muted' : '' ?>"><td><a href="<?= site_url('platform/tenants/' . $t['id']) ?>"><?= esc($t['name']) ?></a></td><td class="mono"><?= esc($t['slug']) ?></td><td><?= badge($t['status']) ?></td><td><?= esc((string) $t['owner_name']) ?></td>
<td class="num"><?= (int) $t['users'] ?></td><td class="num"><?= (int) $t['locations'] ?></td><td><?= local_time($t['created_at'], 'j M Y') ?></td></tr><?php endforeach ?>
</tbody></table></div>
<section class="panel"><h2>Scheduled job</h2>
<ul class="health"><li><span class="mark <?= $tick !== null && $tick < 600 ? '' : 'bad' ?>"></span><span><?= $tick === null ? 'Has never run' : 'Last ran ' . ($tick < 120 ? 'just now' : round($tick / 60) . ' minutes ago') ?><small>Every minute: e-mail, per-organisation jobs, the nightly integrity check<?= $last ? ' (last: ' . (int) $last['problems'] . ' problems, ' . local_time($last['at']) . ')' : '' ?>. <a href="<?= site_url('health') ?>">/health</a> answers 503 when it has not run for 3 minutes.</small></span></li></ul>
<?php if ($cronKey): ?><p class="small">cPanel Cron Jobs, every minute: <code>php <?= esc(ROOTPATH) ?>spark wms:cron</code><br>Or have an outside service call: <code><?= esc(site_url('cron/run?key=' . $cronKey)) ?></code></p><?php endif ?>
<?php if ($jobs): ?><div class="table-wrap"><table><thead><tr><th>Started</th><th>Finished</th><th>Outcome</th></tr></thead><tbody><?php foreach ($jobs as $j): ?><tr><td><?= local_time($j['started_at'], 'j M H:i:s') ?></td><td><?= local_time($j['finished_at'], 'j M H:i:s') ?></td><td class="small mono"><?= esc($j['outcome']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section>
<?= $this->endSection() ?>
