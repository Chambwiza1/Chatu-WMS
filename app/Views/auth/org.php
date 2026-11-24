<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Choose an organisation</h1><p class="sub">You belong to more than one. You can switch at any time from the top of the page.</p></div></div>
<?php if (! $rows): ?><div class="panel empty">You are not part of any active organisation.<?= $platform ? ' Go to the <a href="' . site_url('platform') . '">platform</a>.' : '' ?></div><?php endif ?>
<ul class="attn">
<?php foreach ($rows as $o): ?><li><form method="post" action="<?= site_url('org') ?>"><?= csrf_field() ?><input type="hidden" name="tenant_id" value="<?= (int) $o['id'] ?>">
<button class="btn secondary wide" type="submit"><?= esc($o['name']) ?><?= $o['status'] === 'suspended' ? ' (suspended)' : '' ?></button></form></li><?php endforeach ?>
</ul>
<?php if ($platform && $rows): ?><p><a href="<?= site_url('platform') ?>">Platform console</a></p><?php endif ?>
<?= $this->endSection() ?>
