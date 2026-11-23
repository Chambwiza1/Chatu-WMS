<?php

namespace App\Libraries\Stock;

use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items;

/**
 * The one place where stock changes. Three operations: add (inflow), remove (outflow) and move
 * (bin and/or status change inside a warehouse). Each writes the stock row, an append-only movement,
 * and keeps the FIFO cost layers equal to the stock on hand.
 *
 * Lock order, everywhere: document row, then stock_locks rows of the items involved (sorted by
 * warehouse and item), then stock rows, then cost layers. Callers run inside Db::atomic().
 */
final class Ledger
{
    public const EPS = 0.0005;
    public const STATUSES = ['available', 'quarantine', 'damaged', 'hold'];

    public static function q($v): float
    {
        return round((float) $v, 3);
    }

    /** Lock (warehouse, item) pairs in a fixed order. $pairs: list of [warehouse_id, item_id]. */
    public static function lock(array $pairs): void
    {
        if (! Db::inTransaction()) {
            throw new \LogicException('Stock changes must run inside a transaction.');
        }
        $keys = [];
        foreach ($pairs as [$w, $i]) {
            $keys[(int) $w . ':' . (int) $i] = [(int) $w, (int) $i];
        }
        uksort($keys, static function ($a, $b) {
            [$wa, $ia] = array_map('intval', explode(':', $a));
            [$wb, $ib] = array_map('intval', explode(':', $b));
            return [$wa, $ia] <=> [$wb, $ib];
        });
        foreach ($keys as [$w, $i]) {
            Db::exec('INSERT IGNORE INTO {stock_locks} (warehouse_id, item_id) VALUES (?, ?)', [$w, $i]);
            Db::row('SELECT warehouse_id FROM {stock_locks} WHERE warehouse_id = ? AND item_id = ? FOR UPDATE', [$w, $i]);
        }
    }

