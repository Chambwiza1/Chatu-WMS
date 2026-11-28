<?php

namespace App\Libraries\Reports;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;

/**
 * The dashboard (core 1.6, C12): how the warehouses are doing over a period, for the person's own warehouses only.
 * Money (stock value, losses) only for people who may see costs (stock.view_cost), as everywhere else.
 */
final class Overview
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    public static function build(int $days = 30, int $whId = 0): array
    {
        Access::require('stock.view');
        $days = isset(self::PERIODS[$days]) ? $days : 30;
        $mine = Access::warehouses('stock.view');
        $ids  = $whId && in_array($whId, $mine, true) ? [$whId] : $mine;
        $w    = $ids ? implode(',', array_map('intval', $ids)) : '0';
        $from = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $cost = Access::can('stock.view_cost');

        // goods in and out per day: documents posted
        $per = [];
        for ($d = $days - 1; $d >= 0; $d--) {
            $per[gmdate('Y-m-d', strtotime("-$d days"))] = ['in' => 0, 'out' => 0];
        }
        foreach (Db::rows("SELECT DATE(posted_at) d, COUNT(*) n FROM {receipts} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND posted_at >= ? GROUP BY DATE(posted_at)", [$from]) as $r) {
            if (isset($per[$r['d']])) {
                $per[$r['d']]['in'] = (int) $r['n'];
            }
        }
        foreach (Db::rows("SELECT DATE(dispatched_at) d, COUNT(*) n FROM {shipments} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND dispatched_at >= ? GROUP BY DATE(dispatched_at)", [$from]) as $r) {
            if (isset($per[$r['d']])) {
                $per[$r['d']]['out'] = (int) $r['n'];
            }
        }

        $orders = Db::row("SELECT COUNT(*) n, SUM(so.required_date IS NULL OR DATE(s.dispatched_at) <= so.required_date) on_time, AVG(TIMESTAMPDIFF(HOUR, so.created_at, s.dispatched_at)) hours
                             FROM {shipments} s JOIN {sales_orders} so ON so.tenant_id = s.tenant_id AND so.id = s.so_id
                            WHERE s.tenant_id = {tid} AND s.warehouse_id IN ($w) AND s.dispatched_at >= ?", [$from]);
        $counted = Db::row("SELECT COUNT(*) n, SUM(cl.counted_qty = cl.expected_qty) exact FROM {count_lines} cl JOIN {counts} c ON c.tenant_id = cl.tenant_id AND c.id = cl.count_id
                             WHERE cl.tenant_id = {tid} AND c.warehouse_id IN ($w) AND c.status = 'posted' AND c.posted_at >= ? AND cl.counted_qty IS NOT NULL", [$from]);
        $top = Db::rows("SELECT i.id, i.sku, i.name, i.uom, SUM(m.qty) qty FROM {movements} m JOIN {items} i ON i.tenant_id = m.tenant_id AND i.id = m.item_id
                          WHERE m.tenant_id = {tid} AND m.warehouse_id IN ($w) AND m.type = 'dispatch' AND m.at >= ? GROUP BY i.id ORDER BY qty DESC LIMIT 8", [$from]);
        $out = [
            'days' => $days, 'from' => substr($from, 0, 10), 'per' => $per, 'top' => $top, 'cost' => $cost,
            'shipped' => (int) $orders['n'], 'on_time' => (int) $orders['n'] ? round(100 * (int) $orders['on_time'] / (int) $orders['n']) : null,
            'lead_days' => $orders['hours'] !== null ? round((float) $orders['hours'] / 24, 1) : null,
            'received' => (int) Db::value("SELECT COUNT(*) FROM {receipts} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND posted_at >= ?", [$from]),
            'accuracy' => (int) $counted['n'] ? round(100 * (int) $counted['exact'] / (int) $counted['n']) : null, 'count_lines' => (int) $counted['n'],
            'in_transit' => (int) Db::value("SELECT COUNT(*) FROM {transfers} WHERE tenant_id = {tid} AND status = 'in_transit' AND (to_warehouse_id IN ($w) OR from_warehouse_id IN ($w))"),
            'expiring' => (int) Db::value("SELECT COUNT(DISTINCT l.id) FROM {lots} l JOIN {stock} s ON s.tenant_id = l.tenant_id AND s.lot_id = l.id AND s.qty > 0
                                            WHERE l.tenant_id = {tid} AND s.warehouse_id IN ($w) AND l.expiry_date IS NOT NULL AND l.expiry_date <= ?", [gmdate('Y-m-d', strtotime('+30 days'))]),
            'below' => (int) Db::value("SELECT COUNT(*) FROM (SELECT i.id FROM {items} i LEFT JOIN {stock} s ON s.tenant_id = i.tenant_id AND s.item_id = i.id AND s.warehouse_id IN ($w) AND s.status = 'available'
                                         WHERE i.tenant_id = {tid} AND i.active = 1 AND i.reorder_level > 0 GROUP BY i.id HAVING COALESCE(SUM(s.qty), 0) < MAX(i.reorder_level)) x"),
            'value' => null, 'byWh' => [], 'losses' => null,
        ];
        if ($cost) {
            $out['byWh']   = Db::rows("SELECT w.id, w.code, w.name, COALESCE(SUM(c.qty_left * c.unit_cost), 0) value FROM {warehouses} w LEFT JOIN {cost_layers} c ON c.tenant_id = w.tenant_id AND c.warehouse_id = w.id AND c.qty_left > 0
                                         WHERE w.tenant_id = {tid} AND w.id IN ($w) GROUP BY w.id ORDER BY value DESC");
            $out['value']  = array_sum(array_map(static fn ($r) => (float) $r['value'], $out['byWh']));
            $out['losses'] = -1 * (float) Db::value("SELECT COALESCE(SUM(LEAST(value, 0)), 0) FROM {adjustments} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND status = 'posted' AND decided_at >= ?", [$from])
                + (float) Db::value("SELECT COALESCE(SUM(-LEAST(value, 0)), 0) FROM {counts} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND status = 'posted' AND posted_at >= ?", [$from]);
        }
        return $out;
    }
}
