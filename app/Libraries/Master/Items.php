<?php

namespace App\Libraries\Master;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;

/** Items (products, raw materials, packaging) and their tracking rules. */
final class Items
{
    private static array $cache = [];

    public const KINDS = ['goods' => 'Goods for sale', 'raw_material' => 'Raw material', 'finished_good' => 'Finished good (produced)', 'packaging' => 'Packaging', 'consumable' => 'Consumable'];
    public const TRACKING = ['none' => 'No tracking', 'batch' => 'Batch / lot', 'serial' => 'Serial number', 'batch_serial' => 'Batch and serial number'];

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function get(int $id): array
    {
        if (! isset(self::$cache[$id])) {
            $r = Db::row('SELECT * FROM {items} WHERE id = ?', [$id]);
            if (! $r) {
                throw new BizException('Unknown item.');
            }
            self::$cache[$id] = $r;
        }
        return self::$cache[$id];
    }

    public static function bySku(string $sku): ?array
    {
        return self::byCode($sku)[0];
    }

    /**
     * Find an item by its code, its barcode, or the barcode of one of its packs.
     * Returns [item or null, pack unit name or null] (a pack barcode says which pack was scanned).
     */
    public static function byCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [null, null];
        }
        $r = Db::row('SELECT * FROM {items} WHERE sku = ? OR (barcode = ? AND barcode <> \'\')', [strtoupper($code), $code]);
        if ($r) {
            return [$r, null];
        }
        $u = Db::row("SELECT i.*, u.name pack_name FROM {item_units} u JOIN {items} i ON i.id = u.item_id WHERE u.barcode = ? AND u.barcode <> ''", [$code]);
        if ($u) {
            $pack = $u['pack_name'];
            unset($u['pack_name']);
            return [$u, $pack];
        }
        return [null, null];
    }

    /**
     * A document line entered in a pack ("10 carton") becomes base units: quantities are multiplied by the pack
     * size and prices or costs divided by it. The pack is kept on the line (pack_unit, pack_factor) for display.
     */
    public static function convertLine(array $row, array $qtyKeys = ['qty', 'qty_accepted', 'qty_quarantine', 'qty_rejected'], array $priceKeys = ['unit_cost', 'unit_price']): array
    {
        if (empty($row['item_id'])) {
            return $row;
        }
        $item = self::get((int) $row['item_id']);
        $f    = self::factor($item, $row['unit'] ?? null);
        $unit = trim((string) ($row['unit'] ?? ''));
        unset($row['unit']);
        if ($f === 1.0) {
            return $row;
        }
        foreach ($qtyKeys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                $row[$k] = round((float) $row[$k] * $f, 3);
            }
        }
        foreach ($priceKeys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                $row[$k] = round((float) str_replace(',', '', (string) $row[$k]) / $f, 4);
            }
        }
        $row['pack_unit']   = Db::value('SELECT name FROM {item_units} WHERE item_id = ? AND name = ?', [$item['id'], $unit]);
        $row['pack_factor'] = $f;
        return $row;
    }

    /** "120 each (10 carton)" */
    public static function packText(array $item, $qty, ?string $pack, $factor): string
    {
        $s = self::fmt($item, $qty);
        if ($pack && (float) $factor > 1) {
            $n = (float) $qty / (float) $factor;
            $s .= ' (' . (abs($n - round($n)) < 0.0005 ? number_format($n) : number_format($n, 2)) . ' ' . $pack . ')';
        }
        return $s;
    }

    /** Pack sizes of an item: [[name, factor, barcode, buy_default, sell_default], ...]. */
    public static function units(int $itemId): array
    {
        return Db::rows('SELECT * FROM {item_units} WHERE item_id = ? ORDER BY factor', [$itemId]);
    }

    /** How many base units one $unit holds (1 for the base unit). */
    public static function factor(array $item, ?string $unit): float
    {
        $unit = trim((string) $unit);
        if ($unit === '' || strcasecmp($unit, $item['uom']) === 0) {
            return 1.0;
        }
        $f = Db::value('SELECT factor FROM {item_units} WHERE item_id = ? AND name = ?', [$item['id'], $unit]);
        if ($f === null) {
            throw new BizException($item['sku'] . ' has no pack called "' . $unit . '".');
        }
        return (float) $f;
    }

    /** Replace an item's pack sizes. $units: [{name, factor, barcode, buy_default, sell_default}]. */
    private static function saveUnits(int $id, array $item, array $units): void
    {
        $seen = [];
        $rows = [];
        foreach ($units as $u) {
            $name = strtolower(trim((string) ($u['name'] ?? '')));
            if ($name === '' && trim((string) ($u['factor'] ?? '')) === '') {
                continue;
            }
            $f = round((float) ($u['factor'] ?? 0), 4);
            if ($name === '' || mb_strlen($name) > 12 || ! preg_match('/^[a-z0-9 .\/-]+$/', $name)) {
                throw new BizException('Pack names are 1 to 12 letters or digits (carton, pallet, 6-pack).');
            }
            if (strcasecmp($name, $item['uom']) === 0) {
                throw new BizException('"' . $name . '" is already the base unit of ' . $item['sku'] . '.');
            }
            if ($f <= 1) {
                throw new BizException('Pack "' . $name . '": say how many ' . $item['uom'] . ' it holds (more than 1).');
            }
            if ((int) $item['decimals'] === 0 && abs($f - round($f)) > 0.0001) {
                throw new BizException('Pack "' . $name . '": ' . $item['sku'] . ' is counted in whole ' . $item['uom'] . ', so a pack holds a whole number.');
            }
            if (isset($seen[$name])) {
                throw new BizException('Pack "' . $name . '" is listed twice.');
            }
            $seen[$name] = true;
            $bc = trim((string) ($u['barcode'] ?? ''));
            if ($bc !== '' && (Db::value("SELECT 1 FROM {items} WHERE barcode = ? OR sku = ?", [$bc, strtoupper($bc)]) || Db::value('SELECT 1 FROM {item_units} WHERE barcode = ? AND item_id <> ?', [$bc, $id]))) {
                throw new BizException('Barcode ' . $bc . ' is already used by another item or pack.');
            }
            $rows[] = ['item_id' => $id, 'name' => $name, 'factor' => $f, 'barcode' => $bc ?: null, 'buy_default' => ! empty($u['buy_default']) ? 1 : 0, 'sell_default' => ! empty($u['sell_default']) ? 1 : 0];
        }
        Db::exec('DELETE FROM {item_units} WHERE item_id = ?', [$id]);
        foreach ($rows as $r) {
            Db::insert('item_units', $r);
        }
    }

    public static function lots(array $item): bool
    {
        return in_array($item['tracking'], ['batch', 'batch_serial'], true);
    }

    public static function serials(array $item): bool
    {
        return in_array($item['tracking'], ['serial', 'batch_serial'], true);
    }

    /** Refuse a lot or serial where the item has none, and a missing one where it needs it. */
    public static function checkIdentity(array $item, ?int $lotId, ?int $serialId): void
    {
        if (self::lots($item) && ! $lotId) {
            throw new BizException($item['sku'] . ' is tracked by batch: give the batch number.');
        }
        if (! self::lots($item) && $lotId) {
            throw new BizException($item['sku'] . ' is not tracked by batch.');
        }
        if (self::serials($item) && ! $serialId) {
            throw new BizException($item['sku'] . ' is tracked by serial number: give each serial number.');
        }
        if (! self::serials($item) && $serialId) {
            throw new BizException($item['sku'] . ' has no serial numbers.');
        }
    }

    public static function checkQty(array $item, float $qty): void
    {
        $dec = (int) $item['decimals'];
        if (abs(round($qty, $dec) - $qty) > 0.0005) {
            throw new BizException($item['sku'] . ' is counted in whole ' . $item['uom'] . ($dec ? ' to ' . $dec . ' decimals' : '') . '.');
        }
    }

    public static function fmt(array $item, $qty): string
    {
        return number_format((float) $qty, (int) $item['decimals']) . ' ' . $item['uom'];
    }

    public static function save(array $d, ?int $id = null): int
    {
        Access::require('item.manage');
        $sku = strtoupper(trim((string) ($d['sku'] ?? '')));
        if ($sku === '' || ! preg_match('/^[A-Z0-9._\/-]{1,40}$/', $sku)) {
            throw new BizException('Item code: letters, numbers, dot, dash, slash; up to 40 characters.');
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BizException('Give the item a name.');
        }
        $tracking = (string) ($d['tracking'] ?? 'none');
        if (! isset(self::TRACKING[$tracking])) {
            throw new BizException('Unknown tracking.');
        }
        $kind = (string) ($d['kind'] ?? 'goods');
        if (! isset(self::KINDS[$kind])) {
            throw new BizException('Unknown item kind.');
        }
        $decimals = max(0, min(3, (int) ($d['decimals'] ?? 0)));
        if ($tracking !== 'none' && in_array($tracking, ['serial', 'batch_serial'], true) && $decimals > 0) {
            throw new BizException('Serial-numbered items are counted in whole units.');
        }
        $row = [
            'sku' => $sku, 'barcode' => trim((string) ($d['barcode'] ?? '')) ?: null, 'name' => mb_substr($name, 0, 150),
            'description' => trim((string) ($d['description'] ?? '')) ?: null, 'category_id' => ($d['category_id'] ?? '') !== '' ? (int) $d['category_id'] : null,
            'kind' => $kind, 'uom' => trim((string) ($d['uom'] ?? 'each')) ?: 'each', 'decimals' => $decimals, 'tracking' => $tracking,
            'expiry_required' => ! empty($d['expiry_required']) ? 1 : 0, 'shelf_life_days' => ($d['shelf_life_days'] ?? '') !== '' ? max(0, (int) $d['shelf_life_days']) : null,
            'min_shelf_days' => max(0, (int) ($d['min_shelf_days'] ?? 0)), 'pick_policy' => in_array($d['pick_policy'] ?? '', ['fifo', 'fefo'], true) ? $d['pick_policy'] : null,
            'weight_kg' => ($d['weight_kg'] ?? '') !== '' ? (float) $d['weight_kg'] : null, 'volume_m3' => ($d['volume_m3'] ?? '') !== '' ? (float) $d['volume_m3'] : null,
            'reorder_level' => max(0, (float) ($d['reorder_level'] ?? 0)), 'reorder_qty' => max(0, (float) ($d['reorder_qty'] ?? 0)),
            'sell_price' => max(0, (float) str_replace(',', '', (string) ($d['sell_price'] ?? 0))), 'active' => isset($d['active']) ? (int) (bool) $d['active'] : 1,
        ];
        if ($row['expiry_required'] && ! self::lots($row)) {
            throw new BizException('An expiry date belongs to a batch: choose batch tracking for items with expiry dates.');
        }
        return Db::atomic(static function () use ($row, $id, $sku, $d) {
            $clash = Db::value('SELECT id FROM {items} WHERE sku = ? AND id <> ?', [$sku, $id ?? 0]);
            if ($clash) {
                throw new BizException('Item code ' . $sku . ' is already used.');
            }
            if ($row['barcode'] && (Db::value('SELECT 1 FROM {item_units} WHERE barcode = ? AND item_id <> ?', [$row['barcode'], $id ?? 0])
                || Db::value('SELECT 1 FROM {items} WHERE barcode = ? AND id <> ?', [$row['barcode'], $id ?? 0]))) {
                throw new BizException('Barcode ' . $row['barcode'] . ' is already used by another item or pack.');
            }
            if ($id) {
                $old = Db::row('SELECT * FROM {items} WHERE id = ? FOR UPDATE', [$id]);
                if (! $old) {
                    throw new BizException('Unknown item.');
                }
                $hasStock = (bool) Db::value('SELECT 1 FROM {movements} WHERE item_id = ? LIMIT 1', [$id]);
                if ($hasStock && ($old['tracking'] !== $row['tracking'] || (int) $old['decimals'] > $row['decimals'])) {
                    throw new BizException('Tracking and decimals cannot change once the item has stock movements. Create a new item code instead.');
                }
                $sets = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($row)));
                Db::exec("UPDATE {items} SET $sets, updated_at = ? WHERE id = ?", array_merge(array_values($row), [Db::now(), $id]));
                Audit::log('item.update', 'item', $id, array_intersect_key($old, $row), $row);
                if (array_key_exists('units', $d)) {
                    self::saveUnits($id, $row + ['id' => $id, 'sku' => $sku], (array) $d['units']);
                }
            } else {
                $id = Db::insert('items', $row + ['created_at' => Db::now()]);
                Audit::log('item.create', 'item', $id, null, $row);
                if (! empty($d['units'])) {
                    self::saveUnits($id, $row + ['id' => $id, 'sku' => $sku], (array) $d['units']);
                }
            }
            self::flush();
            return $id;
        });
    }

    public static function categories(): array
    {
        return Db::rows('SELECT * FROM {item_categories} WHERE active = 1 ORDER BY name');
    }

    public static function addCategory(string $name): int
    {
        Access::require('item.manage');
        $name = trim($name);
        if ($name === '') {
            throw new BizException('Give the category a name.');
        }
        $id = Db::value('SELECT id FROM {item_categories} WHERE name = ?', [$name]);
        return $id ? (int) $id : Db::insert('item_categories', ['name' => mb_substr($name, 0, 100)]);
    }

    /** Active items for pick lists. */
    public static function options(?array $kinds = null): array
    {
        $w = 'active = 1';
        $b = [];
        if ($kinds) {
            $w .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
            $b = $kinds;
        }
        return Db::rows("SELECT i.id, i.sku, i.name, i.uom, i.tracking, i.decimals, i.expiry_required,
                                (SELECT GROUP_CONCAT(CONCAT(u.name, ':', u.factor + 0, ':', u.buy_default, u.sell_default) ORDER BY u.factor SEPARATOR '|') FROM {item_units} u WHERE u.item_id = i.id) units
                           FROM {items} i WHERE " . str_replace(['active', 'kind'], ['i.active', 'i.kind'], $w) . ' ORDER BY i.sku', $b);
    }
}
