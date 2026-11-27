<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items;
use App\Libraries\Master\Numbers;
use App\Libraries\Master\Warehouses;
use App\Libraries\Stock\Alloc;
use App\Libraries\Stock\Ledger;
use App\Libraries\Stock\Valuation;

/**
 * Shipments of a sales order: allocated (stock reserved, pick list) → picked (in the DISPATCH bin) → packed
 * → dispatched (left stock at FIFO cost; units recorded per lot and serial) → delivered (proof of delivery)
 * or failed (everything comes back). Delivered units can later come back as a customer return.
 */
final class Shipments
{
    public static function get(int $id, bool $lock = false): array
    {
        $s = Db::row('SELECT * FROM {shipments} WHERE tenant_id = {tid} AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $s) {
            throw new \App\Libraries\Core\NotFoundException('Shipment not found.');
        }
        return $s;
    }

    public static function lines(int $id): array
    {
        return Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, i.tracking, i.weight_kg, ol.pack_unit, ol.pack_factor FROM {shipment_lines} l JOIN {items} i ON i.id = l.item_id LEFT JOIN {so_lines} ol ON ol.id = l.so_line_id WHERE l.tenant_id = {tid} AND l.shipment_id = ? ORDER BY l.id', [$id]);
    }

    /**
     * Create a shipment for an order and reserve its stock. $qtys: so_line_id => qty (default: everything not yet
     * shipped or on another shipment). With $partial, ship what is free now and leave the rest on the order.
     * Batches must have at least the item's (or the customer's, whichever is longer) shelf life left.
     */
    public static function create(int $soId, array $qtys = [], bool $partial = false): int
    {
        return Db::atomic(static function () use ($soId, $qtys, $partial) {
            $so = Sales::get($soId, true);
            $wh = (int) $so['warehouse_id'];
            Access::requireAt($wh, 'dispatch.manage');
            if (! in_array($so['status'], ['confirmed', 'in_progress'], true)) {
                throw new BizException('Order ' . $so['number'] . ' is ' . str_replace('_', ' ', $so['status']) . '; confirm it first.');
            }
            $cust  = Db::row('SELECT * FROM {customers} WHERE tenant_id = {tid} AND id = ?', [$so['customer_id']]);
            $lines = Sales::lines($soId);
            $plan  = [];
            foreach ($lines as $l) {
                $onShip = (float) Db::value("SELECT COALESCE(SUM(sl.qty), 0) FROM {shipment_lines} sl JOIN {shipments} s ON s.id = sl.shipment_id WHERE sl.tenant_id = {tid} AND sl.so_line_id = ? AND s.status IN ('allocated','picked','packed')", [$l['id']]);
                $open   = Ledger::q((float) $l['qty'] - (float) $l['qty_shipped'] - $onShip);
                $want   = array_key_exists((int) $l['id'], $qtys) ? Ledger::q($qtys[(int) $l['id']]) : $open;
                if ($want <= Ledger::EPS) {
                    continue;
                }
                if ($want - $open > Ledger::EPS) {
                    throw new BizException($l['sku'] . ': only ' . $open . ' is still to ship on this order.');
                }
                $plan[] = [$l, $want];
            }
            if (! $plan) {
                throw new BizException('Nothing left to ship on this order.');
            }
            Ledger::lock(array_map(static fn ($p) => [$wh, (int) $p[0]['item_id']], $plan));
            $id = Db::insert('shipments', ['number' => Numbers::next('shipment'), 'so_id' => $soId, 'warehouse_id' => $wh, 'status' => 'allocated', 'created_by' => Auth::id(), 'created_at' => Db::now()]);
            $any = 0.0;
            foreach ($plan as [$l, $want]) {
                $item  = Items::get((int) $l['item_id']);
                $days  = max((int) $item['min_shelf_days'], (int) $cust['min_shelf_days']);
                $lid   = Db::insert('shipment_lines', ['shipment_id' => $id, 'so_line_id' => $l['id'], 'item_id' => $l['item_id'], 'qty' => $want]);
                $got   = Alloc::reserve('shipment', $id, $lid, $wh, (int) $l['item_id'], $want, ['min_expiry' => date('Y-m-d', strtotime('+' . $days . ' days'))], $partial);
                $sum   = array_sum(array_column($got, 2));
                if ($sum <= Ledger::EPS) {
                    Db::exec('DELETE FROM {shipment_lines} WHERE tenant_id = {tid} AND id = ?', [$lid]);
                    continue;
                }
                if ($sum < $want) {
                    Db::exec('UPDATE {shipment_lines} SET qty = ? WHERE tenant_id = {tid} AND id = ?', [Ledger::q($sum), $lid]);
                }
                $any += $sum;
            }
            if ($any <= Ledger::EPS) {
                throw new BizException('No stock is free for this order now (check expiry, holds and shelf life). Nothing was reserved.');
            }
            Sales::refresh($soId);
            Audit::log('shipment.create', 'shipment', $id, null, ['order' => $so['number'], 'partial' => $partial]);
            return $id;
        });
    }

