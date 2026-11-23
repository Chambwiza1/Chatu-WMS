<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>Counts</h1><p class="sub"><?= esc(wh_name($wh)) ?></p></div></div>
<?php if (can('count.manage', $wh)): ?><?= view('partials/item_list', ['items' => $items]) ?>
<details class="panel"><summary>Start a count</summary><form method="post" action="<?= site_url('counts') ?>" class="stack"><?= csrf_field() ?><input type="hidden" name="warehouse_id" value="<?= $wh ?>">
<p class="muted small">The count list is everything the system holds in the part you choose. Leave everything empty to count the whole warehouse.</p>
<div class="row2"><div class="field"><label for="zone_id">Zone</label><select id="zone_id" name="zone_id"><option value="">All zones</option><?php foreach ($zones as $z): ?><option value="<?= $z['id'] ?>"><?= esc($z['code'] . ' ' . $z['name']) ?></option><?php endforeach ?></select></div><div class="field"><label for="sku">One item only</label><input id="sku" list="item-list" name="sku"></div></div>
<div class="row2"><div class="field"><label for="bin_from">Bins from</label><input id="bin_from" class="upper" name="bin_from" placeholder="A-01-01-1"></div><div class="field"><label for="bin_to">to</label><input id="bin_to" class="upper" name="bin_to" placeholder="A-01-03-2"></div></div>
<label class="check"><input type="checkbox" name="blind" value="1" checked> Blind count: counters do not see what the system expects</label>
<div class="field"><label for="notes">Notes</label><input id="notes" name="notes"></div><div><button class="btn" type="submit">Start count</button></div></form></details><?php endif ?>
<div class="pill-filter"><?php foreach (['' => 'All', 'open' => 'Open', 'submitted' => 'To approve', 'posted' => 'Posted', 'cancelled' => 'Cancelled'] as $k => $l): ?><a href="<?= site_url('counts' . ($k !== '' ? '?status=' . $k : '')) ?>" class="<?= $st === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></div>
<?php if (! $rows): ?><div class="panel empty">No counts here.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Count</th><th>Scope</th><th>Started</th><th class="num">Counted</th><th>Status</th><?php if (can('stock.view_cost')): ?><th class="num">Variance value</th><?php endif ?></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr><td><a href="<?= site_url('counts/' . $c['id']) ?>"><?= esc($c['number']) ?></a><?= $c['blind'] ? ' <span class="badge quiet">Blind</span>' : '' ?></td><td><?= esc(trim(($c['zone'] ? 'Zone ' . $c['zone'] : '') . ' ' . ($c['sku'] ?? '')) ?: 'Whole warehouse') ?></td><td><?= local_time($c['created_at'], 'j M H:i') ?> by <?= esc($c['created_name']) ?></td><td class="num"><?= (int) $c['counted'] ?> / <?= (int) $c['n_lines'] ?></td><td><?= badge($c['status']) ?></td><?php if (can('stock.view_cost')): ?><td class="num"><?= $c['status'] !== 'open' ? money($c['value']) : '' ?></td><?php endif ?></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
<?= $this->endSection() ?>
