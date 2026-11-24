<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Billing settings</h1><p class="sub">How Chatu Innovations invoices organisations, and its own Lenco and DPO accounts for organisations paying online. Secrets are kept encrypted and never shown again; leave a secret empty to keep it, or type a single - to remove it.</p></div></div>
<form method="post" action="<?= site_url('platform/billing') ?>" class="stack"><?= csrf_field() ?>
<section class="panel"><h2>Invoices</h2><div class="grid2">
<?php foreach ($fields as $k => [$label, $hint]): ?><div class="field"><label for="<?= $k ?>"><?= esc($label) ?></label><?php if ($k === 'billing_details'): ?><textarea id="<?= $k ?>" name="<?= $k ?>" rows="3"><?= esc((string) \App\Libraries\Core\Settings::get($k)) ?></textarea><?php else: ?><input id="<?= $k ?>" name="<?= $k ?>" value="<?= esc((string) (\App\Libraries\Core\Settings::get($k) ?? ['billing_currency' => 'ZMW', 'invoice_due_days' => '14', 'grace_days' => '7'][$k] ?? '')) ?>"><?php endif ?><?= $hint ? '<span class="hint">' . esc($hint) . '</span>' : '' ?></div><?php endforeach ?>
</div></section>
<?= view('billing/_keys', ['keys' => $keys, 'available' => $available, 'webhook' => site_url('pay/lenco/platform'), 'who' => 'Chatu Innovations']) ?>
<div><button class="btn" type="submit">Save</button></div></form>
<?= $this->endSection() ?>
