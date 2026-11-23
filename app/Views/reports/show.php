<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $period = in_array($code, ['movements', 'dispatches', 'losses', 'production', 'fleet'], true); $tot = []; $link = $res['link'] ?? [] ?>
<div class="page-head"><div><h1><?= esc($meta[0]) ?></h1><p class="sub"><?= esc($meta[1]) ?></p></div>
<div class="actions no-print"><?php if (can('data.export')): ?><a class="btn secondary" href="<?= current_url() . '?' . http_build_query(array_filter($f) + ['csv' => 1]) ?>">Download CSV</a><?php endif ?><button class="btn secondary" data-print>Print</button></div></div>
<form method="get" class="filters no-print">
<?php if ($code !== 'fleet'): ?><div class="field"><label for="w">Warehouse</label><select id="w" name="warehouse_id"><option value="">All mine</option><?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>" <?= (int) $f['warehouse_id'] === (int) $w['id'] ? 'selected' : '' ?>><?= esc($w['code']) ?></option><?php endforeach ?></select></div><?php endif ?>
<?php if ($period): ?><div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="<?= esc($f['from']) ?>"></div><div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="<?= esc($f['to']) ?>"></div><?php endif ?>
<?php if (in_array($code, ['on_hand', 'movements'], true)): ?><div class="field"><label for="q">Item, batch or serial</label><input id="q" name="q" value="<?= esc($f['q']) ?>"></div><?php endif ?>
<?php if ($code === 'expiry'): ?><div class="field"><label for="days">Expiring within (days)</label><input id="days" type="number" min="0" name="days" value="<?= esc($f['days'] ?? \App\Libraries\Core\Settings::get('expiry_warn_days')) ?>"></div><?php endif ?>
<button class="btn secondary" type="submit">Show</button></form>
<?php if (! $res['rows']): ?><div class="panel empty">Nothing to show.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><?php foreach ($res['columns'] as $k => $l): ?><th class="<?= in_array($k, $res['num'], true) ? 'num' : '' ?>"><?= esc($l) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($res['rows'] as $r): ?><tr><?php foreach ($res['columns'] as $k => $l): $v = $r[$k] ?? null; if (in_array($k, $res['total'] ?? [], true)) { $tot[$k] = ($tot[$k] ?? 0) + (float) $v; } ?>
<td class="<?= in_array($k, $res['num'], true) ? 'num' : '' ?>"><?php
if (isset($link[$k]) && isset($r['id'])) { echo '<a href="' . site_url(ltrim(str_replace('{id}', (string) $r['id'], $link[$k]), '/')) . '">' . esc((string) $v) . '</a>'; }
elseif (in_array($k, ['value', 'amount', 'avg_cost', 'unit_price', 'material_cost', 'other_cost', 'unit_cost', 'running_cost', 'cost_of_shipment'], true)) { echo $v === null ? '' : money($v, false); }
elseif (in_array($k, $res['num'], true)) { echo $v === null ? '' : qty($v); }
elseif (in_array($k, ['status', 'lot_status'], true) && $v) { echo badge((string) $v); }
elseif ($k === 'expiry_date' || $k === 'produced_on') { echo nice_date($v); }
elseif ($k === 'at' || $k === 'dispatched_at') { echo esc(local_time($v)); }
else { echo esc((string) $v); } ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody><?php if ($tot): ?><tfoot><tr><?php $first = true; foreach ($res['columns'] as $k => $l): ?><td class="<?= isset($tot[$k]) ? 'num' : '' ?>"><?= isset($tot[$k]) ? money($tot[$k]) : ($first ? 'Total' : '') ?></td><?php $first = false; endforeach ?></tr></tfoot><?php endif ?></table></div>
<p class="muted small"><?= count($res['rows']) ?> rows.</p><?php endif ?>
<?= $this->endSection() ?>
