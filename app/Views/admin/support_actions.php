<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-head"><div><h1>What was saved</h1><p class="sub"><a href="<?= site_url('admin/support') ?>">Support access</a> · <?= esc($log['by_name']) ?> as <?= esc((string) $log['as_name']) ?>, <?= local_time($log['started_at']) ?> · <?= esc($log['reason']) ?></p></div></div>
<p class="muted">Each line is a page that saved or changed something, in order. The audit log shows what changed, under the name of the person signed in as.</p>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Page</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="nowrap"><?= local_time($r['at'], 'j M Y H:i:s') ?></td><td class="mono"><?= esc($r['method'] . ' /' . $r['path']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?= $this->endSection() ?>
