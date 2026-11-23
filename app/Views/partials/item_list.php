<?php
/** Datalist of active items for line editors: type the code or part of the name, pick the item. */
$opts = $items ?? \App\Libraries\Master\Items::options($kinds ?? null);
?>
<datalist id="item-list">
<?php foreach ($opts as $i): ?>
  <option value="<?= esc($i['sku'] . ' — ' . $i['name'], 'attr') ?>" data-tracking="<?= esc($i['tracking'], 'attr') ?>" data-uom="<?= esc($i['uom'], 'attr') ?>" data-units="<?= esc((string) ($i['units'] ?? ''), 'attr') ?>"></option>
<?php endforeach ?>
</datalist>
