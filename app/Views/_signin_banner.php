<?php /** core 1.2: the banner while signed in as someone. The same file in every product. */
if ($sia = \App\Libraries\Core\SignInAs::current()): $who = \App\Libraries\Core\Auth::user(); ?>
<div class="imp-banner" role="status"><span>You are signed in as <b><?= esc((string) ($who['name'] ?? '')) ?></b><?= $sia['kind'] === 'support' ? ' (support access)' : '' ?>. What you save is saved as them and listed for the organisation. Reason: <?= esc($sia['reason']) ?></span>
<form method="post" action="<?= site_url('signin-as/stop') ?>"><?= csrf_field() ?><button class="btn" type="submit">Stop and go back</button></form></div>
<?php endif ?>
