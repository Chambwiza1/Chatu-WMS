<?php

namespace App\Libraries\Reports;

use App\Libraries\Core\Audit;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;

/**
 * Integrity checks, run nightly and after every automated test. Each check returns a list of problems.
 *  1. stock rows = movements (every unit is explained by the ledger)
 *  2. stock on hand = FIFO cost layers (value and quantity agree)
 *  3. reserved quantity on each stock row = its active reservations
 *  4. serial numbers: in stock exactly where a stock row says, in transit exactly where a transfer says
 *  5. goods in transit: units = their cost
 *  6. the audit log chain is unbroken
 */
final class Reconcile
{
    public static function run(bool $store = true): array
    {
        $out = [
            'ledger'      => self::ledger(),
            'valuation'   => self::valuation(),
            'allocations' => self::allocations(),
            'serials'     => self::serials(),
            'transit'     => self::transit(),
            'audit'       => [],
        ];
        $bad = Audit::verify(\App\Libraries\Core\Tenancy::id());
        if ($bad !== null) {
            $out['audit'][] = 'The audit log chain is broken at entry ' . $bad . '.';
        }
        if ($store) {
            Settings::set('last_reconcile', json_encode(['at' => Db::now(), 'problems' => array_sum(array_map('count', $out)), 'detail' => array_map(static fn ($l) => array_slice($l, 0, 20), $out)]));
        }
        return $out;
    }

    public static function ledger(): array
    {
        $rows = Db::rows(
            "SELECT k.bin_id, k.item_id, k.lot_key, k.serial_key, k.status, SUM(k.q) qty FROM (
               SELECT to_bin_id bin_id, item_id, COALESCE(lot_id,0) lot_key, COALESCE(serial_id,0) serial_key, to_status status, qty q FROM {movements} WHERE tenant_id = {tid} AND to_bin_id IS NOT NULL
               UNION ALL
               SELECT from_bin_id, item_id, COALESCE(lot_id,0), COALESCE(serial_id,0), from_status, -qty FROM {movements} WHERE tenant_id = {tid} AND from_bin_id IS NOT NULL
             ) k GROUP BY k.bin_id, k.item_id, k.lot_key, k.serial_key, k.status HAVING ABS(SUM(k.q)) > 0.0005"
        );
        $ledger = [];
        foreach ($rows as $r) {
            $ledger[$r['bin_id'] . ':' . $r['item_id'] . ':' . $r['lot_key'] . ':' . $r['serial_key'] . ':' . $r['status']] = (float) $r['qty'];
        }
        $out = [];
        foreach (Db::rows('SELECT s.*, i.sku, b.code bin FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {bins} b ON b.id = s.bin_id WHERE s.tenant_id = {tid}') as $s) {
            $k = $s['bin_id'] . ':' . $s['item_id'] . ':' . $s['lot_key'] . ':' . $s['serial_key'] . ':' . $s['status'];
            $l = $ledger[$k] ?? 0.0;
            if (abs($l - (float) $s['qty']) > 0.0005) {
                $out[] = "{$s['sku']} in bin {$s['bin']} ({$s['status']}): stock says {$s['qty']}, movements say {$l}.";
            }
            unset($ledger[$k]);
        }
        foreach ($ledger as $k => $q) {
            [$bin, $item] = explode(':', $k);
            $out[] = "Movements leave {$q} of item {$item} in bin {$bin} but there is no stock row.";
        }
        return $out;
    }

    public static function valuation(): array
    {
        $out = [];
        $rows = Db::rows(
            'SELECT t.warehouse_id, t.item_id, SUM(t.s) s, SUM(t.l) l FROM (
               SELECT warehouse_id, item_id, qty s, 0 l FROM {stock}
               WHERE tenant_id = {tid} UNION ALL SELECT warehouse_id, item_id, 0, qty_left FROM {cost_layers} WHERE tenant_id = {tid} AND qty_left > 0
             ) t GROUP BY t.warehouse_id, t.item_id HAVING ABS(SUM(t.s) - SUM(t.l)) > 0.0005'
        );
        foreach ($rows as $r) {
            $out[] = "Item {$r['item_id']} in warehouse {$r['warehouse_id']}: stock {$r['s']} but cost layers {$r['l']}.";
        }
        return $out;
    }

    public static function allocations(): array
    {
        $out = [];
        $rows = Db::rows(
            "SELECT s.id, s.qty_allocated, COALESCE(a.q, 0) q FROM {stock} s
               LEFT JOIN (SELECT stock_id, SUM(qty) q FROM {allocations} WHERE tenant_id = {tid} AND status = 'active' GROUP BY stock_id) a ON a.stock_id = s.id
              WHERE s.tenant_id = {tid} AND ABS(s.qty_allocated - COALESCE(a.q, 0)) > 0.0005"
        );
        foreach ($rows as $r) {
            $out[] = "Stock row {$r['id']}: {$r['qty_allocated']} reserved but reservations add up to {$r['q']}.";
        }
        $orphans = (int) Db::value("SELECT COUNT(*) FROM {allocations} a LEFT JOIN {stock} s ON s.id = a.stock_id WHERE a.tenant_id = {tid} AND a.status = 'active' AND s.id IS NULL");
        if ($orphans) {
            $out[] = "$orphans active reservation(s) point at stock that no longer exists.";
        }
        return $out;
    }

    public static function serials(): array
    {
        $out = [];
        foreach (Db::rows("SELECT se.id, se.serial_no, se.status, se.warehouse_id, s.warehouse_id st_wh FROM {serials} se LEFT JOIN {stock} s ON s.serial_id = se.id AND s.qty > 0
                            WHERE se.tenant_id = {tid} AND (se.status = 'in_stock') <> (s.id IS NOT NULL) OR (se.status = 'in_stock' AND se.warehouse_id <> s.warehouse_id)") as $r) {
            $out[] = "Serial {$r['serial_no']} is '{$r['status']}' but " . ($r['st_wh'] ? 'is in stock in warehouse ' . $r['st_wh'] : 'is in no stock row') . '.';
        }
        foreach (Db::rows("SELECT se.serial_no, se.status FROM {serials} se LEFT JOIN {transit} t ON t.serial_id = se.id AND t.qty_open > 0
                            WHERE se.tenant_id = {tid} AND (se.status = 'in_transit') <> (t.id IS NOT NULL)") as $r) {
            $out[] = "Serial {$r['serial_no']} is '{$r['status']}' but transit records disagree.";
        }
        return $out;
    }

    public static function transit(): array
    {
        $out = [];
        $rows = Db::rows(
            'SELECT x.transfer_line_id, SUM(x.u) u, SUM(x.c) c FROM (
               SELECT transfer_line_id, qty_open u, 0 c FROM {transit}
               WHERE tenant_id = {tid} UNION ALL SELECT transfer_line_id, 0, qty_open FROM {transit_costs}
              WHERE tenant_id = {tid} ) x GROUP BY x.transfer_line_id HAVING ABS(SUM(x.u) - SUM(x.c)) > 0.0005'
        );
        foreach ($rows as $r) {
            $out[] = "Transfer line {$r['transfer_line_id']}: {$r['u']} units in transit but cost for {$r['c']}.";
        }
        return $out;
    }
}
