<?php
/** Branded header on every printed page: the client's logo and name on the left, the product on the right. */
$co = \App\Libraries\Core\Settings::get('company_name') ?: \App\Libraries\Core\Settings::get('app_name');
?>
<div class="print-head"><div class="co"><?php if (\App\Libraries\Admin\Brand::hasLogo()): ?><img src="<?= site_url('brand/logo') ?>" alt=""><?php endif ?><div><b><?= esc($co) ?></b><?php if ($t = \App\Libraries\Core\Settings::get('company_address')): ?><br><span class="small"><?= esc($t) ?></span><?php endif ?></div></div>
<div class="prod"><img src="<?= base_url('static/brand/logo-wide.png') ?>" alt="Chatu Innovations">Chatu Warehouse<?= ! empty($title) ? ' · ' . esc($title) : '' ?></div></div>
