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
use App\Libraries\Master\Warehouses;
use App\Libraries\Stock\Alloc;
use App\Libraries\Stock\Ledger;
use App\Libraries\Stock\Valuation;
use App\Libraries\Workflow\Notify;

/**
 * Transfers between warehouses.
 *   draft → allocated (stock reserved at the source) → in transit (left the source: per lot and serial, with its cost)
 *   → partly received → received.
 * At the destination each unit arrives good (to a bin), damaged (DAMAGED) or rejected (QUARANTINE, for a decision);
 * what has not arrived when the receipt is marked final is missing and written off at the cost it left with.
 */
final class Transfers
{
    public static function get(int $id, bool $lock = false): array
    {
        $t = Db::row('SELECT * FROM {transfers} WHERE tenant_id = {tid} AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $t) {
            throw new \App\Libraries\Core\NotFoundException('Transfer not found.');
        }
        return $t;
    }

    public static function lines(int $id): array
    {
        return Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, i.tracking FROM {transfer_lines} l JOIN {items} i ON i.id = l.item_id WHERE l.tenant_id = {tid} AND l.transfer_id = ? ORDER BY l.id', [$id]);
    }

    /** $d: from_warehouse_id, to_warehouse_id, needed_by, notes, lines [{item_id, qty}]. */
    public static function create(array $d): int
    {
        $from = (int) ($d['from_warehouse_id'] ?? 0);
        $to   = (int) ($d['to_warehouse_id'] ?? 0);
        Access::requireAt($from, 'transfer.manage');
        if ($from === $to) {
            throw new BizException('Choose two different warehouses. Moves inside a warehouse are bin moves.');
        }
        $dest = Warehouses::get($to);
        if (! $dest['active']) {
            throw new BizException('Warehouse ' . $dest['code'] . ' is closed.');
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
            $lines[] = [(int) $item['id'], $q, $l['pack_unit'] ?? null, $l['pack_factor'] ?? null];
        }
        if (! $lines) {
            throw new BizException('Add at least one item.');
        }
        return Db::atomic(static function () use ($d, $from, $to, $lines) {
            $id = Db::insert('transfers', [
                'number' => Numbers::next('transfer'), 'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'status' => 'draft',
                'needed_by' => \App\Libraries\Stock\Lots::date($d['needed_by'] ?? ''), 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
                'created_by' => Auth::id(), 'created_at' => Db::now(),
            ]);
            foreach ($lines as [$item, $q, $pu, $pf]) {
                Db::insert('transfer_lines', ['transfer_id' => $id, 'item_id' => $item, 'qty' => $q, 'pack_unit' => $pu, 'pack_factor' => $pf]);
            }
            Audit::log('transfer.create', 'transfer', $id, null, ['from' => $from, 'to' => $to, 'lines' => count($lines)]);
            return $id;
        });
    }

    /** Reserve the stock at the source (FEFO/FIFO, never expired, held or blocked). All lines or nothing. */
    public static function allocate(int $id): void
    {
        Db::atomic(static function () use ($id) {
            $tr = self::get($id, true);
            Access::requireAt((int) $tr['from_warehouse_id'], 'transfer.manage');
            if ($tr['status'] !== 'draft') {
                throw new BizException('Only a draft transfer can be allocated (this one is ' . str_replace('_', ' ', $tr['status']) . ').');
            }
            $lines = self::lines($id);
            Ledger::lock(array_map(static fn ($l) => [(int) $tr['from_warehouse_id'], (int) $l['item_id']], $lines));
            foreach ($lines as $l) {
                Alloc::reserve('transfer', $id, (int) $l['id'], (int) $tr['from_warehouse_id'], (int) $l['item_id'], (float) $l['qty']);
            }
            Db::exec("UPDATE {transfers} SET status = 'allocated' WHERE tenant_id = {tid} AND id = ?", [$id]);
            Audit::log('transfer.allocate', 'transfer', $id);
        });
    }

