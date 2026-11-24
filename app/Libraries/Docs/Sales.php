<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items;
use App\Libraries\Master\Numbers;
use App\Libraries\Stock\Ledger;
use App\Libraries\Stock\Lots;
use App\Libraries\Workflow\Notify;

/**
 * Sales orders: draft → confirmed → in progress (shipments being prepared) → dispatched → delivered → closed.
 * Invoicing stays in the accounting system: dispatched and delivered orders export to CSV with prices.
 */
final class Sales
{
    public static function get(int $id, bool $lock = false): array
    {
        $so = Db::row('SELECT * FROM {sales_orders} WHERE tenant_id = {tid} AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $so) {
            throw new BizException('Unknown sales order.');
        }
        return $so;
    }

    public static function lines(int $id): array
    {
        return Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, i.tracking FROM {so_lines} l JOIN {items} i ON i.id = l.item_id WHERE l.tenant_id = {tid} AND l.so_id = ? ORDER BY l.id', [$id]);
    }

    /** $d: customer_id, warehouse_id, order_date, required_date, customer_ref, delivery_address, notes, lines [{item_id, qty, unit_price}]. */
    public static function saveDraft(array $d, ?int $id = null): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'sales.manage');
        $cust = Db::row('SELECT * FROM {customers} WHERE tenant_id = {tid} AND id = ? AND active = 1', [(int) ($d['customer_id'] ?? 0)]);
        if (! $cust) {
            throw new BizException('Choose an active customer.');
        }
        $lines = [];
        foreach ($d['lines'] ?? [] as $l) {
            $q = Ledger::q($l['qty'] ?? 0);
            if (empty($l['item_id']) && $q <= 0) {
                continue;
            }
            $item = Items::get((int) $l['item_id']);
            if ($q <= 0) {
                throw new BizException($item['sku'] . ': give a quantity.');
            }
            Items::checkQty($item, $q);
            $price = ($l['unit_price'] ?? '') === '' ? (float) $item['sell_price'] : round((float) str_replace(',', '', (string) $l['unit_price']), 2);
            $lines[] = [(int) $item['id'], $q, max(0, $price), $l['pack_unit'] ?? null, $l['pack_factor'] ?? null];
        }
        if (! $lines) {
            throw new BizException('Add at least one item.');
        }
        return Db::atomic(static function () use ($d, $id, $wh, $cust, $lines) {
            $head = [
                'customer_id' => (int) $cust['id'], 'warehouse_id' => $wh, 'order_date' => Lots::date($d['order_date'] ?? '') ?? date('Y-m-d'),
                'required_date' => Lots::date($d['required_date'] ?? ''), 'customer_ref' => trim((string) ($d['customer_ref'] ?? '')) ?: null,
                'delivery_address' => trim((string) ($d['delivery_address'] ?? '')) ?: ($cust['address'] ?: null), 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            ];
            if ($id) {
                $so = self::get($id, true);
                if ($so['status'] !== 'draft') {
                    throw new BizException('Only a draft order can be changed.');
                }
                Db::exec('UPDATE {sales_orders} SET ' . implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($head))) . ' WHERE tenant_id = {tid} AND id = ?', array_merge(array_values($head), [$id]));
                Db::exec('DELETE FROM {so_lines} WHERE tenant_id = {tid} AND so_id = ?', [$id]);
            } else {
                $id = Db::insert('sales_orders', $head + ['number' => Numbers::next('sales_order'), 'status' => 'draft', 'created_by' => Auth::id(), 'created_at' => Db::now()]);
            }
            foreach ($lines as [$item, $q, $price, $pu, $pf]) {
                Db::insert('so_lines', ['so_id' => $id, 'item_id' => $item, 'qty' => $q, 'unit_price' => $price, 'pack_unit' => $pu, 'pack_factor' => $pf]);
            }
            Audit::log('so.save', 'sales_order', $id, null, $head + ['lines' => count($lines)]);
            return $id;
        });
    }

    public static function confirm(int $id): void
    {
        Db::atomic(static function () use ($id) {
            $so = self::get($id, true);
            Access::requireAt((int) $so['warehouse_id'], 'sales.manage');
            if ($so['status'] !== 'draft') {
                throw new BizException('Only a draft order can be confirmed.');
            }
            Db::exec("UPDATE {sales_orders} SET status = 'confirmed' WHERE tenant_id = {tid} AND id = ?", [$id]);
            Notify::holders('dispatch.manage', (int) $so['warehouse_id'], 'order_confirmed', 'Order ' . $so['number'] . ' is ready to pick', null, '/orders/' . $id, Auth::id());
            Audit::log('so.confirm', 'sales_order', $id);
        });
    }

    /** Cancel an order (or what is left of it). Shipments not yet dispatched are cancelled with it. */
    public static function cancel(int $id, string $why): void
    {
        Db::atomic(static function () use ($id, $why) {
            $so = self::get($id, true);
            Access::requireAt((int) $so['warehouse_id'], 'sales.manage');
            if (in_array($so['status'], ['delivered', 'closed', 'cancelled'], true)) {
                throw new BizException('This order is already ' . $so['status'] . '.');
            }
            if (trim($why) === '') {
                throw new BizException('Say why the order is cancelled.');
            }
            foreach (Db::rows("SELECT id FROM {shipments} WHERE tenant_id = {tid} AND so_id = ? AND status IN ('allocated','picked','packed')", [$id]) as $s) {
                Shipments::cancel((int) $s['id'], 'Order cancelled: ' . $why, true);
            }
            $shipped = (float) Db::value('SELECT COALESCE(SUM(qty_shipped), 0) FROM {so_lines} WHERE tenant_id = {tid} AND so_id = ?', [$id]);
            $to = $shipped > Ledger::EPS ? 'closed' : 'cancelled';
            Db::exec('UPDATE {sales_orders} SET status = ?, notes = CONCAT(COALESCE(notes, \'\'), ?) WHERE tenant_id = {tid} AND id = ?', [$to, "\n" . ucfirst($to) . ': ' . mb_substr($why, 0, 200), $id]);
            Audit::log('so.' . $to, 'sales_order', $id, ['status' => $so['status']], ['status' => $to], 'success', $why);
        });
    }

    /** Recompute the order's status from its lines and shipments. */
    public static function refresh(int $id): void
    {
        $so = self::get($id);
        if (in_array($so['status'], ['draft', 'cancelled', 'closed'], true)) {
            return;
        }
        $t = Db::row('SELECT SUM(qty) q, SUM(qty_shipped) s, SUM(qty_delivered) d FROM {so_lines} WHERE tenant_id = {tid} AND so_id = ?', [$id]);
        $active = (int) Db::value("SELECT COUNT(*) FROM {shipments} WHERE tenant_id = {tid} AND so_id = ? AND status IN ('allocated','picked','packed','dispatched')", [$id]);
        $q = (float) $t['q'];
        $st = 'confirmed';
        if ((float) $t['d'] - $q > -Ledger::EPS) {
            $st = 'delivered';
        } elseif ((float) $t['s'] - $q > -Ledger::EPS) {
            $st = 'dispatched';
        } elseif ($active || (float) $t['s'] > Ledger::EPS) {
            $st = 'in_progress';
        }
        Db::exec('UPDATE {sales_orders} SET status = ? WHERE tenant_id = {tid} AND id = ?', [$st, $id]);
    }
}