    /**
     * Confirm the pick: reserved units move to the DISPATCH bin. $short: allocation_id => qty actually picked
     * (less than reserved: the rest is released and the line shrinks).
     */
    public static function pick(int $id, array $short = [], ?string $token = null): void
    {
        Db::atomic(static function () use ($id, $short, $token) {
            Db::once($token, Auth::id(), static function () use ($id, $short) {
                $s  = self::get($id, true);
                $wh = (int) $s['warehouse_id'];
                Access::requireAt($wh, 'dispatch.manage');
                if ($s['status'] !== 'allocated') {
                    throw new BizException('This shipment is ' . $s['status'] . '; only an allocated shipment can be picked.');
                }
                $w     = Warehouses::get($wh);
                $lines = self::lines($id);
                Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item_id']], $lines));
                foreach (Alloc::active('shipment', $id, null, 'reserved') as $a) {
                    $q = array_key_exists((int) $a['id'], $short) ? Ledger::q($short[(int) $a['id']]) : (float) $a['qty'];
                    if ($q < 0 || $q - (float) $a['qty'] > Ledger::EPS) {
                        throw new BizException('Picked quantity must be between 0 and what was reserved.');
                    }
                    if ($q < (float) $a['qty']) {
                        $rest = Ledger::q((float) $a['qty'] - $q);
                        Db::exec('UPDATE {stock} SET qty_allocated = qty_allocated - ? WHERE tenant_id = {tid} AND id = ?', [$rest, $a['stock_id']]);
                        Db::exec('UPDATE {allocations} SET qty = qty - ? WHERE tenant_id = {tid} AND id = ?', [$rest, $a['id']]);
                        Db::exec('UPDATE {shipment_lines} SET qty = qty - ? WHERE tenant_id = {tid} AND id = ?', [$rest, $a['doc_line_id']]);
                        if ($q <= Ledger::EPS) {
                            Db::exec("UPDATE {allocations} SET status = 'released', qty = ? WHERE tenant_id = {tid} AND id = ?", [$a['qty'], $a['id']]);
                            continue;
                        }
                    }
                    Ledger::move(['stock_id' => (int) $a['stock_id'], 'qty' => $q, 'to_bin_id' => (int) $w['dispatch_bin_id'], 'allocation_id' => (int) $a['id'], 'stage' => 'picked',
                        'type' => 'pick', 'doc_type' => 'shipment', 'doc_id' => $id, 'doc_line_id' => (int) $a['doc_line_id']]);
                    Db::exec('UPDATE {shipment_lines} SET qty_picked = qty_picked + ? WHERE tenant_id = {tid} AND id = ?', [$q, $a['doc_line_id']]);
                }
                Db::exec('DELETE FROM {shipment_lines} WHERE tenant_id = {tid} AND shipment_id = ? AND qty <= 0', [$id]);
                if (! Db::value('SELECT 1 FROM {shipment_lines} WHERE tenant_id = {tid} AND shipment_id = ?', [$id])) {
                    throw new BizException('Nothing was picked. Cancel the shipment instead.');
                }
                $weight = (float) Db::value('SELECT COALESCE(SUM(sl.qty_picked * COALESCE(i.weight_kg, 0)), 0) FROM {shipment_lines} sl JOIN {items} i ON i.id = sl.item_id WHERE sl.tenant_id = {tid} AND sl.shipment_id = ?', [$id]);
                Db::exec("UPDATE {shipments} SET status = 'picked', picked_by = ?, picked_at = ?, weight_kg = ? WHERE tenant_id = {tid} AND id = ?", [Auth::id(), Db::now(), $weight ?: null, $id]);
                Sales::refresh((int) $s['so_id']);
                Audit::log('shipment.pick', 'shipment', $id);
                return $id;
            });
        });
    }

