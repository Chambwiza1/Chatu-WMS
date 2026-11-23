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
 * Stock counts (cycle counts and full counts): open → submitted → posted.
 * The count list is everything the system holds in the chosen zone, bin range or item. Counters enter what they
 * find, including stock that is not on the list. A blind count hides the system quantity from counters.
 * The variance is worked out against the system quantity when the count is approved, and posted as count
 * movements (gains at the latest cost, losses at FIFO). Approval is by someone with approval rights other than
 * the person who submitted it, unless the variance is within the automatic limit and the submitter may approve.
 */
final class Counts
{
    public static function get(int $id, bool $lock = false): array
    {
        $c = Db::row('SELECT * FROM {counts} WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $c) {
            throw new BizException('Unknown count.');
        }
        return $c;
    }

    /** $d: warehouse_id, zone_id, item_id, bin_from, bin_to (codes), blind, notes. */
    public static function start(array $d): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'count.manage');
        return Db::atomic(static function () use ($d, $wh) {
            $w = ['s.warehouse_id = ?', "b.type NOT IN ('dispatch')"];
            $b = [$wh];
            $zone = ($d['zone_id'] ?? '') !== '' ? (int) $d['zone_id'] : null;
            $item = ($d['item_id'] ?? '') !== '' ? (int) $d['item_id'] : null;
            if ($zone) {
                $w[] = 'b.zone_id = ?';
                $b[] = $zone;
            }
            if ($item) {
                $w[] = 's.item_id = ?';
                $b[] = $item;
            }
            if (trim((string) ($d['bin_from'] ?? '')) !== '') {
                $w[] = 'b.code >= ?';
                $b[] = strtoupper(trim((string) $d['bin_from']));
            }
            if (trim((string) ($d['bin_to'] ?? '')) !== '') {
                $w[] = 'b.code <= ?';
                $b[] = strtoupper(trim((string) $d['bin_to']));
            }
            $id = Db::insert('counts', ['number' => Numbers::next('count'), 'warehouse_id' => $wh, 'zone_id' => $zone, 'item_id' => $item, 'blind' => ! empty($d['blind']) ? 1 : 0,
                'status' => 'open', 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'created_by' => Auth::id(), 'created_at' => Db::now()]);
            $rows = Db::rows('SELECT s.* FROM {stock} s JOIN {bins} b ON b.id = s.bin_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.sort, b.code, s.item_id, s.lot_id, s.serial_id', $b);
            foreach ($rows as $r) {
                Db::insert('count_lines', ['count_id' => $id, 'bin_id' => $r['bin_id'], 'item_id' => $r['item_id'], 'lot_id' => $r['lot_id'], 'serial_id' => $r['serial_id'], 'status' => $r['status'], 'expected_qty' => $r['qty']]);
            }
            Audit::log('count.start', 'count', $id, null, ['lines' => count($rows), 'zone' => $zone, 'item' => $item]);
            return $id;
        });
    }

    /**
     * Enter counts. $counts: line_id => counted qty ('' = not counted yet).
     * $extras: [{bin_id, item_id, qty, lot_no, expiry_date, serial_no, status}] found but not on the list.
     */
    public static function enter(int $id, array $counts, array $extras = []): void
    {
        Db::atomic(static function () use ($id, $counts, $extras) {
            $c = self::get($id, true);
            Access::requireAt((int) $c['warehouse_id'], 'count.enter');
            if ($c['status'] !== 'open') {
                throw new BizException('This count is ' . $c['status'] . '; counts can no longer be entered.');
            }
            foreach ($counts as $lineId => $q) {
                $line = Db::row('SELECT * FROM {count_lines} WHERE id = ? AND count_id = ? FOR UPDATE', [(int) $lineId, $id]);
                if (! $line) {
                    throw new BizException('That line is not on this count.');
                }
                if ($q === '' || $q === null) {
                    Db::exec('UPDATE {count_lines} SET counted_qty = NULL, counted_by = NULL WHERE id = ?', [$lineId]);
                    continue;
                }
                $q = Ledger::q($q);
                if ($q < 0 || ($line['serial_id'] && $q > 1)) {
                    throw new BizException('Counts are zero or more (a serial number is 0 or 1).');
                }
                Items::checkQty(Items::get((int) $line['item_id']), $q);
                Db::exec('UPDATE {count_lines} SET counted_qty = ?, counted_by = ? WHERE id = ?', [$q, Auth::id(), $lineId]);
            }
            foreach ($extras as $x) {
                $q = Ledger::q($x['qty'] ?? 0);
                if ($q <= 0) {
                    continue;
                }
                $item = Items::get((int) ($x['item_id'] ?? 0));
                $bin  = Ledger::bin((int) ($x['bin_id'] ?? 0));
                if ((int) $bin['warehouse_id'] !== (int) $c['warehouse_id']) {
                    throw new BizException('Choose a bin in this warehouse.');
                }
                $lotId = null;
                if (Items::lots($item)) {
                    $lotId = Lots::resolve($item, (string) ($x['lot_no'] ?? ''), ['expiry_date' => $x['expiry_date'] ?? null], 'count', $id);
                }
                $serialId = null;
                if (Items::serials($item)) {
                    if (abs($q - 1) > Ledger::EPS) {
                        throw new BizException('Add found serial-numbered units one per line.');
                    }
                    $sn = trim((string) ($x['serial_no'] ?? ''));
                    $s  = Db::row('SELECT * FROM {serials} WHERE item_id = ? AND serial_no = ?', [$item['id'], $sn]);
                    $serialId = $s ? (int) $s['id'] : Db::insert('serials', ['item_id' => $item['id'], 'serial_no' => $sn, 'lot_id' => $lotId, 'status' => 'lost', 'created_at' => Db::now()]);
                }
                $status = in_array($x['status'] ?? '', Ledger::STATUSES, true) ? $x['status'] : 'available';
                $dup = Db::value('SELECT id FROM {count_lines} WHERE count_id = ? AND bin_id = ? AND item_id = ? AND COALESCE(lot_id, 0) = ? AND COALESCE(serial_id, 0) = ? AND status = ?', [$id, $bin['id'], $item['id'], (int) $lotId, (int) $serialId, $status]);
                if ($dup) {
                    Db::exec('UPDATE {count_lines} SET counted_qty = ?, counted_by = ? WHERE id = ?', [$q, Auth::id(), $dup]);
                } else {
                    Db::insert('count_lines', ['count_id' => $id, 'bin_id' => $bin['id'], 'item_id' => $item['id'], 'lot_id' => $lotId, 'serial_id' => $serialId, 'status' => $status, 'expected_qty' => 0, 'counted_qty' => $q, 'is_extra' => 1, 'counted_by' => Auth::id()]);
                }
            }
            Audit::log('count.enter', 'count', $id, null, ['lines' => count($counts), 'extras' => count($extras)]);
        });
    }

    /** Variance per line against the system quantity now. */
    public static function variances(int $id): array
    {
        $out = [];
        foreach (Db::rows('SELECT cl.*, i.sku, i.name, i.uom, i.decimals, b.code bin_code, l.lot_no, se.serial_no FROM {count_lines} cl JOIN {items} i ON i.id = cl.item_id JOIN {bins} b ON b.id = cl.bin_id
                             LEFT JOIN {lots} l ON l.id = cl.lot_id LEFT JOIN {serials} se ON se.id = cl.serial_id WHERE cl.count_id = ? ORDER BY b.sort, b.code, i.sku, cl.id', [$id]) as $l) {
            $now = (float) Db::value('SELECT COALESCE(SUM(qty), 0) FROM {stock} WHERE bin_id = ? AND item_id = ? AND lot_key = ? AND serial_key = ? AND status = ?', [$l['bin_id'], $l['item_id'], (int) $l['lot_id'], (int) $l['serial_id'], $l['status']]);
            $l['system_qty'] = $now;
            $l['variance']   = $l['counted_qty'] === null ? null : Ledger::q((float) $l['counted_qty'] - $now);
            $l['unit_cost']  = Valuation::latestUnit((int) $l['item_id'], null);
            $out[] = $l;
        }
        return $out;
    }

    public static function submit(int $id): void
    {
        Db::atomic(static function () use ($id) {
            $c = self::get($id, true);
            Access::requireAt((int) $c['warehouse_id'], 'count.manage');
            if ($c['status'] !== 'open') {
                throw new BizException('This count is already ' . $c['status'] . '.');
            }
            $missing = (int) Db::value('SELECT COUNT(*) FROM {count_lines} WHERE count_id = ? AND counted_qty IS NULL', [$id]);
            if ($missing) {
                throw new BizException($missing . ' line(s) have not been counted. Enter 0 for what is not there.');
            }
            $value = 0.0;
            foreach (self::variances($id) as $l) {
                $value += abs((float) $l['variance'] * (float) $l['unit_cost']);
            }
            Db::exec("UPDATE {counts} SET status = 'submitted', submitted_by = ?, submitted_at = ?, value = ? WHERE id = ?", [Auth::id(), Db::now(), round($value, 2), $id]);
            Audit::log('count.submit', 'count', $id, null, ['variance_value' => round($value, 2)]);
            $limit = (float) Settings::get('count_auto_limit');
            if ($limit > 0 && $value <= $limit && Access::canAt((int) $c['warehouse_id'], 'stock.approve')) {
                self::post($id, 'Within the automatic limit');
            } else {
                Notify::holders('stock.approve', (int) $c['warehouse_id'], 'count', 'Count ' . $c['number'] . ' waits for approval', 'Variance worth ' . number_format($value, 2), '/counts/' . $id, Auth::id());
            }
        });
    }

    /** Send a submitted count back for recounting. */
    public static function reopen(int $id, string $why): void
    {
        Db::atomic(static function () use ($id, $why) {
            $c = self::get($id, true);
            Access::requireAt((int) $c['warehouse_id'], 'stock.approve');
            if ($c['status'] !== 'submitted') {
                throw new BizException('Only a submitted count can be sent back.');
            }
            Db::exec("UPDATE {counts} SET status = 'open' WHERE id = ?", [$id]);
            Audit::log('count.reopen', 'count', $id, null, null, 'success', $why);
            Notify::user((int) $c['submitted_by'], 'count', 'Count ' . $c['number'] . ' was sent back for a recount', $why, '/counts/' . $id);
        });
    }

    public static function approve(int $id, string $note = ''): void
    {
        Db::atomic(static function () use ($id, $note) {
            $c = self::get($id, true);
            Access::requireAt((int) $c['warehouse_id'], 'stock.approve');
            if ($c['status'] !== 'submitted') {
                throw new BizException('Only a submitted count can be approved.');
            }
            if ((int) $c['submitted_by'] === Auth::id() || (int) Db::value('SELECT COUNT(*) FROM {count_lines} WHERE count_id = ? AND counted_by = ?', [$id, Auth::id()]) > 0) {
                throw new BizException('You submitted or counted this count: someone else must approve it.');
            }
            self::post($id, $note);
        });
    }

    public static function cancel(int $id, string $why): void
    {
        Db::atomic(static function () use ($id, $why) {
            $c = self::get($id, true);
            Access::requireAt((int) $c['warehouse_id'], 'count.manage');
            if (! in_array($c['status'], ['open', 'submitted'], true)) {
                throw new BizException('This count is already ' . $c['status'] . '.');
            }
            Db::exec("UPDATE {counts} SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?", ["\nCancelled: " . mb_substr($why, 0, 200), $id]);
            Audit::log('count.cancel', 'count', $id, null, null, 'success', $why);
        });
    }

    private static function post(int $id, string $note): void
    {
        $c  = self::get($id, true);
        $wh = (int) $c['warehouse_id'];
        $lines = Db::rows('SELECT * FROM {count_lines} WHERE count_id = ? ORDER BY id', [$id]);
        Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['item_id']], $lines));
        $net = 0.0;
        foreach ($lines as $l) {
            $row = Db::row('SELECT * FROM {stock} WHERE bin_id = ? AND item_id = ? AND lot_key = ? AND serial_key = ? AND status = ? FOR UPDATE', [$l['bin_id'], $l['item_id'], (int) $l['lot_id'], (int) $l['serial_id'], $l['status']]);
            $now = $row ? (float) $row['qty'] : 0.0;
            $var = Ledger::q((float) $l['counted_qty'] - $now);
            Db::exec('UPDATE {count_lines} SET posted_qty = ? WHERE id = ?', [$var, $l['id']]);
            if (abs($var) <= Ledger::EPS) {
                continue;
            }
            $base = ['doc_type' => 'count', 'doc_id' => $id, 'doc_line_id' => (int) $l['id']];
            if ($var < 0) {
                if (-$var - ((float) $row['qty'] - (float) $row['qty_allocated']) > Ledger::EPS) {
                    $item = Items::get((int) $l['item_id']);
                    throw new BizException($item['sku'] . ' in bin ' . Ledger::bin((int) $l['bin_id'])['code'] . ' is short, but part of it is reserved for an order or transfer. Release that reservation first, then approve.');
                }
                $r = Ledger::remove($base + ['stock_id' => (int) $row['id'], 'qty' => -$var, 'type' => 'count_out', 'serial_status' => 'lost']);
                $net -= $r['value'];
            } else {
                if ($l['serial_id']) {
                    $s = Db::row('SELECT * FROM {serials} WHERE id = ? FOR UPDATE', [$l['serial_id']]);
                    if ($s['status'] === 'in_stock' && (int) $s['warehouse_id'] !== $wh) {
                        throw new BizException('Serial ' . $s['serial_no'] . ' is recorded in stock in another warehouse. Sort that out first.');
                    }
                    if ($s['status'] === 'in_transit') {
                        throw new BizException('Serial ' . $s['serial_no'] . ' is on a transfer that has not been received. Receive the transfer instead.');
                    }
                }
                $unit = Valuation::latestUnit((int) $l['item_id'], $wh);
                Ledger::add($base + ['warehouse_id' => $wh, 'bin_id' => (int) $l['bin_id'], 'item_id' => (int) $l['item_id'], 'lot_id' => $l['lot_id'], 'serial_id' => $l['serial_id'],
                    'status' => $l['status'], 'qty' => $var, 'type' => 'count_in', 'unit_cost' => $unit, 'allow_blocked' => true]);
                $net += $var * $unit;
            }
        }
        Db::exec("UPDATE {counts} SET status = 'posted', posted_by = ?, posted_at = ? WHERE id = ?", [Auth::id(), Db::now(), $id]);
        Audit::log('count.post', 'count', $id, null, ['net_value' => round($net, 2)], 'success', $note);
        if ((int) $c['submitted_by'] !== Auth::id()) {
            Notify::user((int) $c['submitted_by'], 'count', 'Count ' . $c['number'] . ' was approved and posted', null, '/counts/' . $id);
        }
    }
}
