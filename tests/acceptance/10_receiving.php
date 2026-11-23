<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Docs\Moves;
use App\Libraries\Docs\Purchasing;
use App\Libraries\Docs\Receipts;
use App\Libraries\Docs\SupplierReturns;
use App\Libraries\Stock\Lots;

/** Purchasing, goods receipts, put-away, moves and status changes, supplier returns. */

return [
    'RCV-1 a purchase order is received in two parts; costs become FIFO layers; the order closes itself' => static function (TestKit $t) {
        $t->as('bella');
        $po = Purchasing::saveDraft(['supplier_id' => $t->c['supplier']['ZAMMILL'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 100, 'unit_cost' => 80]]]);
        $t->as('steve');
        $t->throws(static fn () => Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'lines' => [['po_line_id' => 1, 'qty_accepted' => 10]]]), 'is draft');
        $t->as('bella');
        Purchasing::issue($po);
        $line = (int) Db::value('SELECT id FROM {po_lines} WHERE po_id = ?', [$po]);
        $t->as('steve');
        Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'lines' => [['po_line_id' => $line, 'qty_accepted' => 60]]]);
        $t->eq('partly_received', Purchasing::get($po)['status']);
        $t->throws(static fn () => Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'lines' => [['po_line_id' => $line, 'qty_accepted' => 41]]]), 'still due');
        Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'lines' => [['po_line_id' => $line, 'qty_accepted' => 40, 'unit_cost' => 90]]]);
        $t->eq('received', Purchasing::get($po)['status']);
        $t->eq(100.0, $t->qty('LSK', 'RICE5', 'available', 'RECEIVING'));
        $t->eq(60 * 80 + 40 * 90.0, $t->value('LSK', 'RICE5'), 'two cost layers');
    },

    'RCV-2 batch items need a batch and an expiry; the same batch cannot have two expiry dates' => static function (TestKit $t) {
        $t->throws(static fn () => $t->receive('LSK', [['MILK1', 24, 12]]), 'batch number');
        $t->throws(static fn () => $t->receive('LSK', [['MILK1', 24, 12, ['lot_no' => 'M1']]]), 'expiry date');
        $t->receive('LSK', [['MILK1', 24, 12, ['lot_no' => 'm1', 'expiry_date' => '2027-01-31']]]);
        $t->throws(static fn () => $t->receive('LSK', [['MILK1', 12, 12, ['lot_no' => 'M1', 'expiry_date' => '2027-02-28']]]), 'already recorded with expiry');
        $t->receive('LSK', [['MILK1', 12, 13, ['lot_no' => 'M1', 'expiry_date' => '2027-01-31']]]);
        $t->eq(36.0, $t->qty('LSK', 'MILK1'));
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {lots} WHERE lot_no = 'M1'"), 'batch numbers are stored in capitals, one row');
    },

    'RCV-3 serial items: one number per unit, unique while in stock; held units go to quarantine' => static function (TestKit $t) {
        $t->throws(static fn () => $t->receive('LSK', [['PHONE', 2, 1500, ['serials' => 'S1']]]), 'one serial number per unit');
        $t->throws(static fn () => $t->receive('LSK', [['PHONE', 2, 1500, ['serials' => "S1\nS1"]]]), 'listed twice');
        $t->receive('LSK', [['PHONE', 2, 1500, ['serials' => "S1\nS2", 'qty_quarantine' => 1, 'serials_quarantine' => 'S3']]]);
        $t->eq(2.0, $t->qty('LSK', 'PHONE', 'available'));
        $t->eq(1.0, $t->qty('LSK', 'PHONE', 'quarantine', 'QUARANTINE'));
        $t->throws(static fn () => $t->receive('KTW', [['PHONE', 1, 1500, ['serials' => 'S2']]]), 'already in stock');
        $t->true((bool) Db::value("SELECT 1 FROM {notifications} n JOIN {users} u ON u.id = n.user_id WHERE u.username = 'wendy' AND n.type = 'inspection'"), 'the warehouse manager is told about held goods');
    },

    'RCV-4 rejected units never enter stock or cost, and need a reason' => static function (TestKit $t) {
        $t->as('steve');
        $line = ['item_id' => $t->item('RICE5'), 'qty_accepted' => 8, 'qty_rejected' => 2, 'unit_cost' => 80];
        $t->throws(static fn () => Receipts::post(['warehouse_id' => $t->wh('LSK'), 'supplier_id' => $t->c['supplier']['ZAMMILL'], 'lines' => [$line]]), 'say why');
        $rid = Receipts::post(['warehouse_id' => $t->wh('LSK'), 'supplier_id' => $t->c['supplier']['ZAMMILL'], 'lines' => [$line + ['reject_reason' => 'Torn bags']]]);
        $t->eq(8.0, $t->qty('LSK', 'RICE5'));
        $t->eq(640.0, (float) Receipts::get($rid)['value']);
    },

    'RCV-5 permissions are per warehouse; a double submit posts once' => static function (TestKit $t) {
        $t->as('kim');
        $t->throws(static fn () => Receipts::post(['warehouse_id' => $t->wh('LSK'), 'supplier_id' => $t->c['supplier']['ZAMMILL'], 'lines' => [['item_id' => $t->item('RICE5'), 'qty_accepted' => 1, 'unit_cost' => 1]]]), 'outside your area', DeniedException::class);
        $t->as('steve');
        $d = ['warehouse_id' => $t->wh('LSK'), 'supplier_id' => $t->c['supplier']['ZAMMILL'], 'lines' => [['item_id' => $t->item('RICE5'), 'qty_accepted' => 5, 'unit_cost' => 80]]];
        $a = Receipts::post($d, 'tok-12345678');
        $b = Receipts::post($d, 'tok-12345678');
        $t->eq($a, $b, 'same receipt');
        $t->eq(5.0, $t->qty('LSK', 'RICE5'));
    },

    'MOV-1 put-away, bin moves and status changes; release from quarantine needs approval rights' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 20, 80, ['bin' => 'RECEIVING']], ['PHONE', 0, 1500, ['qty_quarantine' => 1, 'serials_quarantine' => 'Q1']]]);
        $t->as('steve');
        Moves::putaway($t->row('LSK', 'RICE5', 'RECEIVING'), $t->bin('LSK', 'A-01-01-1'), 15);
        $t->eq(15.0, $t->qty('LSK', 'RICE5', null, 'A-01-01-1'));
        $t->eq(5.0, $t->qty('LSK', 'RICE5', null, 'RECEIVING'));
        $q = $t->row('LSK', 'PHONE', 'QUARANTINE', null, 'Q1', 'quarantine');
        $t->throws(static fn () => Moves::post(['warehouse_id' => $t->wh('LSK'), 'lines' => [['stock_id' => $q, 'qty' => 1, 'to_bin_id' => $t->bin('LSK', 'A-01-01-2'), 'to_status' => 'available']]]), 'quality decision');
        $t->as('wendy');
        Moves::post(['warehouse_id' => $t->wh('LSK'), 'lines' => [['stock_id' => $q, 'qty' => 1, 'to_bin_id' => $t->bin('LSK', 'A-01-01-2'), 'to_status' => 'available']]]);
        $t->eq(1.0, $t->qty('LSK', 'PHONE', 'available', 'A-01-01-2'));
        $t->as('steve');
        Moves::post(['warehouse_id' => $t->wh('LSK'), 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'A-01-01-1'), 'qty' => 2, 'to_bin_id' => $t->bin('LSK', 'DAMAGED'), 'to_status' => 'damaged']]]);
        $t->eq(2.0, $t->qty('LSK', 'RICE5', 'damaged'));
        $t->eq(20 * 80.0, $t->value('LSK', 'RICE5'), 'moves do not change value');
    },

    'MOV-2 blocked bins take nothing in; a bin with stock cannot be switched off' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80, ['bin' => 'RECEIVING']]]);
        $t->as('wendy');
        \App\Libraries\Master\Warehouses::setBinStatus($t->bin('LSK', 'A-02-01-1'), 'blocked', 'Racking damaged');
        $t->as('steve');
        $t->throws(static fn () => Moves::putaway($t->row('LSK', 'RICE5', 'RECEIVING'), $t->bin('LSK', 'A-02-01-1')), 'blocked');
        Moves::putaway($t->row('LSK', 'RICE5', 'RECEIVING'), $t->bin('LSK', 'A-02-01-2'));
        $t->as('wendy');
        $t->throws(static fn () => \App\Libraries\Master\Warehouses::setBinStatus($t->bin('LSK', 'A-02-01-2'), 'inactive'), 'still holds stock');
    },

    'RTS-1 a return to the supplier leaves at FIFO cost; its serial is marked returned and can come back later' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $t->receive('LSK', [['RICE5', 10, 100]]);
        $t->receive('LSK', [['PHONE', 1, 1500, ['serials' => 'R1']]]);
        $t->as('steve');
        $id = SupplierReturns::post(['warehouse_id' => $t->wh('LSK'), 'supplier_id' => $t->c['supplier']['ZAMMILL'], 'reason_code' => 'DAMAGED', 'lines' => [
            ['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => 12],
            ['stock_id' => $t->row('LSK', 'PHONE', 'STOCK'), 'qty' => 1],
        ]]);
        $t->eq(10 * 80 + 2 * 100 + 1500.0, (float) Db::value('SELECT value FROM {supplier_returns} WHERE id = ?', [$id]), 'oldest cost first');
        $t->eq('returned_supplier', Db::value("SELECT status FROM {serials} WHERE serial_no = 'R1'"));
        $t->receive('LSK', [['PHONE', 1, 1450, ['serials' => 'R1']]]);
        $t->eq('in_stock', Db::value("SELECT status FROM {serials} WHERE serial_no = 'R1'"));
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {serials} WHERE serial_no = 'R1'"), 'the same serial row comes back');
    },
];
