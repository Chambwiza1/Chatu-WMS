<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Numbers;
use App\Libraries\Stock\Ledger;

/** Returns to a supplier: units leave stock at FIFO cost; serials are marked returned to the supplier. */
final class SupplierReturns
{
    /** $d: warehouse_id, supplier_id, receipt_id, reason_code, notes, lines [{stock_id, qty}]. */
    public static function post(array $d, ?string $token = null): int
    {
        $wh = (int) ($d['warehouse_id'] ?? 0);
        Access::requireAt($wh, 'receive.post');
        return (int) Db::atomic(static function () use ($d, $wh, $token) {
            return Db::once($token, Auth::id(), static function () use ($d, $wh) {
                $sup = (int) ($d['supplier_id'] ?? 0);
                if (! Db::value('SELECT 1 FROM {suppliers} WHERE id = ?', [$sup])) {
                    throw new BizException('Choose the supplier.');
                }
                $reason = (string) ($d['reason_code'] ?? '');
                if (! Db::value("SELECT 1 FROM {reasons} WHERE use_for = 'supplier_return' AND code = ? AND active = 1", [$reason])) {
                    throw new BizException('Choose a reason for the return.');
                }
                $lines = [];
                foreach ($d['lines'] ?? [] as $l) {
                    $q = Ledger::q($l['qty'] ?? 0);
                    if ($q <= 0) {
                        continue;
                    }
                    $row = Db::row('SELECT * FROM {stock} WHERE id = ?', [(int) ($l['stock_id'] ?? 0)]);
                    if (! $row || (int) $row['warehouse_id'] !== $wh) {
                        throw new BizException('That stock is not in this warehouse.');
                    }
                    $lines[] = [$row, $q];
                }
                if (! $lines) {
                    throw new BizException('Choose what goes back.');
                }
                Ledger::lock(array_map(static fn ($l) => [$wh, (int) $l[0]['item_id']], $lines));
                $id = Db::insert('supplier_returns', [
                    'number' => Numbers::next('supplier_return'), 'warehouse_id' => $wh, 'supplier_id' => $sup,
                    'receipt_id' => ($d['receipt_id'] ?? '') !== '' ? (int) $d['receipt_id'] : null, 'reason_code' => $reason,
                    'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'posted_by' => Auth::id(), 'posted_at' => Db::now(),
                ]);
                $value = 0.0;
                foreach ($lines as [$row, $q]) {
                    $r = Ledger::remove(['stock_id' => (int) $row['id'], 'qty' => $q, 'type' => 'supplier_return', 'doc_type' => 'supplier_return', 'doc_id' => $id, 'reason_code' => $reason, 'serial_status' => 'returned_supplier']);
                    $value += $r['value'];
                }
                Db::exec('UPDATE {supplier_returns} SET value = ? WHERE id = ?', [round($value, 2), $id]);
                Audit::log('supplier_return.post', 'supplier_return', $id, null, ['lines' => count($lines), 'value' => round($value, 2), 'reason' => $reason]);
                return $id;
            });
        });
    }
}
