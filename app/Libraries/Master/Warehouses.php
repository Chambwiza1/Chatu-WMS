<?php

namespace App\Libraries\Master;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;

/**
 * Warehouses, zones and bins. Every warehouse gets six system bins when it is created:
 * RECEIVING, STOCK (default storage), DISPATCH, QUARANTINE, DAMAGED and PRODUCTION.
 */
final class Warehouses
{
    public const SYSTEM_BINS = [
        'receiving_bin_id'  => ['RECEIVING', 'receiving', 0],
        'stock_bin_id'      => ['STOCK', 'storage', 1],
        'dispatch_bin_id'   => ['DISPATCH', 'dispatch', 0],
        'quarantine_bin_id' => ['QUARANTINE', 'quarantine', 0],
        'damaged_bin_id'    => ['DAMAGED', 'damaged', 0],
        'production_bin_id' => ['PRODUCTION', 'production', 0],
    ];
    public const BIN_TYPES = ['receiving' => 'Receiving', 'storage' => 'Storage', 'picking' => 'Picking face', 'dispatch' => 'Dispatch', 'quarantine' => 'Quarantine', 'damaged' => 'Damaged', 'production' => 'Production'];

    public static function get(int $id): array
    {
        $w = Db::row('SELECT * FROM {warehouses} WHERE tenant_id = {tid} AND id = ?', [$id]);
        if (! $w) {
            throw new BizException('Unknown warehouse.');
        }
        return $w;
    }

