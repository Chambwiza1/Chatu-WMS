<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $o = static fn ($k, $d = '') => esc((string) (old($k) ?? $d), 'attr'); ?>
<div class="page-head"><div><h1>New organisation</h1><p class="sub">Organisations are added here. Each starts with its own copies of the roles and reason codes, its first warehouse and its account owner.</p></div></div>
<form method="post" action="<?= site_url('platform/tenants') ?>" class="panel stack"><?= csrf_field() ?>
<fieldset><legend>Organisation</legend>
<div class="row2"><div class="field"><label for="name">Name</label><input id="name" name="name" required value="<?= $o('name') ?>"></div><div class="field"><label for="slug">Short name</label><input id="slug" name="slug" value="<?= $o('slug') ?>" placeholder="made from the name"><span class="hint">Letters, digits and dashes; used in public links.</span></div></div>
<div class="row3"><div class="field"><label for="country">Country</label><input id="country" name="country" maxlength="2" class="upper" value="<?= $o('country', 'ZM') ?>"></div><div class="field"><label for="timezone">Time zone</label><input id="timezone" name="timezone" value="<?= $o('timezone', 'Africa/Lusaka') ?>"></div>
<div class="field"><label for="status">Status</label><select id="status" name="status"><option value="trial">Trial</option><option value="active">Active</option></select></div></div></fieldset>
<fieldset><legend>First warehouse</legend>
<div class="row3"><div class="field"><label for="location_code">Code</label><input id="location_code" name="location_code" class="upper" value="<?= $o('location_code', 'MAIN') ?>"></div><div class="field"><label for="location_name">Name</label><input id="location_name" name="location_name" value="<?= $o('location_name', 'Main warehouse') ?>"></div><div class="field"><label for="location_town">Town</label><input id="location_town" name="location_town" value="<?= $o('location_town') ?>"></div></div></fieldset>
<fieldset><legend>Account owner (their Administrator)</legend>
<div class="row2"><div class="field"><label for="owner_name">Name</label><input id="owner_name" name="owner_name" required value="<?= $o('owner_name') ?>"></div><div class="field"><label for="owner_username">Username</label><input id="owner_username" name="owner_username" required value="<?= $o('owner_username') ?>"></div></div>
<div class="row2"><div class="field"><label for="owner_email">E-mail</label><input id="owner_email" name="owner_email" type="email" value="<?= $o('owner_email') ?>"></div><div class="field"><label for="owner_password">First password</label><input id="owner_password" name="owner_password" type="password" required minlength="10" autocomplete="new-password"></div></div></fieldset>
<div><button class="btn" type="submit">Add organisation</button></div></form>
<?= $this->endSection() ?>
