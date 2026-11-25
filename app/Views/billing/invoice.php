<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $P = \App\Libraries\Platform\Pay::class; $late = $i['status'] === 'issued' && $i['due_on'] < date('Y-m-d'); ?>
<div class="page-head"><div><h1>Invoice <?= esc($i['number']) ?> <?= badge($late ? 'past_due' : $i['status']) ?></h1><p class="sub">From Chatu Innovations to <?= esc($i['tenant']) ?></p></div>
<div class="actions"><button class="btn secondary" type="button" data-print>Print</button></div></div>
<section class="panel"><table class="kv"><tbody>
<tr><th>Product</th><td><?= esc(\App\Libraries\Platform\Product::NAME) ?><?= $i['plan_name'] ? ', plan ' . esc($i['plan_name']) : '' ?></td></tr>
<tr><th>Period</th><td><?= nice_date($i['period_start']) ?> to <?= nice_date($i['period_end']) ?> (<?= (int) $i['months'] ?> month<?= $i['months'] > 1 ? 's' : '' ?>)</td></tr>
<tr><th>Amount</th><td><strong><?= esc($i['currency']) ?> <?= number_format((float) $i['amount'], 2) ?></strong></td></tr>
<tr><th>Issued</th><td><?= local_time($i['issued_at'], 'j M Y') ?></td></tr><tr><th>Due</th><td><?= nice_date($i['due_on']) ?></td></tr>
<?php if ($i['note']): ?><tr><th>Note</th><td><?= esc($i['note']) ?></td></tr><?php endif ?>
<?php if ($i['status'] === 'paid'): ?><tr><th>Paid</th><td><?= local_time($i['paid_at']) ?> · <?= esc((string) $i['paid_ref']) ?></td></tr><?php endif ?></tbody></table>
<?php if ($d = \App\Libraries\Core\Settings::get('billing_details')): ?><p class="small">To pay by hand: <?= nl2br(esc($d)) ?>. Use <strong><?= esc($i['number']) ?></strong> as the reference.</p><?php endif ?>
<p class="small muted">Chatu Innovations · info@chatuzm.com · +260 773818370 / +260 979913497 · www.chatuzm.com</p></section>
<?php if (! $platform && $i['status'] === 'issued'): ?>
<?php if ($gateways): ?><section class="panel no-print"><h2>Pay online</h2>
<?php if (isset($gateways['lenco'])): ?><form method="post" action="<?= site_url('billing/invoices/' . $i['id'] . '/pay') ?>" class="stack"><?= csrf_field() ?><input type="hidden" name="gateway" value="lenco">
<h3><?= esc($P::GATEWAYS['lenco']) ?></h3><div class="grid2"><div class="field"><label for="phone">Mobile money number</label><input id="phone" name="phone" inputmode="tel" required placeholder="0971234567"></div>
<div class="field"><label for="operator">Network</label><select id="operator" name="operator"><?php foreach ($P::OPERATORS as $k => $l): ?><option value="<?= $k ?>"><?= esc($l) ?></option><?php endforeach ?></select></div></div>
<div><button class="btn" type="submit">Send the request to my phone</button></div></form><?php endif ?>
<?php if (isset($gateways['dpo'])): ?><form method="post" action="<?= site_url('billing/invoices/' . $i['id'] . '/pay') ?>" class="stack"><?= csrf_field() ?><input type="hidden" name="gateway" value="dpo">
<h3><?= esc($P::GATEWAYS['dpo']) ?></h3><div><button class="btn" type="submit">Pay on DPO's page</button></div></form><?php endif ?></section><?php endif ?>
<?php endif ?>
<?php if ($payments): ?><h2>Online payments</h2><div class="table-wrap"><table><thead><tr><th>Started</th><th>Through</th><th>Reference</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($payments as $p): ?><tr><td><?= local_time($p['created_at']) ?></td><td><?= esc($P::GATEWAYS[$p['gateway']] ?? $p['gateway']) ?></td><td class="mono small"><?= esc($p['reference']) ?></td><td><?= badge($p['status']) ?></td>
<td><?php if ($p['status'] === 'pending' && $p['gateway'] === 'dpo' && ! empty($p['pay_url']) && empty($platform)): ?><a class="btn small" href="<?= site_url('pay/go/' . $p['reference']) ?>">Go to the card page</a> <?php endif ?><?php if ($p['status'] === 'pending'): ?><form method="post" action="<?= site_url(($platform ? 'platform/payments/' : 'billing/payments/') . $p['id'] . '/check') ?>"><?= csrf_field() ?><button class="btn small secondary" type="submit">Check now</button></form><?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php if ($platform && $i['status'] === 'issued'): ?><div class="grid2"><section class="panel"><h2>Record a payment</h2>
<form method="post" action="<?= site_url('platform/invoices/' . $i['id'] . '/pay') ?>" class="stack"><?= csrf_field() ?><div class="field"><label for="reference">Reference (bank, mobile money or receipt)</label><input id="reference" name="reference" required maxlength="100"></div><div><button class="btn" type="submit">Mark paid</button></div></form></section>
<section class="panel"><h2>Cancel the invoice</h2><form method="post" action="<?= site_url('platform/invoices/' . $i['id'] . '/void') ?>" class="stack"><?= csrf_field() ?><div class="field"><label for="reason">Reason</label><input id="reason" name="reason" required></div><div><button class="btn secondary" type="submit" data-confirm="Cancel this invoice?">Cancel invoice</button></div></form></section></div><?php endif ?>
<?= $this->endSection() ?>