    public static function unallocate(int $id): void
    {
        Db::atomic(static function () use ($id) {
            $tr = self::get($id, true);
            Access::requireAt((int) $tr['from_warehouse_id'], 'transfer.manage');
            if ($tr['status'] !== 'allocated') {
                throw new BizException('This transfer is not allocated.');
            }
            Ledger::lock(array_map(static fn ($l) => [(int) $tr['from_warehouse_id'], (int) $l['item_id']], self::lines($id)));
            Alloc::release('transfer', $id);
            Db::exec("UPDATE {transfers} SET status = 'draft' WHERE tenant_id = {tid} AND id = ?", [$id]);
            Audit::log('transfer.unallocate', 'transfer', $id);
        });
    }

    public static function cancel(int $id, string $why): void
    {
        Db::atomic(static function () use ($id, $why) {
            $tr = self::get($id, true);
            Access::requireAt((int) $tr['from_warehouse_id'], 'transfer.manage');
            if (! in_array($tr['status'], ['draft', 'allocated'], true)) {
                throw new BizException('A transfer that has left can no longer be cancelled: receive it at the destination (as rejected, if it must come back) and send it back on a new transfer.');
            }
            if ($tr['status'] === 'allocated') {
                Ledger::lock(array_map(static fn ($l) => [(int) $tr['from_warehouse_id'], (int) $l['item_id']], self::lines($id)));
                Alloc::release('transfer', $id);
            }
            Db::exec("UPDATE {transfers} SET status = 'cancelled', closed_at = ?, notes = CONCAT(COALESCE(notes, ''), ?) WHERE tenant_id = {tid} AND id = ?", [Db::now(), "\nCancelled: " . mb_substr($why, 0, 200), $id]);
            Db::exec("DELETE FROM {trip_stops} WHERE tenant_id = {tid} AND kind = 'transfer' AND doc_id = ? AND status = 'pending'", [$id]);
            Audit::log('transfer.cancel', 'transfer', $id, ['status' => $tr['status']], ['status' => 'cancelled'], 'success', $why);
        });
    }

    /** Ship what is allocated: it leaves the source's stock and value, and is in transit per lot and serial. */
    public static function dispatch(int $id, ?string $token = null, bool $viaTrip = false): void
    {
        Db::atomic(static function () use ($id, $token, $viaTrip) {
            Db::once($token, Auth::id(), static function () use ($id, $viaTrip) {
                $tr = self::get($id, true);
                // a trip's departure (checked for dispatch.manage at the gate) dispatches the transfers it carries
                Access::requireAt((int) $tr['from_warehouse_id'], $viaTrip ? 'dispatch.manage' : 'transfer.manage');
                if ($tr['status'] !== 'allocated') {
                    throw new BizException('Allocate the transfer before dispatching it' . ($tr['status'] === 'in_transit' ? ' (it has already left)' : '') . '.');
                }
                $wh    = (int) $tr['from_warehouse_id'];
                $lines = self::lines($id);
                Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item_id']], $lines));
                foreach ($lines as $l) {
                    $sent  = 0.0;
                    $value = 0.0;
                    foreach (Alloc::active('transfer', $id, (int) $l['id']) as $a) {
                        $r = Ledger::remove(['stock_id' => (int) $a['stock_id'], 'qty' => (float) $a['qty'], 'allocation_id' => (int) $a['id'], 'type' => 'transfer_out',
                            'doc_type' => 'transfer', 'doc_id' => $id, 'doc_line_id' => (int) $l['id'], 'serial_status' => 'in_transit']);
                        Db::insert('transit', ['transfer_id' => $id, 'transfer_line_id' => $l['id'], 'item_id' => $l['item_id'], 'lot_id' => $a['lot_id'], 'serial_id' => $a['serial_id'], 'qty' => $a['qty'], 'qty_open' => $a['qty']]);
                        foreach ($r['layers'] as [$lq, $uc, $ra]) {
                            Db::insert('transit_costs', ['transfer_id' => $id, 'transfer_line_id' => $l['id'], 'received_at' => $ra, 'qty' => $lq, 'qty_open' => $lq, 'unit_cost' => $uc]);
                        }
                        $sent  += (float) $a['qty'];
                        $value += $r['value'];
                    }
                    Db::exec('UPDATE {transfer_lines} SET qty_dispatched = ?, value_sent = ? WHERE tenant_id = {tid} AND id = ?', [Ledger::q($sent), round($value, 2), $l['id']]);
                }
                Db::exec("UPDATE {transfers} SET status = 'in_transit', dispatched_by = ?, dispatched_at = ? WHERE tenant_id = {tid} AND id = ?", [Auth::id(), Db::now(), $id]);
                Notify::holders('transfer.receive', (int) $tr['to_warehouse_id'], 'transfer_coming', 'Transfer ' . $tr['number'] . ' is on its way', 'From ' . Warehouses::get($wh)['code'] . '. Receive it when it arrives.', '/transfers/' . $id);
                Audit::log('transfer.dispatch', 'transfer', $id);
                return $id;
            });
        });
    }

