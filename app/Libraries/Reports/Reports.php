<?php

namespace App\Libraries\Reports;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;

/**
 * Report queries. Every report is limited to the warehouses the user may see (stock.view), and values only
 * appear for people with stock.view_cost. Each returns ['columns' => [...], 'rows' => [...], 'money' => [...cols]].
 */
final class Reports
{
    public const LIST = [
        'on_hand'    => ['Stock on hand', 'Free, reserved, held and damaged stock per item and warehouse, with goods in transit.'],
        'by_bin'     => ['Stock by bin', 'Every bin, batch and serial number with its quantity and status.'],
        'expiry'     => ['Expiry', 'Batches that have expired or will expire soon, with where they are.'],
        'reorder'    => ['Reorder', 'Items at or below their reorder level across the company, with what is on order.'],
        'movements'  => ['Movements', 'Every stock movement in a period: receipts, moves, transfers, dispatches, production, adjustments, counts.'],
        'valuation'  => ['Valuation (FIFO)', 'Quantity and value per item and warehouse, from the FIFO cost layers.', 'stock.view_cost'],
        'transit'    => ['Goods in transit', 'Transfers that have left and not fully arrived.'],
        'dispatches' => ['Dispatches for invoicing', 'Shipments dispatched in a period, with prices: export to the accounting system.'],
        'losses'     => ['Losses and write-offs', 'Adjustments, count differences, transfer losses and damaged stock in a period.', 'stock.view_cost'],
        'production' => ['Production', 'Runs in a period with yield and cost per unit.'],
        'fleet'      => ['Fleet use', 'Trips, kilometres and fuel per vehicle in a period.'],
    ];

    /** Day-to-day reports anyone who sees stock may run; the rest need report.view. */
    public const OPERATIONAL = ['on_hand', 'by_bin', 'expiry', 'reorder', 'movements', 'transit'];

    public static function allowed(string $code): bool
    {
        if (! isset(self::LIST[$code])) {
            return false;
        }
        if (! Access::can('report.view') && ! (in_array($code, self::OPERATIONAL, true) && Access::can('stock.view'))) {
            return false;
        }
        return ! isset(self::LIST[$code][2]) || Access::can(self::LIST[$code][2]);
    }

    /** @return array{0:string,1:array} SQL condition on $col for the user's warehouses (optionally one warehouse). */
    private static function wh(string $col, ?int $only): array
    {
        $ids = Access::warehouses('stock.view');
        if ($only) {
            $ids = in_array($only, $ids, true) ? [$only] : [];
        }
        if (! $ids) {
            return ['1=0', []];
        }
        return ["$col IN (" . implode(',', $ids) . ')', []];
    }