    public static function create(array $d): int
    {
        Access::require('warehouse.setup');
        if (! Access::companyWide('warehouse.setup')) {
            throw new BizException('Only someone who sets up warehouses for the whole company can add a warehouse.');
        }
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9-]{1,20}$/', $code)) {
            throw new BizException('Warehouse code: letters, numbers and dash; up to 20 characters.');
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BizException('Give the warehouse a name.');
        }
        return Db::atomic(static function () use ($d, $code, $name) {
            if (Db::value('SELECT id FROM {warehouses} WHERE tenant_id = {tid} AND code = ?', [$code])) {
                throw new BizException('Warehouse code ' . $code . ' is already used.');
            }
            $id = Db::insert('warehouses', [
                'code' => $code, 'name' => $name, 'kind' => in_array($d['kind'] ?? '', ['warehouse', 'depot', 'factory', 'store'], true) ? $d['kind'] : 'warehouse',
                'address' => trim((string) ($d['address'] ?? '')) ?: null, 'town' => trim((string) ($d['town'] ?? '')) ?: null,
                'pick_policy' => ($d['pick_policy'] ?? 'fefo') === 'fifo' ? 'fifo' : 'fefo', 'created_at' => Db::now(),
            ]);
            $set = [];
            $sort = 0;
            foreach (self::SYSTEM_BINS as $col => [$bcode, $type, $pickable]) {
                $set[$col] = Db::insert('bins', ['warehouse_id' => $id, 'code' => $bcode, 'type' => $type, 'pickable' => $pickable, 'is_system' => 1, 'sort' => $sort++]);
            }
            Db::exec('UPDATE {warehouses} SET ' . implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($set))) . ' WHERE tenant_id = {tid} AND id = ?', array_merge(array_values($set), [$id]));
            Audit::log('warehouse.create', 'warehouse', $id, null, ['code' => $code, 'name' => $name]);
            return $id;
        });
    }

    public static function update(int $id, array $d): void
    {
        Access::requireAt($id, 'warehouse.setup');
        $w = self::get($id);
        $row = [
            'name' => trim((string) ($d['name'] ?? $w['name'])) ?: $w['name'],
            'kind' => in_array($d['kind'] ?? '', ['warehouse', 'depot', 'factory', 'store'], true) ? $d['kind'] : $w['kind'],
            'address' => trim((string) ($d['address'] ?? '')) ?: null, 'town' => trim((string) ($d['town'] ?? '')) ?: null,
            'pick_policy' => ($d['pick_policy'] ?? $w['pick_policy']) === 'fifo' ? 'fifo' : 'fefo',
            'active' => isset($d['active']) ? (int) (bool) $d['active'] : (int) $w['active'],
        ];
        if (! $row['active'] && Db::value('SELECT 1 FROM {stock} WHERE tenant_id = {tid} AND warehouse_id = ? LIMIT 1', [$id])) {
            throw new BizException('The warehouse still holds stock. Move or transfer it first.');
        }
        Db::atomic(static function () use ($id, $row, $w) {
            Db::exec('UPDATE {warehouses} SET name = ?, kind = ?, address = ?, town = ?, pick_policy = ?, active = ? WHERE tenant_id = {tid} AND id = ?', array_merge(array_values($row), [$id]));
            Audit::log('warehouse.update', 'warehouse', $id, array_intersect_key($w, $row), $row);
        });
    }

    public static function saveZone(int $wh, array $d): int
    {
        Access::requireAt($wh, 'warehouse.setup');
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9-]{1,20}$/', $code)) {
            throw new BizException('Zone code: letters, numbers and dash.');
        }
        return Db::atomic(static function () use ($wh, $d, $code) {
            if (Db::value('SELECT id FROM {zones} WHERE tenant_id = {tid} AND warehouse_id = ? AND code = ?', [$wh, $code])) {
                throw new BizException('Zone ' . $code . ' already exists here.');
            }
            $id = Db::insert('zones', ['warehouse_id' => $wh, 'code' => $code, 'name' => trim((string) ($d['name'] ?? '')) ?: $code]);
            Audit::log('zone.create', 'zone', $id, null, ['warehouse_id' => $wh, 'code' => $code]);
            return $id;
        });
    }

    /**
     * Add bins. A single code ("A-01-1") or a range: prefix A, aisles 1-3, bays 1-10, levels 1-4
     * gives A-01-01-1 ... A-03-10-4.
     */
    public static function addBins(int $wh, array $d): int
    {
        Access::requireAt($wh, 'warehouse.setup');
        $type = (string) ($d['type'] ?? 'storage');
        if (! isset(self::BIN_TYPES[$type])) {
            throw new BizException('Unknown bin type.');
        }
        $zone = ($d['zone_id'] ?? '') !== '' ? (int) $d['zone_id'] : null;
        if ($zone && ! Db::value('SELECT 1 FROM {zones} WHERE tenant_id = {tid} AND id = ? AND warehouse_id = ?', [$zone, $wh])) {
            throw new BizException('That zone is in another warehouse.');
        }
        $codes = [];
        if (trim((string) ($d['code'] ?? '')) !== '') {
            $codes[] = strtoupper(trim((string) $d['code']));
        } else {
            $prefix = strtoupper(trim((string) ($d['prefix'] ?? '')));
            [$a1, $a2] = self::range($d['aisles'] ?? '1');
            [$b1, $b2] = self::range($d['bays'] ?? '1');
            [$l1, $l2] = self::range($d['levels'] ?? '1');
            for ($a = $a1; $a <= $a2; $a++) {
                for ($b = $b1; $b <= $b2; $b++) {
                    for ($l = $l1; $l <= $l2; $l++) {
                        $codes[] = ltrim(($prefix !== '' ? $prefix . '-' : '') . sprintf('%02d-%02d-%d', $a, $b, $l), '-');
                    }
                }
            }
        }
        if (count($codes) > 2000) {
            throw new BizException('Add at most 2,000 bins at a time.');
        }
        foreach ($codes as $c) {
            if (! preg_match('/^[A-Z0-9-]{1,30}$/', $c)) {
                throw new BizException('Bin code ' . $c . ': letters, numbers and dash; up to 30 characters.');
            }
        }
        $pickable = in_array($type, ['storage', 'picking'], true) ? 1 : 0;
        return Db::atomic(static function () use ($wh, $codes, $zone, $type, $pickable) {
            $n = 0;
            $sort = (int) Db::value('SELECT COALESCE(MAX(sort), 0) FROM {bins} WHERE tenant_id = {tid} AND warehouse_id = ?', [$wh]);
            foreach ($codes as $c) {
                if (Db::value('SELECT 1 FROM {bins} WHERE tenant_id = {tid} AND warehouse_id = ? AND code = ?', [$wh, $c])) {
                    continue;
                }
                Db::insert('bins', ['warehouse_id' => $wh, 'zone_id' => $zone, 'code' => $c, 'type' => $type, 'pickable' => $pickable, 'sort' => ++$sort]);
                $n++;
            }
            Audit::log('bin.create', 'warehouse', $wh, null, ['bins' => $n, 'type' => $type]);
            return $n;
        });
    }

    private static function range($s): array
    {
        $s = trim((string) $s);
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $s, $m)) {
            return [min((int) $m[1], (int) $m[2]), max((int) $m[1], (int) $m[2])];
        }
        $n = max(1, (int) $s);
        return [$n, $n];
    }

    public static function setBinStatus(int $binId, string $status, string $note = ''): void
    {
        $bin = Db::row('SELECT * FROM {bins} WHERE tenant_id = {tid} AND id = ?', [$binId]);
        if (! $bin) {
            throw new BizException('Unknown bin.');
        }
        Access::requireAt((int) $bin['warehouse_id'], 'warehouse.setup');
        if (! in_array($status, ['active', 'blocked', 'inactive'], true)) {
            throw new BizException('Unknown bin status.');
        }
        if ($bin['is_system'] && $status !== 'active') {
            throw new BizException('System bins stay active.');
        }
        if ($status === 'inactive' && Db::value('SELECT 1 FROM {stock} WHERE tenant_id = {tid} AND bin_id = ? LIMIT 1', [$binId])) {
            throw new BizException('Bin ' . $bin['code'] . ' still holds stock. Block it instead, or move the stock out first.');
        }
        Db::atomic(static function () use ($binId, $status, $note, $bin) {
            Db::exec('UPDATE {bins} SET status = ?, note = ? WHERE tenant_id = {tid} AND id = ?', [$status, $note !== '' ? mb_substr($note, 0, 255) : null, $binId]);
            Audit::log('bin.status', 'bin', $binId, ['status' => $bin['status']], ['status' => $status, 'note' => $note]);
        });
    }

    /** Bins of a warehouse for pick lists (optionally only active and of given types). */
    public static function bins(int $wh, ?array $types = null, bool $activeOnly = true): array
    {
        $w = 'b.warehouse_id = ?';
        $b = [$wh];
        if ($activeOnly) {
            $w .= " AND b.status = 'active'";
        }
        if ($types) {
            $w .= ' AND b.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $b = array_merge($b, $types);
        }
        return Db::rows("SELECT b.*, z.code zone_code FROM {bins} b LEFT JOIN {zones} z ON z.id = b.zone_id WHERE b.tenant_id = {tid} AND $w ORDER BY b.is_system DESC, b.sort, b.code", $b);
    }

    public static function binByCode(int $wh, string $code): ?array
    {
        $r = Db::row('SELECT * FROM {bins} WHERE tenant_id = {tid} AND warehouse_id = ? AND code = ?', [$wh, strtoupper(trim($code))]);
        return $r ?: null;
    }

    /** Warehouses the user may work in with $perm. */
    public static function forUser(string $perm): array
    {
        $ids = Access::warehouses($perm);
        if (! $ids) {
            return [];
        }
        return Db::rows('SELECT * FROM {warehouses} WHERE tenant_id = {tid} AND active = 1 AND id IN (' . implode(',', $ids) . ') ORDER BY code');
    }

    public static function all(): array
    {
        return Db::rows('SELECT * FROM {warehouses} WHERE tenant_id = {tid} ORDER BY active DESC, code');
    }
}
