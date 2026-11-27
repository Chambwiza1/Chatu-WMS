<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-wrap">
  <div class="login panel">
    <img class="brand-logo" src="<?= base_url('static/brand/logo-stacked.png') ?>" alt="Chatu Innovations">
<?php if ($mode === 'self'): ?>
    <h1>Check your e-mail</h1>
    <p>We have sent a link<?= empty($email) ? '' : ' to ' . esc($email) ?>. Open it within <?= (int) $hours ?> hours to create your organisation and start your trial.</p>
    <p class="muted small">Nothing arrived? Check your junk folder, or sign up again with the right address.</p>
<?php else: ?>
    <h1>Thank you</h1>
    <p>We have your request for <?= esc($product) ?> and will contact you to set up your account.</p>
<?php endif ?>
    <p><a href="<?= site_url('login') ?>">Back to sign in</a></p>
  </div>
</div>
<?= $this->endSection() ?>
