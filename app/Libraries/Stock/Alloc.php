<?php

namespace App\Libraries\Stock;

use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items;
use App\Libraries\Master\Warehouses;

/**
 * Availability and reservations.
 *
 * Free stock = qty − qty_allocated over rows that are: status available, in an active pickable bin,
 * of an active batch (not on hold or recalled), not expired, and with at least the required shelf life left.
 * Picking order follows the item's policy, else the warehouse's: FEFO (earliest expiry first) or FIFO
 * (oldest batch first). Callers lock the item first (Ledger::lock).
 */
final class Alloc
{
    /**
     * Candidate stock rows, locked, in picking order.
     * $o: min_expiry (Y-m-d: expiry must be on or after), lot_id, serial_id, bin_id, include_unpickable
     */
    public static function candidates(int $wh, int $itemId, array $o = []): array
    {
        $item   = Items::get($itemId);
        $policy = $item['pick_policy'] ?: Warehouses::get($wh)['pick_policy'];
        $w = ["s.warehouse_id = ?", "s.item_id = ?", "s.status = 'available'", "s.qty > s.qty_allocated", "b.status = 'active'"];
        $b = [$wh, $itemId];
        if (! empty($o['bin_types'])) { // production draws on PRODUCTION, storage and picking bins
            $w[] = 'b.type IN (' . implode(',', array_fill(0, count($o['bin_types']), '?')) . ')';
            $b   = array_merge($b, $o['bin_types']);
        } elseif (empty($o['include_unpickable'])) {
            $w[] = 'b.pickable = 1';
        }
        $w[] = "(l.id IS NULL OR l.status = 'active')";
        $w[] = '(l.expiry_date IS NULL OR l.expiry_date >= ?)';
        $b[] = $o['min_expiry'] ?? date('Y-m-d');
        $first = ! empty($o['bin_types']) ? "CASE WHEN b.type = 'production' THEN 0 ELSE 1 END, " : '';
        foreach (['lot_id' => 's.lot_id', 'serial_id' => 's.serial_id', 'bin_id' => 's.bin_id'] as $k => $col) {
            if (! empty($o[$k])) {
                $w[] = "$col = ?";
                $b[] = (int) $o[$k];
            }
        }
        $order = $first . ($policy === 'fefo'
            ? 'CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END, l.expiry_date, l.created_at, b.sort, s.id'
            : 'l.created_at, l.id, b.sort, s.id');
        $ids = Db::rows('SELECT s.id FROM {stock} s JOIN {bins} b ON b.id = s.bin_id LEFT JOIN {lots} l ON l.id = s.lot_id WHERE s.tenant_id = {tid} AND ' . implode(' AND ', $w) . " ORDER BY $order", $b);
        $out = [];
        foreach ($ids as $r) { // lock in id order would be stricter; the item lock already serialises writers
            $row = Db::row('SELECT s.*, (s.qty - s.qty_allocated) free FROM {stock} s WHERE s.tenant_id = {tid} AND s.id = ? FOR UPDATE', [$r['id']]);
            if ($row && (float) $row['free'] > Ledger::EPS) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function free(int $wh, int $itemId, array $o = []): float
    {
        $sum = 0.0;
        foreach (self::candidates($wh, $itemId, $o) as $r) {
            $sum += (float) $r['free'];
        }
        return Ledger::q($sum);
    }

    /**
     * Reserve $qty for a document line. Returns the allocations [[allocation_id, stock_id, qty], ...].
     * With $partial false (default), a shortage refuses the whole thing.
     */
    public static function reserve(string $docType, int $docId, int $lineId, int $wh, int $itemId, float $qty, array $o = [], bool $partial = false): array
    {
        $need = Ledger::q($qty);
        $out  = [];
        foreach (self::candidates($wh, $itemId, $o) as $r) {
            if ($need <= Ledger::EPS) {
                break;
            }
            $take = Ledger::q(min($need, (float) $r['free']));
            if ($r['serial_id'] && $take < 1) {
                continue;
            }
            Db::exec('UPDATE {stock} SET qty_allocated = qty_allocated + ? WHERE tenant_id = {tid} AND id = ?', [$take, $r['id']]);
            $aid   = Db::insert('allocations', ['stock_id' => $r['id'], 'doc_type' => $docType, 'doc_id' => $docId, 'doc_line_id' => $lineId, 'qty' => $take, 'stage' => 'reserved', 'status' => 'active', 'created_at' => Db::now()]);
            $out[] = [$aid, (int) $r['id'], $take];
            $need  = Ledger::q($need - $take);
        }
        if ($need > Ledger::EPS && ! $partial) {
            $item = Items::get($itemId);
            throw new BizException('Not enough free ' . $item['sku'] . ' (' . $item['name'] . '): ' . Items::fmt($item, $qty - $need) . ' free, ' . Items::fmt($item, $qty) . ' needed. Nothing was reserved.');
        }
        return $out;
    }

    /** Release active reservations of a document (or one line). Returns the quantity released. */
    public static function release(string $docType, int $docId, ?int $lineId = null): float
    {
        $w = "doc_type = ? AND doc_id = ? AND status = 'active'";
        $b = [$docType, $docId];
        if ($lineId) {
            $w .= ' AND doc_line_id = ?';
            $b[] = $lineId;
        }
        $sum = 0.0;
        foreach (Db::rows("SELECT * FROM {allocations} WHERE tenant_id = {tid} AND $w ORDER BY stock_id FOR UPDATE", $b) as $a) {
            Db::exec('UPDATE {stock} SET qty_allocated = qty_allocated - ? WHERE tenant_id = {tid} AND id = ?', [$a['qty'], $a['stock_id']]);
            Db::exec("UPDATE {allocations} SET status = 'released' WHERE tenant_id = {tid} AND id = ?", [$a['id']]);
            $sum += (float) $a['qty'];
        }
        // a row whose last units were only reserved (qty 0) cannot exist: rows hold qty >= allocated
        return Ledger::q($sum);
    }

    public static function active(string $docType, int $docId, ?int $lineId = null, ?string $stage = null): array
    {
        $w = "a.doc_type = ? AND a.doc_id = ? AND a.status = 'active'";
        $b = [$docType, $docId];
        if ($lineId) {
            $w .= ' AND a.doc_line_id = ?';
            $b[] = $lineId;
        }
        if ($stage) {
            $w .= ' AND a.stage = ?';
            $b[] = $stage;
        }
        return Db::rows("SELECT a.*, s.bin_id, s.lot_id, s.serial_id, s.item_id, s.warehouse_id, s.status stock_status, b.code bin_code, l.lot_no, l.expiry_date, se.serial_no
                           FROM {allocations} a JOIN {stock} s ON s.id = a.stock_id JOIN {bins} b ON b.id = s.bin_id
                           LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id
                          WHERE a.tenant_id = {tid} AND $w ORDER BY b.sort, b.code, a.id", $b);
    }
}
