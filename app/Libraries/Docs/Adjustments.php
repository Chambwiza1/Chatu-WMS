<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Master\Items;
use App\Libraries\Master\Numbers;
use App\Libraries\Stock\Ledger;
use App\Libraries\Stock\Lots;
use App\Libraries\Stock\Valuation;
use App\Libraries\Workflow\Notify;

/**
 * Stock adjustments (write-offs, found stock, corrections). A second person with approval rights must approve,
 * unless the adjustment is worth no more than the automatic limit and the person raising it may approve.
 * Nobody approves their own adjustment. Units being written off are reserved while the adjustment waits.
 */
final class Adjustments
{
    /**
     * $d: warehouse_id, reason_code, notes, lines:
     *   decrease or increase of existing stock: {stock_id, qty (negative = less, positive = more)}
     *   new stock: {item_id, bin_id, qty > 0, status, lot_no, expiry_date, serial_no, unit_cost}
     */
    public static function request(array $d, ?string $token = null): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'adjust.request');
        return (int) Db::atomic(static function () use ($d, $wh, $token) {
            return Db::once($token, Auth::id(), static function () use ($d, $wh) {
                $reason = (string) ($d['reason_code'] ?? '');
                if (! Db::value("SELECT 1 FROM {reasons} WHERE use_for = 'adjust' AND code = ? AND active = 1", [$reason])) {
                    throw new BizException('Choose a reason for the adjustment.');
                }
                if (trim((string) ($d['notes'] ?? '')) === '') {
                    throw new BizException('Explain the adjustment in the notes.');
                }
                $lines = [];
                foreach ($d['lines'] ?? [] as $l) {
                    $q = Ledger::q($l['qty'] ?? 0);
                    if (abs($q) <= Ledger::EPS) {
                        continue;
                    }
                    if (! empty($l['stock_id'])) {
                        $row = Ledger::row((int) $l['stock_id'], false);
                        if ((int) $row['warehouse_id'] !== $wh) {
                            throw new BizException('That stock is in another warehouse.');
                        }
                        $item = Items::get((int) $row['item_id']);
                        if ($row['serial_id'] && $q > 0) {
                            throw new BizException('A serial number is one unit: add a new serial instead.');
                        }
                        $lines[] = ['item' => $item, 'row' => $row, 'qty' => $q, 'bin_id' => (int) $row['bin_id'], 'lot_id' => $row['lot_id'], 'status' => $row['status'], 'serial_id' => $row['serial_id']];
                    } else {
                        if ($q < 0) {
                            throw new BizException('Choose the stock to reduce.');
                        }
                        $item = Items::get((int) ($l['item_id'] ?? 0));
                        $bin  = Ledger::bin((int) ($l['bin_id'] ?? 0));
                        if ((int) $bin['warehouse_id'] !== $wh) {
                            throw new BizException('Choose a bin in this warehouse.');
                        }
                        if (Items::lots($item) && trim((string) ($l['lot_no'] ?? '')) === '') {
                            throw new BizException($item['sku'] . ': give the batch number.');
                        }
                        if (Items::serials($item) && (abs($q - 1) > Ledger::EPS || trim((string) ($l['serial_no'] ?? '')) === '')) {
                            throw new BizException($item['sku'] . ': add serial-numbered units one line per serial number.');
                        }
                        $lines[] = ['item' => $item, 'row' => null, 'qty' => $q, 'bin_id' => (int) $bin['id'], 'status' => in_array($l['status'] ?? '', Ledger::STATUSES, true) ? $l['status'] : 'available',
                            'lot_no' => trim((string) ($l['lot_no'] ?? '')) ?: null, 'expiry_date' => Lots::date($l['expiry_date'] ?? ''), 'serial_no' => trim((string) ($l['serial_no'] ?? '')) ?: null,
                            'unit_cost' => ($l['unit_cost'] ?? '') !== '' ? round((float) $l['unit_cost'], 4) : null];
                    }
                    Items::checkQty(end($lines)['item'], abs($q));
                }
                if (! $lines) {
                    throw new BizException('Add at least one line.');
                }
                Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item']['id']], $lines));
                $id    = Db::insert('adjustments', ['number' => Numbers::next('adjustment'), 'warehouse_id' => $wh, 'status' => 'pending', 'reason_code' => $reason,
                    'notes' => trim((string) $d['notes']), 'created_by' => Auth::id(), 'created_at' => Db::now()]);
                $value = 0.0;
                foreach ($lines as $l) {
                    $unit = $l['qty'] > 0 ? ($l['unit_cost'] ?? Valuation::latestUnit((int) $l['item']['id'], $wh)) : Valuation::latestUnit((int) $l['item']['id'], $wh);
                    $lid  = Db::insert('adjustment_lines', ['adjustment_id' => $id, 'item_id' => $l['item']['id'], 'bin_id' => $l['bin_id'], 'lot_id' => $l['lot_id'] ?? null,
                        'lot_no' => $l['lot_no'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null, 'serial_no' => $l['serial_no'] ?? null, 'status' => $l['status'],
                        'qty' => $l['qty'], 'unit_cost' => $l['qty'] > 0 ? $unit : null, 'value' => round($l['qty'] * $unit, 2)]);
                    if ($l['qty'] < 0) { // hold the units while waiting
                        $row  = Ledger::row((int) $l['row']['id']);
                        $free = (float) $row['qty'] - (float) $row['qty_allocated'];
                        if (-$l['qty'] - $free > Ledger::EPS) {
                            throw new BizException('Only ' . Items::fmt($l['item'], $free) . ' of ' . $l['item']['sku'] . ' is free there (the rest is reserved).');
                        }
                        Db::exec('UPDATE {stock} SET qty_allocated = qty_allocated + ? WHERE id = ?', [-$l['qty'], $row['id']]);
                        Db::insert('allocations', ['stock_id' => $row['id'], 'doc_type' => 'adjustment', 'doc_id' => $id, 'doc_line_id' => $lid, 'qty' => -$l['qty'], 'stage' => 'reserved', 'status' => 'active', 'created_at' => Db::now()]);
                    }
                    $value += abs($l['qty'] * $unit);
                }
                Db::exec('UPDATE {adjustments} SET value = ? WHERE id = ?', [round($value, 2), $id]);
                Audit::log('adjustment.request', 'adjustment', $id, null, ['lines' => count($lines), 'value' => round($value, 2), 'reason' => $reason]);
                $limit = (float) Settings::get('adjust_auto_limit');
                if ($limit > 0 && $value <= $limit && Access::canAt($wh, 'stock.approve')) {
                    self::post($id, null, 'Within the automatic limit');
                } else {
                    Notify::holders('stock.approve', $wh, 'adjustment', 'Adjustment ' . Db::value('SELECT number FROM {adjustments} WHERE id = ?', [$id]) . ' waits for approval', 'Value ' . number_format($value, 2), '/adjustments/' . $id, Auth::id());
                }
                return $id;
            });
        });
    }

    public static function approve(int $id, string $note = ''): void
    {
        Db::atomic(static function () use ($id, $note) {
            $a = Db::row('SELECT * FROM {adjustments} WHERE id = ? FOR UPDATE', [$id]);
            if (! $a) {
                throw new BizException('Unknown adjustment.');
            }
            Access::requireAt((int) $a['warehouse_id'], 'stock.approve');
            if ($a['status'] !== 'pending') {
                throw new BizException('This adjustment is already ' . $a['status'] . '.');
            }
            if ((int) $a['created_by'] === Auth::id()) {
                throw new BizException('You raised this adjustment: someone else must approve it.');
            }
            self::post($id, Auth::id(), $note);
        });
    }

    public static function reject(int $id, string $note): void
    {
        Db::atomic(static function () use ($id, $note) {
            $a = Db::row('SELECT * FROM {adjustments} WHERE id = ? FOR UPDATE', [$id]);
            if (! $a) {
                throw new BizException('Unknown adjustment.');
            }
            $self = (int) $a['created_by'] === Auth::id();
            if (! $self) {
                Access::requireAt((int) $a['warehouse_id'], 'stock.approve');
            }
            if ($a['status'] !== 'pending') {
                throw new BizException('This adjustment is already ' . $a['status'] . '.');
            }
            if (trim($note) === '') {
                throw new BizException('Say why it is rejected (or withdrawn).');
            }
            $lines = Db::rows('SELECT DISTINCT item_id FROM {adjustment_lines} WHERE adjustment_id = ?', [$id]);
            Ledger::lock(array_map(static fn ($l) => [(int) $a['warehouse_id'], (int) $l['item_id']], $lines));
            \App\Libraries\Stock\Alloc::release('adjustment', $id);
            Db::exec("UPDATE {adjustments} SET status = 'rejected', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?", [Auth::id(), Db::now(), mb_substr($note, 0, 255), $id]);
            Audit::log($self ? 'adjustment.withdraw' : 'adjustment.reject', 'adjustment', $id, null, null, 'success', $note);
            if (! $self) {
                Notify::user((int) $a['created_by'], 'adjustment', 'Adjustment ' . $a['number'] . ' was rejected', $note, '/adjustments/' . $id);
            }
        });
    }

    private static function post(int $id, ?int $approver, string $note): void
    {
        $a     = Db::row('SELECT * FROM {adjustments} WHERE id = ? FOR UPDATE', [$id]);
        $wh    = (int) $a['warehouse_id'];
        $lines = Db::rows('SELECT * FROM {adjustment_lines} WHERE adjustment_id = ? ORDER BY id', [$id]);
        Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item_id']], $lines));
        $value = 0.0;
        foreach ($lines as $l) {
            $item = Items::get((int) $l['item_id']);
            $base = ['doc_type' => 'adjustment', 'doc_id' => $id, 'doc_line_id' => (int) $l['id'], 'reason_code' => $a['reason_code'], 'note' => mb_substr((string) $a['notes'], 0, 255)];
            if ((float) $l['qty'] < 0) {
                $alloc = Db::row("SELECT * FROM {allocations} WHERE doc_type = 'adjustment' AND doc_id = ? AND doc_line_id = ? AND status = 'active'", [$id, $l['id']]);
                if (! $alloc) {
                    throw new \LogicException('Adjustment reservation missing.');
                }
                $r = Ledger::remove($base + ['stock_id' => (int) $alloc['stock_id'], 'qty' => -(float) $l['qty'], 'allocation_id' => (int) $alloc['id'], 'type' => 'adjust_out', 'serial_status' => 'scrapped']);
                Db::exec('UPDATE {adjustment_lines} SET value = ? WHERE id = ?', [-$r['value'], $l['id']]);
                $value -= $r['value'];
            } else {
                $lotId    = $l['lot_id'] ?: (Items::lots($item) ? Lots::resolve($item, (string) $l['lot_no'], ['expiry_date' => $l['expiry_date']], 'adjustment', $id) : null);
                $serialId = $l['serial_no'] ? Lots::serial($item, (string) $l['serial_no'], $lotId) : null;
                $unit     = (float) $l['unit_cost'];
                Ledger::add($base + ['warehouse_id' => $wh, 'bin_id' => (int) $l['bin_id'], 'item_id' => (int) $item['id'], 'lot_id' => $lotId, 'serial_id' => $serialId,
                    'status' => $l['status'], 'qty' => (float) $l['qty'], 'type' => 'adjust_in', 'unit_cost' => $unit]);
                $value += (float) $l['qty'] * $unit;
            }
        }
        Db::exec("UPDATE {adjustments} SET status = 'posted', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?", [$approver, Db::now(), $note !== '' ? mb_substr($note, 0, 255) : null, $id]);
        Audit::log('adjustment.post', 'adjustment', $id, null, ['net_value' => round($value, 2), 'approver' => $approver], 'success', $note);
        if ($approver) {
            Notify::user((int) $a['created_by'], 'adjustment', 'Adjustment ' . $a['number'] . ' was approved and posted', null, '/adjustments/' . $id);
        }
    }
}
