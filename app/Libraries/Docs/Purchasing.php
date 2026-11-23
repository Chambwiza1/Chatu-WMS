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

/** Purchase orders: draft, issued (open), partly received, received, closed or cancelled. */
final class Purchasing
{
    public static function get(int $id, bool $lock = false): array
    {
        $po = Db::row('SELECT * FROM {purchase_orders} WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $po) {
            throw new BizException('Unknown purchase order.');
        }
        return $po;
    }

    public static function lines(int $id): array
    {
        return Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, i.tracking, i.expiry_required FROM {po_lines} l JOIN {items} i ON i.id = l.item_id WHERE l.po_id = ? ORDER BY l.id', [$id]);
    }

    /** Create or replace a draft. $d: supplier_id, warehouse_id, order_date, expected_date, supplier_ref, notes, lines [[item_id, qty, unit_cost]]. */
    public static function saveDraft(array $d, ?int $id = null): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'purchase.manage');
        $sup = (int) ($d['supplier_id'] ?? 0);
        if (! Db::value('SELECT 1 FROM {suppliers} WHERE id = ? AND active = 1', [$sup])) {
            throw new BizException('Choose an active supplier.');
        }
        $lines = self::cleanLines($d['lines'] ?? []);
        [$cur, $rate, $landed] = \App\Libraries\Master\Fx::clean($d['currency'] ?? '', $d['fx_rate'] ?? 1, $d['landed_pct'] ?? 0);
        return Db::atomic(static function () use ($d, $id, $wh, $sup, $lines, $cur, $rate, $landed) {
            $head = [
                'supplier_id' => $sup, 'warehouse_id' => $wh, 'order_date' => Lots::date($d['order_date'] ?? '') ?? date('Y-m-d'),
                'expected_date' => Lots::date($d['expected_date'] ?? ''), 'supplier_ref' => trim((string) ($d['supplier_ref'] ?? '')) ?: null,
                'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'currency' => $cur, 'fx_rate' => $rate, 'landed_pct' => $landed,
            ];
            if ($id) {
                $po = self::get($id, true);
                if ($po['status'] !== 'draft') {
                    throw new BizException('Only a draft can be changed. This order is ' . $po['status'] . '.');
                }
                Access::requireAt((int) $po['warehouse_id'], 'purchase.manage');
                Db::exec('UPDATE {purchase_orders} SET supplier_id = ?, warehouse_id = ?, order_date = ?, expected_date = ?, supplier_ref = ?, notes = ?, currency = ?, fx_rate = ?, landed_pct = ? WHERE id = ?', array_merge(array_values($head), [$id]));
                Db::exec('DELETE FROM {po_lines} WHERE po_id = ?', [$id]);
            } else {
                $id = Db::insert('purchase_orders', $head + ['number' => Numbers::next('po'), 'status' => 'draft', 'created_by' => Auth::id(), 'created_at' => Db::now()]);
            }
            foreach ($lines as [$item, $qty, $cost, $pu, $pf]) {
                Db::insert('po_lines', ['po_id' => $id, 'item_id' => $item, 'qty_ordered' => $qty, 'unit_cost' => $cost, 'pack_unit' => $pu, 'pack_factor' => $pf]);
            }
            Audit::log('po.save', 'purchase_order', $id, null, $head + ['lines' => count($lines)]);
            return $id;
        });
    }

    private static function cleanLines(array $raw): array
    {
        $out = [];
        foreach ($raw as $l) {
            $itemId = (int) ($l['item_id'] ?? 0);
            $qty    = Ledger::q($l['qty'] ?? 0);
            if (! $itemId && $qty <= 0) {
                continue;
            }
            $item = Items::get($itemId);
            if ($qty <= 0) {
                throw new BizException($item['sku'] . ': give a quantity.');
            }
            Items::checkQty($item, $qty);
            $cost = round((float) str_replace(',', '', (string) ($l['unit_cost'] ?? 0)), 4);
            if ($cost < 0) {
                throw new BizException('Costs cannot be negative.');
            }
            $out[] = [$itemId, $qty, $cost, $l['pack_unit'] ?? null, $l['pack_factor'] ?? null];
        }
        if (! $out) {
            throw new BizException('Add at least one line.');
        }
        return $out;
    }

    public static function issue(int $id): void
    {
        Db::atomic(static function () use ($id) {
            $po = self::get($id, true);
            Access::requireAt((int) $po['warehouse_id'], 'purchase.manage');
            if ($po['status'] !== 'draft') {
                throw new BizException('Only a draft can be issued.');
            }
            Db::exec("UPDATE {purchase_orders} SET status = 'open', issued_by = ?, issued_at = ? WHERE id = ?", [Auth::id(), Db::now(), $id]);
            Audit::log('po.issue', 'purchase_order', $id);
        });
    }

    /** Cancel an order that has received nothing, or close one that is partly received (the rest will not come). */
    public static function close(int $id, string $why): void
    {
        Db::atomic(static function () use ($id, $why) {
            $po = self::get($id, true);
            Access::requireAt((int) $po['warehouse_id'], 'purchase.manage');
            if (! in_array($po['status'], ['draft', 'open', 'partly_received'], true)) {
                throw new BizException('This order is already ' . $po['status'] . '.');
            }
            $to = $po['status'] === 'partly_received' ? 'closed' : 'cancelled';
            Db::exec('UPDATE {purchase_orders} SET status = ?, notes = CONCAT(COALESCE(notes, \'\'), ?) WHERE id = ?', [$to, "\n" . ucfirst($to) . ': ' . mb_substr($why, 0, 200), $id]);
            Audit::log('po.' . $to, 'purchase_order', $id, ['status' => $po['status']], ['status' => $to], 'success', $why);
        });
    }

    /** Called by receipts: add received quantities and move the order on. */
    public static function received(int $poId): void
    {
        $open = (float) Db::value('SELECT COALESCE(SUM(GREATEST(qty_ordered - qty_received, 0)), 0) FROM {po_lines} WHERE po_id = ?', [$poId]);
        $any  = (float) Db::value('SELECT COALESCE(SUM(qty_received), 0) FROM {po_lines} WHERE po_id = ?', [$poId]);
        $st   = $open <= Ledger::EPS ? 'received' : ($any > Ledger::EPS ? 'partly_received' : 'open');
        Db::exec('UPDATE {purchase_orders} SET status = ? WHERE id = ?', [$st, $poId]);
    }
}
