<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $P = \App\Libraries\Platform\Product::class; $M = \App\Libraries\Platform\Modules::RULES; $cur = \App\Libraries\Platform\Billing::currency(); ?>
<div class="page-head"><div><h1>Plans</h1><p class="sub">What organisations pay each month, what they may add, and which modules they have. Changing a plan's modules changes them for every organisation on it.</p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('platform/invoices') ?>">Invoices</a><a class="btn secondary" href="<?= site_url('platform/billing') ?>">Billing settings</a></div></div>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th class="num">Per month</th><?php foreach ($P::LIMITS as $l): ?><th class="num"><?= esc($l) ?></th><?php endforeach ?><th class="num">Trial days</th><th>Modules</th><th class="num">Organisations</th><th></th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="9" class="empty">No plans yet. Add the first one below.</td></tr><?php endif ?>
<?php foreach ($rows as $r): $mods = \App\Libraries\Platform\Billing::planModules($r); ?><tr class="<?= $r['active'] ? '' : 'muted' ?>"><td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['name']) ?><?= $r['active'] ? '' : ' <span class="muted small">(not offered)</span>' ?></td>
<td class="num"><?= esc($r['currency']) ?> <?= number_format((float) $r['monthly_price'], 2) ?></td><td class="num"><?= $r['max_locations'] ?? 'Any' ?></td><td class="num"><?= $r['max_users'] ?? 'Any' ?></td><td class="num"><?= (int) $r['trial_days'] ?></td>
<td class="small"><?= esc(implode(', ', array_map(static fn ($k) => $P::MODULES[$k][0] . ($mods[$k] === 'on' ? ' (always)' : ''), array_keys(array_filter($mods, static fn ($v) => $v !== 'off'))))) ?: '—' ?></td>
<td class="num"><?= (int) $r['tenants'] ?></td><td><a href="<?= site_url('platform/plans?edit=' . $r['id']) ?>">Change</a></td></tr><?php endforeach ?>
</tbody></table></div>
<section class="panel"><h2><?= $edit ? 'Change plan ' . esc($edit['code']) : 'New plan' ?></h2>
<form method="post" action="<?= site_url('platform/plans') ?>" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
<div class="grid3"><div class="field"><label for="code">Code</label><input id="code" name="code" required maxlength="20" value="<?= esc($edit['code'] ?? '') ?>" placeholder="STARTER"></div>
<div class="field"><label for="name">Name</label><input id="name" name="name" required maxlength="80" value="<?= esc($edit['name'] ?? '') ?>" placeholder="Starter"></div>
<div class="field"><label for="monthly_price">Price per month (<?= esc($cur) ?>)</label><input id="monthly_price" name="monthly_price" inputmode="decimal" required value="<?= esc((string) ($edit['monthly_price'] ?? '')) ?>"></div>
<div class="field"><label for="max_locations"><?= esc($P::LIMITS['locations']) ?> at most</label><input id="max_locations" name="max_locations" inputmode="numeric" value="<?= esc((string) ($edit['max_locations'] ?? '')) ?>"><span class="hint">Empty: any number.</span></div>
<div class="field"><label for="max_users"><?= esc($P::LIMITS['users']) ?> at most</label><input id="max_users" name="max_users" inputmode="numeric" value="<?= esc((string) ($edit['max_users'] ?? '')) ?>"><span class="hint">Empty: any number.</span></div>
<div class="field"><label for="trial_days">Trial days for new organisations</label><input id="trial_days" name="trial_days" inputmode="numeric" value="<?= esc((string) ($edit['trial_days'] ?? '14')) ?>"></div></div>
<?php if ($P::MODULES): ?><fieldset><legend>Modules on this plan</legend><div class="grid3">
<?php foreach ($P::MODULES as $k => [$name, $what]): $v = $edit['module_rules'][$k] ?? 'allow'; ?><div class="field"><label for="m-<?= $k ?>"><?= esc($name) ?></label><select id="m-<?= $k ?>" name="modules[<?= $k ?>]"><?php foreach ($M as $rk => $rl): ?><option value="<?= $rk ?>" <?= $v === $rk ? 'selected' : '' ?>><?= esc($rl) ?></option><?php endforeach ?></select><span class="hint"><?= esc($what) ?></span></div><?php endforeach ?>
</div></fieldset><?php endif ?>
<?php if ($edit): ?><label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Offered (untick to stop offering it; organisations on it keep it)</label><?php endif ?>
<div><button class="btn" type="submit">Save plan</button><?php if ($edit): ?> <a class="btn secondary" href="<?= site_url('platform/plans') ?>">Cancel</a><?php endif ?></div></form></section>
<?= $this->endSection() ?>
