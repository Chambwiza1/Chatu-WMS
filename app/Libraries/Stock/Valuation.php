<?php

namespace App\Libraries\Stock;

use App\Libraries\Core\Db;

/**
 * FIFO valuation per item and warehouse. Layers are created by inflows (Ledger::add) and consumed
 * oldest first by outflows (Ledger::remove). The quantity left in layers always equals the stock.
 * The lot picked physically (FEFO or FIFO) is independent of the cost layer consumed.
 */
final class Valuation
{
    /** Consume $qty oldest first. Returns [[qty, unit_cost, received_at], ...]. */
    public static function consume(int $wh, int $itemId, float $qty, string $docType, ?int $docId, ?int $movementId, ?array $prefer = null): array
    {
        $need   = Ledger::q($qty);
        $taken  = [];
        // $prefer [source_type, source_id]: take that inflow's own layer first (voiding a production run takes back exactly what it made)
        $first  = $prefer ? '(source_type = ' . Db::conn()->escape($prefer[0]) . ' AND source_id = ' . (int) $prefer[1] . ') DESC, ' : '';
        $layers = Db::rows("SELECT * FROM {cost_layers} WHERE tenant_id = {tid} AND warehouse_id = ? AND item_id = ? AND qty_left > 0 ORDER BY {$first}received_at, id FOR UPDATE", [$wh, $itemId]);
        foreach ($layers as $l) {
            if ($need <= Ledger::EPS) {
                break;
            }
            $use = Ledger::q(min($need, (float) $l['qty_left']));
            Db::exec('UPDATE {cost_layers} SET qty_left = qty_left - ? WHERE tenant_id = {tid} AND id = ?', [$use, $l['id']]);
            Db::insert('cost_entries', [
                'at' => Db::now(), 'layer_id' => $l['id'], 'warehouse_id' => $wh, 'item_id' => $itemId, 'qty' => $use,
                'unit_cost' => $l['unit_cost'], 'movement_id' => $movementId, 'doc_type' => $docType, 'doc_id' => $docId,
            ]);
            $taken[] = [$use, (float) $l['unit_cost'], $l['received_at']];
            $need    = Ledger::q($need - $use);
        }
        if ($need > Ledger::EPS) {
            throw new \LogicException('Cost layers are short of the stock for item ' . $itemId . ' in warehouse ' . $wh . ' (reconciliation needed).');
        }
        return $taken;
    }

    public static function valueOf(array $layers): float
    {
        $v = 0.0;
        foreach ($layers as [$q, $c]) {
            $v += $q * $c;
        }
        return round($v, 2);
    }

    /** Average unit cost of a set of layers. */
    public static function unitOf(array $layers): float
    {
        $q = 0.0;
        foreach ($layers as [$lq]) {
            $q += $lq;
        }
        return $q > Ledger::EPS ? round(self::valueOf($layers) / $q, 4) : 0.0;
    }

    /** Take $qty from the front of a list of layers (used for returns and transit). Returns [taken, rest]. */
    public static function split(array $layers, float $qty): array
    {
        $need  = Ledger::q($qty);
        $taken = $rest = [];
        foreach ($layers as [$lq, $uc, $ra]) {
            if ($need <= Ledger::EPS) {
                $rest[] = [$lq, $uc, $ra];
                continue;
            }
            $use = Ledger::q(min($need, $lq));
            $taken[] = [$use, $uc, $ra];
            if ($lq - $use > Ledger::EPS) {
                $rest[] = [Ledger::q($lq - $use), $uc, $ra];
            }
            $need = Ledger::q($need - $use);
        }
        return [$taken, $rest];
    }

    /** Stock value of an item in a warehouse (or all warehouses). */
    public static function value(?int $wh = null, ?int $itemId = null): float
    {
        $w = ['qty_left > 0'];
        $b = [];
        if ($wh) {
            $w[] = 'warehouse_id = ?';
            $b[] = $wh;
        }
        if ($itemId) {
            $w[] = 'item_id = ?';
            $b[] = $itemId;
        }
        return round((float) Db::value('SELECT COALESCE(SUM(qty_left * unit_cost), 0) FROM {cost_layers} WHERE tenant_id = {tid} AND ' . implode(' AND ', $w), $b), 2);
    }

    /** Latest unit cost known for an item (newest layer anywhere, else the item's last cost). */
    public static function latestUnit(int $itemId, ?int $wh = null): float
    {
        $c = Db::value('SELECT unit_cost FROM {cost_layers} WHERE tenant_id = {tid} AND item_id = ?' . ($wh ? ' AND warehouse_id = ' . (int) $wh : '') . ' ORDER BY received_at DESC, id DESC LIMIT 1', [$itemId]);
        if ($c === null && $wh) {
            return self::latestUnit($itemId);
        }
        return $c !== null ? (float) $c : (float) Db::value('SELECT last_cost FROM {items} WHERE tenant_id = {tid} AND id = ?', [$itemId]);
    }
}
