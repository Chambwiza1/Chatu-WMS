<?php

namespace App\Commands;

use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Receipts;
use App\Libraries\Master\Items;
use RuntimeException;
use Throwable;

/** Helpers and assertions for the acceptance tests. $c is the demo company from Setup\Demo::build(). */
final class TestKit
{
    public array $c;

    public function __construct(array $ctx)
    {
        $this->c = $ctx;
    }

    public function as(string $user): self
    {
        Auth::actAs($this->c['users'][$user]);
        return $this;
    }

    public function uid(string $user): int
    {
        return $this->c['users'][$user];
    }

    public function wh(string $code): int
    {
        return $this->c['wh'][$code];
    }

    public function item(string $sku): int
    {
        return $this->c['item'][$sku];
    }

    public function bin(string $wh, string $code): int
    {
        return $this->c['bin'][$wh . ':' . $code] ?? (int) Db::value('SELECT b.id FROM {bins} b JOIN {warehouses} w ON w.id = b.warehouse_id WHERE b.tenant_id = {tid} AND w.code = ? AND b.code = ?', [$wh, $code]);
    }

    public function eq($expected, $actual, string $msg = ''): void
    {
        if (is_float($expected) || is_float($actual)) {
            if (is_numeric($actual) && is_numeric($expected) && abs((float) $expected - (float) $actual) < 0.006) {
                return;
            }
        } elseif ($expected === $actual) {
            return;
        }
        throw new RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public function true($cond, string $msg): void
    {
        if (! $cond) {
            throw new RuntimeException('Expected true: ' . $msg);
        }
    }

    /** Expect $fn to throw with a message containing $needle. */
    public function throws(callable $fn, string $needle, ?string $class = null): Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($class && ! $e instanceof $class) {
                throw new RuntimeException('Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
            }
            if (stripos($e->getMessage(), $needle) === false) {
                throw new RuntimeException('Expected error containing "' . $needle . '", got: ' . get_class($e) . ': ' . $e->getMessage());
            }
            return $e;
        }
        throw new RuntimeException('Expected an error containing "' . $needle . '" but none was thrown.');
    }

    /** Total stock of an item in a warehouse, optionally only one status or bin. */
    public function qty(string $wh, string $sku, ?string $status = null, ?string $bin = null): float
    {
        $w = 'warehouse_id = ? AND item_id = ?';
        $b = [$this->wh($wh), $this->item($sku)];
        if ($status) {
            $w .= ' AND status = ?';
            $b[] = $status;
        }
        if ($bin) {
            $w .= ' AND bin_id = ?';
            $b[] = $this->bin($wh, $bin);
        }
        return (float) Db::value("SELECT COALESCE(SUM(qty), 0) FROM {stock} WHERE tenant_id = {tid} AND $w", $b);
    }

    public function value(string $wh, string $sku): float
    {
        return (float) Db::value('SELECT COALESCE(SUM(qty_left * unit_cost), 0) FROM {cost_layers} WHERE tenant_id = {tid} AND warehouse_id = ? AND item_id = ?', [$this->wh($wh), $this->item($sku)]);
    }

    /** Stock row id of an item at a bin (optionally a batch number or serial). */
    public function row(string $wh, string $sku, string $bin, ?string $lot = null, ?string $serial = null, string $status = 'available'): int
    {
        $w = 's.warehouse_id = ? AND s.item_id = ? AND s.bin_id = ? AND s.status = ?';
        $b = [$this->wh($wh), $this->item($sku), $this->bin($wh, $bin), $status];
        if ($lot !== null) {
            $w .= ' AND l.lot_no = ?';
            $b[] = $lot;
        }
        if ($serial !== null) {
            $w .= ' AND se.serial_no = ?';
            $b[] = $serial;
        }
        $id = Db::value("SELECT s.id FROM {stock} s LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.tenant_id = {tid} AND $w ORDER BY s.id LIMIT 1", $b);
        if (! $id) {
            throw new RuntimeException("No stock row for $sku at $wh/$bin" . ($lot ? " batch $lot" : '') . ($serial ? " serial $serial" : ''));
        }
        return (int) $id;
    }

    /**
     * Receive goods as the warehouse's storekeeper (no purchase order). $lines: [[sku, qty, unit_cost, extra], ...]
     * extra: lot_no, expiry_date, serials, bin (code, default STOCK), qty_quarantine, serials_quarantine.
     */
    public function receive(string $wh, array $lines, ?string $as = null, string $supplier = 'ZAMMILL'): int
    {
        $this->as($as ?? ($wh === 'LSK' ? 'steve' : 'kim'));
        $out = [];
        foreach ($lines as $l) {
            [$sku, $qty, $cost] = $l;
            $x = $l[3] ?? [];
            $out[] = [
                'item_id' => $this->item($sku), 'qty_accepted' => $qty, 'unit_cost' => $cost, 'lot_no' => $x['lot_no'] ?? null, 'expiry_date' => $x['expiry_date'] ?? null,
                'serials' => $x['serials'] ?? null, 'bin_id' => $this->bin($wh, $x['bin'] ?? 'STOCK'), 'qty_quarantine' => $x['qty_quarantine'] ?? 0,
                'serials_quarantine' => $x['serials_quarantine'] ?? null,
            ];
        }
        return Receipts::post(['warehouse_id' => $this->wh($wh), 'supplier_id' => $this->c['supplier'][$supplier], 'lines' => $out]);
    }

    public function fmtItem(string $sku): array
    {
        return Items::get($this->item($sku));
    }
}