    /**
     * Receive at the destination. $d: final (bool), notes, rows [{transit_id, good, damaged, rejected, bin_id}].
     * A final receipt writes off whatever is still open as missing (a note is required when something is missing).
     */
    public static function receive(int $id, array $d, ?string $token = null): void
    {
        Db::atomic(static function () use ($id, $d, $token) {
            Db::once($token, Auth::id(), static function () use ($id, $d) {
                $tr = self::get($id, true);
                $wh = (int) $tr['to_warehouse_id'];
                Access::requireAt($wh, 'transfer.receive');
                if (! in_array($tr['status'], ['in_transit', 'partly_received'], true)) {
                    throw new BizException('This transfer is not in transit (it is ' . str_replace('_', ' ', $tr['status']) . ').');
                }
                $w     = Warehouses::get($wh);
                $final = ! empty($d['final']);
                $rows  = [];
                foreach ($d['rows'] ?? [] as $r) {
                    $g = Ledger::q($r['good'] ?? 0);
                    $dm = Ledger::q($r['damaged'] ?? 0);
                    $rj = Ledger::q($r['rejected'] ?? 0);
                    if ($g < 0 || $dm < 0 || $rj < 0) {
                        throw new BizException('Quantities cannot be negative.');
                    }
                    if ($g + $dm + $rj > Ledger::EPS) {
                        $rows[(int) $r['transit_id']] = [$g, $dm, $rj, ($r['bin_id'] ?? '') !== '' ? (int) $r['bin_id'] : (int) $w['receiving_bin_id']];
                    }
                }
                if (! $rows && ! $final) {
                    throw new BizException('Nothing received. Give quantities, or mark the receipt as final to record the rest as missing.');
                }
                $transit = Db::rows('SELECT * FROM {transit} WHERE tenant_id = {tid} AND transfer_id = ? ORDER BY id FOR UPDATE', [$id]);
                Ledger::lock(array_map(static fn ($x) => [$wh, (int) $x['item_id']], $transit));
                $rcpt = Db::insert('transfer_receipts', ['transfer_id' => $id, 'is_final' => $final ? 1 : 0, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'received_by' => Auth::id(), 'received_at' => Db::now()]);
                $byId = [];
                foreach ($transit as $x) {
                    $byId[(int) $x['id']] = $x;
                }
                foreach ($rows as $tid => [$g, $dm, $rj, $bin]) {
                    $x = $byId[$tid] ?? null;
                    if (! $x) {
                        throw new BizException('That line is not on this transfer.');
                    }
                    $item = Items::get((int) $x['item_id']);
                    if ($g + $dm + $rj - (float) $x['qty_open'] > Ledger::EPS) {
                        throw new BizException($item['sku'] . ': ' . ($g + $dm + $rj) . ' received but only ' . (float) $x['qty_open'] . ' is still on the way.');
                    }
                    foreach ([[$g, $bin, 'available', 'transfer_in'], [$dm, (int) $w['damaged_bin_id'], 'damaged', 'transfer_in'], [$rj, (int) $w['quarantine_bin_id'], 'quarantine', 'transfer_in']] as [$q, $b, $status, $type]) {
                        if ($q <= Ledger::EPS) {
                            continue;
                        }
                        Items::checkQty($item, $q);
                        $layers = self::takeCost((int) $x['transfer_line_id'], $q);
                        Ledger::add(['warehouse_id' => $wh, 'bin_id' => $b, 'item_id' => $item['id'], 'lot_id' => $x['lot_id'], 'serial_id' => $x['serial_id'], 'status' => $status,
                            'qty' => $q, 'type' => $type, 'doc_type' => 'transfer', 'doc_id' => $id, 'doc_line_id' => (int) $x['transfer_line_id'], 'layers' => $layers, 'note' => 'Receipt ' . $rcpt]);
                    }
                    Db::exec('UPDATE {transit} SET qty_open = qty_open - ? WHERE tenant_id = {tid} AND id = ?', [Ledger::q($g + $dm + $rj), $tid]);
                    Db::exec('UPDATE {transfer_lines} SET qty_received = qty_received + ?, qty_damaged = qty_damaged + ?, qty_rejected = qty_rejected + ? WHERE tenant_id = {tid} AND id = ?', [$g, $dm, $rj, $x['transfer_line_id']]);
                }
                $missing = 0.0;
                if ($final) {
                    foreach (Db::rows('SELECT * FROM {transit} WHERE tenant_id = {tid} AND transfer_id = ? AND qty_open > 0 FOR UPDATE', [$id]) as $x) {
                        $layers = self::takeCost((int) $x['transfer_line_id'], (float) $x['qty_open']);
                        $val    = Valuation::valueOf($layers);
                        Db::exec('UPDATE {transfer_lines} SET qty_missing = qty_missing + ?, value_missing = value_missing + ? WHERE tenant_id = {tid} AND id = ?', [$x['qty_open'], $val, $x['transfer_line_id']]);
                        Db::exec('UPDATE {transit} SET qty_open = 0 WHERE tenant_id = {tid} AND id = ?', [$x['id']]);
                        if ($x['serial_id']) {
                            Db::exec("UPDATE {serials} SET status = 'lost', last_doc = ? WHERE tenant_id = {tid} AND id = ?", ['transfer:' . $id, $x['serial_id']]);
                        }
                        $missing += (float) $x['qty_open'];
                    }
                    if ($missing > Ledger::EPS && Settings::get('transfer_loss_note') === '1' && trim((string) ($d['notes'] ?? '')) === '') {
                        throw new BizException('Units are missing: say what happened in the notes before closing the transfer.');
                    }
                }
                $open   = (float) Db::value('SELECT COALESCE(SUM(qty_open), 0) FROM {transit} WHERE tenant_id = {tid} AND transfer_id = ?', [$id]);
                $status = $open <= Ledger::EPS ? 'received' : 'partly_received';
                Db::exec('UPDATE {transfers} SET status = ?, closed_at = ? WHERE tenant_id = {tid} AND id = ?', [$status, $status === 'received' ? Db::now() : null, $id]);
                if ($missing > Ledger::EPS) {
                    Notify::holders('stock.approve', null, 'transfer_loss', 'Transfer ' . $tr['number'] . ' arrived short', 'Missing units were written off at the cost they left with.', '/transfers/' . $id, Auth::id());
                }
                Audit::log('transfer.receive', 'transfer', $id, null, ['final' => $final, 'rows' => count($rows), 'missing' => $missing, 'status' => $status]);
                return $id;
            });
        });
    }

