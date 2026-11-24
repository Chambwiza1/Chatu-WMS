<?php
/**
 * Every permission on one page (beta12): one row per module; Select, View, Create, Edit, Delete; the module's actions;
 * its supervisor rights. $on: codes ticked; $fromRole: code => role names (shown ticked and greyed, given by a role);
 * $edit: whether boxes may be changed; $name: the input name.
 */
$on       = array_flip($on ?? []);
$fromRole = $fromRole ?? [];
$edit     = $edit ?? true;
$name     = $name ?? 'perms[]';
$box = static function (string $code, string $label, string $desc, bool $show) use ($on, $fromRole, $edit, $name): string {
    $role = $fromRole[$code] ?? null;
    $chk  = isset($on[$code]) || $role;
    $tip  = $desc . ($role ? '. From the role ' . implode(', ', $role) : '');
    return '<label class="pbox' . ($role ? ' from-role' : '') . '" title="' . esc($tip, 'attr') . '"><input type="checkbox" name="' . esc($name, 'attr') . '" value="' . esc($code, 'attr') . '" data-code="' . esc($code, 'attr') . '"'
        . ($chk ? ' checked' : '') . ($role || ! $edit ? ' disabled' : '') . ($role ? ' data-role-given="1"' : '') . '>'
        . ($show ? ' <span>' . esc($label) . '</span>' : '<span class="sr-only">' . esc($label) . '</span>') . '</label>';
};
?>
<div class="table-wrap"><table class="rights" data-rights><thead><tr><th>Module</th>
<?php foreach (\App\Libraries\Core\Rights::COLUMNS as $col => $title): ?><th class="<?= in_array($col, ['do', 'supervise'], true) ? 'wide-col' : 'c' ?>"><?= esc($title) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach (\App\Libraries\Core\Rights::modules() as $mod => [$title, , $cols]): ?><tr>
<th><?= esc($title) ?><?php if ($edit): ?> <button type="button" class="linkish small" data-tick-row>all</button><?php endif ?></th>
<?php foreach (\App\Libraries\Core\Rights::COLUMNS as $col => $ct): ?><td class="<?= in_array($col, ['do', 'supervise'], true) ? 'wide-col' : 'c' ?>">
<?php foreach ($cols[$col] ?? [] as $code => [$label, $desc]) { echo $box($code, $label, $desc, in_array($col, ['do', 'supervise'], true)); } ?>
</td><?php endforeach ?></tr>
<?php endforeach ?></tbody></table></div>
