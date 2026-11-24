<?php

namespace App\Libraries\Setup;

use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Master\Items;
use App\Libraries\Master\Parties;
use App\Libraries\Master\Warehouses;

/**
 * Demo company used by the installer (optional) and by every acceptance test:
 * a main warehouse in Lusaka with a factory floor, a depot in Kitwe, one person per duty,
 * plain, batch-with-expiry and serial items, a recipe's materials, suppliers, customers, a truck and a driver.
 *
 * 0.3.0: the demo company is an organisation. build() makes it (or fills the organisation given) and leaves it as
 * the current one; second() adds Demo Foods, a small second organisation with its own people, and mia, who belongs
 * to both, for trying the organisation chooser and the platform console.
 */
final class Demo
{
    public const PASSWORD = 'Demo-pass-2026';

    public static function build(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            $tenantId = Db::insert('tenants', ['slug' => 'demo-distributors', 'name' => 'Demo Distributors Ltd', 'status' => 'active', 'created_at' => Db::now()]);
            Seed::tenantStart($tenantId);
        }
        Tenancy::set($tenantId);
        Settings::set('company_name', 'Demo Distributors Ltd');
        $admin = Seed::user('sam', 'Sam Admin', self::PASSWORD, null, [['system_admin', 'system', null]], 'IT officer');
        Auth::actAs($admin);
        $lsk = Warehouses::create(['code' => 'LSK', 'name' => 'Lusaka main warehouse', 'town' => 'Lusaka', 'pick_policy' => 'fefo']);
        $ktw = Warehouses::create(['code' => 'KTW', 'name' => 'Kitwe depot', 'town' => 'Kitwe', 'kind' => 'depot', 'pick_policy' => 'fefo']);
        $zA  = Warehouses::saveZone($lsk, ['code' => 'A', 'name' => 'Ambient racking']);
        Warehouses::addBins($lsk, ['prefix' => 'A', 'aisles' => '1-2', 'bays' => '1-3', 'levels' => '1-2', 'zone_id' => $zA, 'type' => 'storage']);
        Warehouses::addBins($lsk, ['code' => 'PF-01', 'type' => 'picking', 'zone_id' => $zA]);
        Warehouses::addBins($ktw, ['prefix' => 'K', 'aisles' => '1', 'bays' => '1-4', 'levels' => '1', 'type' => 'storage']);

