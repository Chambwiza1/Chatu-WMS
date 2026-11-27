<?php /** core 1.2: "Sign in as" on a person's page. Needs $u (the person). The same file in every product. */
$SIA = \App\Libraries\Core\SignInAs::class;
if (\App\Libraries\Core\Access::can(\App\Libraries\Platform\Product::SIGNIN_AS['use']) && (int) $u['id'] !== (int) \App\Libraries\Core\Auth::id() && ! $SIA::current()):
    $why = $SIA::adminBlocked((int) $u['id']); ?>
<?php if (! $why): ?><form method="post" action="<?= site_url('signin-as/' . $u['id']) ?>" class="panel inline-form"><?= csrf_field() ?>
<div class="field"><label for="sia_reason">Sign in as <?= esc($u['name']) ?>, to help them or see what they see</label><input id="sia_reason" name="reason" required minlength="5" maxlength="255" placeholder="Why (kept with the record)"></div>
<button class="btn secondary" type="submit">Sign in as <?= esc(explode(' ', (string) $u['name'])[0]) ?></button>
<span class="small muted">For at most an hour. What you save is saved as them and listed under Support access.</span></form>
<?php else: ?><p class="small muted">Sign in as this person: <?= esc($why) ?></p><?php endif ?>
<?php endif ?>
