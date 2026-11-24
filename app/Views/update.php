<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Updating Chatu Warehouse</title>
<link rel="icon" href="<?= base_url('static/brand/favicon-64.png') ?>" type="image/png">
<link rel="stylesheet" href="<?= base_url('static/app.css?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>">
<script src="<?= base_url('static/app.js?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>" defer></script>
</head>
<body>
<header class="topbar"><span class="brand"><img src="<?= base_url('static/brand/mark-96.png') ?>" alt="" width="40" height="30"><span><span class="name">Chatu Warehouse</span><span class="by"><?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?> · by Chatu Innovations</span></span></span></header>
<main id="main" class="main update-page">
<?php if ($error): ?><div class="flash err" role="alert"><?= esc($error) ?></div><?php endif ?>
<section class="panel">
<?php if ($may): ?>
<h1>Update the database</h1>
<p>The program has been updated to <b><?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?></b>, but the database has not caught up yet<?= $from !== '' ? ' (it is at ' . esc($from) . ')' : '' ?>. <?= count($todo) ?> database step<?= count($todo) > 1 ? 's are' : ' is' ?> waiting<?= $single ? '' : ', for every organisation at once' ?>. Until you press the button, everyone else sees "being updated".</p>
<ol class="small"><?php foreach ($todo as $t): ?><li><?= esc($t) ?></li><?php endforeach ?></ol>
<p><b>First take a backup of the database.</b> Existing data is kept: your company becomes an organisation of its own, and everyone keeps exactly what they may do.</p>
<form method="post" action="<?= site_url('update') ?>"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Have you taken a backup of the database? Update now?">Update the database</button></form>
<p class="small muted">It usually takes a few seconds. Do not close the page while it runs.</p>
<?php else: ?>
<h1>Chatu Warehouse is being updated</h1>
<p>A new version has been installed and the database is being brought up to date. This usually takes a few minutes. Please try again shortly.</p>
<?php if ($signedIn): ?><p class="small muted">The <?= $single ? 'administrator' : 'platform owner' ?> finishes the update by signing in and pressing <b>Update the database</b>.</p>
<form method="post" action="<?= site_url('logout') ?>" class="inline"><?= csrf_field() ?><button class="btn secondary" type="submit">Sign out</button></form> <a class="btn" href="<?= site_url('update') ?>">Try again</a>
<?php else: ?><p class="small muted">Are you the <?= $single ? 'administrator' : 'platform owner' ?>? <a href="<?= site_url('login') ?>">Sign in</a> to finish the update.</p><?php endif ?>
<?php endif ?>
</section>
</main>
<footer class="site-foot"><span class="foot-text"><span>Chatu Warehouse by Chatu Innovations · <a href="https://www.chatuzm.com" rel="noopener">www.chatuzm.com</a></span></span></footer>
</body>
</html>
