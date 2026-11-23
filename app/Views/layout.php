<?php
/** @var array|null $me */
$groups = [];
if ($me) {
    $groups = [
        '' => [
            ['/', 'Home', true],
        ],
        'Stock' => [
            ['/stock', 'Stock', can('stock.view')],
            ['/offline.html', 'Offline look-up', can('stock.view')],
            ['/putaway', 'Put away and move', can('inventory.move')],
            ['/lots', 'Batches and serials', can('stock.view')],
            ['/counts', 'Counts', can('count.enter') || can('count.manage') || can('stock.approve')],
            ['/adjustments', 'Adjustments', can('adjust.request') || can('stock.approve')],
        ],
        'In' => [
            ['/purchase-orders', 'Purchase orders', can('purchase.manage') || can('receive.post')],
            ['/receipts', 'Goods received', can('receive.post') || can('stock.view')],
        ],
        'Out' => [
            ['/orders', 'Sales orders', can('sales.manage') || can('dispatch.manage')],
            ['/shipments', 'Pick, pack, dispatch', can('dispatch.manage')],
            ['/waves', 'Wave picking', can('dispatch.manage')],
            ['/transfers', 'Transfers', can('transfer.manage') || can('transfer.receive')],
            ['/fleet', 'Fleet and trips', can('fleet.manage') || can('dispatch.manage')],
        ],
        'Make' => [
            ['/production', 'Production', can('production.manage')],
        ],
        'Know' => [
            ['/reports', 'Reports', can('report.view') || can('stock.view')],
        ],
        'Lists' => [
            ['/items', 'Items', can('item.manage') || can('stock.view')],
            ['/suppliers', 'Suppliers', can('supplier.manage') || can('purchase.manage')],
            ['/customers', 'Customers', can('customer.manage') || can('sales.manage')],
        ],
        'Set up' => [
            ['/warehouses', 'Warehouses and bins', can('warehouse.setup')],
            ['/admin', 'Administration', can('admin.users') || can('admin.settings')],
            ['/audit', 'Audit log', can('audit.view')],
        ],
    ];
}
$active = static function (string $href) use ($path): bool {
    return $href === '/' ? $path === '/' : ($path === $href || str_starts_with($path, $href . '/'));
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b3a1b">
<title><?= esc(($title ? $title . ' - ' : '') . \App\Libraries\Core\Settings::get('app_name')) ?></title>
<link rel="manifest" href="<?= base_url('manifest.webmanifest') ?>">
<link rel="icon" href="<?= base_url('static/brand/favicon-64.png') ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= base_url('static/brand/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= base_url('static/app.css?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>">
<script src="<?= base_url('static/app.js?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>" defer></script>
</head>
<body data-base="<?= esc(rtrim(base_url(), '/'), 'attr') ?>"<?= \App\Libraries\Core\Auth::id() ? ' data-uid="' . (int) \App\Libraries\Core\Auth::id() . '"' . (\App\Libraries\Core\Access::can('stock.view') ? ' data-snapshot="1"' : '') : '' ?>>
<a class="skip" href="#main">Skip to content</a>
<div id="net-banner" class="net-banner" role="status" hidden>You are offline. Nothing can be saved until you are back online.</div>
<header class="topbar">
  <a class="brand" href="<?= site_url('/') ?>"><img src="<?= base_url('static/brand/mark-96.png') ?>" alt="" width="40" height="30"><span><span class="name"><?= esc(\App\Libraries\Core\Settings::get('app_name')) ?></span><span class="by"><?= esc(\App\Libraries\Core\Settings::get('company_name') ?: 'by Chatu Innovations') ?></span></span></a>
  <?php if ($me): ?>
    <?php if (count($warehouses) > 1): ?>
      <form method="post" action="<?= site_url('warehouse/switch') ?>" class="entity-switch">
        <?= csrf_field() ?>
        <label class="sr" for="wh-select">Warehouse</label>
        <select id="wh-select" name="warehouse_id" data-autosubmit>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= $w['id'] ?>" <?= (int) $w['id'] === $whId ? 'selected' : '' ?>><?= esc($w['code'] . ' ' . $w['name']) ?></option>
          <?php endforeach ?>
        </select>
      </form>
    <?php endif ?>
    <nav class="topnav" aria-label="Account">
      <a href="<?= site_url('notifications') ?>" class="bell<?= $unread ? ' has-unread' : '' ?>">Messages<?= $unread ? ' <span class="count">' . $unread . '</span>' : '' ?></a>
      <a href="<?= site_url('account') ?>"><?= esc($me['name']) ?></a>
      <form method="post" action="<?= site_url('logout') ?>" class="inline"><?= csrf_field() ?><button class="linkish" type="submit">Sign out</button></form>
    </nav>
  <?php endif ?>
</header>
<div class="shell<?= $me ? '' : ' no-nav' ?>">
  <?php if ($me): ?>
  <nav class="sidenav" aria-label="Main">
    <?php foreach ($groups as $g => $items): $shown = array_filter($items, static fn ($i) => $i[2]); if (! $shown) continue; ?>
      <?php if ($g !== ''): ?><span class="grp"><?= esc($g) ?></span><?php endif ?>
      <?php foreach ($shown as [$href, $label]): ?>
        <a href="<?= site_url(ltrim($href, '/')) ?>" class="<?= $active($href) ? 'on' : '' ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
      <?php endforeach ?>
    <?php endforeach ?>
  </nav>
  <?php endif ?>
  <main id="main" class="main">
    <?php if ($msg = session()->getFlashdata('ok')): ?><div class="flash ok" role="status"><?= esc($msg) ?></div><?php endif ?>
    <?php if ($msg = session()->getFlashdata('error')): ?><div class="flash err" role="alert"><?= esc($msg) ?></div><?php endif ?>
    <?= view('partials/print_head', ['title' => $title]) ?>
    <?= $this->renderSection('content') ?>
    <div class="print-foot"><span><?= esc(\App\Libraries\Core\Settings::get('company_name')) ?> · printed <?= esc(local_time(gmdate('Y-m-d H:i:s'))) ?><?= $me ? ' by ' . esc($me['name']) : '' ?></span><span>Chatu Warehouse by Chatu Innovations · www.chatuzm.com</span></div>
  </main>
  <footer class="site-foot"><img class="foot-logo" src="<?= base_url('static/brand/logo-wide.png') ?>" alt="Chatu Innovations" width="93" height="34"><span class="foot-text"><span><?= esc(\App\Libraries\Core\Settings::get('app_name')) ?> <?= esc(\App\Libraries\Core\Audit::APP_VERSION) ?> by Chatu Innovations</span><span>Support: <a href="mailto:info@chatuzm.com">info@chatuzm.com</a>, +260 773818370 / +260 979913497</span><span><a href="https://www.chatuzm.com" rel="noopener">www.chatuzm.com</a></span></span></footer>
</div>
</body>
</html>
