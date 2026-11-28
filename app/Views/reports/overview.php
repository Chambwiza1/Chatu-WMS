<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $max = max(1, ...array_values(array_map(static fn ($d) => max($d["in"], $d["out"]), $per))); ?>
<div class="page-head"><div><h1>Dashboard</h1><p class="sub"><?= esc(\App\Libraries\Reports\Overview::PERIODS[$days]) ?>, from <?= esc($from) ?><?= $whName ? ', at ' . esc($whName) : ', at your warehouses' ?>.</p></div>
<form method="get" class="msg-filter no-print"><label class="sr" for="pd">Period</label><select id="pd" name="days"><?php foreach (\App\Libraries\Reports\Overview::PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= $k === $days ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
<?php if (count($warehouses) > 1): ?><label class="sr" for="pw">Warehouse</label><select id="pw" name="warehouse"><option value="0">All my warehouses</option><?php foreach ($warehouses as $wh): ?><option value="<?= $wh['id'] ?>" <?= (int) $wh['id'] === $whSel ? 'selected' : '' ?>><?= esc($wh['code'] . ' ' . $wh['name']) ?></option><?php endforeach ?></select><?php endif ?>
<button class="btn secondary" type="submit">Show</button></form></div>
<div class="kpis">
<?php if ($cost): ?><a class="kpi" href="<?= site_url('reports/valuation') ?>"><span class="n"><?= money($value) ?></span><span class="l">Stock value now</span></a><?php endif ?>
<a class="kpi" href="<?= site_url('receipts') ?>"><span class="n"><?= $received ?></span><span class="l">Deliveries received</span></a>
<a class="kpi" href="<?= site_url('shipments') ?>"><span class="n"><?= $shipped ?></span><span class="l">Shipments dispatched</span></a>
<div class="kpi"><span class="n"><?= $on_time === null ? '–' : $on_time . '%' ?></span><span class="l">Dispatched by the date asked</span></div>
<div class="kpi"><span class="n"><?= $lead_days === null ? '–' : $lead_days ?></span><span class="l">Days from order to dispatch</span></div>
<div class="kpi"><span class="n"><?= $accuracy === null ? '–' : $accuracy . '%' ?></span><span class="l">Count lines exact<?= $count_lines ? ' (of ' . $count_lines . ')' : '' ?></span></div>
<?php if ($cost): ?><a class="kpi<?= $losses > 0 ? ' warn' : '' ?>" href="<?= site_url('reports/losses') ?>"><span class="n"><?= money($losses) ?></span><span class="l">Written off (adjustments, counts)</span></a><?php endif ?>
<a class="kpi" href="<?= site_url('transfers') ?>"><span class="n"><?= $in_transit ?></span><span class="l">Transfers on the road</span></a>
<a class="kpi<?= $expiring ? ' warn' : '' ?>" href="<?= site_url('reports/expiry') ?>"><span class="n"><?= $expiring ?></span><span class="l">Batches expiring within 30 days</span></a>
<a class="kpi<?= $below ? ' warn' : '' ?>" href="<?= site_url('reports/reorder') ?>"><span class="n"><?= $below ?></span><span class="l">Items below reorder level</span></a>
</div>
<section class="panel"><h2>Goods in and out, day by day</h2>
<div class="daybars" role="img" aria-label="Deliveries received and shipments dispatched each day">
<?php foreach ($per as $d => $v): ?><div class="day" title="<?= esc($d) ?>: <?= $v['in'] ?> in, <?= $v['out'] ?> out"><i class="in" data-css="height:<?= round(100 * $v['in'] / $max) ?>%"></i><i class="out" data-css="height:<?= round(100 * $v['out'] / $max) ?>%"></i></div><?php endforeach ?>
</div>
<p class="small muted"><span class="key in"></span> Deliveries received <span class="key out"></span> Shipments dispatched · highest day <?= $max ?></p></section>
<div class="grid2">
<?php if ($cost && $byWh): $vmax = max(1, ...array_map(static fn ($r) => (float) $r['value'], $byWh)); ?><section class="panel"><h2>Stock value by warehouse</h2>
<?php foreach ($byWh as $r): ?><div class="hbar"><span class="hb-l"><?= esc($r['code']) ?></span><span class="bar"><i data-css="width:<?= round(100 * (float) $r['value'] / $vmax) ?>%"></i></span><span class="v"><?= money((float) $r['value']) ?></span></div><?php endforeach ?></section><?php endif ?>
<section class="panel"><h2>Most dispatched</h2>
<?php if (! $top): ?><p class="muted">Nothing dispatched in this period.</p><?php else: $tmax = max(1, ...array_map(static fn ($r) => (float) $r['qty'], $top)); foreach ($top as $r): ?>
<div class="hbar"><span class="hb-l"><a href="<?= site_url('stock/item/' . $r['id']) ?>"><?= esc($r['sku']) ?></a></span><span class="bar"><i data-css="width:<?= round(100 * (float) $r['qty'] / $tmax) ?>%"></i></span><span class="v"><?= esc(rtrim(rtrim(number_format((float) $r['qty'], 3, '.', ','), '0'), '.') . ' ' . $r['uom']) ?></span></div><?php endforeach; endif ?></section>
</div>
<?= $this->endSection() ?>
