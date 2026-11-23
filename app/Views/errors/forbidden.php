<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<h1>Not allowed</h1>
<p><?= esc($message) ?></p>
<p><a href="<?= site_url('/') ?>">Go to the home page</a></p>
<?= $this->endSection() ?>
