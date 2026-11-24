<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Messages</h1><p class="sub">New messages also pop up on every page until you open them or close them with ×.</p></div>
<form method="post" action="<?= site_url('notifications/read') ?>"><?= csrf_field() ?><button class="btn secondary" type="submit">Mark all as read</button></form></div>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Message</th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="2" class="empty">No messages.</td></tr><?php endif ?>
<?php foreach ($rows as $n): ?>
<tr><td class="muted"><?= local_time($n['created_at']) ?></td><td><?= $n['read_at'] ? '' : '<b>' ?><?= $n['link'] ? '<a href="' . esc(site_url('notifications/' . $n['id']), 'attr') . '">' . esc($n['title']) . '</a>' : esc($n['title']) ?><?= $n['read_at'] ? '' : '</b>' ?><?php if ($n['body']): ?><br><span class="muted"><?= esc($n['body']) ?></span><?php endif ?>
<?php if (! $n['read_at']): ?> <form method="post" action="<?= site_url('notifications/' . $n['id'] . '/read') ?>" class="inline"><?= csrf_field() ?><button class="linkish small" type="submit">Mark read</button></form><?php endif ?></td></tr>
<?php endforeach ?></tbody></table></div>
<?= $this->endSection() ?>
