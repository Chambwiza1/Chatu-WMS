<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Invoices</h1><p class="sub">Invoices to organisations for their plan. Unpaid: <?= esc($currency) ?> <?= number_format($owing, 2) ?>. Each organisation's page issues a new one.</p></div>
<div class="actions"><a class="btn secondary" href="<?= site_url('platform/payments') ?>">Online payments</a><a class="btn secondary" href="<?= site_url('platform/plans') ?>">Plans</a></div></div>
<div class="table-wrap"><table><thead><tr><th>Number</th><th>Organisation</th><th>For</th><th class="num">Amount</th><th>Due</th><th>Status</th><th>Paid</th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="7" class="empty">No invoices yet.</td></tr><?php endif ?>
<?php foreach ($rows as $r): $late = $r['status'] === 'issued' && $r['due_on'] < date('Y-m-d'); ?><tr><td class="mono"><a href="<?= site_url('platform/invoices/' . $r['id']) ?>"><?= esc($r['number']) ?></a></td><td><a href="<?= site_url('platform/tenants/' . $r['tenant_id']) ?>"><?= esc($r['tenant']) ?></a></td>
<td><?= nice_date($r['period_start']) ?> to <?= nice_date($r['period_end']) ?></td><td class="num"><?= esc($r['currency']) ?> <?= number_format((float) $r['amount'], 2) ?></td><td class="<?= $late ? 'bad' : '' ?>"><?= nice_date($r['due_on']) ?></td>
<td><?= badge($late ? 'past_due' : $r['status']) ?></td><td class="small"><?= $r['paid_at'] ? local_time($r['paid_at'], 'j M Y') . ' · ' . esc((string) $r['paid_ref']) : '' ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
