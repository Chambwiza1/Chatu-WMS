<?php
/** Active items for line editors (core 1.7, D2): the type-ahead picker reads them; type part of a code or name words in any order, or scan a barcode. */
$opts = $items ?? \App\Libraries\Master\Items::options($kinds ?? null);
?>
<datalist id="item-list-data">
<?php foreach ($opts as $i): ?>
  <option value="<?= esc($i['sku'] . ' — ' . $i['name'], 'attr') ?>" data-tracking="<?= esc($i['tracking'], 'attr') ?>" data-uom="<?= esc($i['uom'], 'attr') ?>" data-units="<?= esc((string) ($i['units'] ?? ''), 'attr') ?>" data-codes="<?= esc((string) ($i['codes'] ?? ''), 'attr') ?>"></option>
<?php endforeach ?>
</datalist>
