<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-wrap">
  <div class="login panel">
    <img class="brand-logo" src="<?= base_url('static/brand/logo-stacked.png') ?>" alt="Chatu Innovations">
    <h1>Forgotten your password?</h1>
<?php if (! empty($sent)): ?>
    <p>If that sign-in has an e-mail address, a link has been sent to it. Open it within <?= (int) $minutes ?> minutes to choose a new password.</p>
    <p class="muted small">Nothing arrived? Check the spelling and your junk folder, or ask your administrator to set a new password for you.</p>
<?php elseif (empty($available)): ?>
    <p>This system does not send password links by e-mail. Ask your administrator to set a new password for you (People, your name, New password).</p>
<?php else: ?>
    <p class="muted">Give your username or e-mail address. We will e-mail you a link to choose a new password.</p>
    <form method="post" action="<?= site_url('forgot-password') ?>" class="stack">
      <?= csrf_field() ?>
<?php if (! empty($askOrg)): ?>
      <div class="field"><label for="organisation">Organisation</label><input id="organisation" name="organisation" type="text" autocomplete="organization" required value="<?= esc($org ?? '') ?>"></div>
<?php endif ?>
      <div class="field"><label for="username">Username or e-mail</label><input id="username" name="username" type="text" autocomplete="username" required autofocus></div>
      <button class="btn" type="submit">Send me a link</button>
    </form>
    <p class="muted small">No e-mail address on your account? Ask your administrator to set a new password for you.</p>
<?php endif ?>
    <p><a href="<?= site_url('login') ?>">Back to sign in</a></p>
  </div>
</div>
<?= $this->endSection() ?>
