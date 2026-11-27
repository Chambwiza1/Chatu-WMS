<?php /** core 1.2: support access on an organisation's page in the console. Needs $t (the organisation). The same file in every product. */
if (\App\Libraries\Core\Access::platform('platform.support')): $supportUntil = \App\Libraries\Core\SignInAs::supportUntil((int) $t['id']); ?>
<section class="panel stack"><h2>Support</h2>
<?php if ($supportUntil): ?><p><span class="badge good">Open</span> This organisation lets support sign in as its account owner until <b><?= local_time($supportUntil, 'D j M Y, H:i') ?></b>.</p>
<form method="post" action="<?= site_url('platform/tenants/' . $t['id'] . '/signin-as') ?>" class="inline-form"><?= csrf_field() ?>
<input type="text" name="reason" required minlength="5" maxlength="255" placeholder="Why (the organisation sees this)" aria-label="Why">
<button class="btn" type="submit">Sign in as the account owner</button></form>
<p class="small muted">The account owner is told at once. Everything you save is listed for the organisation. You cannot change passwords or keys.</p>
<?php else: ?><p><span class="badge quiet">Closed</span> You cannot sign in to this organisation. Ask its administrator to open it under Support access; it then stays open for the time they choose.</p><?php endif ?></section>
<?php endif ?>
