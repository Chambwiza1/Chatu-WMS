<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Platform settings</h1><p class="sub">For the whole installation: e-mail, sign-in rules, uploads, the scheduled job. Each organisation keeps its own settings.</p></div></div>
<form method="post" action="<?= site_url('platform/settings') ?>" class="stack panel"><?= csrf_field() ?>
<?= view('partials/platform_fields', ['fields' => $fields]) ?>
<div><button class="btn" type="submit">Save settings</button></div></form>
<?= $this->endSection() ?>
