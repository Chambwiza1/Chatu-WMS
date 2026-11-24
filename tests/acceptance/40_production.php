<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Production;
use App\Libraries\Docs\Sales;
use App\Libraries\Docs\Shipments;

/** Recipes and production runs: materials at FIFO cost, finished batches, traceability, void. */

$recipe = static function (TestKit $t): int {
    $t->as('paul');
    return Production::saveRecipe(['code' => 'BREAD', 'name' => 'White bread', 'output_item_id' => $t->item('BREAD'), 'output_qty' => 100, 'other_cost' => 150,
        'lines' => [['item_id' => $t->item('FLOUR'), 'qty' => 40], ['item_id' => $t->item('SUGAR'), 'qty' => 2]]]);
};
$stockUp = static function (TestKit $t): void {
    $t->receive('LSK', [['FLOUR', 50, 9, ['lot_no' => 'F-OLD', 'expiry_date' => date('Y-m-d', strtotime('+30 days'))]]]);
    $t->receive('LSK', [['FLOUR', 50, 10, ['lot_no' => 'F-NEW', 'expiry_date' => date('Y-m-d', strtotime('+200 days'))]]]);
    $t->receive('LSK', [['SUGAR', 10, 18]]);
};

return [
    'PRD-1 a run uses materials at FIFO cost, makes a dated batch costed at (materials + other) / made' => static function (TestKit $t) use ($recipe, $stockUp) {
        $stockUp($t);
        $r = $recipe($t);
        $t->as('paul');
        $run = Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 2, 'actual_qty' => 190]);
        $p = Production::run($run);
        $t->eq(80 * 0 + 50 * 9 + 30 * 10 + 4 * 18.0, (float) $p['material_cost'], 'flour 50@9 + 30@10, sugar 4@18');
        $t->eq(300.0, (float) $p['other_cost']);
        $t->eq(round((822 + 300) / 190, 4), (float) $p['unit_cost'], 'a low yield raises the cost each');
        $t->eq(190.0, $t->qty('LSK', 'BREAD'));
        $t->eq(date('Y-m-d', strtotime('+5 days')), $p['expiry_date'], 'expiry from the shelf life');
        $t->true(str_starts_with($p['lot_no'], 'BREAD-'), 'batch numbered automatically');
        $t->eq(['F-OLD', 'F-NEW'], array_values(array_filter(array_column($p['inputs'], 'lot_no'))), 'earliest expiry first, every batch recorded');
        $t->eq(20.0, $t->qty('LSK', 'FLOUR'));
    },

    'PRD-2 short material refuses the whole run; the PRODUCTION bin is used first; chosen batches are honoured' => static function (TestKit $t) use ($recipe, $stockUp) {
        $stockUp($t);
        $r = $recipe($t);
        $t->as('paul');
        $t->throws(static fn () => Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 3]), 'Not enough FLOUR');
        $t->eq(0, (int) Db::value('SELECT COUNT(*) FROM {production_runs} WHERE tenant_id = {tid}'), 'nothing posted');
        \App\Libraries\Docs\Moves::post(['warehouse_id' => $t->wh('LSK'), 'lines' => [['stock_id' => $t->row('LSK', 'FLOUR', 'STOCK', 'F-NEW'), 'qty' => 40, 'to_bin_id' => $t->bin('LSK', 'PRODUCTION')]]]);
        $run = Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 1]);
        $t->eq(['F-NEW'], array_values(array_unique(array_filter(array_column(Production::run($run)['inputs'], 'lot_no')))), 'staged material used first');
        $run2 = Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 0.25, 'actual_qty' => 25,
            'picks' => [$t->item('FLOUR') => [[$t->row('LSK', 'FLOUR', 'STOCK', 'F-NEW'), 10]]]]);
        $t->eq(['F-NEW'], array_values(array_unique(array_filter(array_column(Production::run($run2)['inputs'], 'lot_no')))), 'chosen batch');
    },

    'PRD-3 void returns the materials to their batches at the cost used; not once the output has been shipped' => static function (TestKit $t) use ($recipe, $stockUp) {
        $stockUp($t);
        $r = $recipe($t);
        $t->as('paul');
        $before = $t->value('LSK', 'FLOUR');
        $run = Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 1]);
        Production::void($run, 'Wrong recipe used');
        $t->eq($before, $t->value('LSK', 'FLOUR'), 'flour value back');
        $t->eq(0.0, $t->qty('LSK', 'BREAD'));
        $t->eq(0.0, $t->value('LSK', 'BREAD'));
        $t->throws(static fn () => Production::void($run, 'again'), 'already voided');
        $run2 = Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 1]);
        $t->as('sally');
        $so = Sales::saveDraft(['customer_id' => $t->c['customer']['SHOPRITE'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('BREAD'), 'qty' => 10]]]);
        Sales::confirm($so);
        Db::exec("UPDATE {items} SET min_shelf_days = 0 WHERE tenant_id = {tid} AND sku = 'BREAD'");
        \App\Libraries\Master\Items::flush();
        $t->as('steve');
        $sh = Shipments::create($so);
        Shipments::pick($sh);
        Shipments::pack($sh, 1);
        Shipments::dispatch($sh);
        $t->as('paul');
        $t->throws(static fn () => Production::void($run2, 'Too late'), 'cannot be voided');
    },

    'PRD-4 traceability: a finished batch leads back to raw-material batches and forward to customers' => static function (TestKit $t) use ($recipe, $stockUp) {
        $stockUp($t);
        $r = $recipe($t);
        $t->as('paul');
        $run = Production::post(['recipe_id' => $r, 'warehouse_id' => $t->wh('LSK'), 'batches' => 1, 'lot_no' => 'B-0001']);
        Db::exec("UPDATE {items} SET min_shelf_days = 0 WHERE tenant_id = {tid} AND sku = 'BREAD'");
        \App\Libraries\Master\Items::flush();
        $t->as('sally');
        $so = Sales::saveDraft(['customer_id' => $t->c['customer']['NDOLA'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('BREAD'), 'qty' => 30]]]);
        Sales::confirm($so);
        Db::exec("UPDATE {customers} SET min_shelf_days = 0 WHERE tenant_id = {tid}");
        $t->as('steve');
        $sh = Shipments::create($so);
        Shipments::pick($sh);
        Shipments::pack($sh, 1);
        Shipments::dispatch($sh);
        $lot = (int) Db::value("SELECT id FROM {lots} WHERE tenant_id = {tid} AND lot_no = 'B-0001'");
        $tr  = \App\Libraries\Reports\Trace::lot($lot);
        $t->eq(['F-OLD'], array_column($tr['made_from'], 'lot_no'), 'back to the flour batch');
        $t->eq(['Ndola Wholesalers'], array_values(array_unique(array_column($tr['customers'], 'customer'))), 'forward to the customer');
        $flour = (int) Db::value("SELECT id FROM {lots} WHERE tenant_id = {tid} AND lot_no = 'F-OLD'");
        $t->eq(['B-0001'], array_column(\App\Libraries\Reports\Trace::lot($flour)['used_in'], 'lot_no'), 'flour batch forward to the bread batch');
        $t->eq(['Ndola Wholesalers'], array_values(array_unique(array_column(\App\Libraries\Reports\Trace::lot($flour)['customers'], 'customer'))), 'flour batch forward to the customer through the bread');
    },
];
