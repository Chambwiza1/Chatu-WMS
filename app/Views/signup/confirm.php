<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-wrap">
  <div class="login panel">
    <img class="brand-logo" src="<?= base_url('static/brand/logo-stacked.png') ?>" alt="Chatu Innovations">
    <h1>Confirm your sign-up</h1>
<?php if (! $s): ?>
    <p><?= esc($error ?? 'This link has been used or is too old.') ?></p>
    <p><a href="<?= site_url('signup') ?>">Sign up again</a> · <a href="<?= site_url('login') ?>">Sign in</a></p>
<?php else: ?>
    <p>Create <b><?= esc($s['org_name']) ?></b> on <?= esc($product) ?>, with the sign-in <b><?= esc((string) $s['username']) ?></b>.</p>
    <form method="post" action="<?= esc($action, 'attr') ?>" class="stack"><?= csrf_field() ?><button class="btn" type="submit">Create my organisation</button></form>
<?php endif ?>
  </div>
</div>
<?= $this->endSection() ?>
