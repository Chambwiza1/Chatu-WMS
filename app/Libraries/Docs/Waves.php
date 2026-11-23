<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Numbers;

/**
 * Wave picking: several allocated shipments of one warehouse picked in one walk. The pick list is the
 * reservations of all the shipments sorted by bin, so a picker visits each bin once. Confirming the wave
 * picks each shipment (Shipments::pick) in one transaction; a short pick on a line only shortens that
 * shipment. A shipment belongs to at most one open wave.
 */
final class Waves
{
    public static function get(int $id, bool $lock = false): array
    {
        $w = Db::row('SELECT v.*, w.code wh_code, w.name wh_name, u.name created_name, p.name picked_name FROM {pick_waves} v JOIN {warehouses} w ON w.id = v.warehouse_id
                       LEFT JOIN {users} u ON u.id = v.created_by LEFT JOIN {users} p ON p.id = v.picked_by WHERE v.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $w) {
            throw new BizException('Wave not found.');
        }
        return $w;
    }

    public static function shipments(int $id): array
    {
        return Db::rows('SELECT s.*, so.number order_no, c.name customer, c.town FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id
                          WHERE s.wave_id = ? ORDER BY s.id', [$id]);
    }

    /** Reserved picks of the wave's shipments still to pick, in walking order (bin sort, then code). */
    public static function pickList(int $id): array
    {
        return Db::rows("SELECT a.*, s.bin_id, s.lot_id, s.serial_id, s.item_id, b.code bin_code, b.sort bin_sort, l.lot_no, l.expiry_date, se.serial_no, i.sku, i.name, i.uom, i.decimals,
                                sh.number shipment_no, sh.id shipment_id
                           FROM {allocations} a JOIN {shipments} sh ON sh.id = a.doc_id AND a.doc_type = 'shipment' JOIN {stock} s ON s.id = a.stock_id JOIN {bins} b ON b.id = s.bin_id
                           JOIN {items} i ON i.id = s.item_id LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id
                          WHERE sh.wave_id = ? AND sh.status = 'allocated' AND a.status = 'active' AND a.stage = 'reserved'
                          ORDER BY b.sort, b.code, i.sku, l.expiry_date, se.serial_no, sh.id", [$id]);
    }

    /** Totals per bin and item: what to take from each bin before sorting into shipments. */
    public static function totals(array $picks): array
    {
        $t = [];
        foreach ($picks as $p) {
            $k = $p['bin_code'] . '|' . $p['sku'] . '|' . ($p['lot_no'] ?? '');
            $t[$k] ??= ['bin_code' => $p['bin_code'], 'sku' => $p['sku'], 'name' => $p['name'], 'lot_no' => $p['lot_no'], 'uom' => $p['uom'], 'decimals' => $p['decimals'], 'qty' => 0.0, 'shipments' => 0];
            $t[$k]['qty'] += (float) $p['qty'];
            $t[$k]['shipments']++;
        }
        return array_values($t);
    }

    /** Shipments that can go into a new wave: allocated, in this warehouse, not in an open wave. */
    public static function candidates(int $wh): array
    {
        return Db::rows("SELECT s.*, so.number order_no, so.required_date, c.name customer, c.town, (SELECT COUNT(*) FROM {shipment_lines} sl WHERE sl.shipment_id = s.id) n_lines
                           FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id
                           LEFT JOIN {pick_waves} v ON v.id = s.wave_id
                          WHERE s.warehouse_id = ? AND s.status = 'allocated' AND (s.wave_id IS NULL OR v.status <> 'open') ORDER BY so.required_date IS NULL, so.required_date, s.id", [$wh]);
    }

    public static function create(int $wh, array $shipmentIds, string $note = ''): int
    {
        return Db::atomic(static function () use ($wh, $shipmentIds, $note) {
            Access::requireAt($wh, 'dispatch.manage');
            $ids = array_values(array_unique(array_filter(array_map('intval', $shipmentIds))));
            sort($ids);
            if (count($ids) < 2) {
                throw new BizException('Choose at least two shipments for a wave (pick a single shipment from its own page).');
            }
            if (count($ids) > 60) {
                throw new BizException('A wave holds up to 60 shipments.');
            }
            $rows = Db::rows('SELECT s.id, s.number, s.status, s.warehouse_id, v.status wave_status FROM {shipments} s LEFT JOIN {pick_waves} v ON v.id = s.wave_id
                               WHERE s.id IN (' . implode(',', $ids) . ') ORDER BY s.id FOR UPDATE', []);
            if (count($rows) !== count($ids)) {
                throw new BizException('A chosen shipment was not found.');
            }
            foreach ($rows as $s) {
                if ((int) $s['warehouse_id'] !== $wh) {
                    throw new BizException($s['number'] . ' is in another warehouse; a wave is picked in one warehouse.');
                }
                if ($s['status'] !== 'allocated') {
                    throw new BizException($s['number'] . ' is ' . str_replace('_', ' ', $s['status']) . '; only allocated shipments can be waved.');
                }
                if ($s['wave_status'] === 'open') {
                    throw new BizException($s['number'] . ' is already in an open wave.');
                }
            }
            $note = mb_substr(trim($note), 0, 250);
            $id   = Db::insert('pick_waves', ['number' => Numbers::next('wave'), 'warehouse_id' => $wh, 'status' => 'open', 'note' => $note ?: null, 'created_by' => Auth::id(), 'created_at' => Db::now()]);
            Db::exec('UPDATE {shipments} SET wave_id = ? WHERE id IN (' . implode(',', $ids) . ')', [$id]);
            Audit::log('wave.create', 'wave', $id, null, ['shipments' => array_column($rows, 'number')]);
            return $id;
        });
    }

    /** Pick everything in the wave. $short: allocation id => quantity actually found (less than reserved). */
    public static function confirm(int $id, array $short = [], ?string $token = null): void
    {
        Db::atomic(static function () use ($id, $short, $token) {
            Db::once($token, Auth::id(), static function () use ($id, $short) {
                $w = self::get($id, true);
                Access::requireAt((int) $w['warehouse_id'], 'dispatch.manage');
                if ($w['status'] !== 'open') {
                    throw new BizException('This wave is already ' . $w['status'] . '.');
                }
                $todo = Db::rows("SELECT id FROM {shipments} WHERE wave_id = ? AND status = 'allocated' ORDER BY id", [$id]);
                if (! $todo) {
                    throw new BizException('No shipment in this wave is waiting to be picked.');
                }
                $byShipment = [];
                foreach ($short as $aid => $q) {
                    $sid = (int) Db::value("SELECT doc_id FROM {allocations} WHERE id = ? AND doc_type = 'shipment'", [(int) $aid]);
                    if ($sid) {
                        $byShipment[$sid][(int) $aid] = $q;
                    }
                }
                $picked = 0;
                foreach ($todo as $s) {
                    $sid = (int) $s['id'];
                    $mine = $byShipment[$sid] ?? [];
                    $all  = Db::rows("SELECT qty FROM {allocations} WHERE doc_type = 'shipment' AND doc_id = ? AND status = 'active' AND stage = 'reserved'", [$sid]);
                    $nothing = $all && count($mine) === count($all) && array_sum(array_map('floatval', $mine)) <= 0;
                    if ($nothing) {
                        // nothing found for this shipment: leave it allocated and take it out of the wave
                        Db::exec('UPDATE {shipments} SET wave_id = NULL WHERE id = ?', [$sid]);
                        continue;
                    }
                    Shipments::pick($sid, $mine, null);
                    $picked++;
                }
                if (! $picked) {
                    throw new BizException('Nothing was picked.');
                }
                Db::exec("UPDATE {pick_waves} SET status = 'picked', picked_by = ?, picked_at = ? WHERE id = ?", [Auth::id(), Db::now(), $id]);
                Audit::log('wave.pick', 'wave', $id, null, ['shipments' => $picked]);
                return $id;
            });
        });
    }

    public static function cancel(int $id): void
    {
        Db::atomic(static function () use ($id) {
            $w = self::get($id, true);
            Access::requireAt((int) $w['warehouse_id'], 'dispatch.manage');
            if ($w['status'] !== 'open') {
                throw new BizException('Only an open wave can be cancelled.');
            }
            Db::exec("UPDATE {shipments} SET wave_id = NULL WHERE wave_id = ? AND status = 'allocated'", [$id]);
            Db::exec("UPDATE {pick_waves} SET status = 'cancelled' WHERE id = ?", [$id]);
            Audit::log('wave.cancel', 'wave', $id, ['status' => 'open'], ['status' => 'cancelled']);
        });
    }
}
