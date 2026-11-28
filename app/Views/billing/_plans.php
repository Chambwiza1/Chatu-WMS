<?php /* core 1.5.5: choose a plan and ask for an invoice. $offered, $requests, $isOwner, $limits, $currency, $unpaid */
$cur = (int) ($limits['plan']['id'] ?? 0); $wait = null; foreach ($requests as $rq) { if ($rq['status'] === 'pending') { $wait = $rq; break; } } ?>
<?php if ($wait): ?><section class="panel warn-panel"><h2>A request is waiting for the account owner</h2>
<p><strong><?= esc($wait['asked_by']) ?></strong> asked for an invoice: <strong><?= esc($wait['plan_name']) ?></strong>, <?= (int) $wait['months'] ?> month<?= (int) $wait['months'] === 1 ? '' : 's' ?>, <?= esc($currency) ?> <?= number_format((float) $wait['amount'], 2) ?> (<?= nice_date($wait['requested_at']) ?>).</p>
<?php if ($isOwner): ?><form method="post" action="<?= site_url('billing/requests/' . $wait['id'] . '/decide') ?>" class="stack"><?= csrf_field() ?>
<label for="reason">Reason, if you reject it</label><input id="reason" name="reason" maxlength="255">
<div class="actions"><button class="btn" type="submit" name="do" value="approve" data-confirm="Approve it? The plan is set and the invoice is issued.">Approve and issue the invoice</button><button class="btn secondary" type="submit" name="do" value="reject">Reject</button></div></form>
<?php else: ?><form method="post" action="<?= site_url('billing/requests/' . $wait['id'] . '/cancel') ?>"><?= csrf_field() ?><p class="small">The account owner approves it. Until then you can take it back.</p><button class="btn secondary" type="submit" data-confirm="Cancel this request?">Cancel the request</button></form><?php endif ?>
</section><?php endif ?>
<section class="panel"><h2>Choose a plan and ask for an invoice</h2>
<?php if (! $offered): ?><p class="small">No plans are on offer yet. Contact Chatu Innovations: info@chatuzm.com · +260 773818370.</p>
<?php else: ?>
<p class="small"><?= $isOwner ? 'As the account owner, your invoice is issued at once and the plan changes straight away.' : 'Your request goes to the account owner to approve. The plan changes and the invoice is issued when they do.' ?></p>
<form method="post" action="<?= site_url('billing/requests') ?>" class="stack wide"><?= csrf_field() ?>
<div class="table-wrap"><table><thead><tr><th></th><th>Plan</th><th class="num">A month</th><?php foreach ($limits['items'] as $k => $it): ?><th class="num"><?= esc($it['label']) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($offered as $i => $pl): ?><tr><td><input type="radio" name="plan_id" id="plan<?= (int) $pl['id'] ?>" value="<?= (int) $pl['id'] ?>" <?= (int) $pl['id'] === $cur || (! $cur && $i === 0) ? 'checked' : '' ?> required></td>
<td><label for="plan<?= (int) $pl['id'] ?>"><strong><?= esc($pl['name']) ?></strong><?= (int) $pl['id'] === $cur ? ' <span class="badge good">Your plan</span>' : '' ?></label></td>
<td class="num"><?= esc($currency) ?> <?= number_format((float) $pl['monthly_price'], 2) ?></td>
<?php foreach ($limits['items'] as $k => $it): $mx = $pl['max_' . $k] ?? null; ?><td class="num"><?= $mx === null ? 'any number' : (int) $mx ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody></table></div>
<div class="grid3"><div><label for="months">Months to invoice</label><select id="months" name="months"><?php foreach ([1, 3, 6, 12, 24] as $m): ?><option value="<?= $m ?>"><?= $m ?> month<?= $m === 1 ? '' : 's' ?></option><?php endforeach ?></select></div>
<div><button class="btn" type="submit" <?= $wait || $unpaid ? 'disabled' : '' ?> data-confirm="<?= $isOwner ? 'Issue the invoice for this plan?' : 'Send this request to the account owner?' ?>"><?= $isOwner ? 'Issue my invoice' : 'Ask for the invoice' ?></button></div></div>
<?php if ($unpaid): ?><p class="small">Invoice <?= esc($unpaid) ?> is not paid yet. Pay it first, or ask Chatu Innovations to cancel it.</p><?php elseif ($wait): ?><p class="small">One request at a time: the one above is still waiting.</p><?php endif ?>
</form><?php endif ?>
<?php $done = array_filter($requests, static fn ($r) => $r['status'] !== 'pending'); if ($done): ?>
<h3>Earlier requests</h3><div class="table-wrap"><table><thead><tr><th>Asked</th><th>By</th><th>Plan</th><th>Months</th><th>Outcome</th></tr></thead><tbody>
<?php foreach ($done as $rq): ?><tr><td><?= nice_date($rq['requested_at']) ?></td><td><?= esc($rq['asked_by']) ?></td><td><?= esc($rq['plan_name']) ?></td><td><?= (int) $rq['months'] ?></td>
<td><?= $rq['status'] === 'approved' ? ($rq['invoice_id'] ? '<a href="' . site_url('billing/invoices/' . $rq['invoice_id']) . '">' . esc($rq['invoice_number']) . '</a>' : 'Approved') : esc(ucfirst($rq['status'])) . ($rq['reason'] ? ': ' . esc($rq['reason']) : '') ?><?= $rq['decided_name'] && $rq['decided_name'] !== $rq['asked_by'] ? ' <span class="small">(' . esc($rq['decided_name']) . ')</span>' : '' ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
</section>
