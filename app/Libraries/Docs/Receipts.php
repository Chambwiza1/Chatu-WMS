<?php

namespace App\Libraries\Docs;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Master\Items;
use App\Libraries\Master\Numbers;
use App\Libraries\Master\Warehouses;
use App\Libraries\Stock\Ledger;
use App\Libraries\Stock\Lots;
use App\Libraries\Workflow\Notify;

/**
 * Goods receipts (GRN), with or without a purchase order, and opening balances.
 * Accepted units go to the chosen bin (default RECEIVING) as available; units held for inspection go
 * to QUARANTINE; rejected units are recorded on the receipt but never enter stock or cost.
 */
final class Receipts
{
    /**
     * $d: warehouse_id, kind (purchase|opening), supplier_id, po_id, received_on, delivery_note, carrier, notes,
     * lines: [{po_line_id, item_id, qty_accepted, qty_quarantine, qty_rejected, reject_reason, unit_cost, bin_id,
     *          lot_no, supplier_lot, mfg_date, expiry_date, serials, serials_quarantine}]
     */
    public static function post(array $d, ?string $token = null): int
    {
        $wh   = (int) ($d['warehouse_id'] ?? 0);
        $kind = ($d['kind'] ?? 'purchase') === 'opening' ? 'opening' : 'purchase';
        Access::requireAt($wh, $kind === 'opening' ? 'stock.opening' : 'receive.post');
        return Db::atomic(static function () use ($d, $wh, $kind, $token) {
            return (int) Db::once($token, Auth::id(), static function () use ($d, $wh, $kind) {
                $w   = Warehouses::get($wh);
                if (! $w['active']) {
                    throw new BizException('Warehouse ' . $w['code'] . ' is closed.');
                }
                $po  = null;
                $sup = ($d['supplier_id'] ?? '') !== '' ? (int) $d['supplier_id'] : null;
                if (! empty($d['po_id'])) {
                    $po = Purchasing::get((int) $d['po_id'], true);
                    if (! in_array($po['status'], ['open', 'partly_received'], true)) {
                        throw new BizException('Purchase order ' . $po['number'] . ' is ' . str_replace('_', ' ', $po['status']) . '; goods cannot be received against it.');
                    }
                    if ((int) $po['warehouse_id'] !== $wh) {
                        throw new BizException('Purchase order ' . $po['number'] . ' is for another warehouse.');
                    }
                    $sup = (int) $po['supplier_id'];
                }
                if ($kind === 'purchase' && ! $sup) {
                    throw new BizException('Choose the supplier.');
                }
                $lines = self::prepare($d['lines'] ?? [], $po, $w);
                // foreign-currency costs: the PO's currency, its rate unless the actual rate on the day is given, and its landed %
                $given = static fn ($k) => isset($d[$k]) && $d[$k] !== '';
                [$cur, $rate, $landed] = \App\Libraries\Master\Fx::clean(
                    $po ? $po['currency'] : ($d['currency'] ?? ''),
                    $given('fx_rate') ? $d['fx_rate'] : ($po['fx_rate'] ?? 1),
                    $given('landed_pct') ? $d['landed_pct'] : ($po['landed_pct'] ?? 0)
                );
                if ($kind === 'opening') {
                    [$cur, $rate, $landed] = ['', 1.0, 0.0];
                }
                foreach ($lines as &$l) {
                    $l['cost_fc'] = $cur !== '' || $landed > 0 ? $l['cost'] : null;
                    $l['cost']    = \App\Libraries\Master\Fx::toHome($l['cost'], $rate, $landed);
                }
                unset($l);
                Ledger::lock(array_map(static fn ($l) => [$wh, $l['item']['id']], $lines));
                $rid = Db::insert('receipts', [
                    'number' => Numbers::next('receipt'), 'warehouse_id' => $wh, 'supplier_id' => $sup, 'po_id' => $po['id'] ?? null, 'kind' => $kind,
                    'received_on' => Lots::date($d['received_on'] ?? '') ?? date('Y-m-d'), 'delivery_note' => trim((string) ($d['delivery_note'] ?? '')) ?: null,
                    'carrier' => trim((string) ($d['carrier'] ?? '')) ?: null, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
                    'posted_by' => Auth::id(), 'posted_at' => Db::now(), 'currency' => $cur, 'fx_rate' => $rate, 'landed_pct' => $landed,
                ]);
                $value = 0.0;
                foreach ($lines as $l) {
                    $item  = $l['item'];
                    $lotId = Items::lots($item) ? Lots::resolve($item, $l['lot_no'], $l + ['supplier_id' => $sup], $kind === 'opening' ? 'opening' : 'receipt', $rid) : null;
                    $lid   = Db::insert('receipt_lines', [
                        'receipt_id' => $rid, 'po_line_id' => $l['po_line_id'], 'item_id' => $item['id'], 'bin_id' => $l['bin_id'], 'lot_id' => $lotId,
                        'qty_accepted' => $l['acc'], 'qty_quarantine' => $l['qua'], 'qty_rejected' => $l['rej'], 'unit_cost' => $l['cost'], 'unit_cost_fc' => $l['cost_fc'],
                        'serials' => $l['serials'] || $l['serials_q'] ? implode("\n", array_merge($l['serials'], $l['serials_q'])) : null,
                        'reject_reason' => $l['reject_reason'], 'pack_unit' => $l['pack_unit'], 'pack_factor' => $l['pack_factor'],
                    ]);
                    $base = ['warehouse_id' => $wh, 'item_id' => $item['id'], 'lot_id' => $lotId, 'type' => $kind === 'opening' ? 'opening' : 'receipt',
                             'doc_type' => 'receipt', 'doc_id' => $rid, 'doc_line_id' => $lid, 'unit_cost' => $l['cost']];
                    foreach ([[$l['acc'], $l['serials'], $l['bin_id'], 'available'], [$l['qua'], $l['serials_q'], (int) $w['quarantine_bin_id'], 'quarantine']] as [$q, $serials, $bin, $status]) {
                        if ($q <= Ledger::EPS) {
                            continue;
                        }
                        if (Items::serials($item)) {
                            foreach ($serials as $sn) {
                                $sid = Lots::serial($item, $sn, $lotId);
                                Ledger::add($base + ['bin_id' => $bin, 'status' => $status, 'qty' => 1, 'serial_id' => $sid]);
                            }
                        } else {
                            Ledger::add($base + ['bin_id' => $bin, 'status' => $status, 'qty' => $q]);
                        }
                        $value += $q * $l['cost'];
                    }
                    if ($l['po_line_id']) {
                        Db::exec('UPDATE {po_lines} SET qty_received = qty_received + ? WHERE id = ?', [$l['acc'] + $l['qua'], $l['po_line_id']]);
                    }
                    if ($l['cost'] > 0) {
                        Db::exec('UPDATE {items} SET last_cost = ? WHERE id = ?', [$l['cost'], $item['id']]);
                    }
                }
                Db::exec('UPDATE {receipts} SET value = ? WHERE id = ?', [round($value, 2), $rid]);
                if ($po) {
                    Purchasing::received((int) $po['id']);
                }
                $held = array_sum(array_map(static fn ($l) => $l['qua'], $lines));
                if ($held > 0) {
                    Notify::holders('stock.approve', $wh, 'inspection', 'Goods held for inspection at ' . $w['code'], 'Receipt ' . Db::value('SELECT number FROM {receipts} WHERE id = ?', [$rid]) . ' put units in QUARANTINE.', '/receipts/' . $rid, Auth::id());
                }
                Audit::log('receipt.post', 'receipt', $rid, null, ['warehouse' => $w['code'], 'kind' => $kind, 'lines' => count($lines), 'value' => round($value, 2)]);
                return $rid;
            });
        });
    }

