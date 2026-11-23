<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= esc($title) ?></title>
<link rel="stylesheet" href="<?= base_url('static/app.css?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>">
<script src="<?= base_url('static/app.js?v=' . \App\Libraries\Core\Audit::APP_VERSION) ?>" defer></script></head>
<body class="labels-page <?= $mode === 'roll' ? 'roll' : 'a4' ?>" data-base="<?= esc(rtrim(base_url(), '/'), 'attr') ?>">
<div class="no-print label-bar"><b><?= esc($title) ?></b> · <?= count($labels) ?> label(s) · <?= $mode === 'roll' ? 'one per page (label printer)' : 'A4, 3 × 8 per sheet' ?>
<a href="?<?= esc(http_build_query(array_merge($_GET, ['roll' => $mode === 'roll' ? null : 1]))) ?>"><?= $mode === 'roll' ? 'Use A4 sheets' : 'Use a label printer' ?></a>
<button class="btn" data-print>Print</button></div>
<?php if (! $labels): ?><p class="no-print">Nothing to print.</p><?php endif ?>
<div class="label-grid"><?php foreach ($labels as $l): ?><div class="lbl<?= ! empty($l['big']) ? ' big' : '' ?>">
<span class="lbl-top"><?= esc($l['top']) ?></span>
<?= \App\Libraries\Labels\Code128::svg((string) $l['code'], 40, 1) ?>
<span class="lbl-code"><?= esc($l['code']) ?></span>
<span class="lbl-line"><?= esc($l['line'] !== $l['code'] ? $l['line'] : '') ?><?= $l['extra'] ? ' · ' . esc($l['extra']) : '' ?></span>
</div><?php endforeach ?></div>
</body></html>
