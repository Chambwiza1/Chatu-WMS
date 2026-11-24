<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Updating ChatuPOS</title>
<link rel="icon" href="<?= base_url('static/brand/favicon-64.png') ?>" type="image/png">
<link rel="stylesheet" href="<?= base_url('static/app.css?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>">
<script src="<?= base_url('static/theme.js?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>"></script>
</head>
<body class="public">
<header class="public-head"><span class="org-name">ChatuPOS</span><span class="small muted"><?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?></span></header>
<main id="main" class="public-main update-page">
<?php if ($error): ?><div class="flash err" role="alert"><?= esc($error) ?></div><?php endif ?>
<section class="panel">
<?php if ($may): ?>
<h1>Update the database</h1>
<p>The program has been updated to <b><?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?></b>, but the database has not caught up yet. <?= count($todo) ?> database step<?= count($todo) > 1 ? 's are' : ' is' ?> waiting. Until you press the button, everyone else sees "being updated".</p>
<ol class="small"><?php foreach ($todo as $t): ?><li><?= esc($t) ?></li><?php endforeach ?></ol>
<p><b>First take a backup of the database</b> (cPanel: phpMyAdmin, Export). Existing data is kept; the update only adds.</p>
<form method="post" action="<?= site_url('update') ?>" data-confirm="Have you taken a backup of the database? Update now?"><?= csrf_field() ?><button class="btn" type="submit">Update the database</button></form>
<p class="small muted">It usually takes a few seconds. Do not close the page while it runs.</p>
<?php else: ?>
<h1>ChatuPOS is being updated</h1>
<p>A new version has been installed and the database is being brought up to date. This usually takes a few minutes. Please try again shortly.</p>
<?php if ($signedIn): ?><p class="small muted">The <?= $single ? 'administrator' : 'platform owner' ?> finishes the update by signing in and pressing <b>Update the database</b>.</p>
<form method="post" action="<?= site_url('logout') ?>" class="inline"><?= csrf_field() ?><button class="btn secondary" type="submit">Sign out</button></form> <a class="btn" href="<?= site_url('update') ?>">Try again</a>
<?php else: ?><p class="small muted">Are you the <?= $single ? 'administrator' : 'platform owner' ?>? <a href="<?= site_url('login') ?>">Sign in</a> to finish the update.</p><?php endif ?>
<?php endif ?>
</section>
</main>
<footer class="public-foot small muted">ChatuPOS by Chatu Innovations · <a href="https://www.chatuzm.com" rel="noopener">www.chatuzm.com</a></footer>
<script src="<?= base_url('static/popup.js?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>" defer></script>
</body>
</html>
