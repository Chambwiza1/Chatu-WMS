<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1><?= ($platform ?? false) ? 'Platform audit log' : 'Audit log' ?></h1><p class="sub"><?= ($platform ?? false) ? 'Platform actions: organisations added and changed, plans and invoices, installs, updates, sign-ins outside an organisation.' : 'Every sign-in, change, decision, posting and refusal in ' . esc($org['name'] ?? '') . '.' ?> Records cannot be edited; each carries a hash of the one before it in this chain.</p></div>
<div class="actions"><a class="btn secondary" href="<?= current_url() . '?' . http_build_query(array_merge($f, ['verify' => 1])) ?>">Check the chain</a>
<?php if (can('data.export')): ?><a class="btn secondary" href="<?= current_url() . '?' . http_build_query(array_merge($f, ['export' => 'csv'])) ?>">Download CSV</a><?php endif ?></div></div>
<?php if ($verify): ?><div class="flash <?= str_starts_with($verify, 'The chain is intact') ? 'ok' : 'err' ?>"><?= esc($verify) ?></div><?php endif ?>
<form method="get" class="filters">
  <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= esc($f['q']) ?>" placeholder="Action, user, object id"></div>
  <div class="field"><label for="outcome">Outcome</label><select id="outcome" name="outcome"><option value="">Any</option><?php foreach (['success' => 'Done', 'denied' => 'Refused', 'failed' => 'Failed'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['outcome'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
  <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="<?= esc($f['from']) ?>"></div>
  <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="<?= esc($f['to']) ?>"></div>
  <button class="btn secondary" type="submit">Filter</button>
</form>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>Where</th><th>Action</th><th>Object</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>
<?php if (! $rows): ?><tr><td colspan="7" class="empty">No events in this range.</td></tr><?php endif ?>
<?php foreach ($rows as $e): ?>
<tr><td class="muted"><?= local_time($e['at'], 'j M Y H:i:s') ?></td><td><?= esc($e['username']) ?><?= $e['on_behalf_of'] ? '<br><span class="muted">for user ' . (int) $e['on_behalf_of'] . '</span>' : '' ?></td><td class="muted"><?= esc($e['ip']) ?></td>
<td><?= esc($e['action']) ?></td><td><?= esc(trim($e['object_type'] . ' ' . $e['object_id'])) ?><?= $e['request_id'] ? ' <a href="' . site_url('requests/' . $e['request_id']) . '">#' . $e['request_id'] . '</a>' : '' ?></td>
<td><span class="badge <?= ['success' => 'good', 'denied' => 'bad', 'failed' => 'warn'][$e['outcome']] ?>"><?= ['success' => 'Done', 'denied' => 'Refused', 'failed' => 'Failed'][$e['outcome']] ?></span></td>
<td><?= esc($e['detail']) ?><?php if ($e['after_json'] || $e['before_json']): ?><details><summary class="muted">Changes</summary><?php if ($e['before_json']): ?><div class="muted">Before: <?= esc($e['before_json']) ?></div><?php endif ?><?php if ($e['after_json']): ?><div>After: <?= esc($e['after_json']) ?></div><?php endif ?></details><?php endif ?></td></tr>
<?php endforeach ?></tbody></table></div>
<?= $this->endSection() ?>
