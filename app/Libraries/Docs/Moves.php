<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Numbers;
use App\Libraries\Stock\Ledger;

/**
 * Moves inside a warehouse: put-away from RECEIVING, bin to bin, and status changes
 * (available, quarantine, damaged, hold). Releasing stock from quarantine, damaged or hold back to
 * available is a quality decision and needs the stock.approve permission.
 */
final class Moves
{
    /** $d: warehouse_id, note, lines [{stock_id, qty, to_bin_id, to_status}]. Returns the move id. */
    public static function post(array $d, ?string $token = null): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'inventory.move');
        return (int) Db::atomic(static function () use ($d, $wh, $token) {
            return Db::once($token, Auth::id(), static function () use ($d, $wh) {
                $lines = [];
                foreach ($d['lines'] ?? [] as $l) {
                    $q = Ledger::q($l['qty'] ?? 0);
                    if ($q <= 0) {
                        continue;
                    }
                    $row = Db::row('SELECT * FROM {stock} WHERE tenant_id = {tid} AND id = ?', [(int) ($l['stock_id'] ?? 0)]);
                    if (! $row || (int) $row['warehouse_id'] !== $wh) {
                        throw new BizException('That stock is not in this warehouse (it may have moved). Refresh and try again.');
                    }
                    $lines[] = ['row' => $row, 'qty' => $q, 'to_bin_id' => ($l['to_bin_id'] ?? '') !== '' ? (int) $l['to_bin_id'] : null, 'to_status' => ($l['to_status'] ?? '') !== '' ? $l['to_status'] : null];
                }
                if (! $lines) {
                    throw new BizException('Nothing to move.');
                }
                Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l['row']['item_id']], $lines));
                $kinds = [];
                $mid   = Db::insert('moves', ['number' => Numbers::next('move'), 'warehouse_id' => $wh, 'kind' => 'move', 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'user_id' => Auth::id(), 'at' => Db::now()]);
                foreach ($lines as $l) {
                    $from = $l['row']['status'];
                    $to   = $l['to_status'] ?? $from;
                    if ($from !== 'available' && $to === 'available' && ! Access::canAt($wh, 'stock.approve')) {
                        throw new BizException('Releasing ' . $from . ' stock back to available is a quality decision: someone with approval rights must do it.');
                    }
                    $fromBin = Ledger::bin((int) $l['row']['bin_id']);
                    $kinds[] = $to !== $from ? 'status' : ($fromBin['type'] === 'receiving' ? 'putaway' : 'move');
                    Ledger::move([
                        'stock_id' => (int) $l['row']['id'], 'qty' => $l['qty'], 'to_bin_id' => $l['to_bin_id'], 'to_status' => $to,
                        'type' => end($kinds) === 'status' ? 'status_change' : (end($kinds) === 'putaway' ? 'putaway' : 'bin_move'),
                        'doc_type' => 'move', 'doc_id' => $mid, 'note' => $d['note'] ?? null,
                    ]);
                }
                $kind = count(array_unique($kinds)) === 1 ? $kinds[0] : 'move';
                Db::exec('UPDATE {moves} SET kind = ? WHERE tenant_id = {tid} AND id = ?', [$kind, $mid]);
                Audit::log('move.post', 'move', $mid, null, ['warehouse_id' => $wh, 'lines' => count($lines), 'kind' => $kind]);
                return $mid;
            });
        });
    }

    /** Put away everything in RECEIVING for one item/row to a bin: convenience used by the put-away screen. */
    public static function putaway(int $stockId, int $toBin, ?float $qty = null, ?string $token = null): int
    {
        $row = Db::row('SELECT * FROM {stock} WHERE tenant_id = {tid} AND id = ?', [$stockId]);
        if (! $row) {
            throw new BizException('That stock has already moved.');
        }
        return self::post(['warehouse_id' => (int) $row['warehouse_id'], 'lines' => [['stock_id' => $stockId, 'qty' => $qty ?? ((float) $row['qty'] - (float) $row['qty_allocated']), 'to_bin_id' => $toBin]]], $token);
    }
}
