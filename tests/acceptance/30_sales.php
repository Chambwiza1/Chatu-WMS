<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Sales;
use App\Libraries\Docs\Shipments;

/** Sales orders through allocation, picking, packing, dispatch, proof of delivery, failed deliveries and returns. */

$order = static function (TestKit $t, array $lines, string $cust = 'SHOPRITE'): int {
    $t->as('sally');
    $id = Sales::saveDraft(['customer_id' => $t->c['customer'][$cust], 'warehouse_id' => $t->wh('LSK'), 'lines' => array_map(static fn ($l) => ['item_id' => $t->item($l[0]), 'qty' => $l[1]], $lines)]);
    Sales::confirm($id);
    return $id;
};
$ship = static function (TestKit $t, int $so, bool $partial = false): int {
    $t->as('steve');
    $id = Shipments::create($so, [], $partial);
    Shipments::pick($id);
    Shipments::pack($id, 2);
    Shipments::dispatch($id);
    return $id;
};

return [
    'SO-1 order to delivery: reserve, pick into DISPATCH, pack, dispatch at FIFO cost, proof of delivery' => static function (TestKit $t) use ($order) {
        $t->receive('LSK', [['RICE5', 10, 80], ['PHONE', 1, 1500, ['serials' => 'P1']]]);
        $so = $order($t, [['RICE5', 6], ['PHONE', 1]]);
        $t->eq(120.0, (float) Db::value('SELECT unit_price FROM {so_lines} WHERE so_id = ? AND item_id = ?', [$so, $t->item('RICE5')]), 'price from the item');
        $t->true((bool) Db::value("SELECT 1 FROM {notifications} n JOIN {users} u ON u.id = n.user_id WHERE u.username = 'steve' AND n.type = 'order_confirmed'"), 'the store is told');
        $t->as('steve');
        $sh = Shipments::create($so);
        $t->eq('in_progress', Sales::get($so)['status']);
        $t->throws(static fn () => Shipments::dispatch($sh), 'Pack');
        Shipments::pick($sh);
        $t->eq(6.0, $t->qty('LSK', 'RICE5', null, 'DISPATCH'));
        Shipments::pack($sh, 1);
        Shipments::dispatch($sh);
        $t->throws(static fn () => Shipments::dispatch($sh), 'already left');
        $t->eq(4.0, $t->qty('LSK', 'RICE5'));
        $t->eq(6 * 80 + 1500.0, (float) Shipments::get($sh)['value']);
        $t->eq('dispatched', Sales::get($so)['status']);
        $t->eq('dispatched', Db::value("SELECT status FROM {serials} WHERE serial_no = 'P1'"));
        Shipments::deliver($sh, 'Mrs Banda', 'All in good order');
        $t->eq('delivered', Sales::get($so)['status']);
    },

    'SO-2 partial shipment leaves the rest on the order; a second shipment completes it' => static function (TestKit $t) use ($order, $ship) {
        $t->receive('LSK', [['RICE5', 4, 80]]);
        $so = $order($t, [['RICE5', 10]]);
        $t->as('steve');
        $t->throws(static fn () => Shipments::create($so), 'Not enough free');
        $a = $ship($t, $so, true);
        $t->eq(4.0, (float) Db::value('SELECT qty_shipped FROM {so_lines} WHERE so_id = ?', [$so]));
        $t->eq('in_progress', Sales::get($so)['status']);
        $t->receive('LSK', [['RICE5', 6, 90]]);
        $ship($t, $so);
        $t->eq('dispatched', Sales::get($so)['status']);
    },

    'SO-3 short shelf life is not shipped to a customer who needs more days left' => static function (TestKit $t) use ($order) {
        $t->receive('LSK', [['MILK1', 10, 12, ['lot_no' => 'S5', 'expiry_date' => date('Y-m-d', strtotime('+5 days'))]]]);
        $so = $order($t, [['MILK1', 5]], 'NDOLA');
        $t->as('steve');
        $t->throws(static fn () => Shipments::create($so), 'Not enough free');
        $so2 = $order($t, [['MILK1', 5]], 'SHOPRITE');
        $t->as('steve');
        $t->throws(static fn () => Shipments::create($so2), 'Not enough free'); // the item itself needs 30 days
        Db::exec("UPDATE {items} SET min_shelf_days = 0 WHERE sku = 'MILK1'");
        \App\Libraries\Master\Items::flush();
        $t->as('steve');
        Shipments::create($so2);
    },

    'SO-4 short pick releases the rest; cancelling a picked shipment leaves the stock free in DISPATCH' => static function (TestKit $t) use ($order) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $so = $order($t, [['RICE5', 8]]);
        $t->as('steve');
        $sh = Shipments::create($so);
        $a  = Shipments::pickList($sh)[0];
        Shipments::pick($sh, [$a['id'] => 5]);
        $t->eq(5.0, (float) Db::value('SELECT qty FROM {shipment_lines} WHERE shipment_id = ?', [$sh]));
        $t->eq(5.0, $t->qty('LSK', 'RICE5', null, 'DISPATCH'));
        Shipments::cancel($sh, 'Customer will collect later');
        $t->eq(0.0, (float) Db::value('SELECT COALESCE(SUM(qty_allocated), 0) FROM {stock}'));
        $t->eq('confirmed', Sales::get($so)['status']);
    },

    'SO-5 failed delivery brings everything back at the same cost; the order can go out again' => static function (TestKit $t) use ($order, $ship) {
        $t->receive('LSK', [['RICE5', 10, 80], ['PHONE', 1, 1500, ['serials' => 'F1']]]);
        $so = $order($t, [['RICE5', 5], ['PHONE', 1]]);
        $sh = $ship($t, $so);
        $t->throws(static fn () => Shipments::fail($sh, 'NOPE'), 'Choose why');
        Shipments::fail($sh, 'CLOSED', 'Shop closed for stock-take');
        $t->eq(10.0, $t->qty('LSK', 'RICE5'));
        $t->eq(800.0, $t->value('LSK', 'RICE5'));
        $t->eq('in_stock', Db::value("SELECT status FROM {serials} WHERE serial_no = 'F1'"));
        $t->eq('confirmed', Sales::get($so)['status']);
        $ship($t, $so);
        $t->eq('dispatched', Sales::get($so)['status']);
    },

    'SO-6 a customer return goes to quarantine at the cost it left with; a cancelled order releases everything' => static function (TestKit $t) use ($order, $ship) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $so = $order($t, [['RICE5', 4]]);
        $sh = $ship($t, $so);
        Shipments::deliver($sh, 'Mr Phiri');
        $u = Shipments::units($sh)[0];
        $t->throws(static fn () => Shipments::customerReturn($sh, [$u['id'] => 5], 'DAMAGED'), 'More is being returned');
        Shipments::customerReturn($sh, [$u['id'] => 1], 'DAMAGED', 'Bag torn');
        $t->eq(1.0, $t->qty('LSK', 'RICE5', 'quarantine'));
        $t->eq(7 * 80.0, $t->value('LSK', 'RICE5'));
        $so2 = $order($t, [['RICE5', 3]]);
        $t->as('steve');
        Shipments::create($so2);
        $t->as('sally');
        $t->throws(static fn () => Sales::cancel($so2, ''), 'Say why');
        Sales::cancel($so2, 'Customer changed mind');
        $t->eq('cancelled', Sales::get($so2)['status']);
        $t->eq(0.0, (float) Db::value('SELECT COALESCE(SUM(qty_allocated), 0) FROM {stock}'));
    },
];
