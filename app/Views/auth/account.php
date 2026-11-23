<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1><?= esc($me['name']) ?></h1><p class="sub"><?= esc($me['username']) ?><?= $me['position'] ? ' · ' . esc($me['position']) : '' ?></p></div></div>
<div class="cols">
<section class="panel"><h2>What you may do</h2>
<?php if (! $roles): ?><p class="muted">No roles yet. Ask the administrator.</p><?php else: ?><ul><?php foreach ($roles as $r): ?><li><b><?= esc($r['name']) ?></b>, <?= $r['scope_type'] === 'system' ? 'whole company' : esc($r['wh_code'] . ' ' . $r['wh_name']) ?></li><?php endforeach ?></ul><?php endif ?></section>
<section class="panel"><h2>Change your password</h2>
<form method="post" action="<?= site_url('account/password') ?>" class="stack"><?= csrf_field() ?>
<div class="field"><label for="current">Current password</label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
<div class="field"><label for="new">New password</label><input id="new" name="new" type="password" autocomplete="new-password" minlength="10" required><span class="hint">10 characters or more, letters and numbers.</span></div>
<div class="field"><label for="confirm">New password again</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required></div>
<div><button class="btn" type="submit">Change password</button></div></form></section></div>
<?= $this->endSection() ?>