    private static function prepare(array $raw, ?array $po, array $w): array
    {
        $over  = max(0, (float) Settings::get('over_receipt_pct', '0')) / 100;
        $out   = [];
        $poRem = [];
        if ($po) {
            foreach (Purchasing::lines((int) $po['id']) as $pl) {
                $poRem[(int) $pl['id']] = $pl;
            }
        }
        foreach ($raw as $n => $l) {
            $acc = Ledger::q($l['qty_accepted'] ?? 0);
            $qua = Ledger::q($l['qty_quarantine'] ?? 0);
            $rej = Ledger::q($l['qty_rejected'] ?? 0);
            if ($acc + $qua + $rej <= Ledger::EPS) {
                continue;
            }
            if ($acc < 0 || $qua < 0 || $rej < 0) {
                throw new BizException('Quantities cannot be negative.');
            }
            $poLine = null;
            if ($po) {
                $poLine = $poRem[(int) ($l['po_line_id'] ?? 0)] ?? null;
                if (! $poLine) {
                    throw new BizException('Line ' . ($n + 1) . ' is not on purchase order ' . $po['number'] . '.');
                }
                $itemId = (int) $poLine['item_id'];
                $left   = (float) $poLine['qty_ordered'] * (1 + $over) - (float) $poLine['qty_received'];
                if ($acc + $qua - $left > Ledger::EPS) {
                    throw new BizException($poLine['sku'] . ': ' . ($acc + $qua) . ' received but only ' . max(0, round($left, 3)) . ' still due on the order.');
                }
                $poRem[(int) $poLine['id']]['qty_received'] = (float) $poLine['qty_received'] + $acc + $qua;
            } else {
                $itemId = (int) ($l['item_id'] ?? 0);
            }
            $item = Items::get($itemId);
            if (! $item['active']) {
                throw new BizException($item['sku'] . ' is inactive.');
            }
            Items::checkQty($item, $acc);
            Items::checkQty($item, $qua);
            $cost = isset($l['unit_cost']) && $l['unit_cost'] !== '' ? round((float) str_replace(',', '', (string) $l['unit_cost']), 4) : (float) ($poLine['unit_cost'] ?? $item['last_cost']);
            if ($cost < 0) {
                throw new BizException('Costs cannot be negative.');
            }
            $bin = ($l['bin_id'] ?? '') !== '' ? (int) $l['bin_id'] : (int) $w['receiving_bin_id'];
            $b   = Ledger::bin($bin);
            if ((int) $b['warehouse_id'] !== (int) $w['id'] || in_array($b['type'], ['quarantine', 'damaged', 'dispatch'], true)) {
                throw new BizException('Accepted goods go to a receiving, storage or picking bin of this warehouse.');
            }
            $serials = $serialsQ = [];
            if (Items::serials($item)) {
                $serials  = Lots::parseSerials((string) ($l['serials'] ?? ''));
                $serialsQ = Lots::parseSerials((string) ($l['serials_quarantine'] ?? ''));
                if (count($serials) !== (int) $acc || count($serialsQ) !== (int) $qua) {
                    throw new BizException($item['sku'] . ': give one serial number per unit (' . (int) $acc . ' accepted, ' . (int) $qua . ' held; you gave ' . count($serials) . ' and ' . count($serialsQ) . ').');
                }
                if (array_intersect($serials, $serialsQ)) {
                    throw new BizException($item['sku'] . ': a serial number is both accepted and held.');
                }
            }
            if ($rej > 0 && trim((string) ($l['reject_reason'] ?? '')) === '') {
                throw new BizException($item['sku'] . ': say why units were rejected.');
            }
            $out[] = [
                'item' => $item, 'po_line_id' => $poLine ? (int) $poLine['id'] : null, 'acc' => $acc, 'qua' => $qua, 'rej' => $rej, 'cost' => $cost, 'bin_id' => $bin,
                'lot_no' => (string) ($l['lot_no'] ?? ''), 'supplier_lot' => $l['supplier_lot'] ?? null, 'mfg_date' => $l['mfg_date'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null,
                'serials' => $serials, 'serials_q' => $serialsQ, 'reject_reason' => trim((string) ($l['reject_reason'] ?? '')) ?: null,
                'pack_unit' => $l['pack_unit'] ?? ($poLine['pack_unit'] ?? null), 'pack_factor' => $l['pack_factor'] ?? ($poLine['pack_factor'] ?? null),
            ];
        }
        if (! $out) {
            throw new BizException('Nothing to receive: give quantities on at least one line.');
        }
        return $out;
    }

    public static function get(int $id): array
    {
        $r = Db::row('SELECT r.*, w.code wh_code, w.name wh_name, s.name supplier, p.number po_number, u.name posted_name FROM {receipts} r JOIN {warehouses} w ON w.id = r.warehouse_id
                        LEFT JOIN {suppliers} s ON s.id = r.supplier_id LEFT JOIN {purchase_orders} p ON p.id = r.po_id LEFT JOIN {users} u ON u.id = r.posted_by WHERE r.id = ?', [$id]);
        if (! $r) {
            throw new BizException('Unknown receipt.');
        }
        return $r;
    }

    public static function lines(int $id): array
    {
        return Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, b.code bin_code, lo.lot_no, lo.expiry_date FROM {receipt_lines} l JOIN {items} i ON i.id = l.item_id
                           JOIN {bins} b ON b.id = l.bin_id LEFT JOIN {lots} lo ON lo.id = l.lot_id WHERE l.receipt_id = ? ORDER BY l.id', [$id]);
    }
}