    public static function pack(int $id, int $packages, ?float $weight = null): void
    {
        Db::atomic(static function () use ($id, $packages, $weight) {
            $s = self::get($id, true);
            Access::requireAt((int) $s['warehouse_id'], 'dispatch.manage');
            if (! in_array($s['status'], ['picked', 'packed'], true)) {
                throw new BizException('Pick the shipment before packing it.');
            }
            if ($packages < 1) {
                throw new BizException('Give the number of packages (cartons, pallets).');
            }
            Db::exec("UPDATE {shipments} SET status = 'packed', packages = ?, weight_kg = COALESCE(?, weight_kg), packed_at = ? WHERE tenant_id = {tid} AND id = ?", [$packages, $weight, Db::now(), $id]);
            Audit::log('shipment.pack', 'shipment', $id, null, ['packages' => $packages, 'weight' => $weight]);
        });
    }

    /** The goods leave: stock and value go down; serials are marked as with the customer. */
    public static function dispatch(int $id, ?string $token = null): void
    {
        Db::atomic(static function () use ($id, $token) {
            Db::once($token, Auth::id(), static function () use ($id) {
                $s  = self::get($id, true);
                $wh = (int) $s['warehouse_id'];
                Access::requireAt($wh, 'dispatch.manage');
                if ($s['status'] !== 'packed') {
                    throw new BizException($s['status'] === 'dispatched' ? 'This shipment has already left.' : 'Pack the shipment before dispatching it.');
                }
                $so    = Sales::get((int) $s['so_id'], true);
                $lines = self::lines($id);
                Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item_id']], $lines));
                $total = 0.0;
                foreach (Alloc::active('shipment', $id, null, 'picked') as $a) {
                    $r = Ledger::remove(['stock_id' => (int) $a['stock_id'], 'qty' => (float) $a['qty'], 'allocation_id' => (int) $a['id'], 'type' => 'dispatch',
                        'doc_type' => 'shipment', 'doc_id' => $id, 'doc_line_id' => (int) $a['doc_line_id'], 'serial_status' => 'dispatched', 'customer_id' => (int) $so['customer_id']]);
                    Db::insert('shipment_units', ['shipment_id' => $id, 'shipment_line_id' => $a['doc_line_id'], 'item_id' => $a['item_id'], 'lot_id' => $a['lot_id'], 'serial_id' => $a['serial_id'], 'qty' => $a['qty'], 'value' => $r['value']]);
                    $total += $r['value'];
                }
                foreach ($lines as $l) {
                    Db::exec('UPDATE {so_lines} SET qty_shipped = qty_shipped + ? WHERE tenant_id = {tid} AND id = ?', [$l['qty_picked'], $l['so_line_id']]);
                }
                Db::exec("UPDATE {shipments} SET status = 'dispatched', dispatched_by = ?, dispatched_at = ?, value = ? WHERE tenant_id = {tid} AND id = ?", [Auth::id(), Db::now(), round($total, 2), $id]);
                Sales::refresh((int) $s['so_id']);
                Audit::log('shipment.dispatch', 'shipment', $id, null, ['value' => round($total, 2)]);
                \App\Libraries\Messaging\Messages::shipment($id, 'dispatched');   // core 1.4: the customer is told
                return $id;
            });
        });
    }

    /** Proof of delivery: who signed, any note; an optional photo or scan is attached separately. */
    public static function deliver(int $id, string $receivedBy, string $note = ''): void
    {
        Db::atomic(static function () use ($id, $receivedBy, $note) {
            $s = self::get($id, true);
            Access::requireAt((int) $s['warehouse_id'], 'dispatch.manage');
            if ($s['status'] !== 'dispatched') {
                throw new BizException('Only a dispatched shipment can be delivered.');
            }
            if (trim($receivedBy) === '') {
                throw new BizException('Give the name of the person who received the goods.');
            }
            foreach (self::lines($id) as $l) {
                Db::exec('UPDATE {so_lines} SET qty_delivered = qty_delivered + ? WHERE tenant_id = {tid} AND id = ?', [$l['qty_picked'], $l['so_line_id']]);
            }
            Db::exec("UPDATE {shipments} SET status = 'delivered', delivered_at = ?, pod_name = ?, pod_note = ? WHERE tenant_id = {tid} AND id = ?", [Db::now(), mb_substr(trim($receivedBy), 0, 100), trim($note) ?: null, $id]);
            Db::exec("UPDATE {trip_stops} SET status = 'done', done_at = ? WHERE tenant_id = {tid} AND kind = 'shipment' AND doc_id = ? AND status = 'pending'", [Db::now(), $id]);
            Sales::refresh((int) $s['so_id']);
            Audit::log('shipment.deliver', 'shipment', $id, null, ['received_by' => $receivedBy]);
            \App\Libraries\Messaging\Messages::shipment($id, 'delivered');
        });
    }

