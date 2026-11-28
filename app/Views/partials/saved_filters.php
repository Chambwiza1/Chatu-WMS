<?php
/* core 1.9 (D6): saved filters for the list or report on this page. */
use App\Libraries\Core\Db;
use App\Libraries\Core\Filters;
use App\Libraries\Core\Tenancy;

if (! Tenancy::id()) {
    return; // the platform's own pages (no organisation chosen) have no saved filters
}

$sfPage  = Filters::page(uri_string());
$sfQuery = Filters::clean((array) service('request')->getGet());
$sfList  = Filters::forPage($sfPage);
$sfShare = Filters::canShare();
$sfRoles = $sfShare ? Db::rows('SELECT id, name FROM {roles} WHERE tenant_id = {tid} ORDER BY rank_level DESC, name') : [];
?>
<div class="saved-filters no-print" data-saved-filters>
<?php if ($sfList): ?><span class="muted small">Saved filters</span>
<?php foreach ($sfList as $sf): $on = $sf['query'] === $sfQuery; ?>
<span class="sf-chip<?= $on ? ' on' : '' ?>"><a href="<?= site_url($sfPage) . '?' . esc($sf['query'], 'attr') ?>" title="<?= $sf['role_id'] ? esc('Shared with ' . $sf['role_name'] . ' by ' . $sf['owner'], 'attr') : 'Yours' ?>"><?= esc($sf['name']) ?><?php if ($sf['role_id']): ?> <small>· <?= esc((string) $sf['role_name']) ?></small><?php endif ?></a><?php if ((int) $sf['user_id'] === (int) \App\Libraries\Core\Auth::id() || ($sf['role_id'] && $sfShare)): ?><form method="post" action="<?= site_url('filters/' . $sf['id'] . '/delete') ?>"><?= csrf_field() ?><input type="hidden" name="page" value="<?= esc($sfPage, 'attr') ?>"><button type="submit" class="sf-x" aria-label="Remove saved filter <?= esc($sf['name'], 'attr') ?>" title="Remove">×</button></form><?php endif ?></span>
<?php endforeach ?>
<?php endif ?>
<?php if ($sfQuery !== ''): ?>
<details class="sf-save"><summary class="small">Save these filters</summary>
<form method="post" action="<?= site_url('filters') ?>" class="filters"><?= csrf_field() ?><input type="hidden" name="page" value="<?= esc($sfPage, 'attr') ?>"><input type="hidden" name="query" value="<?= esc($sfQuery, 'attr') ?>">
<div class="field"><label for="sf-name">Name</label><input id="sf-name" name="name" maxlength="80" required placeholder="e.g. Lusaka, low stock"></div>
<?php if ($sfShare): ?><div class="field"><label for="sf-role">Share with</label><select id="sf-role" name="role_id"><option value="">Only me</option><?php foreach ($sfRoles as $r): ?><option value="<?= $r['id'] ?>"><?= esc($r['name']) ?></option><?php endforeach ?></select></div><?php endif ?>
<button class="btn secondary" type="submit">Save</button></form></details>
<?php endif ?>
</div>
