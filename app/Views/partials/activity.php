<?php // core 1.6 (C10): the record's activity, from the audit log. Needs $type and $id.
$lines = ($id ?? null) ? \App\Libraries\Core\Activity::of($type, (int) $id) : [];
if ($lines): ?>
<details class="activity panel no-print" id="activity"><summary><h2>Activity <span class="small muted">(<?= count($lines) ?>)</span></h2></summary>
<ol class="activity-list"><?php foreach ($lines as $a): ?><li><span class="at"><?= local_time($a['at']) ?></span> <b><?= esc($a['who']) ?></b> <?= esc(mb_strtolower($a['what'])) ?>
<?php if ($a['changes']): ?><ul class="small muted"><?php foreach ($a['changes'] as $c): ?><li><?= esc($c) ?></li><?php endforeach ?></ul><?php endif ?></li><?php endforeach ?></ol>
<?php if (can('audit.view')): ?><p class="small"><a href="<?= site_url('audit') ?>">The full audit log</a></p><?php endif ?>
</details>
<?php endif ?>