    /**
     * Failed delivery: everything comes back into the warehouse at the cost it left with, to STOCK
     * (or the given bin) as available, or to QUARANTINE when $status is quarantine. The order can be shipped again.
     */
    public static function fail(int $id, string $reason, string $note = '', string $status = 'available', ?int $binId = null, ?string $token = null): int
    {
        return (int) Db::atomic(static function () use ($id, $reason, $note, $status, $binId, $token) {
            return Db::once($token, Auth::id(), static function () use ($id, $reason, $note, $status, $binId) {
                $s = self::get($id, true);
                Access::requireAt((int) $s['warehouse_id'], 'dispatch.manage');
                if ($s['status'] !== 'dispatched') {
                    throw new BizException('Only a dispatched shipment can fail.');
                }
                if (! Db::value("SELECT 1 FROM {reasons} WHERE tenant_id = {tid} AND use_for = 'failed_delivery' AND code = ? AND active = 1", [$reason])) {
                    throw new BizException('Choose why the delivery failed.');
                }
                $units = Db::rows('SELECT * FROM {shipment_units} WHERE tenant_id = {tid} AND shipment_id = ? AND qty > qty_returned FOR UPDATE', [$id]);
                $rid   = self::bringBack($s, $units, array_map(static fn ($u) => (float) $u['qty'] - (float) $u['qty_returned'], $units), 'failed_delivery', $reason, $note, $status, $binId);
                foreach (self::lines($id) as $l) {
                    Db::exec('UPDATE {so_lines} SET qty_shipped = qty_shipped - ? WHERE tenant_id = {tid} AND id = ?', [$l['qty_picked'], $l['so_line_id']]);
                }
                Db::exec("UPDATE {shipments} SET status = 'failed', failed_reason = ? WHERE tenant_id = {tid} AND id = ?", [mb_substr($reason . ($note !== '' ? ': ' . $note : ''), 0, 255), $id]);
                Db::exec("UPDATE {trip_stops} SET status = 'failed', done_at = ? WHERE tenant_id = {tid} AND kind = 'shipment' AND doc_id = ? AND status = 'pending'", [Db::now(), $id]);
                Sales::refresh((int) $s['so_id']);
                return $rid;
            });
        });
    }

    /** Customer return of delivered units: $qtys shipment_unit_id => qty. They go to QUARANTINE unless $status says otherwise. */
    public static function customerReturn(int $id, array $qtys, string $reason, string $note = '', string $status = 'quarantine', ?string $token = null): int
    {
        return (int) Db::atomic(static function () use ($id, $qtys, $reason, $note, $status, $token) {
            return Db::once($token, Auth::id(), static function () use ($id, $qtys, $reason, $note, $status) {
                $s = self::get($id, true);
                Access::requireAt((int) $s['warehouse_id'], 'dispatch.manage');
                if ($s['status'] !== 'delivered') {
                    throw new BizException('Only delivered goods can be returned by the customer.');
                }
                if (! Db::value("SELECT 1 FROM {reasons} WHERE tenant_id = {tid} AND use_for = 'customer_return' AND code = ? AND active = 1", [$reason])) {
                    throw new BizException('Choose a reason for the return.');
                }
                $units = [];
                $q     = [];
                foreach ($qtys as $uid => $qty) {
                    $qty = Ledger::q($qty);
                    if ($qty <= 0) {
                        continue;
                    }
                    $u = Db::row('SELECT * FROM {shipment_units} WHERE tenant_id = {tid} AND id = ? AND shipment_id = ? FOR UPDATE', [(int) $uid, $id]);
                    if (! $u) {
                        throw new BizException('That unit was not on this shipment.');
                    }
                    if ($qty - ((float) $u['qty'] - (float) $u['qty_returned']) > Ledger::EPS) {
                        throw new BizException('More is being returned than was delivered.');
                    }
                    $units[] = $u;
                    $q[]     = $qty;
                }
                if (! $units) {
                    throw new BizException('Choose what came back.');
                }
                $rid = self::bringBack($s, $units, $q, 'customer_return', $reason, $note, $status, null);
                foreach ($units as $i => $u) {
                    $soLine = (int) Db::value('SELECT so_line_id FROM {shipment_lines} WHERE tenant_id = {tid} AND id = ?', [$u['shipment_line_id']]);
                    Db::exec('UPDATE {so_lines} SET qty_returned = qty_returned + ? WHERE tenant_id = {tid} AND id = ?', [$q[$i], $soLine]);
                    Db::exec('UPDATE {shipment_lines} SET qty_returned = qty_returned + ? WHERE tenant_id = {tid} AND id = ?', [$q[$i], $u['shipment_line_id']]);
                }
                return $rid;
            });
        });
    }