    public static function run(string $code, array $f): array
    {
        $whId = ! empty($f['warehouse_id']) ? (int) $f['warehouse_id'] : null;
        $from = $f['from'] ?? date('Y-m-01');
        $to   = $f['to'] ?? date('Y-m-d');
        $cost = Access::can('stock.view_cost');
        switch ($code) {
            case 'on_hand':
                [$w] = self::wh('s.warehouse_id', $whId);
                $fq  = \App\Libraries\Core\Find::where((string) ($f['q'] ?? ''), ['i.sku', 'i.name'], ['i.barcode']);   // core 1.7 (D1)
                $rows = Db::rows("SELECT wh.code warehouse, i.sku, i.name, i.uom,
                        SUM(CASE WHEN s.status = 'available' AND b.pickable = 1 AND b.status = 'active' AND (l.id IS NULL OR (l.status = 'active' AND (l.expiry_date IS NULL OR l.expiry_date >= CURDATE()))) THEN s.qty - s.qty_allocated ELSE 0 END) free_qty,
                        SUM(s.qty_allocated) reserved,
                        SUM(CASE WHEN b.type = 'receiving' AND s.status = 'available' THEN s.qty ELSE 0 END) to_put_away,
                        SUM(CASE WHEN s.status IN ('quarantine','hold') OR b.status = 'blocked' OR l.status IN ('on_hold','recalled') THEN s.qty ELSE 0 END) held,
                        SUM(CASE WHEN l.expiry_date < CURDATE() THEN s.qty ELSE 0 END) expired,
                        SUM(CASE WHEN s.status = 'damaged' THEN s.qty ELSE 0 END) damaged,
                        SUM(s.qty) on_hand
                    FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {warehouses} wh ON wh.id = s.warehouse_id JOIN {bins} b ON b.id = s.bin_id LEFT JOIN {lots} l ON l.id = s.lot_id
                   WHERE s.tenant_id = {tid} AND $w AND " . $fq[0] . ' GROUP BY s.warehouse_id, s.item_id ORDER BY wh.code, i.sku', $fq[1]);
                return ['columns' => ['warehouse' => 'Warehouse', 'sku' => 'Item', 'name' => 'Description', 'uom' => 'Unit', 'free_qty' => 'Free', 'reserved' => 'Reserved', 'to_put_away' => 'To put away', 'held' => 'Held', 'expired' => 'Expired', 'damaged' => 'Damaged', 'on_hand' => 'On hand'],
                    'rows' => $rows, 'num' => ['free_qty', 'reserved', 'to_put_away', 'held', 'expired', 'damaged', 'on_hand']];

            case 'by_bin':
                [$w] = self::wh('s.warehouse_id', $whId);
                $rows = Db::rows("SELECT wh.code warehouse, b.code bin, b.type bin_type, i.sku, i.name, l.lot_no, l.expiry_date, se.serial_no, s.status, s.qty, s.qty_allocated reserved
                    FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {warehouses} wh ON wh.id = s.warehouse_id JOIN {bins} b ON b.id = s.bin_id
                    LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.tenant_id = {tid} AND $w ORDER BY wh.code, b.sort, b.code, i.sku, l.expiry_date LIMIT 20000");
                return ['columns' => ['warehouse' => 'Warehouse', 'bin' => 'Bin', 'bin_type' => 'Bin type', 'sku' => 'Item', 'name' => 'Description', 'lot_no' => 'Batch', 'expiry_date' => 'Expiry', 'serial_no' => 'Serial', 'status' => 'Status', 'qty' => 'Quantity', 'reserved' => 'Reserved'],
                    'rows' => $rows, 'num' => ['qty', 'reserved']];

            case 'expiry':
                [$w] = self::wh('s.warehouse_id', $whId);
                $days = max(0, (int) ($f['days'] ?? Settings::get('expiry_warn_days')));
                $rows = Db::rows("SELECT l.expiry_date, DATEDIFF(l.expiry_date, CURDATE()) days_left, i.sku, i.name, l.lot_no, l.status lot_status, wh.code warehouse, b.code bin, s.status, SUM(s.qty) qty
                    FROM {stock} s JOIN {lots} l ON l.id = s.lot_id JOIN {items} i ON i.id = s.item_id JOIN {warehouses} wh ON wh.id = s.warehouse_id JOIN {bins} b ON b.id = s.bin_id
                   WHERE s.tenant_id = {tid} AND $w AND l.expiry_date IS NOT NULL AND l.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY)
                   GROUP BY s.warehouse_id, s.bin_id, s.lot_id, s.status ORDER BY l.expiry_date, i.sku");
                return ['columns' => ['expiry_date' => 'Expiry', 'days_left' => 'Days left', 'sku' => 'Item', 'name' => 'Description', 'lot_no' => 'Batch', 'lot_status' => 'Batch status', 'warehouse' => 'Warehouse', 'bin' => 'Bin', 'status' => 'Stock status', 'qty' => 'Quantity'],
                    'rows' => $rows, 'num' => ['days_left', 'qty']];

            case 'reorder':
                [$w] = self::wh('s.warehouse_id', $whId);
                $rows = Db::rows("SELECT i.sku, i.name, i.uom, i.reorder_level, i.reorder_qty, COALESCE(st.qty, 0) on_hand,
                        COALESCE((SELECT SUM(GREATEST(pl.qty_ordered - pl.qty_received, 0)) FROM {po_lines} pl JOIN {purchase_orders} p ON p.id = pl.po_id WHERE pl.tenant_id = {tid} AND pl.item_id = i.id AND p.status IN ('open','partly_received')), 0) on_order
                    FROM {items} i LEFT JOIN (SELECT s.item_id, SUM(s.qty - s.qty_allocated) qty FROM {stock} s WHERE s.tenant_id = {tid} AND $w AND s.status = 'available' GROUP BY s.item_id) st ON st.item_id = i.id
                   WHERE i.tenant_id = {tid} AND i.active = 1 AND i.reorder_level > 0 AND COALESCE(st.qty, 0) <= i.reorder_level ORDER BY i.sku");
                return ['columns' => ['sku' => 'Item', 'name' => 'Description', 'uom' => 'Unit', 'on_hand' => 'Free now', 'reorder_level' => 'Reorder level', 'on_order' => 'On order', 'reorder_qty' => 'Usual order'],
                    'rows' => $rows, 'num' => ['on_hand', 'reorder_level', 'on_order', 'reorder_qty']];

            case 'movements':
                [$w] = self::wh('m.warehouse_id', $whId);
                $b = [$from . ' 00:00:00', $to . ' 23:59:59'];
                $extra = '';
                if (! empty($f['q'])) {
                    $fm = \App\Libraries\Core\Find::where((string) $f['q'], ['i.sku', 'i.name', 'l.lot_no', 'se.serial_no']);   // core 1.7 (D1)
                    $extra = ' AND ' . $fm[0];
                    $b = array_merge($b, $fm[1]);
                }
                $rows = Db::rows("SELECT m.at, wh.code warehouse, m.type, CONCAT(m.doc_type, ' ', COALESCE(m.doc_id, '')) doc, i.sku, l.lot_no, se.serial_no, fb.code from_bin, tb.code to_bin,
                        CONCAT_WS(' > ', m.from_status, m.to_status) status, IF(m.to_bin_id IS NOT NULL AND m.from_bin_id IS NULL, m.qty, IF(m.from_bin_id IS NOT NULL AND m.to_bin_id IS NULL, -m.qty, m.qty)) qty,
                        " . ($cost ? 'm.value,' : '') . " m.reason_code reason, u.name user
                    FROM {movements} m JOIN {items} i ON i.id = m.item_id JOIN {warehouses} wh ON wh.id = m.warehouse_id LEFT JOIN {lots} l ON l.id = m.lot_id LEFT JOIN {serials} se ON se.id = m.serial_id
                    LEFT JOIN {bins} fb ON fb.id = m.from_bin_id LEFT JOIN {bins} tb ON tb.id = m.to_bin_id LEFT JOIN {users} u ON u.id = m.user_id
                   WHERE m.tenant_id = {tid} AND $w AND m.at BETWEEN ? AND ? $extra ORDER BY m.id DESC LIMIT 5000", $b);
                $cols = ['at' => 'When (UTC)', 'warehouse' => 'Warehouse', 'type' => 'Movement', 'doc' => 'Document', 'sku' => 'Item', 'lot_no' => 'Batch', 'serial_no' => 'Serial', 'from_bin' => 'From', 'to_bin' => 'To', 'status' => 'Status', 'qty' => 'Quantity'];
                if ($cost) {
                    $cols['value'] = 'Value';
                }
                return ['columns' => $cols + ['reason' => 'Reason', 'user' => 'By'], 'rows' => $rows, 'num' => ['qty', 'value']];

            case 'valuation':
                [$w] = self::wh('c.warehouse_id', $whId);
                $rows = Db::rows("SELECT wh.code warehouse, i.sku, i.name, i.uom, SUM(c.qty_left) qty, SUM(c.qty_left * c.unit_cost) value, SUM(c.qty_left * c.unit_cost) / NULLIF(SUM(c.qty_left), 0) avg_cost
                    FROM {cost_layers} c JOIN {items} i ON i.id = c.item_id JOIN {warehouses} wh ON wh.id = c.warehouse_id WHERE c.tenant_id = {tid} AND $w AND c.qty_left > 0 GROUP BY c.warehouse_id, c.item_id ORDER BY wh.code, i.sku");
                $transit = Db::rows('SELECT CONCAT(fw.code, \' > \', tw.code) warehouse, i.sku, i.name, i.uom, SUM(tc.qty_open) qty, SUM(tc.qty_open * tc.unit_cost) value, SUM(tc.qty_open * tc.unit_cost) / NULLIF(SUM(tc.qty_open), 0) avg_cost
                    FROM {transit_costs} tc JOIN {transfers} t ON t.id = tc.transfer_id JOIN {transfer_lines} tl ON tl.id = tc.transfer_line_id JOIN {items} i ON i.id = tl.item_id
                    JOIN {warehouses} fw ON fw.id = t.from_warehouse_id JOIN {warehouses} tw ON tw.id = t.to_warehouse_id WHERE tc.tenant_id = {tid} AND tc.qty_open > 0 AND (' . self::wh('t.from_warehouse_id', $whId)[0] . ' OR ' . self::wh('t.to_warehouse_id', $whId)[0] . ') GROUP BY t.from_warehouse_id, t.to_warehouse_id, tl.item_id');
                foreach ($transit as &$t) {
                    $t['warehouse'] = 'In transit ' . $t['warehouse'];
                }
                return ['columns' => ['warehouse' => 'Warehouse', 'sku' => 'Item', 'name' => 'Description', 'uom' => 'Unit', 'qty' => 'Quantity', 'avg_cost' => 'Average cost', 'value' => 'Value'],
                    'rows' => array_merge($rows, $transit), 'num' => ['qty', 'avg_cost', 'value'], 'total' => ['value']];

            case 'transit':
                [$w1] = self::wh('t.from_warehouse_id', $whId);
                [$w2] = self::wh('t.to_warehouse_id', $whId);
                $rows = Db::rows("SELECT t.id, t.number, fw.code from_wh, tw.code to_wh, t.dispatched_at, DATEDIFF(CURDATE(), DATE(t.dispatched_at)) days, i.sku, i.name, l.lot_no, se.serial_no, x.qty_open qty
                    FROM {transit} x JOIN {transfers} t ON t.id = x.transfer_id JOIN {items} i ON i.id = x.item_id JOIN {warehouses} fw ON fw.id = t.from_warehouse_id JOIN {warehouses} tw ON tw.id = t.to_warehouse_id
                    LEFT JOIN {lots} l ON l.id = x.lot_id LEFT JOIN {serials} se ON se.id = x.serial_id WHERE x.tenant_id = {tid} AND x.qty_open > 0 AND ($w1 OR $w2) ORDER BY t.dispatched_at, t.id");
                return ['columns' => ['number' => 'Transfer', 'from_wh' => 'From', 'to_wh' => 'To', 'dispatched_at' => 'Left (UTC)', 'days' => 'Days on the way', 'sku' => 'Item', 'name' => 'Description', 'lot_no' => 'Batch', 'serial_no' => 'Serial', 'qty' => 'Quantity'],
                    'rows' => $rows, 'num' => ['days', 'qty'], 'link' => ['number' => '/transfers/{id}']];

            case 'dispatches':
                [$w] = self::wh('sh.warehouse_id', $whId);
                $rows = Db::rows("SELECT sh.id, sh.number shipment, DATE(sh.dispatched_at) dispatched, sh.status, so.number order_no, so.customer_ref, c.code customer_code, c.name customer, c.tax_no, i.sku, i.name item,
                        sl.qty_picked - sl.qty_returned qty, ol.unit_price, ROUND((sl.qty_picked - sl.qty_returned) * ol.unit_price, 2) amount" . ($cost ? ', sh.value cost_of_shipment' : '') . "
                    FROM {shipments} sh JOIN {sales_orders} so ON so.id = sh.so_id JOIN {customers} c ON c.id = so.customer_id JOIN {shipment_lines} sl ON sl.shipment_id = sh.id
                    JOIN {so_lines} ol ON ol.id = sl.so_line_id JOIN {items} i ON i.id = sl.item_id
                   WHERE sh.tenant_id = {tid} AND $w AND sh.status IN ('dispatched','delivered') AND sh.dispatched_at BETWEEN ? AND ? ORDER BY sh.dispatched_at, sh.id, sl.id", [$from . ' 00:00:00', $to . ' 23:59:59']);
                $cols = ['shipment' => 'Shipment', 'dispatched' => 'Dispatched', 'status' => 'Status', 'order_no' => 'Order', 'customer_ref' => 'Customer reference', 'customer_code' => 'Customer code', 'customer' => 'Customer', 'tax_no' => 'TPIN',
                    'sku' => 'Item', 'item' => 'Description', 'qty' => 'Quantity', 'unit_price' => 'Unit price', 'amount' => 'Amount'];
                return ['columns' => $cols, 'rows' => $rows, 'num' => ['qty', 'unit_price', 'amount'], 'total' => ['amount'], 'link' => ['shipment' => '/shipments/{id}']];

            case 'losses':
                [$w] = self::wh('m.warehouse_id', $whId);
                $rows = Db::rows("SELECT DATE(m.at) day, wh.code warehouse, m.type, m.reason_code reason, i.sku, i.name, l.lot_no, se.serial_no, m.qty, m.value
                    FROM {movements} m JOIN {items} i ON i.id = m.item_id JOIN {warehouses} wh ON wh.id = m.warehouse_id LEFT JOIN {lots} l ON l.id = m.lot_id LEFT JOIN {serials} se ON se.id = m.serial_id
                   WHERE m.tenant_id = {tid} AND $w AND m.at BETWEEN ? AND ? AND m.type IN ('adjust_out','count_out') ORDER BY m.at", [$from . ' 00:00:00', $to . ' 23:59:59']);
                $tl = Db::rows("SELECT DATE(t.closed_at) day, CONCAT(fw.code, ' > ', tw.code) warehouse, 'transfer_loss' type, t.number reason, i.sku, i.name, NULL lot_no, NULL serial_no, tl.qty_missing qty, tl.value_missing value
                    FROM {transfer_lines} tl JOIN {transfers} t ON t.id = tl.transfer_id JOIN {items} i ON i.id = tl.item_id JOIN {warehouses} fw ON fw.id = t.from_warehouse_id JOIN {warehouses} tw ON tw.id = t.to_warehouse_id
                   WHERE tl.tenant_id = {tid} AND tl.qty_missing > 0 AND t.closed_at BETWEEN ? AND ? AND (" . self::wh('t.to_warehouse_id', $whId)[0] . ')', [$from . ' 00:00:00', $to . ' 23:59:59']);
                return ['columns' => ['day' => 'Date', 'warehouse' => 'Warehouse', 'type' => 'Kind', 'reason' => 'Reason', 'sku' => 'Item', 'name' => 'Description', 'lot_no' => 'Batch', 'serial_no' => 'Serial', 'qty' => 'Quantity', 'value' => 'Value'],
                    'rows' => array_merge($rows, $tl), 'num' => ['qty', 'value'], 'total' => ['value']];

            case 'production':
                [$w] = self::wh('p.warehouse_id', $whId);
                $rows = Db::rows("SELECT p.id, p.number, p.produced_on, wh.code warehouse, r.code recipe, i.sku, l.lot_no, p.batches, p.expected_qty, p.actual_qty, ROUND(100 * p.actual_qty / NULLIF(p.expected_qty, 0), 1) yield_pct,
                        p.status" . ($cost ? ', p.material_cost, p.other_cost, p.unit_cost' : '') . "
                    FROM {production_runs} p JOIN {recipes} r ON r.id = p.recipe_id JOIN {items} i ON i.id = p.output_item_id JOIN {warehouses} wh ON wh.id = p.warehouse_id LEFT JOIN {lots} l ON l.id = p.lot_id
                   WHERE p.tenant_id = {tid} AND $w AND p.produced_on BETWEEN ? AND ? ORDER BY p.produced_on, p.id", [$from, $to]);
                $cols = ['number' => 'Run', 'produced_on' => 'Date', 'warehouse' => 'Warehouse', 'recipe' => 'Recipe', 'sku' => 'Item', 'lot_no' => 'Batch', 'batches' => 'Batches', 'expected_qty' => 'Expected', 'actual_qty' => 'Made', 'yield_pct' => 'Yield %', 'status' => 'Status'];
                if ($cost) {
                    $cols += ['material_cost' => 'Materials', 'other_cost' => 'Other cost', 'unit_cost' => 'Cost each'];
                }
                return ['columns' => $cols, 'rows' => $rows, 'num' => ['batches', 'expected_qty', 'actual_qty', 'yield_pct', 'material_cost', 'other_cost', 'unit_cost'], 'link' => ['number' => '/production/runs/{id}']];

            case 'fleet':
                $rows = Db::rows("SELECT v.reg_no, v.make, v.model, COUNT(DISTINCT t.id) trips, COALESCE(SUM(t.odometer_in - t.odometer_out), 0) km,
                        (SELECT COALESCE(SUM(litres), 0) FROM {vehicle_logs} g WHERE g.tenant_id = {tid} AND g.vehicle_id = v.id AND g.kind = 'fuel' AND g.on_date BETWEEN ? AND ?) litres,
                        (SELECT COALESCE(SUM(cost), 0) FROM {vehicle_logs} g WHERE g.tenant_id = {tid} AND g.vehicle_id = v.id AND g.on_date BETWEEN ? AND ?) running_cost
                    FROM {vehicles} v LEFT JOIN {trips} t ON t.vehicle_id = v.id AND t.status = 'completed' AND DATE(t.returned_at) BETWEEN ? AND ?
                   WHERE v.tenant_id = {tid} GROUP BY v.id ORDER BY v.reg_no", [$from, $to, $from, $to, $from, $to]);
                foreach ($rows as &$r) {
                    $r['km_per_litre'] = (float) $r['litres'] > 0 ? round((float) $r['km'] / (float) $r['litres'], 2) : null;
                }
                return ['columns' => ['reg_no' => 'Vehicle', 'make' => 'Make', 'model' => 'Model', 'trips' => 'Trips', 'km' => 'Km', 'litres' => 'Fuel (litres)', 'km_per_litre' => 'Km per litre', 'running_cost' => 'Fuel and repairs'],
                    'rows' => $rows, 'num' => ['trips', 'km', 'litres', 'km_per_litre', 'running_cost'], 'total' => ['running_cost']];
        }
        return ['columns' => [], 'rows' => [], 'num' => []];
    }
}
