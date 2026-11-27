<?php

namespace App\Libraries\Reports;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Fleet\Fleet;

/** The home page: what waits for this person, from their duties, plus the figures they may see. */
final class Dashboard
{
    /** List of [count, label, hint, link, tone]. */
    public static function todo(): array
    {
        $uid = Auth::id();
        $out = [];
        $in  = static function (string $perm): string {
            $ids = Access::warehouses($perm);
            return $ids ? implode(',', $ids) : '0';
        };
        if (Access::can('stock.approve')) {
            $w = $in('stock.approve');
            $n = (int) Db::value("SELECT COUNT(*) FROM {adjustments} WHERE tenant_id = {tid} AND status = 'pending' AND warehouse_id IN ($w) AND created_by <> ?", [$uid]);
            $out[] = [$n, 'Adjustments to approve', 'Raised by someone else', '/adjustments?status=pending', $n ? 'warn' : ''];
            $n = (int) Db::value("SELECT COUNT(*) FROM {counts} WHERE tenant_id = {tid} AND status = 'submitted' AND warehouse_id IN ($w) AND submitted_by <> ?", [$uid]);
            $out[] = [$n, 'Counts to approve', 'Variances to post', '/counts?status=submitted', $n ? 'warn' : ''];
            $n = (int) Db::value("SELECT COUNT(DISTINCT s.item_id, s.lot_id) FROM {stock} s WHERE s.tenant_id = {tid} AND s.status = 'quarantine' AND s.warehouse_id IN ($w)");
            $out[] = [$n, 'Held for inspection', 'Release, damage or return', '/stock?status=quarantine', $n ? 'warn' : ''];
        }
        if (Access::can('transfer.receive')) {
            $w = $in('transfer.receive');
            $n = (int) Db::value("SELECT COUNT(*) FROM {transfers} WHERE tenant_id = {tid} AND status IN ('in_transit','partly_received') AND to_warehouse_id IN ($w)", []);
            $out[] = [$n, 'Transfers on their way to you', 'Receive them when they arrive', '/transfers?view=incoming', $n ? 'warn' : ''];
        }
        if (Access::can('inventory.move')) {
            $w = $in('inventory.move');
            $n = (int) Db::value("SELECT COUNT(*) FROM {stock} s JOIN {bins} b ON b.id = s.bin_id WHERE s.tenant_id = {tid} AND b.type = 'receiving' AND s.warehouse_id IN ($w) AND s.qty > s.qty_allocated");
            $out[] = [$n, 'Lines to put away', 'Goods waiting in RECEIVING', '/putaway', $n ? 'warn' : ''];
        }
        if (Access::can('dispatch.manage')) {
            $w = $in('dispatch.manage');
            $n = (int) Db::value("SELECT COUNT(*) FROM {sales_orders} so WHERE so.tenant_id = {tid} AND so.status IN ('confirmed','in_progress') AND so.warehouse_id IN ($w)
                                   AND EXISTS (SELECT 1 FROM {so_lines} l WHERE l.tenant_id = {tid} AND l.so_id = so.id AND l.qty > l.qty_shipped + COALESCE((SELECT SUM(sl.qty) FROM {shipment_lines} sl JOIN {shipments} s ON s.id = sl.shipment_id WHERE sl.tenant_id = {tid} AND sl.so_line_id = l.id AND s.status IN ('allocated','picked','packed')), 0))");
            $out[] = [$n, 'Orders to pick', 'Confirmed, not yet on a shipment', '/orders?status=to_pick', $n ? 'warn' : ''];
            $n = (int) Db::value("SELECT COUNT(*) FROM {shipments} WHERE tenant_id = {tid} AND status IN ('allocated','picked','packed') AND warehouse_id IN ($w)");
            $out[] = [$n, 'Shipments being prepared', 'Pick, pack and dispatch', '/shipments?status=open', $n ? '' : ''];
            $n = (int) Db::value("SELECT COUNT(*) FROM {shipments} WHERE tenant_id = {tid} AND status = 'dispatched' AND warehouse_id IN ($w)");
            $out[] = [$n, 'Deliveries to confirm', 'Record proof of delivery', '/shipments?status=dispatched', $n ? '' : ''];
        }
        if (Access::can('transfer.manage')) {
            $w = $in('transfer.manage');
            $n = (int) Db::value("SELECT COUNT(*) FROM {transfers} WHERE tenant_id = {tid} AND status IN ('draft','allocated') AND from_warehouse_id IN ($w)");
            $out[] = [$n, 'Transfers to send', 'Drafts and allocated', '/transfers?view=outgoing', ''];
        }
        if (Access::can('purchase.manage')) {
            $w = $in('purchase.manage');
            $n = (int) Db::value("SELECT COUNT(*) FROM {purchase_orders} WHERE tenant_id = {tid} AND status IN ('open','partly_received') AND expected_date < CURDATE() AND warehouse_id IN ($w)");
            $out[] = [$n, 'Purchase orders overdue', 'Expected date has passed', '/purchase-orders?status=overdue', $n ? 'warn' : ''];
        }
        if (Access::can('fleet.manage') && \App\Libraries\Platform\Modules::on('fleet')) {
            $exp = Fleet::expiring();
            $bad = count(array_filter($exp, static fn ($e) => $e['overdue']));
            $out[] = [count($exp), 'Fleet papers and services', $bad ? $bad . ' already overdue' : 'Within ' . Settings::get('fleet_warn_days') . ' days', '/fleet', $bad ? 'bad' : (count($exp) ? 'warn' : '')];
            $n = (int) Db::value("SELECT COUNT(*) FROM {trips} WHERE tenant_id = {tid} AND status = 'on_road'");
            $out[] = [$n, 'Trips on the road', 'Close them when the vehicle is back', '/fleet/trips?status=on_road', ''];
        }
        if (Access::can('stock.view')) {
            $w    = $in('stock.view');
            $days = (int) Settings::get('expiry_warn_days');
            $n = (int) Db::value("SELECT COUNT(DISTINCT s.lot_id) FROM {stock} s JOIN {lots} l ON l.id = s.lot_id WHERE s.tenant_id = {tid} AND s.warehouse_id IN ($w) AND l.expiry_date < CURDATE()");
            $out[] = [$n, 'Expired batches in stock', 'Write off or return', '/reports/expiry?days=0', $n ? 'bad' : ''];
            $n = (int) Db::value("SELECT COUNT(DISTINCT s.lot_id) FROM {stock} s JOIN {lots} l ON l.id = s.lot_id WHERE s.tenant_id = {tid} AND s.warehouse_id IN ($w) AND l.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)");
            $out[] = [$n, 'Batches expiring soon', 'Within ' . $days . ' days', '/reports/expiry', $n ? 'warn' : ''];
            $n = (int) Db::value("SELECT COUNT(*) FROM {items} i WHERE i.tenant_id = {tid} AND i.active = 1 AND i.reorder_level > 0 AND COALESCE((SELECT SUM(s.qty - s.qty_allocated) FROM {stock} s WHERE s.tenant_id = {tid} AND s.item_id = i.id AND s.status = 'available' AND s.warehouse_id IN ($w)), 0) <= i.reorder_level");
            $out[] = [$n, 'Items to reorder', 'At or below the reorder level', '/reports/reorder', $n ? 'warn' : ''];
        }
        if (Access::can('admin.settings') || Access::can('stock.approve')) {
            $last = json_decode((string) Settings::get('last_reconcile'), true);
            if ($last && $last['problems'] > 0) {
                $out[] = [(int) $last['problems'], 'Integrity problems', 'Found by the nightly check', '/admin/reconcile', 'bad'];
            }
        }
        return $out;
    }

    /**
     * core 1.3 (E1): what waits for this person, as pop-up alerts. Each is keyed by what it is and how many, so one put
     * aside comes back when the number changes. Only the ones that need doing (warn) or are overdue (bad).
     */
    public static function alerts(array $aside = []): array
    {
        $out = [];
        foreach (self::todo() as [$n, $label, $hint, $link, $tone]) {
            if ($n <= 0 || ! in_array($tone, ['warn', 'bad'], true)) {
                continue;
            }
            $key = 'a-' . substr(md5($label), 0, 10) . '-' . $n;
            if (in_array($key, $aside, true)) {
                continue;
            }
            $out[] = ['key' => $key, 'title' => $n . ' · ' . $label, 'body' => $hint, 'tone' => $tone, 'url' => site_url(ltrim($link, '/'))];
        }
        return $out;
    }

    public static function figures(): ?array
    {
        if (! Access::can('stock.view')) {
            return null;
        }
        $ids = Access::warehouses('stock.view');
        $w   = $ids ? implode(',', $ids) : '0';
        $out = [
            'lines'     => (int) Db::value("SELECT COUNT(DISTINCT item_id) FROM {stock} WHERE tenant_id = {tid} AND warehouse_id IN ($w)"),
            'shipped'   => (int) Db::value("SELECT COUNT(*) FROM {shipments} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND dispatched_at >= ?", [gmdate('Y-m-d 00:00:00', strtotime('-7 days'))]),
            'received'  => (int) Db::value("SELECT COUNT(*) FROM {receipts} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND posted_at >= ?", [gmdate('Y-m-d 00:00:00', strtotime('-7 days'))]),
            'value'     => null,
            'transit'   => null,
            'byWh'      => [],
        ];
        if (Access::can('stock.view_cost')) {
            $out['value']   = (float) Db::value("SELECT COALESCE(SUM(qty_left * unit_cost), 0) FROM {cost_layers} WHERE tenant_id = {tid} AND warehouse_id IN ($w) AND qty_left > 0");
            $out['transit'] = (float) Db::value("SELECT COALESCE(SUM(tc.qty_open * tc.unit_cost), 0) FROM {transit_costs} tc JOIN {transfers} t ON t.id = tc.transfer_id WHERE tc.tenant_id = {tid} AND tc.qty_open > 0 AND (t.from_warehouse_id IN ($w) OR t.to_warehouse_id IN ($w))");
            $out['byWh']    = Db::rows("SELECT w.code, w.name, COALESCE(SUM(c.qty_left * c.unit_cost), 0) value FROM {warehouses} w LEFT JOIN {cost_layers} c ON c.warehouse_id = w.id AND c.qty_left > 0 WHERE w.tenant_id = {tid} AND w.id IN ($w) GROUP BY w.id ORDER BY value DESC");
        }
        return $out;
    }
}
