<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head">
  <div><h1>Good <?= (int) local_time(gmdate('Y-m-d H:i:s'), 'G') < 12 ? 'morning' : ((int) local_time(gmdate('Y-m-d H:i:s'), 'G') < 17 ? 'afternoon' : 'evening') ?>, <?= esc(explode(' ', $me['name'])[0]) ?></h1>
  <p class="sub"><?= esc(local_time(gmdate('Y-m-d H:i:s'), 'l j F Y')) ?></p></div>
  <form method="get" action="<?= site_url('find') ?>" class="inline-form" role="search" id="find-form">
    <label class="sr" for="q">Find</label>
    <input id="q" name="q" type="search" placeholder="Scan or type: item, bin, batch, serial, document" autofocus>
    <button class="btn secondary" type="submit">Find</button>
  </form>
</div>
<div class="scan-box" data-scanner data-target="#q" data-submit="#find-form"><button class="btn secondary small" type="button" data-scan-start>Scan with camera</button><video playsinline muted hidden></video><p class="muted small" data-scan-status></p></div>

<?php if ($fig): ?>
<dl class="kpis">
  <?php if ($fig['value'] !== null): ?><div class="hero"><dt>Stock value (FIFO)</dt><dd><?= money($fig['value']) ?></dd></div>
  <div><dt>In transit</dt><dd><?= money($fig['transit']) ?></dd></div><?php endif ?>
  <div><dt>Items in stock</dt><dd><?= number_format($fig['lines']) ?></dd></div>
  <div><dt>Receipts, last 7 days</dt><dd><?= number_format($fig['received']) ?></dd></div>
  <div><dt>Dispatches, last 7 days</dt><dd><?= number_format($fig['shipped']) ?></dd></div>
</dl>
<?php endif ?>

<?php if ($todo): ?>
<section class="panel"><h2>Waiting for you</h2>
<ul class="attn">
  <?php foreach ($todo as [$n, $label, $hint, $link, $tone]): ?>
    <li class="<?= $n ? '' : 'none' ?>"><a href="<?= site_url(ltrim($link, '/')) ?>"><span class="n <?= esc($tone) ?>"><?= $n ?></span><span><?= esc($label) ?><small><?= esc($hint) ?></small></span></a></li>
  <?php endforeach ?>
</ul></section>
<?php endif ?>

<div class="cols">
<?php if ($fig && $fig['byWh']): ?>
<section class="panel"><h2>Value by warehouse</h2>
<?php $max = max(1, max(array_map(static fn ($r) => (float) $r['value'], $fig['byWh']))) ?>
<ul class="hbars">
<?php foreach ($fig['byWh'] as $r): ?>
  <li><span class="hb-label"><?= esc($r['code'] . ' ' . $r['name']) ?></span><svg class="hb-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect width="<?= round(100 * (float) $r['value'] / $max, 2) ?>" height="10" rx="2"></rect></svg><span class="hb-value"><?= money($r['value']) ?></span></li>
<?php endforeach ?>
</ul></section>
<?php endif ?>
<?php if ($recent): ?>
<section class="panel"><h2>Latest movements</h2>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Where</th><th>What</th><th class="num">Qty</th><th>By</th></tr></thead><tbody>
<?php foreach ($recent as $m): ?><tr><td class="nowrap"><?= local_time($m['at'], 'j M H:i') ?></td><td><?= esc($m['wh']) ?></td><td><?= esc(str_replace('_', ' ', $m['type'])) ?> <span class="mono"><?= esc($m['sku']) ?></span></td><td class="num"><?= qty($m['qty']) ?></td><td><?= esc($m['user']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<p><a href="<?= site_url('reports/movements') ?>">All movements</a></p></section>
<?php endif ?>
</div>
<?php if (! $todo && ! $fig): ?>
<section class="panel"><p>Your account has no warehouse duties yet. Ask the administrator to give you a role.</p></section>
<?php endif ?>
<?= $this->endSection() ?>
