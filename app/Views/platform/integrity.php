<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $labels = \App\Libraries\Reports\Integrity::LABELS; ?>
<div class="page-head"><div><h1>Integrity check</h1><p class="sub">Runs every night over every organisation, and after every automated test. <?= $last ? 'Last run ' . local_time($last['at']) . ': ' . (int) $last['problems'] . ' problem(s).' : 'Not run yet.' ?></p></div>
<div class="actions"><form method="post" action="<?= site_url('platform/integrity') ?>"><?= csrf_field() ?><button class="btn" type="submit">Run it now</button></form></div></div>
<?php if ($result): ?><div class="table-wrap"><table><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>
<?php foreach ($result as $k => $list): ?><tr><td><?= esc($labels[$k] ?? $k) ?></td><td><?= $list ? '<span class="badge bad">' . count($list) . ' problem(s)</span><ul>' . implode('', array_map(static fn ($x) => '<li>' . esc($x) . '</li>', $list)) . '</ul>' : '<span class="badge good">Agrees</span>' ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
