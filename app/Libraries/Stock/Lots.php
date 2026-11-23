<?php

namespace App\Libraries\Stock;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items;

/** Batches (lots) and serial numbers. */
final class Lots
{
    /**
     * Find or create a lot for an item. A lot number that exists keeps its dates: a different expiry
     * for the same lot number is refused (two expiry dates cannot share one batch).
     */
    public static function resolve(array $item, string $lotNo, array $d, string $source, ?int $sourceId = null): int
    {
        $lotNo = strtoupper(trim($lotNo));
        if ($lotNo === '' || mb_strlen($lotNo) > 60) {
            throw new BizException($item['sku'] . ': give a batch number (up to 60 characters).');
        }
        $expiry = self::date($d['expiry_date'] ?? null);
        $mfg    = self::date($d['mfg_date'] ?? null);
        if ($item['expiry_required'] && ! $expiry) {
            throw new BizException($item['sku'] . ' needs an expiry date for batch ' . $lotNo . '.');
        }
        if ($expiry && $mfg && $mfg > $expiry) {
            throw new BizException('Batch ' . $lotNo . ': the manufacture date is after the expiry date.');
        }
        $lot = Db::row('SELECT * FROM {lots} WHERE item_id = ? AND lot_no = ? FOR UPDATE', [$item['id'], $lotNo]);
        if ($lot) {
            if ($expiry && $lot['expiry_date'] && $lot['expiry_date'] !== $expiry) {
                throw new BizException('Batch ' . $lotNo . ' of ' . $item['sku'] . ' is already recorded with expiry ' . $lot['expiry_date'] . ', not ' . $expiry . '.');
            }
            if ($expiry && ! $lot['expiry_date']) {
                Db::exec('UPDATE {lots} SET expiry_date = ? WHERE id = ?', [$expiry, $lot['id']]);
            }
            return (int) $lot['id'];
        }
        return Db::insert('lots', [
            'item_id' => $item['id'], 'lot_no' => $lotNo, 'supplier_lot' => trim((string) ($d['supplier_lot'] ?? '')) ?: null,
            'mfg_date' => $mfg, 'expiry_date' => $expiry, 'source' => $source, 'source_id' => $sourceId,
            'supplier_id' => $d['supplier_id'] ?? null, 'created_at' => Db::now(),
        ]);
    }

    public static function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $v);
        if (! $d || $d->format('Y-m-d') !== $v) {
            throw new BizException('Dates are written as YYYY-MM-DD (' . $v . ').');
        }
        return $v;
    }

    /**
     * Register a serial number arriving into stock. A serial that left (sold, returned to the supplier)
     * comes back as the same row; one that is already in stock or in transit is refused.
     */
    public static function serial(array $item, string $serialNo, ?int $lotId): int
    {
        $serialNo = trim($serialNo);
        if ($serialNo === '' || mb_strlen($serialNo) > 80) {
            throw new BizException($item['sku'] . ': serial numbers are 1 to 80 characters.');
        }
        $s = Db::row('SELECT * FROM {serials} WHERE item_id = ? AND serial_no = ? FOR UPDATE', [$item['id'], $serialNo]);
        if ($s) {
            if (in_array($s['status'], ['in_stock', 'in_transit'], true)) {
                throw new BizException('Serial ' . $serialNo . ' of ' . $item['sku'] . ' is already ' . str_replace('_', ' ', $s['status']) . '.');
            }
            Db::exec('UPDATE {serials} SET lot_id = COALESCE(?, lot_id) WHERE id = ?', [$lotId, $s['id']]);
            return (int) $s['id'];
        }
        return Db::insert('serials', ['item_id' => $item['id'], 'serial_no' => $serialNo, 'lot_id' => $lotId, 'status' => 'in_transit', 'created_at' => Db::now()]);
    }

    /** Split a text box of serial numbers (one per line, or separated by commas). Duplicates are refused. */
    public static function parseSerials(string $text): array
    {
        $list = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text) ?: []), static fn ($s) => $s !== ''));
        $dups = array_diff_assoc($list, array_unique($list));
        if ($dups) {
            throw new BizException('Serial number ' . reset($dups) . ' is listed twice.');
        }
        return $list;
    }

    public static function setStatus(int $lotId, string $status, string $reason): void
    {
        Access::require('lot.hold');
        if (! in_array($status, ['active', 'on_hold', 'recalled'], true)) {
            throw new BizException('Unknown batch status.');
        }
        if ($status !== 'active' && trim($reason) === '') {
            throw new BizException('Say why the batch is held or recalled.');
        }
        Db::atomic(static function () use ($lotId, $status, $reason) {
            $lot = Db::row('SELECT * FROM {lots} WHERE id = ? FOR UPDATE', [$lotId]);
            if (! $lot) {
                throw new BizException('Unknown batch.');
            }
            Db::exec('UPDATE {lots} SET status = ?, hold_reason = ? WHERE id = ?', [$status, $status === 'active' ? null : mb_substr($reason, 0, 255), $lotId]);
            Audit::log('lot.' . $status, 'lot', $lotId, ['status' => $lot['status']], ['status' => $status, 'reason' => $reason]);
            if ($status !== 'active') {
                // stock of a held batch cannot be picked; existing reservations stay, but are reported on the dashboard
                $item = Items::get((int) $lot['item_id']);
                \App\Libraries\Workflow\Notify::holders('stock.approve', null, 'lot_hold', 'Batch ' . $lot['lot_no'] . ' of ' . $item['sku'] . ' is ' . str_replace('_', ' ', $status), $reason, '/lots/' . $lotId);
            }
        });
    }
}
