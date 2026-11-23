<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Settings</h1></div></div>
<form method="post" action="<?= site_url('admin/settings') ?>" class="stack panel"><?= csrf_field() ?>
<?php foreach ($fields as $k => [$label, $hint]): ?><div class="field"><label for="<?= $k ?>"><?= esc($label) ?></label><input id="<?= $k ?>" name="<?= $k ?>" <?= $k === 'smtp_pass' ? 'type="password" autocomplete="new-password" placeholder="unchanged"' : 'value="' . esc((string) \App\Libraries\Core\Settings::get($k), 'attr') . '"' ?>><?php if ($hint): ?><span class="hint"><?= esc($hint) ?></span><?php endif ?></div><?php endforeach ?>
<div><button class="btn" type="submit">Save settings</button></div></form>
<h2>Your logo</h2>
<section class="panel"><p class="muted small">Printed on delivery notes, purchase orders, goods received notes, trip sheets and every printed page, next to your company name. PNG, JPG or WEBP, under 1 MB; a wide logo on a white or transparent background prints best.</p>
<?php if (\App\Libraries\Admin\Brand::hasLogo()): ?><p class="brand-preview"><img src="<?= site_url('brand/logo') ?>" alt="Your logo"></p><?php endif ?>
<form method="post" action="<?= site_url('admin/logo') ?>" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-label="Logo file"><button class="btn secondary" type="submit">Upload logo</button><?php if (\App\Libraries\Admin\Brand::hasLogo()): ?><button class="btn small danger" type="submit" name="remove" value="1" data-confirm="Remove the logo?">Remove</button><?php endif ?></form></section>
<h2>Reason codes</h2>
<div class="table-wrap"><table><thead><tr><th>Used for</th><th>Code</th><th>Description</th><th>Status</th></tr></thead><tbody>
<?php foreach ($reasons as $r): ?><tr class="<?= $r['active'] ? '' : 'muted' ?>"><td><?= esc(str_replace('_', ' ', $r['use_for'])) ?></td><td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['label']) ?></td><td><?= $r['active'] ? '' : 'off' ?></td></tr><?php endforeach ?></tbody></table></div>
<form method="post" action="<?= site_url('admin/reasons') ?>" class="panel inline-form"><?= csrf_field() ?><select name="use_for" aria-label="Used for"><option value="adjust">Adjustments</option><option value="supplier_return">Returns to suppliers</option><option value="failed_delivery">Failed deliveries</option><option value="customer_return">Customer returns</option><option value="hold">Holds</option></select>
<input name="code" class="upper" placeholder="CODE" aria-label="Code" required><input name="label" placeholder="Description (- to switch off)" aria-label="Description"><button class="btn secondary" type="submit">Add or change</button></form>
<?= $this->endSection() ?>
