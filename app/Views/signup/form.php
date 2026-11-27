<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-wrap">
  <div class="login panel signup">
    <img class="brand-logo" src="<?= base_url('static/brand/logo-stacked.png') ?>" alt="Chatu Innovations">
    <h1><?= $mode === 'self' ? 'Sign up for ' . esc($product) : 'Ask for ' . esc($product) ?></h1>
<?php if (! $open): ?>
    <p>Sign-up is not open on this site. Please contact Chatu Innovations: <a href="mailto:info@chatuzm.com">info@chatuzm.com</a>, +260 773818370.</p>
<?php else: ?>
    <p class="muted"><?= $mode === 'self'
        ? 'Create your organisation and its first sign-in. We e-mail you a link to confirm; your free trial starts when you open it.'
        : 'Tell us what you need. We read every request and contact you to set up your account.' ?></p>
<?php if ($error): ?><p class="flash err" role="alert"><?= esc($error) ?></p><?php endif ?>
    <form method="post" action="<?= site_url('signup') ?>" class="stack">
      <?= csrf_field() ?>
      <div class="field"><label for="org_name">Organisation</label><input id="org_name" name="org_name" type="text" maxlength="150" autocomplete="organization" required value="<?= esc((string) ($old['org_name'] ?? ''), 'attr') ?>"></div>
      <div class="field"><label for="contact_name">Your name</label><input id="contact_name" name="contact_name" type="text" maxlength="150" autocomplete="name" required value="<?= esc((string) ($old['contact_name'] ?? ''), 'attr') ?>"></div>
      <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= esc((string) ($old['email'] ?? ''), 'attr') ?>"></div>
      <div class="field"><label for="phone">Phone<?= $mode === 'self' ? ' (optional)' : '' ?></label><input id="phone" name="phone" type="text" inputmode="tel" maxlength="25" autocomplete="tel" <?= $mode === 'self' ? '' : 'required' ?> value="<?= esc((string) ($old['phone'] ?? ''), 'attr') ?>"></div>
<?php foreach ($fields as $key => $f): $v = (string) ($old['q'][$key] ?? ''); ?>
      <div class="field"><label for="q_<?= esc($key, 'attr') ?>"><?= esc($f['label']) ?></label>
<?php if (isset($f['options'])): ?>
        <select id="q_<?= esc($key, 'attr') ?>" name="q[<?= esc($key, 'attr') ?>]" <?= empty($f['required']) ? '' : 'required' ?>><option value="">Choose…</option>
<?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= esc((string) $ov, 'attr') ?>" <?= $v === (string) $ov ? 'selected' : '' ?>><?= esc($ol) ?></option><?php endforeach ?></select>
<?php elseif (($f['type'] ?? '') === 'number'): ?>
        <input id="q_<?= esc($key, 'attr') ?>" name="q[<?= esc($key, 'attr') ?>]" type="number" min="0" max="100000" <?= empty($f['required']) ? '' : 'required' ?> value="<?= esc($v, 'attr') ?>">
<?php elseif (($f['type'] ?? '') === 'long'): ?>
        <textarea id="q_<?= esc($key, 'attr') ?>" name="q[<?= esc($key, 'attr') ?>]" rows="3" maxlength="500" <?= empty($f['required']) ? '' : 'required' ?>><?= esc($v) ?></textarea>
<?php else: ?>
        <input id="q_<?= esc($key, 'attr') ?>" name="q[<?= esc($key, 'attr') ?>]" type="text" maxlength="200" <?= empty($f['required']) ? '' : 'required' ?> value="<?= esc($v, 'attr') ?>">
<?php endif ?>
<?php if (! empty($f['hint'])): ?><span class="hint"><?= esc($f['hint']) ?></span><?php endif ?></div>
<?php endforeach ?>
<?php if ($mode === 'self'): ?>
      <div class="field"><label for="username">Username to sign in with</label><input id="username" name="username" type="text" maxlength="60" autocomplete="username" required pattern="[A-Za-z0-9._\-]{3,60}" value="<?= esc((string) ($old['username'] ?? ''), 'attr') ?>"></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="10"><span class="hint">At least 10 characters, with letters and numbers.</span></div>
      <div class="field"><label for="password2">Password again</label><input id="password2" name="password2" type="password" autocomplete="new-password" required minlength="10"></div>
<?php endif ?>
      <div class="field hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
      <label class="check"><input type="checkbox" name="agree" value="1" required <?= empty($old['agree']) ? '' : 'checked' ?>> Chatu Innovations may keep these details to set up and run my account.</label>
      <button class="btn" type="submit"><?= $mode === 'self' ? 'Sign up' : 'Send my request' ?></button>
    </form>
<?php endif ?>
    <p><a href="<?= site_url('login') ?>">I already have a sign-in</a></p>
  </div>
</div>
<?= $this->endSection() ?>
