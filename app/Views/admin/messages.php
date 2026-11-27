<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php use App\Libraries\Core\Settings; use App\Libraries\Messaging\Outbox; $e = $sum['email']; $w = $sum['whatsapp']; $t = static fn ($k) => (string) Settings::tenant($k); ?>
<div class="page-head"><div><h1>Messages</h1><p class="sub">E-mail and WhatsApp go out from <?= esc($org['name']) ?>'s own address and number: copies of your people's messages, and notices to your customers when their goods leave and arrive.</p></div></div>
<?php if ($waiting['email'] || $waiting['whatsapp']): ?><div class="flash err" role="status"><b><?= (int) ($waiting['email'] + $waiting['whatsapp']) ?> message<?= $waiting['email'] + $waiting['whatsapp'] === 1 ? '' : 's' ?> waiting</b> for your <?= $waiting['email'] ? 'e-mail settings' : '' ?><?= $waiting['email'] && $waiting['whatsapp'] ? ' and ' : '' ?><?= $waiting['whatsapp'] ? 'WhatsApp number' : '' ?>. They go out as soon as these are set below.</div><?php endif ?>

<div class="grid2">
<section class="panel stack"><h2>E-mail <?= $sum['email_ready'] ? '<span class="badge good">Ready</span>' : '<span class="badge warn">Not set</span>' ?></h2>
<?php if ($e && $e['sender'] === 'installation'): ?><p class="small">Sending through this installation's mail settings (Settings), from <b><?= esc($e['from']) ?></b>. Fill in the form only to use another address.</p>
<?php elseif ($e): ?><p class="small">From <b><?= esc($e['from_name']) ?> &lt;<?= esc($e['from']) ?>&gt;</b><?= $e['host'] !== '' ? ' through ' . esc($e['host']) : '' ?>.</p>
<?php else: ?><p class="small">Until this is set, e-mail to your people waits here, and no e-mail goes to customers.</p><?php endif ?>
<form method="post" action="<?= site_url('admin/messages/email') ?>" class="stack"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="mfrom">Send from (address)</label><input id="mfrom" name="mail_own_from" type="email" value="<?= esc($t('mail_own_from'), 'attr') ?>" placeholder="stores@yourcompany.co.zm"></div>
<div class="field"><label for="mname">Sender name</label><input id="mname" name="mail_own_name" value="<?= esc($t('mail_own_name'), 'attr') ?>" placeholder="<?= esc($org['name'], 'attr') ?>"></div></div>
<div class="row2"><div class="field"><label for="mhost">Mail server (SMTP)</label><input id="mhost" name="mail_own_host" value="<?= esc($t('mail_own_host'), 'attr') ?>" placeholder="mail.yourcompany.co.zm"></div>
<div class="field"><label for="mport">Port</label><input id="mport" name="mail_own_port" inputmode="numeric" value="<?= esc($t('mail_own_port'), 'attr') ?>" placeholder="587"></div></div>
<div class="row2"><div class="field"><label for="muser">User name</label><input id="muser" name="mail_own_user" autocomplete="off" value="<?= esc($t('mail_own_user'), 'attr') ?>"></div>
<div class="field"><label for="mpass">Password</label><input id="mpass" name="mail_own_pass" type="password" autocomplete="new-password" placeholder="<?= $t('mail_own_pass_enc') !== '' ? 'kept; type to change' : '' ?>"><span class="hint">Kept encrypted, never shown again.</span></div></div>
<div class="row2"><div class="field"><label for="mcrypto">Security</label><select id="mcrypto" name="mail_own_crypto"><?php foreach (\App\Libraries\Messaging\Senders::CRYPTO as $k => $l): ?><option value="<?= $k ?>" <?= ($t('mail_own_crypto') ?: 'tls') === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="mreply">Replies go to</label><input id="mreply" name="mail_own_reply" type="email" value="<?= esc($t('mail_own_reply'), 'attr') ?>"><span class="hint">Empty: to the sender address.</span></div></div>
<div><button class="btn" type="submit">Save e-mail</button></div></form></section>

