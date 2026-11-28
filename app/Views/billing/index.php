<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Billing</h1><p class="sub">Your plan with Chatu Innovations, and its invoices. Pay online, or by bank or mobile money with the invoice number as the reference.</p></div></div>
<?php if ($single): ?><p class="panel">This is the single-company edition: there are no plans or invoices here.</p><?php else: ?>
<?php if ($t['status'] === 'suspended'): ?><section class="panel warn-panel"><h2><?= $t['status_reason'] === 'trial_ended' ? 'Your trial has ended' : 'Suspended for an unpaid invoice' ?></h2><p>You can look everything up, but nothing can be changed until an invoice is paid. Paying online opens it again at once.</p></section>
<?php elseif ($t['status'] === 'past_due'): ?><section class="panel warn-panel"><h2>An invoice is overdue</h2><p>Please pay it below. After a few days the organisation becomes read-only until it is paid.</p></section>
<?php elseif ($days !== null): ?><section class="panel"><h2>Trial: <?= $days ?> day<?= $days === 1 ? '' : 's' ?> left</h2><p>To carry on without a break, pay your first invoice before the trial ends.</p></section><?php endif ?>
<div class="grid2"><section class="panel"><h2>Plan</h2><table class="kv"><tbody>
<tr><th>Plan</th><td><?= $limits['plan'] ? esc($limits['plan']['name']) . ' · ' . esc($limits['plan']['currency']) . ' ' . number_format((float) $limits['plan']['monthly_price'], 2) . ' a month' : 'Not chosen yet' ?></td></tr>
<?php foreach ($limits['items'] as $it): ?><tr><th><?= esc($it['label']) ?></th><td><?= (int) $it['used'] ?> of <?= $it['max'] ?? 'any number' ?></td></tr><?php endforeach ?>
<tr><th>Paid until</th><td><?= $t['paid_until'] ? nice_date($t['paid_until']) : '—' ?></td></tr></tbody></table>
<p class="small">Choose or change your plan below. Help: info@chatuzm.com · +260 773818370.</p></section>
<section class="panel"><h2>Invoices</h2><div class="table-wrap"><table><thead><tr><th>Number</th><th>For</th><th class="num">Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="5" class="empty">No invoices yet.</td></tr><?php endif ?>
<?php foreach ($rows as $r): ?><tr><td class="mono"><a href="<?= site_url('billing/invoices/' . $r['id']) ?>"><?= esc($r['number']) ?></a></td><td><?= nice_date($r['period_start']) ?> to <?= nice_date($r['period_end']) ?></td><td class="num"><?= esc($r['currency']) ?> <?= number_format((float) $r['amount'], 2) ?></td><td><?= nice_date($r['due_on']) ?></td><td><?= badge($r['status'] === 'issued' && $r['due_on'] < date('Y-m-d') ? 'past_due' : $r['status']) ?></td></tr><?php endforeach ?>
</tbody></table></div></section></div>
<?= $this->include('billing/_plans') ?><?php endif ?>
<?= $this->endSection() ?>
