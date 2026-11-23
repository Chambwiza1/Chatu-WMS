<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Docs\Transfers;

/** Transfers between warehouses: reservation, goods in transit, partial and final receipt. */

$tr = static function (TestKit $t, array $lines): int {
    $t->as('wendy');
    return Transfers::create(['from_warehouse_id' => $t->wh('LSK'), 'to_warehouse_id' => $t->wh('KTW'), 'lines' => array_map(static fn ($l) => ['item_id' => $t->item($l[0]), 'qty' => $l[1]], $lines)]);
};

return [
    'TRF-1 allocate, dispatch, receive: the value travels with the goods, nothing is in two places at once' => static function (TestKit $t) use ($tr) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $t->receive('LSK', [['RICE5', 10, 100]]);
        $id = $tr($t, [['RICE5', 15]]);
        $t->throws(static fn () => Transfers::dispatch($id), 'Allocate');
        Transfers::allocate($id);
        $t->eq(15.0, (float) Db::value('SELECT SUM(qty_allocated) FROM {stock} WHERE item_id = ?', [$t->item('RICE5')]), 'reserved at the source');
        Transfers::dispatch($id);
        $t->eq(5.0, $t->qty('LSK', 'RICE5'));
        $t->eq(0.0, $t->qty('KTW', 'RICE5'));
        $t->eq(500.0, $t->value('LSK', 'RICE5'), 'the newest layer stays');
        $t->eq(1300.0, (float) Db::value('SELECT value_sent FROM {transfer_lines} WHERE transfer_id = ?', [$id]), 'in transit at FIFO cost');
        $t->true((bool) Db::value("SELECT 1 FROM {notifications} n JOIN {users} u ON u.id = n.user_id WHERE u.username = 'kim' AND n.type = 'transfer_coming'"), 'the destination is told');
        $x = Transfers::transit($id)[0];
        $t->as('steve');
        $t->throws(static fn () => Transfers::receive($id, ['rows' => [['transit_id' => $x['id'], 'good' => 15]]]), 'outside your area', DeniedException::class);
        $t->as('kim');
        Transfers::receive($id, ['rows' => [['transit_id' => $x['id'], 'good' => 15, 'bin_id' => $t->bin('KTW', 'K-01-01-1')]]]);
        $t->eq('received', Transfers::get($id)['status']);
        $t->eq(15.0, $t->qty('KTW', 'RICE5', 'available', 'K-01-01-1'));
        $t->eq(1300.0, $t->value('KTW', 'RICE5'), 'same value at the destination');
    },

    'TRF-2 partial receipt, then a final receipt: damaged to DAMAGED, rejected to QUARANTINE, missing written off with a note' => static function (TestKit $t) use ($tr) {
        $t->receive('LSK', [['MILK1', 20, 12, ['lot_no' => 'B1', 'expiry_date' => date('Y-m-d', strtotime('+90 days'))]]]);
        $id = $tr($t, [['MILK1', 20]]);
        Transfers::allocate($id);
        Transfers::dispatch($id);
        $x = Transfers::transit($id)[0];
        $t->as('kim');
        Transfers::receive($id, ['rows' => [['transit_id' => $x['id'], 'good' => 10]]]);
        $t->eq('partly_received', Transfers::get($id)['status']);
        $t->throws(static fn () => Transfers::receive($id, ['rows' => [['transit_id' => $x['id'], 'good' => 11]]]), 'still on the way');
        $t->throws(static fn () => Transfers::receive($id, ['final' => 1, 'rows' => [['transit_id' => $x['id'], 'damaged' => 2, 'rejected' => 3]]]), 'say what happened');
        Transfers::receive($id, ['final' => 1, 'notes' => 'Two cartons fell off the truck', 'rows' => [['transit_id' => $x['id'], 'damaged' => 2, 'rejected' => 3]]]);
        $l = Transfers::lines($id)[0];
        $t->eq('received', Transfers::get($id)['status']);
        $t->eq([10.0, 2.0, 3.0, 5.0], [(float) $l['qty_received'], (float) $l['qty_damaged'], (float) $l['qty_rejected'], (float) $l['qty_missing']]);
        $t->eq(60.0, (float) $l['value_missing']);
        $t->eq(2.0, $t->qty('KTW', 'MILK1', 'damaged', 'DAMAGED'));
        $t->eq(3.0, $t->qty('KTW', 'MILK1', 'quarantine', 'QUARANTINE'));
        $t->eq('B1', Db::value('SELECT l.lot_no FROM {stock} s JOIN {lots} l ON l.id = s.lot_id WHERE s.warehouse_id = ? LIMIT 1', [$t->wh('KTW')]), 'the batch travels');
        $t->eq(15 * 12.0, $t->value('KTW', 'MILK1'));
    },

    'TRF-3 serials are in transit while on the road and lost if they never arrive' => static function (TestKit $t) use ($tr) {
        $t->receive('LSK', [['PHONE', 2, 1500, ['serials' => "T1\nT2"]]]);
        $id = $tr($t, [['PHONE', 2]]);
        Transfers::allocate($id);
        Transfers::dispatch($id);
        $t->eq(['in_transit', 'in_transit'], array_column(Db::rows("SELECT status FROM {serials} WHERE serial_no IN ('T1','T2') ORDER BY serial_no"), 'status'));
        $t->throws(static fn () => $t->receive('LSK', [['PHONE', 1, 1500, ['serials' => 'T1']]]), 'already in transit');
        $rows = Transfers::transit($id);
        $t->as('kim');
        Transfers::receive($id, ['final' => 1, 'notes' => 'One handset not in the box', 'rows' => [['transit_id' => $rows[0]['id'], 'good' => 1]]]);
        $t->eq(['in_stock', 'lost'], array_column(Db::rows("SELECT status FROM {serials} WHERE serial_no IN ('T1','T2') ORDER BY serial_no"), 'status'));
    },

    'TRF-4 an allocation never takes expired, held or blocked stock; short stock refuses the whole transfer' => static function (TestKit $t) use ($tr) {
        $t->receive('LSK', [['MILK1', 5, 12, ['lot_no' => 'OLD', 'expiry_date' => date('Y-m-d', strtotime('-1 day'))]]]);
        $t->receive('LSK', [['MILK1', 5, 12, ['lot_no' => 'HELD', 'expiry_date' => date('Y-m-d', strtotime('+60 days'))]]]);
        $t->receive('LSK', [['MILK1', 5, 12, ['lot_no' => 'GOOD', 'expiry_date' => date('Y-m-d', strtotime('+90 days'))]]]);
        $t->as('wendy');
        \App\Libraries\Stock\Lots::setStatus((int) Db::value("SELECT id FROM {lots} WHERE lot_no = 'HELD'"), 'on_hold', 'Supplier query');
        $id = $tr($t, [['MILK1', 6]]);
        $t->throws(static fn () => Transfers::allocate($id), '5 carton free');
        $t->eq(0.0, (float) Db::value('SELECT COALESCE(SUM(qty_allocated), 0) FROM {stock}'), 'nothing reserved');
        $id2 = $tr($t, [['MILK1', 5]]);
        Transfers::allocate($id2);
        $t->eq('GOOD', Db::value("SELECT l.lot_no FROM {allocations} a JOIN {stock} s ON s.id = a.stock_id JOIN {lots} l ON l.id = s.lot_id WHERE a.doc_type = 'transfer' AND a.doc_id = ?", [$id2]));
        Transfers::cancel($id2, 'Not needed');
        $t->eq(0.0, (float) Db::value('SELECT COALESCE(SUM(qty_allocated), 0) FROM {stock}'), 'released');
    },

    'TRF-5 FEFO picks the batch that expires first, FIFO the oldest batch' => static function (TestKit $t) use ($tr) {
        $t->receive('LSK', [['MILK1', 5, 12, ['lot_no' => 'LATE', 'expiry_date' => date('Y-m-d', strtotime('+120 days'))]]]);
        $t->receive('LSK', [['MILK1', 5, 12, ['lot_no' => 'SOON', 'expiry_date' => date('Y-m-d', strtotime('+40 days'))]]]);
        $id = $tr($t, [['MILK1', 5]]);
        Transfers::allocate($id);
        $lot = static fn () => Db::value("SELECT l.lot_no FROM {allocations} a JOIN {stock} s ON s.id = a.stock_id JOIN {lots} l ON l.id = s.lot_id WHERE a.status = 'active'");
        $t->eq('SOON', $lot(), 'FEFO');
        Transfers::unallocate($id);
        Db::exec("UPDATE {warehouses} SET pick_policy = 'fifo' WHERE code = 'LSK'");
        Transfers::allocate($id);
        $t->eq('LATE', $lot(), 'FIFO');
    },
];