<section class="panel stack"><h2>WhatsApp <?= $sum['whatsapp_ready'] ? '<span class="badge good">Ready</span>' . ($sum['log_only'] ? ' <span class="badge quiet">Log only</span>' : '') : '<span class="badge quiet">Off</span>' ?></h2>
<p class="small">Your own WhatsApp Business number, from Meta's set-up. Customers see your name. Messages use one approved template, <code>chatu_notice</code> (or yours), with two parts: <code>{{1}}</code> your name and <code>{{2}}</code> what happened.</p>
<form method="post" action="<?= site_url('admin/messages/whatsapp') ?>" class="stack"><?= csrf_field() ?>
<label class="check"><input type="radio" name="wa_mode" value="own" <?= $sum['wa_mode'] === 'own' ? 'checked' : '' ?>> <b>Our own WhatsApp Business number</b></label>
<label class="check"><input type="radio" name="wa_mode" value="off" <?= $sum['wa_mode'] !== 'own' ? 'checked' : '' ?>> <b>No WhatsApp</b> (e-mail only)</label>
<div class="row2"><div class="field"><label for="wid">Phone number id</label><input id="wid" name="wa_phone_number_id" inputmode="numeric" value="<?= esc($t('wa_phone_number_id'), 'attr') ?>"><span class="hint">The long number Meta shows for the phone number, not the phone number.</span></div>
<div class="field"><label for="wdisp">Number as customers see it</label><input id="wdisp" name="wa_display" value="<?= esc($t('wa_display'), 'attr') ?>" placeholder="+260 97 1234567"></div></div>
<div class="row2"><div class="field"><label for="wtok">Permanent access token</label><input id="wtok" name="wa_token" type="password" autocomplete="new-password" placeholder="<?= $t('wa_token_enc') !== '' ? 'kept; type to change' : '' ?>"></div>
<div class="field"><label for="wsec">App secret</label><input id="wsec" name="wa_app_secret" type="password" autocomplete="new-password" placeholder="<?= $t('wa_app_secret_enc') !== '' ? 'kept; type to change' : '' ?>"><span class="hint">Checks Meta's delivery reports. Both kept encrypted.</span></div></div>
<div class="row2"><div class="field"><label for="wtpl">Template name</label><input id="wtpl" name="wa_template_notice" value="<?= esc($t('wa_template_notice'), 'attr') ?>" placeholder="chatu_notice"></div>
<div class="field"><label for="wlang">Template language</label><input id="wlang" name="wa_lang" value="<?= esc($t('wa_lang'), 'attr') ?>" placeholder="en"></div></div>
<p class="small muted">In Meta's set-up, the webhook address for delivery reports is <code><?= esc($webhook) ?></code>.</p>
<div><button class="btn" type="submit">Save WhatsApp</button></div></form></section>
</div>

<div class="grid2">
<section class="panel stack"><h2>Customers</h2>
<form method="post" action="<?= site_url('admin/messages/customers') ?>" class="stack"><?= csrf_field() ?>
<label class="check"><input type="checkbox" name="notify_customers" value="1" <?= Settings::tenant('notify_customers', '1') === '1' ? 'checked' : '' ?>> <b>Tell customers when their goods leave and when they are delivered</b></label>
<p class="small muted">By e-mail to the customer's address and by WhatsApp to their phone, on whichever of the two you have set up. Customers without either are not told.</p>
<div><button class="btn secondary" type="submit">Save</button></div></form></section>
<section class="panel stack"><h2>Send a test</h2>
<form method="post" action="<?= site_url('admin/messages/test') ?>" class="stack"><?= csrf_field() ?>
<div class="row2"><div class="field"><label for="tch">By</label><select id="tch" name="channel"><option value="email">E-mail</option><option value="whatsapp">WhatsApp</option></select></div>
<div class="field"><label for="tto">To</label><input id="tto" name="to" required placeholder="you@example.com or 0977 123456"></div></div>
<div><button class="btn secondary" type="submit">Send a test now</button></div></form></section>
</div>

<h2>Sent and waiting</h2>
<form method="get" class="msg-filter"><label class="sr" for="fs">Status</label><select id="fs" name="status"><option value="">Every status</option><?php foreach (Outbox::LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= esc($l) ?><?= isset($counts[$k]) ? ' (' . (int) $counts[$k] . ')' : '' ?></option><?php endforeach ?></select>
<label class="sr" for="fc">Channel</label><select id="fc" name="channel"><option value="">E-mail and WhatsApp</option><option value="email" <?= $f['channel'] === 'email' ? 'selected' : '' ?>>E-mail</option><option value="whatsapp" <?= $f['channel'] === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option></select>
<label class="sr" for="fq">To</label><input id="fq" name="q" type="search" value="<?= esc($f['q'], 'attr') ?>" placeholder="To"><button class="btn secondary" type="submit">Show</button></form>
<div class="table-wrap"><table><thead><tr><th>When</th><th>What</th><th>How</th><th>To</th><th>Status</th><th class="no-print"></th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="6" class="muted">Nothing yet.</td></tr><?php endif ?>
<?php foreach ($rows as $m): ?><tr><td><?= local_time($m['created_at']) ?></td>
<td><?= esc(['staff' => 'Copy to one of your people', 'customer' => 'Notice to a customer', 'test' => 'Test'][$m['purpose']] ?? $m['purpose']) ?><?= $m['object_type'] === 'shipment' ? ' · <a href="' . site_url('shipments/' . $m['object_id']) . '">shipment</a>' : '' ?></td>
<td><?= $m['channel'] === 'whatsapp' ? 'WhatsApp' : 'E-mail' ?></td><td><?= esc($m['recipient']) ?></td>
<td><?= esc(Outbox::LABELS[$m['status']] ?? $m['status']) ?><?= (int) $m['attempts'] > 1 ? ' <span class="small muted">(' . (int) $m['attempts'] . ' tries)</span>' : '' ?><?= $m['error'] && ! in_array($m['status'], ['sent', 'delivered', 'read'], true) ? '<div class="small muted">' . esc($m['error']) . '</div>' : '' ?></td>
<td class="no-print"><?php if ($m['status'] === 'failed' && $m['body_enc']): ?><form method="post" action="<?= site_url('admin/messages/' . $m['id'] . '/retry') ?>"><?= csrf_field() ?><button class="btn small secondary" type="submit">Try again</button></form><?php elseif ($m['status'] === 'queued'): ?><form method="post" action="<?= site_url('admin/messages/' . $m['id'] . '/cancel') ?>"><?= csrf_field() ?><button class="btn small secondary" type="submit" data-confirm="Cancel this message?">Cancel</button></form><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
