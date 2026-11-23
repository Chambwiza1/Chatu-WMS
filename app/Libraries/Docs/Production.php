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
use App\Libraries\Stock\Lots;

/**
 * Production: recipes (bill of materials) and production runs.
 *
 * A run takes the materials out of stock at their real FIFO cost, from the PRODUCTION bin first and then
 * storage (earliest expiry first), or from the exact batches and bins chosen. It puts the finished goods into
 * stock as a new batch (with manufacture and expiry dates) costed at (materials + other cost) / quantity made.
 * Every batch and serial used is recorded, so a finished batch can be traced back to its raw-material batches.
 * A run can be voided while all of its output is still in stock: the output leaves at its own cost and the
 * materials come back to where they were taken from at the cost they were used at.
 */
final class Production
{
    public static function recipe(int $id): array
    {
        $r = Db::row('SELECT r.*, i.sku, i.name item_name, i.uom, i.tracking, i.decimals FROM {recipes} r JOIN {items} i ON i.id = r.output_item_id WHERE r.id = ?', [$id]);
        if (! $r) {
            throw new BizException('Unknown recipe.');
        }
        $r['lines'] = Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, i.tracking FROM {recipe_lines} l JOIN {items} i ON i.id = l.item_id WHERE l.recipe_id = ? ORDER BY l.id', [$id]);
        return $r;
    }

