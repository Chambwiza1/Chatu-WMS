<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<h1>Support access</h1>
<?php if ($saas): ?><section class="panel stack"><h2>Chatu support</h2>
<?php if ($until): ?><p><span class="badge good">Open</span> Chatu support may sign in as your account owner until <b><?= local_time($until, 'D j M Y, H:i') ?></b>.</p>
<?php else: ?><p><span class="badge quiet">Closed</span> Nobody from Chatu can sign in to your organisation or see its records.</p><?php endif ?>
<p class="small muted">Open it only when you have asked us for help. While it is open, a support person can sign in as your account owner and see and do what the account owner can. Each sign-in asks them for a reason, tells the account owner at once, and is listed below with everything they saved. They cannot change passwords, keys or this setting. It closes by itself at the time shown.</p>
<form method="post" action="<?= site_url('admin/support') ?>" class="inline-form"><?= csrf_field() ?>
<label for="hours" class="sr">Open for</label><select id="hours" name="hours"><?php foreach ($hours as $h => $l): ?><option value="<?= (int) $h ?>" <?= $h === 24 ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select>
<button class="btn" type="submit"><?= $until ? 'Open for this long from now' : 'Let support sign in' ?></button></form>
<?php if ($until): ?><form method="post" action="<?= site_url('admin/support') ?>"><?= csrf_field() ?><input type="hidden" name="hours" value="0"><button class="btn danger" type="submit">Close it now</button></form><?php endif ?>
</section><?php endif ?>
<h2>Sign-ins as someone else</h2>
<p class="muted">Every time an administrator<?= $saas ? ' or Chatu support' : '' ?> signed in as one of your people. An administrator does it from the person's own page, for at most an hour.</p>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>Signed in as</th><th>Reason</th><th>Ended</th><th class="num">Saved</th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="6" class="empty">None yet.</td></tr><?php endif ?>
<?php foreach ($rows as $r): ?><tr><td class="nowrap"><?= local_time($r['started_at']) ?></td><td><?= esc($r['by_name']) ?><?= $r['kind'] === 'support' ? ' <span class="badge info">Support</span>' : '' ?></td><td><?= esc((string) $r['as_name']) ?></td>
<td><?= esc($r['reason']) ?></td><td class="small"><?= $r['ended_at'] ? local_time($r['ended_at'], 'j M H:i') . ' · ' . esc(['stopped' => 'stopped', 'time' => 'time ran out', 'closed' => 'access closed', 'gone' => 'ended', 'signed out' => 'signed out'][$r['ended_how']] ?? (string) $r['ended_how']) : '<span class="badge warn">Going on</span>' ?></td>
<td class="num"><?= (int) $r['actions'] ? '<a href="' . site_url('admin/support/' . $r['id']) . '">' . (int) $r['actions'] . '</a>' : '0' ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
