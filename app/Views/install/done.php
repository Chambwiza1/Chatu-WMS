<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Chatu Warehouse installed</title>
<link rel="stylesheet" href="<?= esc(rtrim($base, '/')) ?>/static/app.css"></head>
<body><header class="topbar"><span class="brand"><img src="<?= esc(rtrim($base, '/')) ?>/static/brand/mark-96.png" alt="" width="40" height="30"><span><span class="name">Chatu Warehouse</span><span class="by">by Chatu Innovations</span></span></span></header>
<main class="main">
<h1>Installed</h1>
<ol>
  <li>Set up the scheduled job (e-mail copies of messages, the nightly integrity check, daily expiry, transfer and fleet reminders). In cPanel open <b>Cron Jobs</b>, choose "Once Per Five Minutes" and enter:<br><code>php <?= esc(ROOTPATH) ?>spark wms:cron</code><br>On a Linux server add the same line to the web user's crontab with <code>*/5 * * * *</code>.</li>
  <?php if (($edition ?? 'single') === 'saas'): ?><li>Sign in as the platform owner. Under <b>Plans</b> set your plans and prices, then add each organisation under <b>Organisations &gt; New organisation</b>. Its account owner then adds their people, warehouses and items.</li>
  <?php else: ?><li>Sign in as the account owner. Under <b>Warehouses and bins</b> add your zones and racks; under <b>Administration &gt; People and access</b> add your people and give each their roles and warehouses.</li><?php endif ?>
  <li>The warehouse manager (or a buyer) adds items, suppliers and customers, then enters the <b>opening stock</b> under Goods received, counted bin by bin with batches, expiry dates and serial numbers.</li>
  <?php if ($demo): ?><li>The demo is loaded<?= ($edition ?? 'single') === 'saas' ? ' (Demo Distributors Ltd and Demo Foods; <b>fay</b> owns Demo Foods, <b>mia</b> belongs to both)' : '' ?>. Try <b>wendy</b> (warehouse manager), <b>steve</b> (storekeeper), <b>sally</b> (sales), <b>paul</b> (production), <b>tom</b> (transport), <b>anna</b> (accountant) or <b>dora</b> (director), password <b>Demo-pass-2026</b>. Switch these users off before going live.</li><?php endif ?>
</ol>
<p><a class="btn" href="<?= esc(rtrim($base, '/')) ?>/login">Go to sign in</a></p>
</main></body></html>