    /** $d: code, name, output_item_id, output_qty, other_cost, notes, active, lines [{item_id, qty}]. */
    public static function saveRecipe(array $d, ?int $id = null): int
    {
        Access::require('production.manage');
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9._-]{1,30}$/', $code)) {
            throw new BizException('Recipe code: letters, numbers, dot and dash.');
        }
        $out = Items::get((int) ($d['output_item_id'] ?? 0));
        if (Items::serials($out)) {
            throw new BizException('Produced items are tracked by batch, not by serial number.');
        }
        $outQty = Ledger::q($d['output_qty'] ?? 0);
        if ($outQty <= 0) {
            throw new BizException('Say how much one batch makes.');
        }
        $lines = [];
        foreach ($d['lines'] ?? [] as $l) {
            $q = Ledger::q($l['qty'] ?? 0);
            if (empty($l['item_id']) && $q <= 0) {
                continue;
            }
            $item = Items::get((int) $l['item_id']);
            if ((int) $item['id'] === (int) $out['id']) {
                throw new BizException('A recipe cannot use its own output.');
            }
            if (isset($lines[(int) $item['id']])) {
                throw new BizException($item['sku'] . ' is listed twice.');
            }
            if ($q <= 0) {
                throw new BizException($item['sku'] . ': give the quantity one batch uses.');
            }
            $lines[(int) $item['id']] = $q;
        }
        if (! $lines) {
            throw new BizException('Add the materials one batch uses.');
        }
        $other = round((float) str_replace(',', '', (string) ($d['other_cost'] ?? 0)), 2);
        if ($other < 0) {
            throw new BizException('Other cost cannot be negative.');
        }
        return Db::atomic(static function () use ($d, $id, $code, $out, $outQty, $lines, $other) {
            if (Db::value('SELECT id FROM {recipes} WHERE code = ? AND id <> ?', [$code, $id ?? 0])) {
                throw new BizException('Recipe code ' . $code . ' is already used.');
            }
            $row = ['code' => $code, 'name' => trim((string) ($d['name'] ?? '')) ?: $out['name'], 'output_item_id' => $out['id'], 'output_qty' => $outQty,
                'other_cost' => $other, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'active' => isset($d['active']) ? (int) (bool) $d['active'] : 1];
            if ($id) {
                Db::exec('UPDATE {recipes} SET ' . implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', array_merge(array_values($row), [$id]));
                Db::exec('DELETE FROM {recipe_lines} WHERE recipe_id = ?', [$id]);
            } else {
                $id = Db::insert('recipes', $row + ['created_at' => Db::now()]);
            }
            foreach ($lines as $item => $q) {
                Db::insert('recipe_lines', ['recipe_id' => $id, 'item_id' => $item, 'qty' => $q]);
            }
            Audit::log('recipe.save', 'recipe', $id, null, $row + ['lines' => $lines]);
            return $id;
        });
    }

    /**
     * Post a run. $d: recipe_id, warehouse_id, batches, actual_qty (default: expected), produced_on, lot_no, expiry_date,
     * output_bin_id, output_status (available|quarantine), notes,
     * used: item_id => quantity actually used (default: recipe × batches),
     * picks: item_id => [[stock_id, qty], ...] (exact batches and bins; default: PRODUCTION bin first, then FEFO).
     */
    public static function post(array $d, ?string $token = null): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'production.manage');
        return (int) Db::atomic(static function () use ($d, $wh, $token) {
            return Db::once($token, Auth::id(), static function () use ($d, $wh) {
                $rec = self::recipe((int) ($d['recipe_id'] ?? 0));
                if (! $rec['active']) {
                    throw new BizException('Recipe ' . $rec['code'] . ' is switched off.');
                }
                $w       = Warehouses::get($wh);
                $batches = Ledger::q($d['batches'] ?? 1);
                if ($batches <= 0) {
                    throw new BizException('Give the number of batches.');
                }
                $out      = Items::get((int) $rec['output_item_id']);
                $expected = Ledger::q((float) $rec['output_qty'] * $batches);
                $actual   = ($d['actual_qty'] ?? '') === '' ? $expected : Ledger::q($d['actual_qty']);
                if ($actual <= 0) {
                    throw new BizException('Give the quantity actually made. A run that made nothing is not posted.');
                }
                Items::checkQty($out, $actual);
                $day = Lots::date($d['produced_on'] ?? '') ?? date('Y-m-d');
                if ($day > date('Y-m-d')) {
                    throw new BizException('Production cannot be dated in the future.');
                }
                $status = ($d['output_status'] ?? 'available') === 'quarantine' ? 'quarantine' : 'available';
                $bin    = $status === 'quarantine' ? (int) $w['quarantine_bin_id'] : (($d['output_bin_id'] ?? '') !== '' ? (int) $d['output_bin_id'] : (int) $w['stock_bin_id']);
                // what was used
                $used = [];
                foreach ($rec['lines'] as $l) {
                    $planned = Ledger::q((float) $l['qty'] * $batches);
                    $q       = isset($d['used'][$l['item_id']]) && $d['used'][$l['item_id']] !== '' ? Ledger::q($d['used'][$l['item_id']]) : $planned;
                    if ($q < 0) {
                        throw new BizException('Used quantities cannot be negative.');
                    }
                    $used[(int) $l['item_id']] = [$planned, $q, $l];
                }
                $pairs   = array_map(static fn ($i) => [$wh, $i], array_merge(array_keys($used), [(int) $out['id']]));
                Ledger::lock($pairs);
                $runId = Db::insert('production_runs', [
                    'number' => Numbers::next('production'), 'recipe_id' => $rec['id'], 'warehouse_id' => $wh, 'status' => 'posted', 'batches' => $batches,
                    'expected_qty' => $expected, 'actual_qty' => $actual, 'output_item_id' => $out['id'], 'output_bin_id' => $bin, 'output_status' => $status,
                    'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'produced_on' => $day, 'posted_by' => Auth::id(), 'posted_at' => Db::now(),
                ]);
                $material = 0.0;
                foreach ($used as $itemId => [$planned, $q, $l]) {
                    if ($q <= Ledger::EPS) {
                        Db::insert('production_inputs', ['run_id' => $runId, 'item_id' => $itemId, 'planned_qty' => $planned, 'bin_id' => $w['production_bin_id'], 'qty' => 0]);
                        continue;
                    }
                    $item = Items::get($itemId);
                    Items::checkQty($item, $q);
                    $plan = self::plan($wh, $item, $q, $d['picks'][$itemId] ?? null);
                    $first = true;
                    foreach ($plan as [$row, $take]) {
                        $r = Ledger::remove(['stock_id' => (int) $row['id'], 'qty' => $take, 'type' => 'production_use', 'doc_type' => 'production', 'doc_id' => $runId, 'serial_status' => 'consumed']);
                        Db::insert('production_inputs', ['run_id' => $runId, 'item_id' => $itemId, 'planned_qty' => $first ? $planned : 0, 'bin_id' => $row['bin_id'], 'lot_id' => $row['lot_id'], 'serial_id' => $row['serial_id'], 'qty' => $take, 'value' => $r['value']]);
                        $material += $r['value'];
                        $first = false;
                    }
                }
                $otherCost = round((float) $rec['other_cost'] * $batches, 2);
                $unit      = round(($material + $otherCost) / $actual, 4);
                $lotId     = null;
                if (Items::lots($out)) {
                    $lotNo = trim((string) ($d['lot_no'] ?? ''));
                    if ($lotNo === '') {
                        $lotNo = $out['sku'] . '-' . date('ymd', strtotime($day)) . '-' . substr(Db::value('SELECT number FROM {production_runs} WHERE id = ?', [$runId]), -3);
                    }
                    if (Db::value('SELECT 1 FROM {lots} WHERE item_id = ? AND lot_no = ?', [$out['id'], strtoupper($lotNo)])) {
                        throw new BizException('Batch ' . strtoupper($lotNo) . ' of ' . $out['sku'] . ' already exists. Each run makes its own batch.');
                    }
                    $expiry = ($d['expiry_date'] ?? '') !== '' ? $d['expiry_date'] : ($out['shelf_life_days'] ? date('Y-m-d', strtotime($day . ' +' . (int) $out['shelf_life_days'] . ' days')) : null);
                    $lotId  = Lots::resolve($out, $lotNo, ['mfg_date' => $day, 'expiry_date' => $expiry], 'production', $runId);
                }
                Ledger::add(['warehouse_id' => $wh, 'bin_id' => $bin, 'item_id' => (int) $out['id'], 'lot_id' => $lotId, 'status' => $status, 'qty' => $actual, 'unit_cost' => $unit,
                    'type' => 'production_out', 'doc_type' => 'production', 'doc_id' => $runId]);
                Db::exec('UPDATE {production_runs} SET lot_id = ?, material_cost = ?, other_cost = ?, unit_cost = ? WHERE id = ?', [$lotId, round($material, 2), $otherCost, $unit, $runId]);
                Db::exec('UPDATE {items} SET last_cost = ? WHERE id = ?', [$unit, $out['id']]);
                Audit::log('production.post', 'production_run', $runId, null, ['recipe' => $rec['code'], 'batches' => $batches, 'expected' => $expected, 'actual' => $actual, 'unit_cost' => $unit]);
                return $runId;
            });
        });
    }

    /** Which stock rows a material comes from: the chosen ones, or PRODUCTION bin first then FEFO from storage. */
    private static function plan(int $wh, array $item, float $qty, ?array $picks): array
    {
        $out = [];
        if ($picks) {
            $sum = 0.0;
            foreach ($picks as [$stockId, $q]) {
                $q = Ledger::q($q);
                if ($q <= 0) {
                    continue;
                }
                $row = Ledger::row((int) $stockId);
                if ((int) $row['warehouse_id'] !== $wh || (int) $row['item_id'] !== (int) $item['id'] || $row['status'] !== 'available') {
                    throw new BizException($item['sku'] . ': the chosen stock is not available material of this item in this warehouse.');
                }
                $out[] = [$row, $q];
                $sum  += $q;
            }
            if (abs($sum - $qty) > Ledger::EPS) {
                throw new BizException($item['sku'] . ': the chosen batches add up to ' . $sum . ', but ' . $qty . ' was used.');
            }
            return $out;
        }
        $need = $qty;
        foreach (Alloc::candidates($wh, (int) $item['id'], ['bin_types' => ['production', 'storage', 'picking']]) as $row) {
            if ($need <= Ledger::EPS) {
                break;
            }
            $take = Ledger::q(min($need, (float) $row['free']));
            $out[] = [$row, $take];
            $need  = Ledger::q($need - $take);
        }
        if ($need > Ledger::EPS) {
            throw new BizException('Not enough ' . $item['sku'] . ' (' . $item['name'] . '): ' . Items::fmt($item, $qty - $need) . ' usable, ' . Items::fmt($item, $qty) . ' needed. Nothing was posted.');
        }
        return $out;
    }

    /** Void a run while all of its output is still in stock here. */
    public static function void(int $runId, string $reason, ?string $token = null): void
    {
        Db::atomic(static function () use ($runId, $reason, $token) {
            Db::once($token, Auth::id(), static function () use ($runId, $reason) {
                $run = Db::row('SELECT * FROM {production_runs} WHERE id = ? FOR UPDATE', [$runId]);
                if (! $run) {
                    throw new BizException('Unknown run.');
                }
                $wh = (int) $run['warehouse_id'];
                Access::requireAt($wh, 'production.manage');
                if ($run['status'] !== 'posted') {
                    throw new BizException('This run is already voided.');
                }
                if (trim($reason) === '') {
                    throw new BizException('Say why the run is voided.');
                }
                $inputs = Db::rows('SELECT * FROM {production_inputs} WHERE run_id = ? AND qty > 0 ORDER BY id', [$runId]);
                Ledger::lock(array_map(static fn ($i) => [$wh, (int) $i['item_id']], array_merge($inputs, [['item_id' => $run['output_item_id']]])));
                // take the output back: from its own batch (or the item, when not batch-tracked), any bin, available or held
                $need = (float) $run['actual_qty'];
                $w    = 's.warehouse_id = ? AND s.item_id = ? AND s.qty > s.qty_allocated';
                $b    = [$wh, $run['output_item_id']];
                if ($run['lot_id']) {
                    $w .= ' AND s.lot_id = ?';
                    $b[] = $run['lot_id'];
                }
                $rows = Db::rows("SELECT s.* FROM {stock} s WHERE $w ORDER BY s.bin_id = ? DESC, s.id FOR UPDATE", array_merge($b, [$run['output_bin_id']]));
                $free = array_sum(array_map(static fn ($r) => (float) $r['qty'] - (float) $r['qty_allocated'], $rows));
                if ($need - $free > Ledger::EPS) {
                    throw new BizException('Only ' . round($free, 3) . ' of the ' . (float) $run['actual_qty'] . ' made is still free in this warehouse (the rest was shipped, moved out or reserved). The run cannot be voided.');
                }
                foreach ($rows as $r) {
                    if ($need <= Ledger::EPS) {
                        break;
                    }
                    $take = Ledger::q(min($need, (float) $r['qty'] - (float) $r['qty_allocated']));
                    Ledger::remove(['stock_id' => (int) $r['id'], 'qty' => $take, 'type' => 'production_void', 'doc_type' => 'production', 'doc_id' => $runId, 'prefer_layer' => ['production', $runId], 'note' => 'Void: ' . $reason]);
                    $need = Ledger::q($need - $take);
                }
                $wrow = Warehouses::get($wh);
                foreach ($inputs as $i) {
                    $bin = Db::row('SELECT * FROM {bins} WHERE id = ?', [$i['bin_id']]);
                    Ledger::add(['warehouse_id' => $wh, 'bin_id' => $bin && $bin['status'] === 'active' ? (int) $bin['id'] : (int) $wrow['production_bin_id'], 'item_id' => (int) $i['item_id'],
                        'lot_id' => $i['lot_id'], 'serial_id' => $i['serial_id'], 'qty' => (float) $i['qty'], 'unit_cost' => round((float) $i['value'] / (float) $i['qty'], 4),
                        'type' => 'production_void', 'doc_type' => 'production', 'doc_id' => $runId, 'note' => 'Void: ' . $reason]);
                }
                Db::exec("UPDATE {production_runs} SET status = 'voided', voided_by = ?, voided_at = ?, void_reason = ? WHERE id = ?", [Auth::id(), Db::now(), mb_substr($reason, 0, 255), $runId]);
                Audit::log('production.void', 'production_run', $runId, ['status' => 'posted'], ['status' => 'voided'], 'success', $reason);
                return $runId;
            });
        });
    }

    public static function run(int $id): array
    {
        $r = Db::row('SELECT p.*, r.code recipe_code, r.name recipe_name, i.sku, i.name item_name, i.uom, i.decimals, l.lot_no, l.expiry_date, w.code wh_code, b.code bin_code, u.name posted_name
                        FROM {production_runs} p JOIN {recipes} r ON r.id = p.recipe_id JOIN {items} i ON i.id = p.output_item_id JOIN {warehouses} w ON w.id = p.warehouse_id
                        JOIN {bins} b ON b.id = p.output_bin_id LEFT JOIN {lots} l ON l.id = p.lot_id LEFT JOIN {users} u ON u.id = p.posted_by WHERE p.id = ?', [$id]);
        if (! $r) {
            throw new BizException('Unknown run.');
        }
        $r['inputs'] = Db::rows('SELECT pi.*, i.sku, i.name, i.uom, i.decimals, l.lot_no, l.expiry_date, s.serial_no, b.code bin_code FROM {production_inputs} pi JOIN {items} i ON i.id = pi.item_id
                                   LEFT JOIN {lots} l ON l.id = pi.lot_id LEFT JOIN {serials} s ON s.id = pi.serial_id LEFT JOIN {bins} b ON b.id = pi.bin_id WHERE pi.run_id = ? ORDER BY pi.id', [$id]);
        return $r;
    }
}
