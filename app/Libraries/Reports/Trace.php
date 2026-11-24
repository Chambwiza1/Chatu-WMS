<?php

namespace App\Libraries\Reports;

use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;

/**
 * Batch and serial traceability for recalls.
 * Backward: where a batch came from (supplier receipt, or the production run and its raw-material batches).
 * Forward: where it went (production runs that used it, the batches they made, and every customer who received
 * the batch or anything made from it), and where it still is.
 */
final class Trace
{
    public static function lot(int $lotId): array
    {
        $lot = Db::row('SELECT l.*, i.sku, i.name item_name, i.uom, i.decimals, s.name supplier FROM {lots} l JOIN {items} i ON i.id = l.item_id LEFT JOIN {suppliers} s ON s.id = l.supplier_id WHERE l.tenant_id = {tid} AND l.id = ?', [$lotId]);
        if (! $lot) {
            throw new \App\Libraries\Core\NotFoundException('Batch not found.');
        }
        $out = ['lot' => $lot];
        $out['received'] = Db::rows('SELECT r.id, r.number, r.received_on, r.kind, r.warehouse_id, w.code wh, s.name supplier, rl.qty_accepted, rl.qty_quarantine, rl.qty_rejected FROM {receipt_lines} rl
                                       JOIN {receipts} r ON r.id = rl.receipt_id JOIN {warehouses} w ON w.id = r.warehouse_id LEFT JOIN {suppliers} s ON s.id = r.supplier_id WHERE rl.tenant_id = {tid} AND rl.lot_id = ? ORDER BY r.id', [$lotId]);
        $out['made_by'] = Db::rows("SELECT p.id, p.number, p.produced_on, p.status, p.actual_qty, p.warehouse_id, w.code wh FROM {production_runs} p JOIN {warehouses} w ON w.id = p.warehouse_id WHERE p.tenant_id = {tid} AND p.lot_id = ?", [$lotId]);
        $out['made_from'] = Db::rows("SELECT DISTINCT l.id, l.lot_no, i.sku, i.name, l.expiry_date, s.name supplier FROM {production_runs} p JOIN {production_inputs} pi ON pi.run_id = p.id
                                        JOIN {lots} l ON l.id = pi.lot_id JOIN {items} i ON i.id = l.item_id LEFT JOIN {suppliers} s ON s.id = l.supplier_id
                                       WHERE p.tenant_id = {tid} AND p.lot_id = ? AND p.status = 'posted' AND pi.qty > 0 ORDER BY i.sku, l.lot_no", [$lotId]);
        $out['used_in'] = Db::rows("SELECT DISTINCT o.id, o.lot_no, i.sku, i.name, p.number run, p.id run_id, p.warehouse_id FROM {production_inputs} pi JOIN {production_runs} p ON p.id = pi.run_id AND p.status = 'posted'
                                      JOIN {lots} o ON o.id = p.lot_id JOIN {items} i ON i.id = o.item_id WHERE pi.tenant_id = {tid} AND pi.lot_id = ? AND pi.qty > 0 ORDER BY o.lot_no", [$lotId]);
        // customers: this batch, and everything made from it (up to five levels down)
        $lots = [$lotId];
        $front = [$lotId];
        for ($depth = 0; $depth < 5 && $front; $depth++) {
            $in    = implode(',', array_map('intval', $front));
            $front = array_values(array_diff(array_map('intval', array_column(Db::rows("SELECT DISTINCT p.lot_id FROM {production_inputs} pi JOIN {production_runs} p ON p.id = pi.run_id AND p.status = 'posted' WHERE pi.tenant_id = {tid} AND pi.lot_id IN ($in) AND p.lot_id IS NOT NULL AND pi.qty > 0"), 'lot_id')), $lots));
            $lots  = array_merge($lots, $front);
        }
        $in = implode(',', array_map('intval', $lots));
        $out['customers'] = Db::rows("SELECT c.name customer, c.phone, c.town, so.number order_no, sh.id shipment_id, sh.warehouse_id, sh.number shipment, sh.dispatched_at, sh.status, l.lot_no, i.sku, SUM(u.qty - u.qty_returned) qty
                                        FROM {shipment_units} u JOIN {shipments} sh ON sh.id = u.shipment_id JOIN {sales_orders} so ON so.id = sh.so_id JOIN {customers} c ON c.id = so.customer_id
                                        JOIN {lots} l ON l.id = u.lot_id JOIN {items} i ON i.id = u.item_id
                                       WHERE u.tenant_id = {tid} AND u.lot_id IN ($in) AND sh.status IN ('dispatched','delivered') GROUP BY c.id, sh.id, l.id HAVING qty > 0 ORDER BY sh.dispatched_at");
        $out['in_stock'] = Db::rows("SELECT w.code wh, b.code bin, s.status, SUM(s.qty) qty, SUM(s.qty_allocated) reserved, l.lot_no, i.sku FROM {stock} s JOIN {warehouses} w ON w.id = s.warehouse_id
                                       JOIN {bins} b ON b.id = s.bin_id JOIN {lots} l ON l.id = s.lot_id JOIN {items} i ON i.id = s.item_id WHERE s.tenant_id = {tid} AND s.lot_id IN ($in) GROUP BY s.warehouse_id, s.bin_id, s.lot_id, s.status ORDER BY w.code, b.code");
        $out['in_transit'] = Db::rows("SELECT t.number, t.id, t.from_warehouse_id, t.to_warehouse_id, SUM(x.qty_open) qty, l.lot_no FROM {transit} x JOIN {transfers} t ON t.id = x.transfer_id JOIN {lots} l ON l.id = x.lot_id WHERE x.tenant_id = {tid} AND x.lot_id IN ($in) AND x.qty_open > 0 GROUP BY t.id, l.id");
        return $out;
    }

    public static function serial(int $serialId): array
    {
        $s = Db::row('SELECT se.*, i.sku, i.name item_name, l.lot_no, c.name customer FROM {serials} se JOIN {items} i ON i.id = se.item_id LEFT JOIN {lots} l ON l.id = se.lot_id LEFT JOIN {customers} c ON c.id = se.customer_id WHERE se.tenant_id = {tid} AND se.id = ?', [$serialId]);
        if (! $s) {
            throw new \App\Libraries\Core\NotFoundException('Serial number not found.');
        }
        $s['history'] = Db::rows('SELECT m.*, w.code wh, fb.code from_bin, tb.code to_bin, u.name user FROM {movements} m JOIN {warehouses} w ON w.id = m.warehouse_id
                                    LEFT JOIN {bins} fb ON fb.id = m.from_bin_id LEFT JOIN {bins} tb ON tb.id = m.to_bin_id LEFT JOIN {users} u ON u.id = m.user_id WHERE m.tenant_id = {tid} AND m.serial_id = ? ORDER BY m.id', [$serialId]);
        return $s;
    }
}
