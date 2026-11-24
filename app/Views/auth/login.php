<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-wrap">
  <div class="login panel">
    <img class="brand-logo" src="<?= base_url('static/brand/logo-stacked.png') ?>" alt="Chatu Innovations">
    <p class="product">Chatu <span>Warehouse</span></p>
    <p class="tagline">Stock by bin, batch and serial. Receiving, transfers, production, dispatch and fleet.</p>
    <form method="post" action="<?= site_url('login') ?>" class="stack">
      <?= csrf_field() ?>
      <div class="field"><label for="username">Username</label><input id="username" name="username" type="text" autocomplete="username" required autofocus value="<?= esc(old('username')) ?>"></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="btn" type="submit">Sign in</button>
    </form>
    <p class="forgot"><a href="<?= site_url('forgot-password') ?>">Forgotten your password?</a></p>
    <p class="contact">Chatu Innovations · info@chatuzm.com · +260 773818370 · www.chatuzm.com</p>
  </div>
</div>
<?= $this->endSection() ?>
