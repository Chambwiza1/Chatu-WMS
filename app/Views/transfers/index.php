<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Transfers</h1></div><div class="actions"><?php if (can('transfer.manage')): ?><a class="btn" href="<?= site_url('transfers/new') ?>">New transfer</a><?php endif ?></div></div>
<div class="pill-filter"><?php foreach (['' => 'All', 'outgoing' => 'To send', 'incoming' => 'On the way to us'] as $k => $l): ?><a href="<?= site_url('transfers' . ($k !== '' ? '?view=' . $k : '')) ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></div>
<?php if (! $rows): ?><div class="panel empty">No transfers here.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Number</th><th>From</th><th>To</th><th>Lines</th><th>Needed by</th><th>Left</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $t): ?><tr><td><a href="<?= site_url('transfers/' . $t['id']) ?>"><?= esc($t['number']) ?></a></td><td><?= esc($t['from_code']) ?></td><td><?= esc($t['to_code']) ?></td><td><?= (int) $t['n_lines'] ?></td><td><?= nice_date($t['needed_by']) ?></td><td><?= local_time($t['dispatched_at'], 'j M H:i') ?></td><td><?= badge($t['status']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
