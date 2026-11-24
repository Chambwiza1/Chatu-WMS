<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-wrap">
  <div class="login panel">
    <img class="brand-logo" src="<?= base_url('static/brand/logo-stacked.png') ?>" alt="Chatu Innovations">
<?php if (empty($u)): ?>
    <h1>This link no longer works</h1>
    <p>It has been used, replaced by a newer one, or is more than <?= (int) $minutes ?> minutes old.</p>
    <p><a class="btn" href="<?= site_url('forgot-password') ?>">Ask for a new link</a></p>
<?php else: ?>
    <h1>Choose a new password</h1>
    <p class="muted">For <?= esc($u['name']) ?> (<?= esc($u['username']) ?>). At least 10 characters, with letters and numbers.</p>
    <form method="post" action="<?= esc($action) ?>" class="stack">
      <?= csrf_field() ?>
      <div class="field"><label for="password">New password</label><input id="password" name="password" type="password" minlength="10" autocomplete="new-password" required autofocus></div>
      <div class="field"><label for="password2">The same again</label><input id="password2" name="password2" type="password" minlength="10" autocomplete="new-password" required></div>
      <button class="btn" type="submit">Save the new password</button>
    </form>
<?php endif ?>
    <p><a href="<?= site_url('login') ?>">Back to sign in</a></p>
  </div>
</div>
<?= $this->endSection() ?>