        $u = ['sam' => $admin];
        $people = [
            'dora'  => ['Dora Director', [['director', 'system', null]], 'Director'],
            'wendy' => ['Wendy Manager', [['warehouse_manager', 'warehouse', $lsk]], 'Warehouse manager, Lusaka'],
            'kalo'  => ['Kalo Manager', [['warehouse_manager', 'warehouse', $ktw]], 'Depot manager, Kitwe'],
            'steve' => ['Steve Storekeeper', [['storekeeper', 'warehouse', $lsk]], 'Storekeeper, Lusaka'],
            'kim'   => ['Kim Storekeeper', [['storekeeper', 'warehouse', $ktw]], 'Storekeeper, Kitwe'],
            'bella' => ['Bella Buyer', [['buyer', 'system', null]], 'Buyer'],
            'sally' => ['Sally Sales', [['sales_clerk', 'system', null]], 'Sales clerk'],
            'paul'  => ['Paul Production', [['production', 'warehouse', $lsk]], 'Production supervisor'],
            'tom'   => ['Tom Transport', [['transport', 'system', null]], 'Transport officer'],
            'anna'  => ['Anna Accountant', [['accountant', 'system', null]], 'Accountant'],
            'olive' => ['Olive Auditor', [['auditor', 'system', null]], 'External auditor'],
        ];
        foreach ($people as $un => [$name, $roles, $pos]) {
            $u[$un] = Seed::user($un, $name, self::PASSWORD, null, $roles, $pos);
        }
        // items are kept by the buyer
        Auth::actAs($u['bella']);
        $cat = Items::addCategory('Groceries');
        $items = [
            'RICE5'  => ['Rice 5 kg bag', 'goods', 'bag', 0, 'none', 0, null, 0, 85, 120],
            'MILK1'  => ['UHT milk 1 L', 'goods', 'carton', 0, 'batch', 1, 180, 30, 14, 22],
            'PHONE'  => ['Smartphone X1', 'goods', 'each', 0, 'serial', 0, null, 0, 1500, 2100],
            'FLOUR'  => ['Wheat flour', 'raw_material', 'kg', 3, 'batch', 1, 365, 0, 9.5, 0],
            'SUGAR'  => ['Sugar', 'raw_material', 'kg', 3, 'none', 0, null, 0, 18, 0],
            'BREAD'  => ['White bread 700 g', 'finished_good', 'loaf', 0, 'batch', 1, 5, 2, 0, 16],
        ];
        $ids = [];
        foreach ($items as $sku => [$name, $kind, $uom, $dec, $track, $exp, $shelf, $minShelf, $cost, $price]) {
            $ids[$sku] = Items::save(['sku' => $sku, 'name' => $name, 'kind' => $kind, 'uom' => $uom, 'decimals' => $dec, 'tracking' => $track, 'expiry_required' => $exp,
                'shelf_life_days' => $shelf, 'min_shelf_days' => $minShelf, 'sell_price' => $price, 'category_id' => $cat, 'reorder_level' => 10, 'reorder_qty' => 50]);
            Db::exec('UPDATE {items} SET last_cost = ? WHERE tenant_id = {tid} AND id = ?', [$cost, $ids[$sku]]);
        }
        $sup = [
            'ZAMMILL' => Parties::save('suppliers', ['code' => 'ZAMMILL', 'name' => 'Zambia Millers Ltd', 'phone' => '+260 211 000001', 'lead_days' => 3]),
            'DAIRY'   => Parties::save('suppliers', ['code' => 'DAIRY', 'name' => 'Copperbelt Dairies', 'lead_days' => 2]),
            'TECH'    => Parties::save('suppliers', ['code' => 'TECH', 'name' => 'Tech Imports', 'lead_days' => 14]),
        ];
        Auth::actAs($u['sally']);
        $cust = [
            'SHOPRITE' => Parties::save('customers', ['code' => 'SHOPRITE', 'name' => 'Corner Shop Kabwata', 'town' => 'Lusaka', 'address' => 'Plot 12, Kabwata']),
            'NDOLA'    => Parties::save('customers', ['code' => 'NDOLA', 'name' => 'Ndola Wholesalers', 'town' => 'Ndola', 'min_shelf_days' => 10]),
        ];
        Auth::actAs(null);
        $bins = [];
        foreach (Db::rows('SELECT b.id, b.code, w.code wh FROM {bins} b JOIN {warehouses} w ON w.id = b.warehouse_id WHERE b.tenant_id = {tid}') as $b) {
            $bins[$b['wh'] . ':' . $b['code']] = (int) $b['id'];
        }
        return ['tenant' => $tenantId, 'users' => $u, 'wh' => ['LSK' => $lsk, 'KTW' => $ktw], 'item' => $ids, 'supplier' => $sup, 'customer' => $cust, 'bin' => $bins];
    }

    /**
     * Demo Foods: a second organisation with one warehouse, its own items (one with the same code as Demo
     * Distributors', which codes per organisation allow), its owner fay, and mia, a member of both. Returns ids.
     */
    public static function second(array $c): array
    {
        $tid = Db::insert('tenants', ['slug' => 'demo-foods', 'name' => 'Demo Foods', 'status' => 'trial', 'trial_ends_at' => gmdate('Y-m-d H:i:s', time() + 14 * 86400), 'created_at' => Db::now()]);
        Seed::tenantStart($tid);
        return Tenancy::run($tid, static function () use ($tid, $c) {
            Settings::set('company_name', 'Demo Foods');
            $fay = Seed::user('fay', 'Fay Owner', self::PASSWORD, 'fay@demo-foods.example', [['system_admin', 'system', null]], 'Owner', true);
            Auth::actAs($fay);
            $wh  = Warehouses::create(['code' => 'LSK', 'name' => 'Demo Foods store', 'town' => 'Lusaka', 'kind' => 'store', 'pick_policy' => 'fefo']);
            Warehouses::addBins($wh, ['prefix' => 'S', 'aisles' => '1', 'bays' => '1-2', 'levels' => '1', 'type' => 'storage']);
            $mia = Seed::user('mia', 'Mia Both', self::PASSWORD, null, [['storekeeper', 'warehouse', $wh]], 'Storekeeper');
            Seed::member((int) $c['tenant'], $mia, [['auditor', null]]);
            $cat  = Items::addCategory('Groceries');
            $rice = Items::save(['sku' => 'RICE5', 'name' => 'Rice 5 kg bag (Demo Foods)', 'kind' => 'goods', 'uom' => 'bag', 'decimals' => 0, 'tracking' => 'none', 'category_id' => $cat, 'sell_price' => 125]);
            Auth::actAs(null);
            return ['tenant' => $tid, 'users' => ['fay' => $fay, 'mia' => $mia], 'wh' => $wh, 'item' => $rice];
        });
    }

    /** Some history so every screen has something in it: receipts, put-away, an order shipped and delivered, a transfer on the road, a production run, a truck. */
    public static function samples(array $c): void
    {
        $as  = static fn (string $u) => Auth::actAs($c['users'][$u]);
        $lsk = $c['wh']['LSK'];
        $ktw = $c['wh']['KTW'];
        $bin = static fn (string $k) => $c['bin'][$k];
        $d   = static fn (int $days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
        $as('bella');
        foreach (['RICE5' => [['name' => 'bale', 'factor' => 10, 'barcode' => '6009001000105', 'buy_default' => 1]], 'MILK1' => [['name' => 'crate', 'factor' => 12, 'barcode' => '6009001000112', 'buy_default' => 1, 'sell_default' => 1]]] as $sku => $units) {
            \App\Libraries\Master\Items::save(\App\Libraries\Master\Items::get($c['item'][$sku]) + ['units' => $units], $c['item'][$sku]);
        }
        $usd = \App\Libraries\Docs\Purchasing::saveDraft(['supplier_id' => $c['supplier']['TECH'], 'warehouse_id' => $lsk, 'expected_date' => $d(21), 'supplier_ref' => 'PI-2026-118',
            'currency' => 'USD', 'fx_rate' => 27.8, 'landed_pct' => 12, 'notes' => 'Sea freight via Dar es Salaam', 'lines' => [['item_id' => $c['item']['PHONE'], 'qty' => 10, 'unit_cost' => 48]]]);
        \App\Libraries\Docs\Purchasing::issue($usd);
        $po = \App\Libraries\Docs\Purchasing::saveDraft(['supplier_id' => $c['supplier']['ZAMMILL'], 'warehouse_id' => $lsk, 'expected_date' => $d(2), 'lines' => [
            ['item_id' => $c['item']['RICE5'], 'qty' => 200, 'unit_cost' => 82], ['item_id' => $c['item']['FLOUR'], 'qty' => 500, 'unit_cost' => 9.5], ['item_id' => $c['item']['SUGAR'], 'qty' => 100, 'unit_cost' => 18]]]);
        \App\Libraries\Docs\Purchasing::issue($po);
        $lines = \App\Libraries\Docs\Purchasing::lines($po);
        $as('steve');
        \App\Libraries\Docs\Receipts::post(['warehouse_id' => $lsk, 'po_id' => $po, 'delivery_note' => 'ZM-88213', 'lines' => [
            ['po_line_id' => $lines[0]['id'], 'qty_accepted' => 190, 'qty_rejected' => 10, 'reject_reason' => 'Torn bags', 'bin_id' => $bin('LSK:A-01-01-1')],
            ['po_line_id' => $lines[1]['id'], 'qty_accepted' => 500, 'lot_no' => 'ZM-F-2611', 'expiry_date' => $d(150), 'bin_id' => $bin('LSK:PRODUCTION')],
        ]]);
        \App\Libraries\Docs\Receipts::post(['warehouse_id' => $lsk, 'supplier_id' => $c['supplier']['DAIRY'], 'lines' => [
            ['item_id' => $c['item']['MILK1'], 'qty_accepted' => 120, 'unit_cost' => 14, 'lot_no' => 'CD-0921', 'expiry_date' => $d(40), 'bin_id' => $bin('LSK:A-01-02-1')],
            ['item_id' => $c['item']['MILK1'], 'qty_accepted' => 96, 'unit_cost' => 14.5, 'lot_no' => 'CD-1004', 'expiry_date' => $d(75), 'bin_id' => $bin('LSK:A-01-02-2')],
            ['item_id' => $c['item']['MILK1'], 'qty_accepted' => 12, 'unit_cost' => 13, 'lot_no' => 'CD-0811', 'expiry_date' => $d(9)],
        ]]);
        \App\Libraries\Docs\Receipts::post(['warehouse_id' => $lsk, 'supplier_id' => $c['supplier']['TECH'], 'lines' => [
            ['item_id' => $c['item']['PHONE'], 'qty_accepted' => 5, 'qty_quarantine' => 1, 'unit_cost' => 1500, 'serials' => "X1-0001\nX1-0002\nX1-0003\nX1-0004\nX1-0005", 'serials_quarantine' => 'X1-0006', 'bin_id' => $bin('LSK:PF-01')],
        ]]);
        \App\Libraries\Docs\Receipts::post(['warehouse_id' => $lsk, 'supplier_id' => $c['supplier']['ZAMMILL'], 'lines' => [['item_id' => $c['item']['SUGAR'], 'qty_accepted' => 60, 'unit_cost' => 18, 'bin_id' => $bin('LSK:A-02-01-1')]]]);
        // production: bread from flour and sugar
        $as('paul');
        $rec = \App\Libraries\Docs\Production::saveRecipe(['code' => 'BREAD700', 'name' => 'White bread 700 g', 'output_item_id' => $c['item']['BREAD'], 'output_qty' => 100, 'other_cost' => 180,
            'lines' => [['item_id' => $c['item']['FLOUR'], 'qty' => 42], ['item_id' => $c['item']['SUGAR'], 'qty' => 2.5]]]);
        \App\Libraries\Docs\Production::post(['recipe_id' => $rec, 'warehouse_id' => $lsk, 'batches' => 2, 'actual_qty' => 194]);
        // a customer order picked, packed, dispatched and delivered; another waiting to be picked
        $as('sally');
        $so = \App\Libraries\Docs\Sales::saveDraft(['customer_id' => $c['customer']['SHOPRITE'], 'warehouse_id' => $lsk, 'customer_ref' => 'LPO 4471', 'lines' => [
            ['item_id' => $c['item']['RICE5'], 'qty' => 20], ['item_id' => $c['item']['MILK1'], 'qty' => 24], ['item_id' => $c['item']['PHONE'], 'qty' => 1]]]);
        \App\Libraries\Docs\Sales::confirm($so);
        $so2 = \App\Libraries\Docs\Sales::saveDraft(['customer_id' => $c['customer']['NDOLA'], 'warehouse_id' => $lsk, 'required_date' => $d(1), 'lines' => [['item_id' => $c['item']['RICE5'], 'qty' => 40], ['item_id' => $c['item']['MILK1'], 'qty' => 48]]]);
        \App\Libraries\Docs\Sales::confirm($so2);
        $as('steve');
        $sh = \App\Libraries\Docs\Shipments::create($so);
        \App\Libraries\Docs\Shipments::pick($sh);
        \App\Libraries\Docs\Shipments::pack($sh, 3, 130);
        \App\Libraries\Docs\Shipments::dispatch($sh);
        \App\Libraries\Docs\Shipments::deliver($sh, 'Mrs Banda', 'All in good order');
        // two orders waiting in one pick wave
        $as('sally');
        $so3 = \App\Libraries\Docs\Sales::saveDraft(['customer_id' => $c['customer']['SHOPRITE'], 'warehouse_id' => $lsk, 'required_date' => $d(1), 'lines' => [['item_id' => $c['item']['RICE5'], 'qty' => 10], ['item_id' => $c['item']['SUGAR'], 'qty' => 6]]]);
        \App\Libraries\Docs\Sales::confirm($so3);
        $as('steve');
        $w1 = \App\Libraries\Docs\Shipments::create($so2);
        $w2 = \App\Libraries\Docs\Shipments::create($so3);
        \App\Libraries\Docs\Waves::create($lsk, [$w1, $w2], 'Morning wave, Ndola truck');
        // a transfer to Kitwe on a truck
        $as('tom');
        $veh = \App\Libraries\Fleet\Fleet::saveVehicle(['reg_no' => 'BAX 4521', 'make' => 'Isuzu', 'model' => 'NPR 4 t', 'body' => 'truck', 'capacity_kg' => 4000, 'odometer_km' => 84210, 'home_warehouse_id' => $lsk,
            'insurance_expiry' => $d(200), 'fitness_expiry' => $d(20), 'road_tax_expiry' => $d(90), 'next_service_date' => $d(35), 'next_service_km' => 90000, 'asset_code' => 'MV-0007', 'vin' => 'JALFRR90NK7000123']);
        \App\Libraries\Fleet\Fleet::saveVehicle(['reg_no' => 'ALB 9087', 'make' => 'Toyota', 'model' => 'Hilux', 'body' => 'pickup', 'capacity_kg' => 900, 'odometer_km' => 152300,
            'insurance_expiry' => $d(120), 'fitness_expiry' => $d(120), 'road_tax_expiry' => $d(120)]);
        $drv = \App\Libraries\Fleet\Fleet::saveDriver(['name' => 'Joseph Mwale', 'phone' => '+260 977 000111', 'licence_no' => 'ZM-DL-55120', 'licence_class' => 'C1', 'licence_expiry' => $d(400)]);
        \App\Libraries\Fleet\Fleet::saveDriver(['name' => 'Peter Banda', 'phone' => '+260 966 000222', 'licence_no' => 'ZM-DL-61044', 'licence_class' => 'B', 'licence_expiry' => $d(25)]);
        $as('wendy');
        $tr = \App\Libraries\Docs\Transfers::create(['from_warehouse_id' => $lsk, 'to_warehouse_id' => $ktw, 'needed_by' => $d(2), 'lines' => [['item_id' => $c['item']['RICE5'], 'qty' => 60], ['item_id' => $c['item']['MILK1'], 'qty' => 36]]]);
        \App\Libraries\Docs\Transfers::allocate($tr);
        $as('tom');
        $trip = \App\Libraries\Fleet\Fleet::planTrip(['warehouse_id' => $lsk, 'vehicle_id' => $veh, 'driver_id' => $drv, 'route_note' => 'Lusaka - Kabwe - Kapiri - Ndola - Kitwe', 'transfers' => [$tr]]);
        $as('steve');
        \App\Libraries\Fleet\Fleet::depart($trip, 84230);
        // an adjustment waiting for approval and a count in progress
        $row = (int) Db::value("SELECT s.id FROM {stock} s JOIN {items} i ON i.id = s.item_id WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND i.sku = 'RICE5' AND s.qty > s.qty_allocated + 3 ORDER BY s.id LIMIT 1", [$lsk]);
        if ($row) {
            \App\Libraries\Docs\Adjustments::request(['warehouse_id' => $lsk, 'reason_code' => 'DAMAGE', 'notes' => 'Forklift tore three bags', 'lines' => [['stock_id' => $row, 'qty' => -3]]]);
        }
        $as('wendy');
        \App\Libraries\Docs\Counts::start(['warehouse_id' => $lsk, 'item_id' => $c['item']['MILK1'], 'blind' => 1, 'notes' => 'Monthly milk count']);
        Auth::actAs(null);
    }
}
