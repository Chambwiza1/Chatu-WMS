<?php $B = \App\Libraries\Platform\Billing::class; $P = \App\Libraries\Platform\Product::class; $lim = $B::limits((int) $t['id']); $plans = $B::plans(true); $rules = \App\Libraries\Platform\Modules::rules((int) $t['id']); $inv = $B::invoices((int) $t['id'], 12); $days = $B::trialDaysLeft($t); ?>
<h2>Plan and billing</h2>
<div class="grid2"><section class="panel"><h3>Plan</h3>
<table class="kv"><tbody><tr><th>Plan</th><td><?= $lim['plan'] ? esc($lim['plan']['name']) . ' · ' . esc($lim['plan']['currency']) . ' ' . number_format((float) $lim['plan']['monthly_price'], 2) . ' a month' : 'None' ?></td></tr>
<?php foreach ($lim['items'] as $it): ?><tr><th><?= esc($it['label']) ?></th><td><?= (int) $it['used'] ?> of <?= $it['max'] ?? 'any' ?></td></tr><?php endforeach ?>
<tr><th>Paid until</th><td><?= $t['paid_until'] ? nice_date($t['paid_until']) : '—' ?></td></tr><?php if ($days !== null): ?><tr><th>Trial</th><td><?= $days ?> day<?= $days === 1 ? '' : 's' ?> left</td></tr><?php endif ?></tbody></table>
<form method="post" action="<?= site_url('platform/tenants/' . $t['id'] . '/plan') ?>" class="stack"><?= csrf_field() ?>
<div class="field"><label for="plan_id">Plan</label><select id="plan_id" name="plan_id"><option value="">None</option><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>" <?= (int) $t['plan_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= esc($p['name'] . ' (' . $p['code'] . ')') ?></option><?php endforeach ?></select></div>
<div class="field"><label for="billing_email">Invoices also go to (e-mail)</label><input id="billing_email" name="billing_email" type="email" value="<?= esc((string) $t['billing_email']) ?>"></div>
<div class="field"><label for="plan-reason">Reason</label><input id="plan-reason" name="reason"></div><div><button class="btn" type="submit">Save plan</button></div></form>
<?php if ($t['status'] === 'trial' || ($t['status'] === 'suspended' && $t['status_reason'] === 'trial_ended')): ?>
<form method="post" action="<?= site_url('platform/tenants/' . $t['id'] . '/trial') ?>" class="stack"><?= csrf_field() ?><h3>Extend the trial</h3>
<div class="grid2"><div class="field"><label for="days">Days from today</label><input id="days" name="days" inputmode="numeric" value="14" required></div><div class="field"><label for="trial-reason">Reason</label><input id="trial-reason" name="reason" required></div></div>
<div><button class="btn secondary" type="submit">Extend</button></div></form><?php endif ?></section>
<section class="panel"><h3>Invoice</h3>
<form method="post" action="<?= site_url('platform/tenants/' . $t['id'] . '/invoice') ?>" class="stack"><?= csrf_field() ?>
<div class="grid2"><div class="field"><label for="months">Months</label><select id="months" name="months"><?php foreach ([1, 3, 6, 12, 24] as $m): ?><option value="<?= $m ?>"><?= $m ?></option><?php endforeach ?></select></div>
<div class="field"><label for="amount">Amount</label><input id="amount" name="amount" inputmode="decimal" placeholder="the plan's price"></div></div>
<div class="field"><label for="note">Note on the invoice</label><input id="note" name="note" maxlength="255"></div><div><button class="btn" type="submit">Issue invoice</button></div></form>
<div class="table-wrap"><table><thead><tr><th>Number</th><th class="num">Amount</th><th>Due</th><th>Status</th></tr></thead><tbody><?php if (! $inv): ?><tr><td colspan="4" class="empty">None yet.</td></tr><?php endif ?>
<?php foreach ($inv as $i): ?><tr><td class="mono"><a href="<?= site_url('platform/invoices/' . $i['id']) ?>"><?= esc($i['number']) ?></a></td><td class="num"><?= number_format((float) $i['amount'], 2) ?></td><td><?= nice_date($i['due_on']) ?></td><td><?= badge($i['status'] === 'issued' && $i['due_on'] < date('Y-m-d') ? 'past_due' : $i['status']) ?></td></tr><?php endforeach ?></tbody></table></div></section></div>
<?php if ($P::MODULES): ?><section class="panel"><h3>Modules</h3><p class="small">Set by the plan when it is chosen; change one here for this organisation only. A module in use cannot be made unavailable.</p>
<form method="post" action="<?= site_url('platform/tenants/' . $t['id'] . '/modules') ?>" class="stack"><?= csrf_field() ?><div class="grid3">
<?php foreach ($P::MODULES as $k => [$name, $what]): ?><div class="field"><label for="tm-<?= $k ?>"><?= esc($name) ?></label><select id="tm-<?= $k ?>" name="modules[<?= $k ?>]"><?php foreach (\App\Libraries\Platform\Modules::RULES as $rk => $rl): ?><option value="<?= $rk ?>" <?= $rules[$k] === $rk ? 'selected' : '' ?>><?= esc($rl) ?></option><?php endforeach ?></select></div><?php endforeach ?>
</div><div class="field"><label for="mod-reason">Reason</label><input id="mod-reason" name="reason"></div><div><button class="btn secondary" type="submit">Save modules</button></div></form></section><?php endif ?>
