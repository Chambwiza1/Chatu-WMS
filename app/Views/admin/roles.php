<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>What each role may do</h1><p class="sub">A role is given for the whole company or for one warehouse.</p></div><div class="actions"><button class="btn secondary" data-print>Print</button></div></div>
<div class="table-wrap matrix-wrap"><table class="matrix"><thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><span><?= esc($r['name']) ?></span></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($perms as $code => $desc): ?><tr><th><?= esc($desc) ?><small class="mono"><?= esc($code) ?></small></th><?php foreach ($roles as $r): ?><td><?= isset($grid[$code][$r['code']]) ? '✓' : '' ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody></table></div>
<h2>Duties kept apart</h2><ul><?php foreach ($sod as $s): ?><li><b><?= esc($s['a']) ?></b> and <b><?= esc($s['b']) ?></b>: <?= esc($s['description']) ?></li><?php endforeach ?></ul>
<p class="muted">Whatever the roles, nobody approves their own adjustment, and nobody who counted or submitted a count approves it.</p>
<?= $this->endSection() ?>