    /** Take the cost of $qty units of a line from its transit costs, oldest first. */
    private static function takeCost(int $lineId, float $qty): array
    {
        $need = Ledger::q($qty);
        $out  = [];
        foreach (Db::rows('SELECT * FROM {transit_costs} WHERE tenant_id = {tid} AND transfer_line_id = ? AND qty_open > 0 ORDER BY received_at, id FOR UPDATE', [$lineId]) as $c) {
            if ($need <= Ledger::EPS) {
                break;
            }
            $use = Ledger::q(min($need, (float) $c['qty_open']));
            Db::exec('UPDATE {transit_costs} SET qty_open = qty_open - ? WHERE tenant_id = {tid} AND id = ?', [$use, $c['id']]);
            $out[] = [$use, (float) $c['unit_cost'], $c['received_at']];
            $need  = Ledger::q($need - $use);
        }
        if ($need > Ledger::EPS) {
            throw new \LogicException('Transit cost short for transfer line ' . $lineId);
        }
        return $out;
    }

    public static function transit(int $id): array
    {
        return Db::rows('SELECT t.*, i.sku, i.name, i.uom, i.decimals, l.lot_no, l.expiry_date, s.serial_no FROM {transit} t JOIN {items} i ON i.id = t.item_id
                           LEFT JOIN {lots} l ON l.id = t.lot_id LEFT JOIN {serials} s ON s.id = t.serial_id WHERE t.tenant_id = {tid} AND t.transfer_id = ? ORDER BY t.transfer_line_id, t.id', [$id]);
    }
}
