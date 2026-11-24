<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Online payments</h1><p class="sub">Organisations paying their invoices through Lenco or DPO. A payment is marked paid once, whichever comes first: the gateway's message, the customer coming back, or a check. Anything still waiting is checked every few minutes.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Started</th><th>Organisation</th><th>Reference</th><th>Through</th><th class="num">Amount</th><th>Status</th><th>Detail</th><th></th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="8" class="empty">No online payments yet.</td></tr><?php endif ?>
<?php foreach ($rows as $r): ?><tr><td><?= local_time($r['created_at'], 'j M H:i') ?></td><td><?= esc($r['tenant']) ?></td><td class="mono small"><?= esc($r['reference']) ?></td><td><?= esc(\App\Libraries\Platform\Pay::GATEWAYS[$r['gateway']] ?? $r['gateway']) ?><?= $r['phone'] ? '<br><span class="small muted">' . esc($r['phone']) . '</span>' : '' ?></td>
<td class="num"><?= esc($r['currency']) ?> <?= number_format((float) $r['amount'], 2) ?></td><td><?= badge($r['status']) ?></td><td class="small"><?= esc((string) $r['detail']) ?> <?php if ($r['purpose'] === 'platform_invoice'): ?><a href="<?= site_url('platform/invoices/' . $r['object_id']) ?>">invoice</a><?php endif ?></td>
<td><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= site_url('platform/payments/' . $r['id'] . '/check') ?>"><?= csrf_field() ?><button class="btn small secondary" type="submit">Check now</button></form><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
