<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1><?= esc($me['name']) ?></h1><p class="sub"><?= esc($me['username']) ?><?= $me['position'] ? ' · ' . esc($me['position']) : '' ?></p></div></div>
<div class="cols">
<section class="panel"><h2>What you may do</h2>
<?php if (! $roles): ?><p class="muted">No roles<?= $org ? ' in ' . esc($org['name']) : '' ?>. Ask the administrator.</p><?php else: ?><ul><?php foreach ($roles as $r): ?><li><b><?= esc($r['name']) ?></b><?= $r['scope_type'] === 'platform' ? ' (platform)' : '' ?></li><?php endforeach ?></ul><?php endif ?>
<?php if ($org): ?><p class="small">Warehouses: <?= $wh['all'] ? 'all of them' : (esc(implode(', ', $whCodes)) ?: '<span class="bad-text">none yet</span>') ?>.</p><?php endif ?></section>
<section class="panel"><h2>Light or dark</h2>
<form method="post" action="<?= site_url('account/theme') ?>" class="stack"><?= csrf_field() ?>
<?php $th = \App\Libraries\Core\Auth::theme(); ?>
<div class="field"><label for="theme">On every device you sign in on</label><select id="theme" name="theme" data-theme-account>
<?php foreach (['auto' => 'Follow the device', 'light' => 'Light', 'dark' => 'Dark'] as $k => $l): ?><option value="<?= $k ?>" <?= $th === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
<span class="hint">The button at the top changes it for this device only, for example a shared screen in a dark store.</span></div>
<div class="btn-row"><button class="btn" type="submit">Save</button> <button class="btn secondary" type="button" data-theme-device-clear hidden>Use my account's choice on this device</button></div></form></section>
<section class="panel"><h2>Change your password</h2>
<form method="post" action="<?= site_url('account/password') ?>" class="stack"><?= csrf_field() ?>
<div class="field"><label for="current">Current password</label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
<div class="field"><label for="new">New password</label><input id="new" name="new" type="password" autocomplete="new-password" minlength="10" required><span class="hint">10 characters or more, letters and numbers.</span></div>
<div class="field"><label for="confirm">New password again</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required></div>
<div><button class="btn" type="submit">Change password</button></div></form></section></div>
<?= $this->endSection() ?>