    /** Units come back at the cost they left with (their share of the shipment unit's value). */
    private static function bringBack(array $s, array $units, array $qtys, string $kind, string $reason, string $note, string $status, ?int $binId): int
    {
        $wh = (int) $s['warehouse_id'];
        $w  = Warehouses::get($wh);
        if (! in_array($status, ['available', 'quarantine', 'damaged'], true)) {
            throw new BizException('Returned goods are available, in quarantine or damaged.');
        }
        $bin = $binId ?: (int) ($status === 'quarantine' ? $w['quarantine_bin_id'] : ($status === 'damaged' ? $w['damaged_bin_id'] : $w['stock_bin_id']));
        Ledger::lock(array_map(static fn ($u) => [$wh, (int) $u['item_id']], $units));
        $rid   = Db::insert('customer_returns', ['number' => Numbers::next('customer_return'), 'shipment_id' => $s['id'], 'warehouse_id' => $wh, 'kind' => $kind, 'reason_code' => $reason,
            'notes' => trim($note) ?: null, 'posted_by' => Auth::id(), 'posted_at' => Db::now()]);
        $value = 0.0;
        foreach ($units as $i => $u) {
            $q    = Ledger::q($qtys[$i]);
            $unit = (float) $u['qty'] > 0 ? (float) $u['value'] / (float) $u['qty'] : 0.0;
            Ledger::add(['warehouse_id' => $wh, 'bin_id' => $bin, 'item_id' => (int) $u['item_id'], 'lot_id' => $u['lot_id'], 'serial_id' => $u['serial_id'], 'status' => $status, 'qty' => $q,
                'type' => $kind, 'doc_type' => 'customer_return', 'doc_id' => $rid, 'doc_line_id' => (int) $u['id'], 'unit_cost' => round($unit, 4), 'reason_code' => $reason, 'allow_blocked' => false]);
            Db::exec('UPDATE {shipment_units} SET qty_returned = qty_returned + ? WHERE tenant_id = {tid} AND id = ?', [$q, $u['id']]);
            $value += $q * $unit;
        }
        Db::exec('UPDATE {customer_returns} SET value = ? WHERE tenant_id = {tid} AND id = ?', [round($value, 2), $rid]);
        Audit::log($kind . '.post', 'customer_return', $rid, null, ['shipment' => $s['number'], 'reason' => $reason, 'value' => round($value, 2), 'status' => $status]);
        return $rid;
    }

    /**
     * Cancel a shipment that has not left. Reservations are released; units already picked stay in the
     * DISPATCH bin as free stock to be put back.
     */
    public static function cancel(int $id, string $why, bool $fromOrder = false): void
    {
        Db::atomic(static function () use ($id, $why, $fromOrder) {
            $s  = self::get($id, true);
            $wh = (int) $s['warehouse_id'];
            if (! $fromOrder) {
                Access::requireAt($wh, 'dispatch.manage');
            }
            if (! in_array($s['status'], ['allocated', 'picked', 'packed'], true)) {
                throw new BizException('A shipment that has left cannot be cancelled; record a failed delivery instead.');
            }
            Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item_id']], self::lines($id)));
            Alloc::release('shipment', $id);
            Db::exec("UPDATE {shipments} SET status = 'cancelled', failed_reason = ? WHERE tenant_id = {tid} AND id = ?", [mb_substr($why, 0, 255), $id]);
            Db::exec("DELETE FROM {trip_stops} WHERE tenant_id = {tid} AND kind = 'shipment' AND doc_id = ? AND status = 'pending'", [$id]);
            if (! $fromOrder) {
                Sales::refresh((int) $s['so_id']);
            }
            Audit::log('shipment.cancel', 'shipment', $id, ['status' => $s['status']], ['status' => 'cancelled'], 'success', $why);
        });
    }

    public static function pickList(int $id): array
    {
        return Alloc::active('shipment', $id);
    }

    public static function units(int $id): array
    {
        return Db::rows('SELECT u.*, i.sku, i.name, i.uom, i.decimals, l.lot_no, l.expiry_date, se.serial_no FROM {shipment_units} u JOIN {items} i ON i.id = u.item_id
                           LEFT JOIN {lots} l ON l.id = u.lot_id LEFT JOIN {serials} se ON se.id = u.serial_id WHERE u.tenant_id = {tid} AND u.shipment_id = ? ORDER BY u.id', [$id]);
    }
}
