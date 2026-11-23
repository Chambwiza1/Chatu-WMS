<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Docs\Purchasing;
use App\Libraries\Docs\Receipts;
use App\Libraries\Docs\Sales;
use App\Libraries\Docs\Shipments;
use App\Libraries\Docs\Waves;
use App\Libraries\Fleet\AssetsImport;
use App\Libraries\Fleet\Fleet;
use App\Libraries\Labels\Code128;
use App\Libraries\Master\Items;

/** Release 0.2: packs (units of measure), foreign-currency purchase costs, wave picking, barcodes, vehicles from Chatu Assets. */

$packs = static function (TestKit $t, string $sku, array $units): void {
    $t->as('bella');
    $id = $t->item($sku);
    Items::save(Items::get($id) + ['units' => $units], $id);
};
$order = static function (TestKit $t, array $lines, string $cust = 'SHOPRITE'): int {
    $t->as('sally');
    $id = Sales::saveDraft(['customer_id' => $t->c['customer'][$cust], 'warehouse_id' => $t->wh('LSK'), 'lines' => array_map(static fn ($l) => ['item_id' => $t->item($l[0]), 'qty' => $l[1]], $lines)]);
    Sales::confirm($id);
    return $id;
};

return [
    'UOM-1 packs: validation, pack barcodes are found, a PO in cartons is stored and received in base units at the base cost' => static function (TestKit $t) use ($packs) {
        $t->throws(static fn () => $packs($t, 'RICE5', [['name' => 'carton', 'factor' => 1]]), 'more than 1');
        $t->throws(static fn () => $packs($t, 'RICE5', [['name' => 'bag', 'factor' => 5]]), 'already the base unit');
        $t->throws(static fn () => $packs($t, 'RICE5', [['name' => 'carton', 'factor' => 2.5]]), 'whole number');
        $t->throws(static fn () => $packs($t, 'RICE5', [['name' => 'carton', 'factor' => 10], ['name' => 'Carton', 'factor' => 12]]), 'listed twice');
        $t->throws(static fn () => $packs($t, 'RICE5', [['name' => 'carton!', 'factor' => 10]]), 'Pack names');
        $packs($t, 'RICE5', [['name' => 'Carton', 'factor' => 10, 'barcode' => '6001234567890', 'buy_default' => 1], ['name' => 'pallet', 'factor' => 400]]);
        $t->throws(static fn () => $packs($t, 'MILK1', [['name' => 'crate', 'factor' => 12, 'barcode' => '6001234567890']]), 'already used');
        $t->eq(2, count(Items::units($t->item('RICE5'))));

        [$item, $pack] = Items::byCode('6001234567890');
        $t->eq('RICE5', $item['sku'], 'a pack barcode finds the item');
        $t->eq('carton', $pack);
        [$item, $pack] = Items::byCode('rice5');
        $t->eq('RICE5', $item['sku'], 'codes are found in any case');
        $t->eq(null, $pack);
        $t->throws(static fn () => Items::convertLine(['item_id' => $t->item('RICE5'), 'unit' => 'crate', 'qty' => 1]), 'no pack called');

        $line = Items::convertLine(['item_id' => $t->item('RICE5'), 'unit' => 'carton', 'qty' => 3, 'unit_cost' => '800']);
        $t->eq(30.0, (float) $line['qty'], '3 cartons = 30 bags');
        $t->eq(80.0, (float) $line['unit_cost'], 'K800 a carton = K80 a bag');
        $t->eq('carton', $line['pack_unit']);
        $t->as('bella');
        $po = Purchasing::saveDraft(['supplier_id' => $t->c['supplier']['ZAMMILL'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [$line]]);
        Purchasing::issue($po);
        $pl = Db::row('SELECT * FROM {po_lines} WHERE po_id = ?', [$po]);
        $t->eq(30.0, (float) $pl['qty_ordered']);
        $t->eq('carton', $pl['pack_unit']);
        $t->eq(10.0, (float) $pl['pack_factor']);
        $t->as('steve');
        $rid = Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'lines' => [['po_line_id' => (int) $pl['id'], 'qty_accepted' => 30]]]);
        $t->eq('carton', Db::value('SELECT pack_unit FROM {receipt_lines} WHERE receipt_id = ?', [$rid]), 'the receipt keeps the ordered pack for display');
        $t->eq(30.0, $t->qty('LSK', 'RICE5'));
        $t->eq(2400.0, $t->value('LSK', 'RICE5'));
    },

    'UOM-2 a sales order in packs reserves base units at the price per base unit; the pack shows on the line' => static function (TestKit $t) use ($packs) {
        $packs($t, 'RICE5', [['name' => 'carton', 'factor' => 10, 'sell_default' => 1]]);
        $t->receive('LSK', [['RICE5', 50, 80]]);
        $t->as('sally');
        $line = Items::convertLine(['item_id' => $t->item('RICE5'), 'unit' => 'carton', 'qty' => 2, 'unit_price' => 1150]);
        $so = Sales::saveDraft(['customer_id' => $t->c['customer']['SHOPRITE'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [$line]]);
        Sales::confirm($so);
        $l = Db::row('SELECT * FROM {so_lines} WHERE so_id = ?', [$so]);
        $t->eq(20.0, (float) $l['qty']);
        $t->eq(115.0, (float) $l['unit_price']);
        $t->eq('carton', $l['pack_unit']);
        $t->as('steve');
        $sh = Shipments::create($so);
        Shipments::pick($sh);
        $t->eq(20.0, $t->qty('LSK', 'RICE5', null, 'DISPATCH'));
        $t->eq(10.0, (float) Db::value('SELECT pack_factor FROM {so_lines} WHERE so_id = ?', [$so]));
    },

    'FX-1 a PO in US dollars with landed cost: stock is valued at cost × rate × (1 + landed %), the actual rate can be given on the day' => static function (TestKit $t) {
        $t->as('bella');
        $base = ['supplier_id' => $t->c['supplier']['TECH'], 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 10, 'unit_cost' => 3]]];
        $t->throws(static fn () => Purchasing::saveDraft($base + ['currency' => 'US']), 'three letters');
        $t->throws(static fn () => Purchasing::saveDraft($base + ['currency' => 'USD', 'fx_rate' => 0]), 'Exchange rate');
        $t->throws(static fn () => Purchasing::saveDraft($base + ['currency' => 'USD', 'fx_rate' => 27.5, 'landed_pct' => 600]), 'Landed cost');
        $home = Purchasing::saveDraft($base + ['currency' => 'zmw', 'fx_rate' => 99]);
        $t->eq('', Purchasing::get($home)['currency'], 'the home currency is stored as no currency');
        $t->eq(1.0, (float) Purchasing::get($home)['fx_rate']);

        $po = Purchasing::saveDraft($base + ['currency' => 'usd', 'fx_rate' => '27.5', 'landed_pct' => 10]);
        Purchasing::issue($po);
        $t->eq('USD', Purchasing::get($po)['currency']);
        $pl = (int) Db::value('SELECT id FROM {po_lines} WHERE po_id = ?', [$po]);
        $t->as('steve');
        $r1 = Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'lines' => [['po_line_id' => $pl, 'qty_accepted' => 5]]]);
        $l1 = Db::row('SELECT * FROM {receipt_lines} WHERE receipt_id = ?', [$r1]);
        $t->eq(90.75, (float) $l1['unit_cost'], '3 × 27.5 × 1.10');
        $t->eq(3.0, (float) $l1['unit_cost_fc']);
        $t->eq('USD', Db::value('SELECT currency FROM {receipts} WHERE id = ?', [$r1]));
        $r2 = Receipts::post(['warehouse_id' => $t->wh('LSK'), 'po_id' => $po, 'fx_rate' => 28, 'lines' => [['po_line_id' => $pl, 'qty_accepted' => 5]]]);
        $t->eq(92.4, (float) Db::value('SELECT unit_cost FROM {receipt_lines} WHERE receipt_id = ?', [$r2]), 'the rate on the day');
        $t->eq(28.0, (float) Db::value('SELECT fx_rate FROM {receipts} WHERE id = ?', [$r2]));
        $t->eq(round(5 * 90.75 + 5 * 92.4, 2), round($t->value('LSK', 'RICE5'), 2));
        $t->eq(92.4, (float) Db::value('SELECT last_cost FROM {items} WHERE id = ?', [$t->item('RICE5')]), 'last cost is in kwacha');

        // a receipt without a PO may carry its own currency; opening stock never does
        $r3 = Receipts::post(['warehouse_id' => $t->wh('LSK'), 'supplier_id' => $t->c['supplier']['TECH'], 'currency' => 'ZAR', 'fx_rate' => 1.5, 'landed_pct' => 0,
            'lines' => [['item_id' => $t->item('SUGAR'), 'qty_accepted' => 10, 'unit_cost' => 12, 'bin_id' => $t->bin('LSK', 'STOCK')]]]);
        $t->eq(18.0, (float) Db::value('SELECT unit_cost FROM {receipt_lines} WHERE receipt_id = ?', [$r3]));
    },

    'WAVE-1 two shipments picked in one walk; a short pick shortens only its shipment; rules on what can be waved' => static function (TestKit $t) use ($order) {
        $t->receive('LSK', [['RICE5', 30, 80], ['SUGAR', 20, 18]]);
        $soA = $order($t, [['RICE5', 10], ['SUGAR', 5]]);
        $soB = $order($t, [['RICE5', 8]], 'NDOLA');
        $soC = $order($t, [['SUGAR', 4]]);
        $t->as('steve');
        $a = Shipments::create($soA);
        $b = Shipments::create($soB);
        $c = Shipments::create($soC);
        $wh = $t->wh('LSK');
        $t->throws(static fn () => Waves::create($wh, [$a]), 'at least two');
        $t->throws(static fn () => Waves::create($t->wh('KTW'), [$a, $b]), 'outside your area', DeniedException::class);
        $t->as('kalo');
        $t->throws(static fn () => Waves::create($t->wh('KTW'), [$a, $b]), 'another warehouse');
        $t->as('steve');
        $w = Waves::create($wh, [$a, $b]);
        $t->throws(static fn () => Waves::create($wh, [$b, $c]), 'already in an open wave');
        $t->eq(1, count(Waves::candidates($wh)), 'only C is free');

        $picks = Waves::pickList($w);
        $t->eq(3, count($picks));
        $totals = Waves::totals($picks);
        $rice = array_values(array_filter($totals, static fn ($x) => $x['sku'] === 'RICE5'))[0];
        $t->eq(18.0, $rice['qty'], 'one take of 18 bags for both shipments');
        $t->eq(2, $rice['shipments']);

        $bAlloc = array_values(array_filter($picks, static fn ($p) => (int) $p['shipment_id'] === $b))[0];
        $t->as('sally');
        $t->throws(static fn () => Waves::confirm($w), 'permission', DeniedException::class);
        $t->as('steve');
        Waves::confirm($w, [(int) $bAlloc['id'] => 6]);
        $t->eq('picked', Waves::get($w)['status']);
        $t->eq('picked', Shipments::get($a)['status']);
        $t->eq('picked', Shipments::get($b)['status']);
        $t->eq(16.0, $t->qty('LSK', 'RICE5', null, 'DISPATCH'), '10 for A and 6 found for B');
        $t->eq(6.0, (float) Db::value('SELECT qty_picked FROM {shipment_lines} WHERE shipment_id = ?', [$b]));
        $t->throws(static fn () => Waves::confirm($w), 'already picked');
        $t->eq('allocated', Shipments::get($c)['status'], 'C was not in the wave');
    },

    'WAVE-2 a shipment with nothing found leaves the wave still allocated; a cancelled wave frees its shipments' => static function (TestKit $t) use ($order) {
        $t->receive('LSK', [['RICE5', 30, 80]]);
        $s = [];
        foreach ([5, 6, 7, 3] as $n) {
            $so = $order($t, [['RICE5', $n]]);
            $t->as('steve');
            $s[] = Shipments::create($so);
        }
        $wh = $t->wh('LSK');
        $w = Waves::create($wh, [$s[0], $s[1]], 'Truck 1');
        $none = array_values(array_filter(Waves::pickList($w), static fn ($p) => (int) $p['shipment_id'] === $s[1]));
        Waves::confirm($w, array_fill_keys(array_map(static fn ($p) => (int) $p['id'], $none), 0));
        $t->eq('picked', Shipments::get($s[0])['status']);
        $t->eq('allocated', Shipments::get($s[1])['status']);
        $t->eq(null, Shipments::get($s[1])['wave_id'], 'it can go into the next wave');
        $t->eq(5.0, $t->qty('LSK', 'RICE5', null, 'DISPATCH'));

        $w2 = Waves::create($wh, [$s[1], $s[2], $s[3]]);
        Waves::cancel($w2);
        $t->eq('cancelled', Waves::get($w2)['status']);
        $t->eq(0, (int) Db::value('SELECT COUNT(*) FROM {shipments} WHERE wave_id = ?', [$w2]));
        $t->throws(static fn () => Waves::confirm($w2), 'already cancelled');
        $w3 = Waves::create($wh, [$s[1], $s[2]]);
        Waves::confirm($w3);
        $t->eq(18.0, $t->qty('LSK', 'RICE5', null, 'DISPATCH'));
    },

    'LBL-1 Code 128: digits use the short code set, check digit, refuses what a label cannot carry' => static function (TestKit $t) {
        $c = Code128::encode('12345678');
        $t->eq(105, $c[0], 'all digits start in set C');
        $t->eq([105, 12, 34, 56, 78], array_slice($c, 0, 5), 'pairs of digits');
        $t->eq(7, count($c), 'start, four pairs, check digit, stop');
        $t->eq((105 + 12 * 1 + 34 * 2 + 56 * 3 + 78 * 4) % 103, $c[5], 'check digit');
        $t->eq(106, $c[6]);
        $b = Code128::encode('K-01-02-1');
        $t->eq(104, $b[0], 'letters start in set B');
        $t->eq(ord('K') - 32, $b[1]);
        $t->true(str_contains(Code128::svg('RICE5'), '<rect'), 'drawn as SVG bars');
        $t->throws(static fn () => Code128::encode(''), '1 to 80');
        $t->throws(static fn () => Code128::encode(str_repeat('A', 81)), '1 to 80');
        $t->throws(static fn () => Code128::encode('Café'), '1 to 80');
    },

    'FLT-3 vehicles from the Chatu Assets vehicle register: matched on registration, new ones added, retired ones skipped' => static function (TestKit $t) {
        $t->as('tom');
        $vid = Fleet::saveVehicle(['reg_no' => 'BAB 1234', 'make' => 'Isuzu', 'body' => 'truck', 'capacity_kg' => 3000]);
        $csv = "\xEF\xBB\xBF" . "Asset,Registration,Description,\"Make and model\",\"VIN (chassis)\",\"Engine number\",State,\"Insured until\"\n"
            . "FA-0001,bab1234,\"Isuzu truck 3 t\",\"Isuzu NPR\",JALNPR123,E1,\"In service\",2027-03-31\n"
            . "FA-0002,\"ALB 99\",\"Toyota Hilux pickup\",\"Toyota Hilux\",AHT999,E2,\"In service\",\"Not insured\"\n"
            . "FA-0003,\"ABC 1\",\"Old van\",Nissan,Missing,Missing,Retired,\"Not insured\"\n"
            . "FA-0004,Missing,Forklift,Toyota,Missing,Missing,\"In service\",\"Not insured\"\n"
            . "FA-0005,\"ALB  99\",\"Duplicate\",X,Y,Z,\"In service\",\"Not insured\"\n";
        $f = tempnam(sys_get_temp_dir(), 'cw');
        file_put_contents($f, $csv);
        $rows = AssetsImport::parse($f);
        $t->eq(5, count($rows));
        $plan = array_column(AssetsImport::plan($rows), 'action', 'asset');
        $t->eq(['FA-0001' => 'match', 'FA-0002' => 'add', 'FA-0003' => 'skip', 'FA-0004' => 'skip', 'FA-0005' => 'skip'], $plan);
        $t->as('anna');
        $t->throws(static fn () => AssetsImport::apply($rows, true), 'permission', DeniedException::class);
        $t->as('tom');
        $t->eq([1, 1, 3], AssetsImport::apply($rows, true, $t->wh('LSK')));
        $v = Db::row('SELECT * FROM {vehicles} WHERE id = ?', [$vid]);
        $t->eq('FA-0001', $v['asset_code']);
        $t->eq('JALNPR123', $v['vin']);
        $t->eq('Isuzu', $v['make'], 'our make is kept');
        $t->eq('NPR', $v['model'], 'an empty model is filled in');
        $t->eq('2027-03-31', $v['insurance_expiry']);
        $n = Db::row("SELECT * FROM {vehicles} WHERE reg_no = 'ALB 99'");
        $t->eq('pickup', $n['body']);
        $t->eq('FA-0002', $n['asset_code']);
        $t->eq(null, $n['insurance_expiry']);
        $t->eq([0, 0, 5], AssetsImport::apply($rows, true), 'a second run changes nothing');
        file_put_contents($f, "Name,Plate\nA,B\n");
        $t->throws(static fn () => AssetsImport::parse($f), 'not the Chatu Assets vehicle register');
        unlink($f);
    },
];
