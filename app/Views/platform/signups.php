<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<h1>Sign-ups</h1>
<section class="panel stack">
<p><?= $mode === 'self'
    ? 'Customers create their own organisation on the sign-up page. They confirm by an e-mailed link and start on a trial.'
    : 'Customers send a request from the sign-up page, saying what they need. You create the organisation and mark the request done.' ?>
 Page: <span class="mono"><?= esc(site_url('signup')) ?></span></p>
<?php if ($mode === 'self' && ! $mailReady): ?><p class="flash err">The sign-up page stays closed until this installation can send e-mail: set the sender address under Platform settings.</p><?php endif ?>
<form method="post" action="<?= site_url('platform/signups/open') ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="open" value="<?= $open ? '0' : '1' ?>">
<span>The sign-up page is <b><?= $open ? 'open' : 'closed' ?></b>.</span> <button class="btn secondary small" type="submit"><?= $open ? 'Close it' : 'Open it' ?></button></form>
</section>
<p class="tabs"><a href="<?= site_url('platform/signups') ?>" <?= $status === '' ? 'class="on"' : '' ?>>All</a>
<?php foreach (\App\Libraries\Platform\Signup::STATUSES as $k => $l): ?><a href="<?= site_url('platform/signups?status=' . $k) ?>" <?= $status === $k ? 'class="on"' : '' ?>><?= esc($l) ?></a><?php endforeach ?></p>
<?php if (! $rows): ?><p class="muted">Nothing here.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Organisation</th><th>Contact</th><th>What they need</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><?= local_time($r['created_at']) ?></td>
<td><?= $r['tenant_id'] ? '<a href="' . site_url('platform/tenants/' . $r['tenant_id']) . '">' . esc($r['org_name']) . '</a>' : esc($r['org_name']) ?></td>
<td><?= esc($r['contact_name']) ?><br><span class="small"><?= esc($r['email']) ?><?= $r['phone'] ? ' · ' . esc($r['phone']) : '' ?></span></td>
<td class="small"><?= nl2br(esc($r['summary'])) ?></td>
<td><?= esc(\App\Libraries\Platform\Signup::STATUSES[$r['status']]) ?><?= $r['note'] ? '<br><span class="small muted">' . esc($r['note']) . '</span>' : '' ?></td>
<td><?php if ($r['status'] === 'requested'): ?>
<form method="post" action="<?= site_url('platform/signups/' . $r['id']) ?>" class="inline-form"><?= csrf_field() ?>
<input type="text" name="note" maxlength="255" placeholder="Note (needed to dismiss)" aria-label="Note">
<button class="btn small" type="submit" name="status" value="done">Done</button> <button class="btn small secondary" type="submit" name="status" value="dismissed">Dismiss</button></form>
<a class="small" href="<?= site_url('platform/tenants/new') ?>">New organisation</a><?php endif ?></td>
</tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
