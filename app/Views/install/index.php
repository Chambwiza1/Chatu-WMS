<?php $o = static fn ($k, $d = '') => esc((string) ($old[$k] ?? $d), 'attr'); $allOk = ! in_array(false, array_column($req, 1), true); $b = rtrim($base, '/'); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install Chatu Warehouse</title><link rel="icon" href="<?= esc($b) ?>/static/brand/favicon-64.png" type="image/png">
<link rel="stylesheet" href="<?= esc($b) ?>/static/app.css"></head>
<body data-base="<?= esc($b, 'attr') ?>">
<header class="topbar"><span class="brand"><img src="<?= esc($b) ?>/static/brand/mark-96.png" alt="" width="40" height="30"><span><span class="name">Chatu Warehouse</span><span class="by">by Chatu Innovations</span></span></span></header>
<main class="main" id="main">
<div class="page-head"><div><h1>Install</h1><p class="sub">About two minutes. You need an empty MySQL or MariaDB database and its user (in cPanel: MySQL Databases).</p></div></div>
<?php if ($error): ?><div class="flash err" role="alert"><?= esc($error) ?></div><?php endif ?>
<section class="panel"><h2>Server check</h2>
<div class="table-wrap"><table><tbody>
<?php foreach ($req as [$label, $ok, $help]): ?><tr><td><?= esc($label) ?></td><td><?= $ok ? '<span class="badge good">OK</span>' : '<span class="badge bad">Fix this</span> <span class="muted">' . esc($help) . '</span>' ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<?php if ($allOk): ?>
<form method="post" action="<?= esc($b) ?>/install" class="stack">
<?= csrf_field() ?>
<fieldset><legend>Site</legend>
  <div class="field"><label for="base_url">Site address</label><input id="base_url" name="base_url" type="text" required value="<?= $o('base_url', $base) ?>"><span class="hint">Use https:// if the site has a certificate (cPanel: SSL/TLS Status, AutoSSL). Use https for live use.</span></div>
  <div class="field"><label for="timezone">Time zone</label><input id="timezone" name="timezone" type="text" value="<?= $o('timezone', 'Africa/Lusaka') ?>"></div>
</fieldset>
<fieldset><legend>Database</legend>
  <div class="row2"><div class="field"><label for="db_host">Server</label><input id="db_host" name="db_host" type="text" required value="<?= $o('db_host', 'localhost') ?>"></div><div class="field"><label for="db_port">Port</label><input id="db_port" name="db_port" type="number" value="<?= $o('db_port', '3306') ?>"></div></div>
  <div class="row2"><div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" type="text" required value="<?= $o('db_name') ?>"><span class="hint">On cPanel it starts with your account name, e.g. acme_wms.</span></div><div class="field"><label for="db_prefix">Table prefix</label><input id="db_prefix" name="db_prefix" type="text" value="<?= $o('db_prefix', 'cw_') ?>"></div></div>
  <div class="row2"><div class="field"><label for="db_user">Database user</label><input id="db_user" name="db_user" type="text" required value="<?= $o('db_user') ?>"></div><div class="field"><label for="db_pass">Password</label><input id="db_pass" name="db_pass" type="password" autocomplete="off"></div></div>
</fieldset>
<fieldset><legend>Edition</legend>
  <label class="check"><input type="radio" name="edition" value="single" <?= ($old['edition'] ?? 'single') === 'single' ? 'checked' : '' ?>><span><b>Single company</b>, for one organisation that keeps its own data. Your account is that organisation's <b>account owner</b>.</span></label><br>
  <label class="check"><input type="radio" name="edition" value="saas" <?= ($old['edition'] ?? '') === 'saas' ? 'checked' : '' ?>><span><b>Online service</b> for many organisations. Your account is the <b>platform owner</b>: you add organisations, set their plans and modules, and never see their stock.</span></label>
</fieldset>
<fieldset><legend>Your company and first warehouse (single company only)</legend>
  <div class="row2"><div class="field"><label for="company">Company name</label><input id="company" name="company" type="text" value="<?= $o('company') ?>"></div><div class="field"><label for="currency_symbol">Currency symbol</label><input id="currency_symbol" name="currency_symbol" type="text" maxlength="5" value="<?= $o('currency_symbol', 'K') ?>"></div></div>
  <div class="row2"><div class="field"><label for="wh_code">Warehouse code</label><input id="wh_code" name="wh_code" type="text" maxlength="20" value="<?= $o('wh_code', 'MAIN') ?>"></div><div class="field"><label for="wh_name">Warehouse name</label><input id="wh_name" name="wh_name" type="text" value="<?= $o('wh_name', 'Main warehouse') ?>"></div></div>
  <div class="field"><label for="wh_town">Town</label><input id="wh_town" name="wh_town" type="text" value="<?= $o('wh_town') ?>"></div>
  <p class="hint">More warehouses, zones and bins are added afterwards under Warehouses and bins.</p>
</fieldset>
<fieldset><legend>Your account</legend>
  <p class="hint">Single company: the account owner, who may do everything and gives everyone else their roles and warehouses. Online service: the platform owner. You can sign in with the username or the e-mail address.</p>
  <div class="row2"><div class="field"><label for="admin_username">Username</label><input id="admin_username" name="admin_username" type="text" required value="<?= $o('admin_username', 'admin') ?>"></div><div class="field"><label for="admin_name">Your name</label><input id="admin_name" name="admin_name" type="text" required value="<?= $o('admin_name') ?>"></div></div>
  <div class="field"><label for="admin_email">E-mail</label><input id="admin_email" name="admin_email" type="email" value="<?= $o('admin_email') ?>"></div>
  <div class="row2"><div class="field"><label for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" required minlength="10" autocomplete="new-password"></div><div class="field"><label for="admin_password2">Password again</label><input id="admin_password2" name="admin_password2" type="password" required autocomplete="new-password"></div></div>
</fieldset>
<fieldset><legend>Warehouse manager (optional, single company)</legend>
  <p class="hint">A second account that runs the warehouses: items, suppliers and customers, purchasing, receiving, transfers, orders and dispatch, production, fleet, and approval of adjustments and counts. You can add it, and everyone else, later under Administration.</p>
  <div class="row2"><div class="field"><label for="manager_username">Username</label><input id="manager_username" name="manager_username" type="text" value="<?= $o('manager_username') ?>"></div><div class="field"><label for="manager_name">Name</label><input id="manager_name" name="manager_name" type="text" value="<?= $o('manager_name') ?>"></div></div>
  <div class="field"><label for="manager_password">Password</label><input id="manager_password" name="manager_password" type="password" minlength="10" autocomplete="new-password"></div>
</fieldset>
<label class="check"><input type="checkbox" name="demo" value="1"><span>Also load the demo, for trying the system: Demo Distributors Ltd with warehouses LSK and KTW, sample people, items and history (single company: in your organisation). Online service: also Demo Foods, a second organisation. Demo users (wendy, steve, sally, paul, tom, anna, dora; fay and mia at Demo Foods) have the password Demo-pass-2026.</span></label>
<button class="btn" type="submit">Install</button>
</form>
<?php else: ?><p>Fix the items above, then reload this page.</p><?php endif ?>
</main></body></html>
