<?php

namespace App\Libraries\Core;

use App\Libraries\Platform\Modules;

/**
 * Search everything from the top bar (core 1.6, C7; Chatu Construction's model): items, warehouses and bins, batches
 * and serial numbers, customers, suppliers, vehicles and drivers, and documents by their number. Each group holds only
 * what the person may open, by the same rules as its own list: their duties, and their warehouses.
 */
final class Search
{
    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    private static function in(array $ids): string
    {
        return $ids ? implode(',', array_map('intval', $ids)) : '0';
    }

    /** @return list<array{group:string, rows:list<array{title:string, sub:string, url:string}>}> */
    public static function run(string $q, int $per = 6): array
    {
        $q = trim(preg_replace('/\s+/', ' ', $q));
        if (mb_strlen($q) < 2 || ! Tenancy::id()) {
            return [];
        }
        $l     = self::like($q);
        $up    = strtoupper($q);
        $digit = (bool) preg_match('/\d/', $q);
        $out   = [];
        $add   = static function (string $group, array $rows) use (&$out): void {
            if ($rows) {
                $out[] = ['group' => $group, 'rows' => $rows];
            }
        };
        $stock = Access::warehouses('stock.view');
        $in    = self::in($stock);

        // items: by code, name, barcode or a pack's barcode
        if (Access::canAny(['stock.view', 'item.view'])) {
            $add('Items', array_map(static fn ($i) => ['title' => $i['sku'] . ' ' . $i['name'], 'sub' => trim(($i['active'] ? '' : 'switched off') . ($i['pack'] ? ' · pack ' . $i['pack'] : ''), ' ·'),
                'url' => $stock ? 'stock/item/' . $i['id'] : 'items/' . $i['id']],
                Db::rows("SELECT i.id, i.sku, i.name, i.active, (SELECT u.name FROM {item_units} u WHERE u.tenant_id = i.tenant_id AND u.item_id = i.id AND u.barcode = ? LIMIT 1) pack
                            FROM {items} i WHERE i.tenant_id = {tid} AND (i.sku LIKE ? OR i.name LIKE ? OR i.barcode = ?
                              OR EXISTS (SELECT 1 FROM {item_units} u WHERE u.tenant_id = i.tenant_id AND u.item_id = i.id AND u.barcode = ? AND u.barcode <> ''))
                           ORDER BY i.sku = ? DESC, i.active DESC, i.sku LIMIT " . $per, [$q, $l, $l, $q, $q, $up])));
        }
        if ($stock) {
            // warehouses and bins: only the person's own
            $add('Warehouses', array_map(static fn ($w) => ['title' => $w['code'] . ' ' . $w['name'], 'sub' => (string) $w['town'], 'url' => Access::can('warehouse.setup') ? 'warehouses/' . $w['id'] : 'stock?warehouse_id=' . $w['id']],
                Db::rows("SELECT id, code, name, town FROM {warehouses} WHERE tenant_id = {tid} AND id IN ($in) AND (code LIKE ? OR name LIKE ? OR town LIKE ?) ORDER BY code LIMIT " . $per, [$l, $l, $l])));
            $add('Bins', array_map(static fn ($b) => ['title' => $b['code'], 'sub' => $b['wh'] . ' · ' . str_replace('_', ' ', (string) $b['type']), 'url' => 'bins/' . $b['id']],
                Db::rows("SELECT b.id, b.code, b.type, w.code wh FROM {bins} b JOIN {warehouses} w ON w.tenant_id = b.tenant_id AND w.id = b.warehouse_id
                           WHERE b.tenant_id = {tid} AND b.warehouse_id IN ($in) AND b.code LIKE ? ORDER BY b.code = ? DESC, b.code LIMIT " . $per, [$l, $up])));
            // batches and serial numbers (their pages show only the person's warehouses)
            $add('Batches', array_map(static fn ($r) => ['title' => $r['lot_no'], 'sub' => $r['sku'] . ' ' . $r['name'] . ($r['expiry_date'] ? ' · expires ' . $r['expiry_date'] : ''), 'url' => 'lots/' . $r['id']],
                Db::rows("SELECT l.id, l.lot_no, l.expiry_date, i.sku, i.name FROM {lots} l JOIN {items} i ON i.tenant_id = l.tenant_id AND i.id = l.item_id
                           WHERE l.tenant_id = {tid} AND (l.lot_no LIKE ? OR l.supplier_lot LIKE ?) ORDER BY l.id DESC LIMIT " . $per, [$l, $l])));
            if ($digit) {
                $add('Serial numbers', array_map(static fn ($r) => ['title' => $r['serial_no'], 'sub' => $r['sku'] . ' ' . $r['name'] . ' · ' . str_replace('_', ' ', $r['status']), 'url' => 'serials/' . $r['id']],
                    Db::rows("SELECT s.id, s.serial_no, s.status, i.sku, i.name FROM {serials} s JOIN {items} i ON i.tenant_id = s.tenant_id AND i.id = s.item_id
                               WHERE s.tenant_id = {tid} AND s.serial_no LIKE ? ORDER BY s.id DESC LIMIT " . $per, [$l])));
            }
        }
        // customers and suppliers
        foreach (['customers' => ['Customers', 'customer.view'], 'suppliers' => ['Suppliers', 'supplier.view']] as $t => [$label, $perm]) {
            if (Access::can($perm)) {
                $add($label, array_map(static fn ($r) => ['title' => $r['code'] . ' ' . $r['name'], 'sub' => $r['active'] ? trim((string) $r['phone']) : 'switched off', 'url' => $t . '/' . $r['id']],
                    Db::rows("SELECT id, code, name, phone, active FROM {{$t}} WHERE tenant_id = {tid} AND (code LIKE ? OR name LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY active DESC, name LIMIT " . $per, [$l, $l, $l, $l])));
            }
        }
        // vehicles and drivers
        if (Access::can('fleet.manage') && Modules::on('fleet')) {
            $add('Vehicles', array_map(static fn ($v) => ['title' => $v['reg_no'], 'sub' => trim($v['make'] . ' ' . $v['model'] . ($v['asset_code'] ? ' · ' . $v['asset_code'] : '')), 'url' => 'fleet/vehicles/' . $v['id']],
                Db::rows("SELECT id, reg_no, make, model, asset_code FROM {vehicles} WHERE tenant_id = {tid} AND (REPLACE(reg_no, ' ', '') LIKE ? OR make LIKE ? OR model LIKE ? OR asset_code LIKE ? OR vin = ?) ORDER BY reg_no LIMIT " . $per,
                    [self::like(str_replace(' ', '', $q)), $l, $l, $l, $up])));
            $add('Drivers', array_map(static fn ($d) => ['title' => $d['name'], 'sub' => trim((string) $d['phone'] . ($d['licence_no'] ? ' · licence ' . $d['licence_no'] : ''), ' ·'), 'url' => 'fleet/drivers/' . $d['id']],
                Db::rows("SELECT id, name, phone, licence_no FROM {drivers} WHERE tenant_id = {tid} AND (name LIKE ? OR phone LIKE ? OR licence_no LIKE ?) ORDER BY name LIMIT " . $per, [$l, $l, $l])));
        }
        // documents by number, at the person's warehouses (only when the search has a digit)
        if ($digit && $stock) {
            $docs = [];
            $kinds = [
                ['purchase_orders', 'purchase-orders', 'Purchase order', 'warehouse_id', ['purchase.manage', 'receive.post']],
                ['receipts', 'receipts', 'Goods received', 'warehouse_id', ['receive.post', 'stock.view']],
                ['sales_orders', 'orders', 'Sales order', 'warehouse_id', ['sales.manage', 'dispatch.manage']],
                ['shipments', 'shipments', 'Shipment', 'warehouse_id', ['dispatch.manage', 'sales.manage']],
                ['pick_waves', 'waves', 'Pick wave', 'warehouse_id', ['dispatch.manage']],
                ['transfers', 'transfers', 'Transfer', null, ['transfer.manage', 'transfer.receive', 'stock.view']],
                ['adjustments', 'adjustments', 'Adjustment', 'warehouse_id', ['adjust.request', 'stock.approve']],
                ['counts', 'counts', 'Count', 'warehouse_id', ['count.manage', 'count.enter', 'stock.approve']],
                ['production_runs', 'production/runs', 'Production run', 'warehouse_id', ['production.manage'], 'production'],
                ['trips', 'fleet/trips', 'Trip', 'warehouse_id', ['fleet.manage', 'dispatch.manage'], 'fleet'],
            ];
            foreach ($kinds as $k) {
                [$table, $url, $label, $col, $perms] = $k;
                if (isset($k[5]) && ! Modules::on($k[5])) {
                    continue;
                }
                $where = [];
                foreach ($perms as $p) {
                    $where = array_merge($where, Access::warehouses($p));
                }
                $where = array_values(array_unique($where));
                if (! $where) {
                    continue;
                }
                $w   = self::in($where);
                $sc  = $col ? "$col IN ($w)" : "(from_warehouse_id IN ($w) OR to_warehouse_id IN ($w))";
                foreach (Db::rows('SELECT id, number, ' . ($table === 'receipts' ? 'kind' : 'status') . " status FROM {{$table}} WHERE tenant_id = {tid} AND number LIKE ? AND $sc ORDER BY number = ? DESC, id DESC LIMIT 3", [$l, $up]) as $r) {
                    $docs[] = ['title' => $r['number'], 'sub' => $label . ' · ' . str_replace('_', ' ', (string) $r['status']), 'url' => $url . '/' . $r['id']];
                }
            }
            $add('Documents', array_slice($docs, 0, $per * 2));
        }
        return $out;
    }

    /** "+" in the top bar (core 1.6, C8): what this person may add, by the duties they hold. */
    public static function quickAdd(): array
    {
        if (! Tenancy::id()) {
            return [];
        }
        $items = [
            ['purchase-orders/new', 'Purchase order', 'purchase.manage'],
            ['receipts/new', 'Goods received', 'receive.post'],
            ['orders/new', 'Sales order', 'sales.manage'],
            ['transfers/new', 'Transfer', 'transfer.manage'],
            ['adjustments/new', 'Adjustment', 'adjust.request'],
            ['counts', 'Count', 'count.manage'],
            ['production/runs/new', 'Production run', 'production.manage', 'production'],
            ['fleet/trips/new', 'Trip', 'fleet.manage', 'fleet'],
            ['items/new', 'Item', 'item.create'],
            ['customers/new', 'Customer', 'customer.create'],
            ['suppliers/new', 'Supplier', 'supplier.create'],
            ['fleet/vehicles/new', 'Vehicle', 'fleet.manage', 'fleet'],
        ];
        return array_values(array_filter($items, static fn ($i) => Access::can($i[2]) && (! isset($i[3]) || Modules::on($i[3]))));
    }
}
