<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Purchasing;
use App\Libraries\Docs\Receipts;
use App\Libraries\Docs\SupplierReturns;
use App\Libraries\Master\Parties;
use App\Libraries\Master\Warehouses;

/** Goods received (with or without a purchase order), opening balances, returns to suppliers. */
class Receiving extends BaseController
{
    public function index()
    {
        if (! Access::can('receive.post') && ! Access::can('stock.view')) {
            return $this->need('receive.post');
        }
        $wh = $this->wh();
        $rows = Db::rows('SELECT r.*, s.name supplier, p.number po_number, u.name posted_name FROM {receipts} r LEFT JOIN {suppliers} s ON s.id = r.supplier_id LEFT JOIN {purchase_orders} p ON p.id = r.po_id
                           LEFT JOIN {users} u ON u.id = r.posted_by WHERE r.warehouse_id = ? ORDER BY r.id DESC LIMIT 200', [$wh]);
        $due = Db::rows("SELECT p.id, p.number, p.expected_date, s.name supplier FROM {purchase_orders} p JOIN {suppliers} s ON s.id = p.supplier_id WHERE p.warehouse_id = ? AND p.status IN ('open','partly_received') ORDER BY p.expected_date IS NULL, p.expected_date", [$wh]);
        $returns = Db::rows('SELECT r.*, s.name supplier FROM {supplier_returns} r JOIN {suppliers} s ON s.id = r.supplier_id WHERE r.warehouse_id = ? ORDER BY r.id DESC LIMIT 30', [$wh]);
        return $this->render('receiving/index', ['rows' => $rows, 'due' => $due, 'returns' => $returns, 'wh' => $wh], 'Goods received');
    }

    public function form()
    {
        $po    = null;
        $lines = [];
        if ($poId = (int) $this->request->getGet('po')) {
            $po = Purchasing::get($poId);
            $wh = (int) $po['warehouse_id'];
            foreach (Purchasing::lines($poId) as $l) {
                if ((float) $l['qty_ordered'] - (float) $l['qty_received'] > 0.0005) {
                    $l['pack_hint'] = ($l['pack_unit'] ?? '') && (float) ($l['pack_factor'] ?? 1) > 1 ? 'ordered in ' . $l['pack_unit'] . ' of ' . (float) $l['pack_factor'] . ' · count ' . $l['uom'] : ($l['uom'] ?? '');
                    $lines[] = $l;
                }
            }
        } else {
            $wh = $this->wh('receive.post');
        }
        $opening = $this->request->getGet('kind') === 'opening';
        if ($r = $this->need($opening ? 'stock.opening' : 'receive.post', $wh ?: null)) {
            return $r;
        }
        return $this->render('receiving/form', ['po' => $po, 'lines' => $lines, 'wh' => $wh, 'opening' => $opening, 'suppliers' => Parties::options('suppliers'),
            'bins' => Warehouses::bins($wh, ['receiving', 'storage', 'picking', 'production']), 'w' => Warehouses::get($wh)], $opening ? 'Opening stock' : 'Receive goods');
    }

    public function post()
    {
        $back = previous_url();
        return $this->act(function () {
            $d          = (array) $this->request->getPost();
            $d['lines'] = $this->lines();
            $id         = Receipts::post($d, $this->in('op_token'));
            return $this->ok('Received. ' . (Db::value('SELECT COUNT(*) FROM {receipt_lines} WHERE receipt_id = ? AND qty_quarantine > 0', [$id]) ? 'Held units are in QUARANTINE for inspection. ' : '') . 'Put the goods away next.', 'receipts/' . $id);
        }, $back);
    }

    public function show(int $id)
    {
        $r = Receipts::get($id);
        if ($x = $this->needAny(['receive.post', 'stock.view'], (int) $r['warehouse_id'])) {
            return $x;
        }
        return $this->render('receiving/show', ['r' => $r, 'lines' => Receipts::lines($id)], $r['number']);
    }

    public function returnForm()
    {
        $wh = $this->wh('receive.post');
        if ($sid = (int) $this->request->getGet('stock_id')) {
            $wh = (int) Db::value('SELECT warehouse_id FROM {stock} WHERE id = ?', [$sid]) ?: $wh;
        }
        if ($r = $this->need('receive.post', $wh ?: null)) {
            return $r;
        }
        $q = trim((string) $this->request->getGet('q'));
        $w = 's.warehouse_id = ? AND s.qty > s.qty_allocated';
        $b = [$wh];
        if ($sid) {
            $w .= ' AND s.id = ?';
            $b[] = $sid;
        } elseif ($q !== '') {
            $w .= ' AND (i.sku LIKE ? OR i.name LIKE ? OR l.lot_no LIKE ? OR se.serial_no LIKE ?)';
            $b = array_merge($b, array_fill(0, 4, '%' . $q . '%'));
        } else {
            $w .= " AND s.status IN ('quarantine','damaged')";
        }
        $rows = Db::rows("SELECT s.*, i.sku, i.name, i.uom, i.decimals, b.code bin, l.lot_no, l.expiry_date, l.supplier_id, se.serial_no FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {bins} b ON b.id = s.bin_id
                           LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE $w ORDER BY s.status DESC, i.sku LIMIT 200", $b);
        return $this->render('receiving/return', ['rows' => $rows, 'wh' => $wh, 'q' => $q, 'suppliers' => Parties::options('suppliers'),
            'reasons' => Db::rows("SELECT * FROM {reasons} WHERE use_for = 'supplier_return' AND active = 1 ORDER BY label")], 'Return to supplier');
    }

    public function returnPost()
    {
        $back = previous_url();
        return $this->act(function () {
            $d     = (array) $this->request->getPost();
            $lines = [];
            foreach ((array) ($d['qty'] ?? []) as $stockId => $q) {
                if ((float) $q > 0) {
                    $lines[] = ['stock_id' => (int) $stockId, 'qty' => $q];
                }
            }
            $d['lines'] = $lines;
            SupplierReturns::post($d, $this->in('op_token'));
            return $this->ok('Returned to the supplier.', 'receipts');
        }, $back);
    }
}