    public static function row(int $stockId, bool $forUpdate = true): array
    {
        $r = Db::row('SELECT * FROM {stock} WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$stockId]);
        if (! $r) {
            throw new BizException('That stock is no longer there (it was moved or used). Refresh and try again.');
        }
        return $r;
    }

    public static function bin(int $binId): array
    {
        $b = Db::row('SELECT * FROM {bins} WHERE id = ?', [$binId]);
        if (! $b) {
            throw new BizException('Unknown bin.');
        }
        return $b;
    }

    /**
     * Inflow. $p: warehouse_id, bin_id, item_id, qty, type, [lot_id, serial_id, status, doc_type, doc_id, doc_line_id,
     * reason_code, note, at] and the cost: unit_cost (a new layer) or layers [[qty, unit_cost, received_at], ...].
     * Returns the movement id.
     */
    public static function add(array $p): int
    {
        $wh   = (int) $p['warehouse_id'];
        $item = Items::get((int) $p['item_id']);
        $qty  = self::q($p['qty']);
        if ($qty <= self::EPS) {
            throw new BizException('Quantity must be more than zero.');
        }
        Items::checkQty($item, $qty);
        $bin = self::bin((int) $p['bin_id']);
        if ((int) $bin['warehouse_id'] !== $wh) {
            throw new BizException('Bin ' . $bin['code'] . ' is in another warehouse.');
        }
        if ($bin['status'] !== 'active' && empty($p['allow_blocked'])) {
            throw new BizException('Bin ' . $bin['code'] . ' is ' . $bin['status'] . '; stock cannot be put there.');
        }
        $status = $p['status'] ?? 'available';
        if (! in_array($status, self::STATUSES, true)) {
            throw new BizException('Unknown stock status.');
        }
        $lotId    = isset($p['lot_id']) && $p['lot_id'] ? (int) $p['lot_id'] : null;
        $serialId = isset($p['serial_id']) && $p['serial_id'] ? (int) $p['serial_id'] : null;
        Items::checkIdentity($item, $lotId, $serialId);
        if ($serialId && abs($qty - 1) > self::EPS) {
            throw new BizException('A serial number is one unit.');
        }
        $now = Db::now();
        Db::exec(
            'INSERT INTO {stock} (warehouse_id, bin_id, item_id, lot_id, serial_id, status, qty, qty_allocated, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = VALUES(updated_at)',
            [$wh, $bin['id'], $item['id'], $lotId, $serialId, $status, $qty, $now]
        );
        if ($serialId) {
            Db::exec("UPDATE {serials} SET status = 'in_stock', warehouse_id = ?, customer_id = NULL, last_doc = ? WHERE id = ?", [$wh, self::docRef($p), $serialId]);
        }
        // cost layers
        $layers = [];
        if (isset($p['layers'])) {
            $sum = 0.0;
            foreach ($p['layers'] as [$lq, $uc, $ra]) {
                $sum += $lq;
                $layers[] = [self::q($lq), round((float) $uc, 4), $ra ?? $now];
            }
            if (abs(self::q($sum) - $qty) > self::EPS) {
                throw new \LogicException('Cost layers do not match the quantity.');
            }
        } else {
            if (! array_key_exists('unit_cost', $p)) {
                throw new \LogicException('An inflow needs a cost.');
            }
            $layers[] = [$qty, max(0, round((float) $p['unit_cost'], 4)), $now];
        }
        $value = 0.0;
        foreach ($layers as [$lq, $uc, $ra]) {
            if ($lq <= self::EPS) {
                continue;
            }
            Db::insert('cost_layers', [
                'warehouse_id' => $wh, 'item_id' => $item['id'], 'lot_id' => $lotId, 'received_at' => $ra,
                'source_type' => $p['doc_type'] ?? $p['type'], 'source_id' => $p['doc_id'] ?? null,
                'qty_in' => $lq, 'qty_left' => $lq, 'unit_cost' => $uc,
            ]);
            $value += $lq * $uc;
        }
        return self::movement($p + ['qty' => $qty], null, (int) $bin['id'], null, $status, $lotId, $serialId, round($value, 2));
    }

    /**
     * Outflow from one stock row. $p: stock_id, qty, type, [allocation_id (consume that reservation), serial_status,
     * customer_id, doc_type, doc_id, doc_line_id, reason_code, note].
     * Returns ['movement_id' => int, 'value' => float, 'layers' => [[qty, unit_cost, received_at], ...]].
     */
    public static function remove(array $p): array
    {
        $row = self::row((int) $p['stock_id']);
        $qty = self::q($p['qty']);
        if ($qty <= self::EPS) {
            throw new BizException('Quantity must be more than zero.');
        }
        $own = 0.0;
        if (! empty($p['allocation_id'])) {
            $own = self::takeAllocation((int) $p['allocation_id'], (int) $row['id'], $qty);
        }
        $free = self::q($row['qty'] - $row['qty_allocated'] + $own);
        if ($qty - $free > self::EPS) {
            $item = Items::get((int) $row['item_id']);
            throw new BizException('Only ' . Items::fmt($item, $free) . ' of ' . $item['sku'] . ' is free in that place (' . Items::fmt($item, $row['qty_allocated'] - $own) . ' reserved). Nothing was saved.');
        }
        self::changeRow($row, -$qty, -$own);
        if ($row['serial_id']) {
            Db::exec('UPDATE {serials} SET status = ?, warehouse_id = NULL, customer_id = ?, last_doc = ? WHERE id = ?', [$p['serial_status'] ?? 'dispatched', $p['customer_id'] ?? null, self::docRef($p), $row['serial_id']]);
        }
        $mid    = self::movement($p + ['warehouse_id' => $row['warehouse_id'], 'item_id' => $row['item_id']], (int) $row['bin_id'], null, $row['status'], null, $row['lot_id'] ? (int) $row['lot_id'] : null, $row['serial_id'] ? (int) $row['serial_id'] : null, null);
        $layers = Valuation::consume((int) $row['warehouse_id'], (int) $row['item_id'], $qty, $p['doc_type'] ?? $p['type'], $p['doc_id'] ?? null, $mid, $p['prefer_layer'] ?? null);
        $value  = Valuation::valueOf($layers);
        Db::exec('UPDATE {movements} SET value = ? WHERE id = ?', [$value, $mid]);
        return ['movement_id' => $mid, 'value' => $value, 'layers' => $layers];
    }

    /**
     * Move inside a warehouse: another bin, another status, or both. $p: stock_id, qty, type, [to_bin_id, to_status,
     * allocation_id (the reservation travels with the units: picking), doc_type, doc_id, doc_line_id, reason_code, note].
     * Returns ['movement_id' => int, 'stock_id' => destination row id].
     */
    public static function move(array $p): array
    {
        $row  = self::row((int) $p['stock_id']);
        $qty  = self::q($p['qty']);
        $toB  = isset($p['to_bin_id']) && $p['to_bin_id'] ? (int) $p['to_bin_id'] : (int) $row['bin_id'];
        $toS  = $p['to_status'] ?? $row['status'];
        if ($qty <= self::EPS) {
            throw new BizException('Quantity must be more than zero.');
        }
        if (! in_array($toS, self::STATUSES, true)) {
            throw new BizException('Unknown stock status.');
        }
        if ($toB === (int) $row['bin_id'] && $toS === $row['status']) {
            throw new BizException('Choose another bin or another status.');
        }
        $bin = self::bin($toB);
        if ((int) $bin['warehouse_id'] !== (int) $row['warehouse_id']) {
            throw new BizException('Bin ' . $bin['code'] . ' is in another warehouse. Use a transfer.');
        }
        if ($bin['status'] !== 'active') {
            throw new BizException('Bin ' . $bin['code'] . ' is ' . $bin['status'] . '; stock cannot be put there.');
        }
        $carry = 0.0;
        $allocId = ! empty($p['allocation_id']) ? (int) $p['allocation_id'] : null;
        if ($allocId) {
            $a = Db::row("SELECT * FROM {allocations} WHERE id = ? AND status = 'active' FOR UPDATE", [$allocId]);
            if (! $a || (int) $a['stock_id'] !== (int) $row['id'] || $qty - (float) $a['qty'] > self::EPS) {
                throw new BizException('That reservation does not cover this stock.');
            }
            $carry = $qty;
        }
        $free = self::q($row['qty'] - $row['qty_allocated'] + $carry);
        if ($qty - $free > self::EPS) {
            $item = Items::get((int) $row['item_id']);
            throw new BizException('Only ' . Items::fmt($item, $free) . ' of ' . $item['sku'] . ' can be moved from there (the rest is reserved).');
        }
        self::changeRow($row, -$qty, -$carry);
        Db::exec(
            'INSERT INTO {stock} (warehouse_id, bin_id, item_id, lot_id, serial_id, status, qty, qty_allocated, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), qty_allocated = qty_allocated + VALUES(qty_allocated), updated_at = VALUES(updated_at)',
            [$row['warehouse_id'], $toB, $row['item_id'], $row['lot_id'], $row['serial_id'], $toS, $qty, $carry, Db::now()]
        );
        $dest = (int) Db::value('SELECT id FROM {stock} WHERE bin_id = ? AND item_id = ? AND lot_key = ? AND serial_key = ? AND status = ?', [$toB, $row['item_id'], (int) $row['lot_id'], (int) $row['serial_id'], $toS]);
        if ($allocId) {
            $a = Db::row('SELECT * FROM {allocations} WHERE id = ?', [$allocId]);
            if ((float) $a['qty'] - $qty > self::EPS) { // part of the reservation moves: split it
                Db::exec('UPDATE {allocations} SET qty = qty - ? WHERE id = ?', [$qty, $allocId]);
                $allocId = Db::insert('allocations', ['stock_id' => $dest, 'doc_type' => $a['doc_type'], 'doc_id' => $a['doc_id'], 'doc_line_id' => $a['doc_line_id'], 'qty' => $qty, 'stage' => $p['stage'] ?? 'picked', 'status' => 'active', 'created_at' => Db::now()]);
            } else {
                Db::exec('UPDATE {allocations} SET stock_id = ?, stage = ? WHERE id = ?', [$dest, $p['stage'] ?? 'picked', $allocId]);
            }
        }
        $mid = self::movement($p + ['warehouse_id' => $row['warehouse_id'], 'item_id' => $row['item_id']], (int) $row['bin_id'], $toB, $row['status'], $toS, $row['lot_id'] ? (int) $row['lot_id'] : null, $row['serial_id'] ? (int) $row['serial_id'] : null, null);
        return ['movement_id' => $mid, 'stock_id' => $dest, 'allocation_id' => $allocId];
    }

    /** Apply a quantity change to a locked row; deletes it when nothing is left. */
    private static function changeRow(array $row, float $dQty, float $dAlloc): void
    {
        $newQty   = self::q($row['qty'] + $dQty);
        $newAlloc = self::q($row['qty_allocated'] + $dAlloc);
        if ($newQty < -self::EPS || $newAlloc < -self::EPS || $newAlloc - $newQty > self::EPS) {
            throw new \LogicException('Stock row ' . $row['id'] . ' would become inconsistent.');
        }
        if ($newQty <= self::EPS && $newAlloc <= self::EPS) {
            Db::exec('DELETE FROM {stock} WHERE id = ?', [$row['id']]);
        } else {
            Db::exec('UPDATE {stock} SET qty = ?, qty_allocated = ?, updated_at = ? WHERE id = ?', [max(0, $newQty), max(0, $newAlloc), Db::now(), $row['id']]);
        }
    }

    /** Consume (part of) an allocation on a stock row; returns the reserved quantity used. */
    private static function takeAllocation(int $allocId, int $stockId, float $qty): float
    {
        $a = Db::row("SELECT * FROM {allocations} WHERE id = ? AND status = 'active' FOR UPDATE", [$allocId]);
        if (! $a || (int) $a['stock_id'] !== $stockId) {
            throw new BizException('That reservation does not cover this stock.');
        }
        $use = min($qty, (float) $a['qty']);
        if ((float) $a['qty'] - $use <= self::EPS) {
            Db::exec("UPDATE {allocations} SET status = 'consumed' WHERE id = ?", [$allocId]);
        } else {
            Db::exec('UPDATE {allocations} SET qty = qty - ? WHERE id = ?', [$use, $allocId]);
        }
        return self::q($use);
    }

    private static function movement(array $p, ?int $fromBin, ?int $toBin, ?string $fromStatus, ?string $toStatus, ?int $lotId, ?int $serialId, ?float $value): int
    {
        return Db::insert('movements', [
            'at'           => $p['at'] ?? Db::now(),
            'warehouse_id' => (int) $p['warehouse_id'],
            'item_id'      => (int) $p['item_id'],
            'lot_id'       => $lotId,
            'serial_id'    => $serialId,
            'qty'          => self::q($p['qty']),
            'from_bin_id'  => $fromBin,
            'to_bin_id'    => $toBin,
            'from_status'  => $fromStatus,
            'to_status'    => $toStatus,
            'type'         => $p['type'],
            'doc_type'     => $p['doc_type'] ?? null,
            'doc_id'       => $p['doc_id'] ?? null,
            'doc_line_id'  => $p['doc_line_id'] ?? null,
            'value'        => $value,
            'reason_code'  => $p['reason_code'] ?? null,
            'note'         => isset($p['note']) ? mb_substr((string) $p['note'], 0, 255) : null,
            'user_id'      => Auth::id(),
        ]);
    }

    private static function docRef(array $p): ?string
    {
        return isset($p['doc_type']) ? $p['doc_type'] . ':' . ($p['doc_id'] ?? '') : $p['type'];
    }
}
