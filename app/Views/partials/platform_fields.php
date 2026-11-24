<?php foreach ($fields as $k => [$label, $hint]): $cur = (string) \App\Libraries\Core\Settings::get($k); ?>
<div class="field"><label for="<?= $k ?>"><?= esc($label) ?></label><input id="<?= $k ?>" name="<?= $k ?>" <?= $k === 'smtp_pass' ? 'type="password" autocomplete="new-password" placeholder="unchanged"' : 'value="' . esc($cur, 'attr') . '"' ?>><?php if ($hint): ?><span class="hint"><?= esc($hint) ?></span><?php endif ?></div>
<?php endforeach ?>
