<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Adjustments;
use App\Libraries\Docs\Counts;
use App\Libraries\Docs\Sales;
use App\Libraries\Docs\Shipments;
use App\Libraries\Docs\Transfers;
use App\Libraries\Fleet\Fleet;
use App\Libraries\Core\Settings;

/** Fleet and trips; adjustments and counts with two-person approval. */

$fleet = static function (TestKit $t, array $v = [], array $d = []): array {
    $t->as('tom');
    $vid = Fleet::saveVehicle($v + ['reg_no' => 'BAB 1234', 'make' => 'Isuzu', 'body' => 'truck', 'capacity_kg' => 3000, 'odometer_km' => 10000,
        'insurance_expiry' => date('Y-m-d', strtotime('+100 days')), 'fitness_expiry' => date('Y-m-d', strtotime('+100 days'))]);
    $did = Fleet::saveDriver($d + ['name' => 'Joseph Mwale', 'licence_no' => 'DL123', 'licence_expiry' => date('Y-m-d', strtotime('+300 days'))]);
    return [$vid, $did];
};

return [
    'FLT-1 a trip carries a shipment and a transfer; departure dispatches both; completion needs every delivery recorded' => static function (TestKit $t) use ($fleet) {
        Db::exec("UPDATE {items} SET weight_kg = 5 WHERE sku = 'RICE5'");
        \App\Libraries\Master\Items::flush();
        $t->receive('LSK', [['RICE5', 100, 80]]);
        $t->as('sally');
        $so = Sales::saveDraft(['customer_id' => $t->c['customer']['SHOPRITE'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 20]]]);
        Sales::confirm($so);
        $t->as('steve');
        $sh = Shipments::create($so);
        Shipments::pick($sh);
        $t->as('wendy');
        $tr = Transfers::create(['from_warehouse_id' => $t->wh('LSK'), 'to_warehouse_id' => $t->wh('KTW'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 30]]]);
        Transfers::allocate($tr);
        [$vid, $did] = $fleet($t);
        $t->as('tom');
        $trip = Fleet::planTrip(['warehouse_id' => $t->wh('LSK'), 'vehicle_id' => $vid, 'driver_id' => $did, 'shipments' => [$sh], 'transfers' => [$tr]]);
        $t->eq(250.0, Fleet::load($trip), '50 bags × 5 kg');
        $t->throws(static fn () => Fleet::planTrip(['warehouse_id' => $t->wh('LSK'), 'vehicle_id' => $vid, 'driver_id' => $did, 'shipments' => [$sh]]), 'already on trip');
        $t->as('steve');
        $t->throws(static fn () => Fleet::depart($trip, 10100), 'not packed');
        Shipments::pack($sh, 2);
        $t->throws(static fn () => Fleet::depart($trip, 9000), 'lower than the last');
        Fleet::depart($trip, 10100);
        $t->eq('dispatched', Shipments::get($sh)['status']);
        $t->eq('in_transit', Transfers::get($tr)['status']);
        $t->eq('on_trip', Db::value('SELECT status FROM {vehicles} WHERE id = ?', [$vid]));
        $t->eq(50.0, 100 - $t->qty('LSK', 'RICE5'));
        $t->as('tom');
        $t->throws(static fn () => Fleet::complete($trip, 10350), 'Record the delivery');
        $t->as('steve');
        Shipments::deliver($sh, 'Shop owner');
        $t->as('tom');
        Fleet::complete($trip, 10350, ['litres' => 40, 'cost' => 1200]);
        $t->eq(['completed', 'available', 10350], [Db::value('SELECT status FROM {trips} WHERE id = ?', [$trip]), Db::value('SELECT status FROM {vehicles} WHERE id = ?', [$vid]), (int) Db::value('SELECT odometer_km FROM {vehicles} WHERE id = ?', [$vid])]);
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {vehicle_logs} WHERE kind = 'fuel' AND trip_id = ?", [$trip]));
    },

    'FLT-2 expired papers or licence stop a trip; overloading is refused; warnings list what runs out soon' => static function (TestKit $t) use ($fleet) {
        [$vid, $did] = $fleet($t, ['insurance_expiry' => date('Y-m-d', strtotime('-1 day')), 'capacity_kg' => 100], ['licence_expiry' => date('Y-m-d', strtotime('+10 days'))]);
        $t->throws(static fn () => Fleet::planTrip(['warehouse_id' => $t->wh('LSK'), 'vehicle_id' => $vid, 'driver_id' => $did]), 'insurance ran out');
        Fleet::saveVehicle(['reg_no' => 'BAB 1234', 'capacity_kg' => 100, 'insurance_expiry' => date('Y-m-d', strtotime('+365 days'))], $vid);
        Db::exec("UPDATE {items} SET weight_kg = 5 WHERE sku = 'RICE5'");
        \App\Libraries\Master\Items::flush();
        $t->receive('LSK', [['RICE5', 30, 80]]);
        $t->as('wendy');
        $tr = Transfers::create(['from_warehouse_id' => $t->wh('LSK'), 'to_warehouse_id' => $t->wh('KTW'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 30]]]);
        $t->as('tom');
        $t->throws(static fn () => Fleet::planTrip(['warehouse_id' => $t->wh('LSK'), 'vehicle_id' => $vid, 'driver_id' => $did, 'transfers' => [$tr]]), 'more than the vehicle carries');
        $labels = array_column(Fleet::expiring(), 'label');
        $t->true(in_array('Driving licence', $labels, true), 'licence expiring in 10 days is listed');
    },

    'ADJ-1 an adjustment waits for a second person; the units are held meanwhile; the requester cannot approve' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $t->as('steve');
        $t->throws(static fn () => Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'DAMAGE', 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => -3]]]), 'Explain');
        $id = Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'DAMAGE', 'notes' => 'Rats', 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => -3]]]);
        $t->eq('pending', Db::value('SELECT status FROM {adjustments} WHERE id = ?', [$id]));
        $t->eq(3.0, (float) Db::value('SELECT qty_allocated FROM {stock} WHERE id = ?', [$t->row('LSK', 'RICE5', 'STOCK')]), 'held while waiting');
        $t->throws(static fn () => Adjustments::approve($id), 'permission');
        $t->as('wendy');
        Adjustments::approve($id, 'Seen the bags');
        $t->eq(7.0, $t->qty('LSK', 'RICE5'));
        $t->eq(560.0, $t->value('LSK', 'RICE5'));
        $t->as('wendy');
        $own = Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'FOUND', 'notes' => 'Found behind racking', 'lines' => [['item_id' => $t->item('RICE5'), 'bin_id' => $t->bin('LSK', 'STOCK'), 'qty' => 2]]]);
        $t->throws(static fn () => Adjustments::approve($own), 'someone else');
        $t->as('anna');
        Adjustments::approve($own);
        $t->eq(9.0, $t->qty('LSK', 'RICE5'));
    },

    'ADJ-2 small adjustments by an approver post at once when an automatic limit is set; rejection releases the hold' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        Settings::set('adjust_auto_limit', '200');
        $t->as('wendy');
        $a = Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'SAMPLE', 'notes' => 'Trade show', 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => -2]]]);
        $t->eq('posted', Db::value('SELECT status FROM {adjustments} WHERE id = ?', [$a]));
        $t->as('steve');
        $b = Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'SAMPLE', 'notes' => 'More samples', 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => -1]]]);
        $t->eq('pending', Db::value('SELECT status FROM {adjustments} WHERE id = ?', [$b]), 'a storekeeper cannot approve, so it waits');
        $t->as('wendy');
        Adjustments::reject($b, 'No samples this month');
        $t->eq(0.0, (float) Db::value('SELECT qty_allocated FROM {stock} WHERE id = ?', [$t->row('LSK', 'RICE5', 'STOCK')]));
    },

    'CNT-1 a blind count finds a shortage, a surplus and a lost serial; approval by someone else posts the variance' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80], ['PHONE', 2, 1500, ['serials' => "C1\nC2"]]]);
        $t->as('wendy');
        $c = Counts::start(['warehouse_id' => $t->wh('LSK'), 'blind' => 1]);
        $lines = Db::rows('SELECT cl.id, i.sku, se.serial_no FROM {count_lines} cl JOIN {items} i ON i.id = cl.item_id LEFT JOIN {serials} se ON se.id = cl.serial_id WHERE cl.count_id = ?', [$c]);
        $t->eq(3, count($lines));
        $counts = [];
        foreach ($lines as $l) {
            $counts[$l['id']] = $l['sku'] === 'RICE5' ? 8 : ($l['serial_no'] === 'C2' ? 0 : 1);
        }
        $t->as('steve');
        Counts::enter($c, $counts, [['bin_id' => $t->bin('LSK', 'A-01-01-1'), 'item_id' => $t->item('RICE5'), 'qty' => 1]]);
        $t->as('wendy');
        $t->throws(static fn () => Counts::approve($c), 'Only a submitted');
        Counts::submit($c);
        $t->throws(static fn () => Counts::approve($c), 'someone else');
        $t->as('anna');
        Counts::approve($c);
        $t->eq(9.0, $t->qty('LSK', 'RICE5'));
        $t->eq(1.0, $t->qty('LSK', 'RICE5', null, 'A-01-01-1'));
        $t->eq('lost', Db::value("SELECT status FROM {serials} WHERE serial_no = 'C2'"));
        $t->eq('posted', Counts::get($c)['status']);
    },

    'CNT-2 every line must be counted; a shortage on reserved stock is refused until the reservation is released' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $t->as('sally');
        $so = Sales::saveDraft(['customer_id' => $t->c['customer']['SHOPRITE'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 6]]]);
        Sales::confirm($so);
        $t->as('steve');
        Shipments::create($so);
        $t->as('wendy');
        $c = Counts::start(['warehouse_id' => $t->wh('LSK'), 'item_id' => $t->item('RICE5')]);
        $t->throws(static fn () => Counts::submit($c), 'not been counted');
        $line = (int) Db::value('SELECT id FROM {count_lines} WHERE count_id = ?', [$c]);
        $t->as('steve');
        Counts::enter($c, [$line => 3]);
        $t->as('wendy');
        Counts::submit($c);
        $t->as('anna');
        $t->throws(static fn () => Counts::approve($c), 'reserved');
        $t->eq('submitted', Counts::get($c)['status'], 'nothing posted');
    },
];
