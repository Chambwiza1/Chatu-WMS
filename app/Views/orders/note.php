<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Delivery note <?= esc($s['number']) ?></title>
<link rel="stylesheet" href="<?= base_url('static/app.css?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>">
<script src="<?= base_url('static/app.js?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>" defer></script></head>
<body class="dn" data-base="<?= esc(rtrim(base_url(), '/'), 'attr') ?>">
<div class="dn-head"><div><h1>Delivery note</h1><div class="mono"><?= esc($s['number']) ?></div></div><div class="dn-from"><?php if (\App\Libraries\Admin\Brand::hasLogo()): ?><img class="co-logo" src="<?= site_url('brand/logo') ?>" alt=""><?php endif ?><b><?= esc(\App\Libraries\Core\Settings::get('company_name') ?: \App\Libraries\Core\Settings::get('app_name')) ?></b><?= ($tp = \App\Libraries\Core\Settings::get('company_tpin')) ? '<br>TPIN ' . esc($tp) : '' ?><?= ($ad = \App\Libraries\Core\Settings::get('company_address')) ? '<br>' . esc($ad) : '' ?><br><?= esc($s['wh_name']) ?><br><?= esc($s['wh_address']) ?></div></div>
<dl class="facts"><dt>Customer</dt><dd><b><?= esc($s['customer']) ?></b><?= $s['phone'] ? ', ' . esc($s['phone']) : '' ?></dd><dt>Deliver to</dt><dd><?= esc($s['delivery_address'] ?: $s['town']) ?></dd>
<dt>Order</dt><dd><?= esc($s['order_no']) ?><?= $s['customer_ref'] ? ' (your ref ' . esc($s['customer_ref']) . ')' : '' ?></dd><dt>Date</dt><dd><?= local_time($s['dispatched_at'] ?: gmdate('Y-m-d H:i:s'), 'j M Y') ?></dd>
<?php if ($s['reg_no']): ?><dt>Vehicle</dt><dd><?= esc($s['reg_no']) ?>, driver <?= esc($s['driver']) ?> (trip <?= esc($s['trip_no']) ?>)</dd><?php endif ?>
<dt>Packages</dt><dd><?= (int) $s['packages'] ?><?= $s['weight_kg'] ? ', ' . qty($s['weight_kg']) . ' kg' : '' ?></dd></dl>
<table class="dn-lines"><thead><tr><th>Item</th><th>Description</th><th class="num">Quantity</th><th>Unit</th><th>Batch / serial</th></tr></thead><tbody>
<?php foreach ($lines as $l): $ids = array_filter($units, static fn ($u) => (int) $u['shipment_line_id'] === (int) $l['id']) ?><tr><td class="mono"><?= esc($l['sku']) ?></td><td><?= esc($l['name']) ?></td><td class="num"><?= qty($l['qty_picked'] ?: $l['qty'], (int) $l['decimals']) ?><?= pack_hint($l['qty_picked'] ?: $l['qty'], $l['pack_unit'], $l['pack_factor']) ?></td><td><?= esc($l['uom']) ?></td>
<td class="small"><?= esc(implode(', ', array_filter(array_map(static fn ($u) => trim(($u['lot_no'] ? $u['lot_no'] . ($u['expiry_date'] ? ' exp ' . $u['expiry_date'] : '') : '') . ' ' . ($u['serial_no'] ?? '')), $ids)))) ?></td></tr><?php endforeach ?>
</tbody></table>
<div class="sig"><div>Dispatched by</div><div>Driver</div><div>Received in good order by (name, signature, date, stamp)</div></div>
<div class="dn-foot"><span>Delivery note <?= esc($s['number']) ?> · Chatu Warehouse by Chatu Innovations · www.chatuzm.com</span><img src="<?= base_url('static/brand/logo-wide.png') ?>" alt="Chatu Innovations"></div>
<p class="no-print gap-top"><button class="btn" data-print>Print</button></p>
</body></html>
