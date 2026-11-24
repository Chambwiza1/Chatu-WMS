<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Moves;
use App\Libraries\Master\Items;
use App\Libraries\Master\Warehouses;
use App\Libraries\Reports\Reports;
use App\Libraries\Reports\Trace;
use App\Libraries\Stock\Lots;

/** Stock on hand, item stock by bin, put-away and moves, batches and serial numbers. */
class Stock extends BaseController
{
    public function index()
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        $wh     = $this->wh();
        $status = (string) $this->request->getGet('status');
        $q      = trim((string) $this->request->getGet('q'));
        if (in_array($status, ['quarantine', 'damaged', 'hold'], true)) {
            $rows = Db::rows("SELECT s.*, i.sku, i.name, i.uom, i.decimals, b.code bin, l.lot_no, l.expiry_date, se.serial_no FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {bins} b ON b.id = s.bin_id
                               LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND s.status = ? ORDER BY i.sku, b.code", [$wh, $status]);
            return $this->render('stock/held', ['rows' => $rows, 'status' => $status, 'wh' => $wh], 'Stock: ' . state_label($status));
        }
        $rep = Reports::run('on_hand', ['warehouse_id' => $wh, 'q' => $q]);
        return $this->render('stock/index', ['rows' => $rep['rows'], 'q' => $q, 'wh' => $wh, 'ids' => array_column(Db::rows('SELECT id, sku FROM {items} WHERE tenant_id = {tid}'), 'id', 'sku')], 'Stock');
    }

    public function item(int $id)
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        $item = Db::row('SELECT i.*, c.name category FROM {items} i LEFT JOIN {item_categories} c ON c.id = i.category_id WHERE i.tenant_id = {tid} AND i.id = ?', [$id]);
        if (! $item) {
            return $this->notFound('Item');
        }
        [$w, $b] = Access::whereWarehouse('stock.view', 's.warehouse_id');
        $rows = Db::rows("SELECT s.*, w.code wh, b.code bin, b.type bin_type, b.pickable, l.lot_no, l.expiry_date, l.status lot_status, se.serial_no FROM {stock} s JOIN {warehouses} w ON w.id = s.warehouse_id
                           JOIN {bins} b ON b.id = s.bin_id LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id
                          WHERE s.tenant_id = {tid} AND s.item_id = ? AND $w ORDER BY w.code, b.sort, b.code, l.expiry_date", array_merge([$id], $b));
        [$wm, $bm] = Access::whereWarehouse('stock.view', 'm.warehouse_id');
        $moves = Db::rows("SELECT m.*, w.code wh, fb.code from_bin, tb.code to_bin, l.lot_no, se.serial_no, u.name user FROM {movements} m JOIN {warehouses} w ON w.id = m.warehouse_id
                            LEFT JOIN {bins} fb ON fb.id = m.from_bin_id LEFT JOIN {bins} tb ON tb.id = m.to_bin_id LEFT JOIN {lots} l ON l.id = m.lot_id LEFT JOIN {serials} se ON se.id = m.serial_id
                            LEFT JOIN {users} u ON u.id = m.user_id WHERE m.tenant_id = {tid} AND m.item_id = ? AND $wm ORDER BY m.id DESC LIMIT 40", array_merge([$id], $bm));
        $value = Access::can('stock.view_cost') ? Db::rows("SELECT w.code wh, SUM(c.qty_left) qty, SUM(c.qty_left * c.unit_cost) value FROM {cost_layers} c JOIN {warehouses} w ON w.id = c.warehouse_id
                                                            WHERE c.tenant_id = {tid} AND c.item_id = ? AND c.qty_left > 0 AND " . str_replace('s.warehouse_id', 'c.warehouse_id', $w) . ' GROUP BY c.warehouse_id', array_merge([$id], $b)) : null;
        $transit = Db::value("SELECT COALESCE(SUM(qty_open), 0) FROM {transit} WHERE tenant_id = {tid} AND item_id = ?", [$id]);
        return $this->render('stock/item', ['item' => $item, 'rows' => $rows, 'moves' => $moves, 'value' => $value, 'transit' => (float) $transit], $item['sku']);
    }

    /** What is in one bin: open it by scanning the bin label into Find. */
    public function bin(int $id)
    {
        $bin = Db::row('SELECT b.*, z.code zone_code, z.name zone, w.code wh_code, w.name wh_name FROM {bins} b JOIN {warehouses} w ON w.id = b.warehouse_id LEFT JOIN {zones} z ON z.id = b.zone_id WHERE b.tenant_id = {tid} AND b.id = ?', [$id]);
        if (! $bin) {
            return $this->notFound('Bin');
        }
        if ($r = $this->need('stock.view', (int) $bin['warehouse_id'])) {
            return $r;
        }
        $rows = Db::rows('SELECT s.*, i.sku, i.name, i.uom, i.decimals, l.lot_no, l.expiry_date, se.serial_no FROM {stock} s JOIN {items} i ON i.id = s.item_id
                           LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.tenant_id = {tid} AND s.bin_id = ? AND s.qty > 0 ORDER BY i.sku, l.expiry_date, se.serial_no', [$id]);
        return $this->render('stock/bin', ['bin' => $bin, 'rows' => $rows], 'Bin ' . $bin['code']);
    }

    public function putaway()
    {
        $wh = $this->wh('inventory.move');
        if ($r = $this->need('inventory.move', $wh ?: null)) {
            return $r;
        }
        $rows = Db::rows("SELECT s.*, i.sku, i.name, i.uom, i.decimals, b.code bin, l.lot_no, l.expiry_date, se.serial_no FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {bins} b ON b.id = s.bin_id
                           LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id
                          WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND b.type = 'receiving' AND s.qty > s.qty_allocated ORDER BY s.updated_at, i.sku", [$wh]);
        // a suggestion per item: the storage bin where it already is, else the first empty storage bin
        $suggest = [];
        foreach ($rows as $r) {
            $suggest[$r['id']] = (int) (Db::value("SELECT s.bin_id FROM {stock} s JOIN {bins} b ON b.id = s.bin_id WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND s.item_id = ? AND b.type IN ('storage','picking') AND b.status = 'active' ORDER BY b.type = 'picking' DESC, s.qty DESC LIMIT 1", [$wh, $r['item_id']])
                ?: Db::value("SELECT b.id FROM {bins} b WHERE b.tenant_id = {tid} AND b.warehouse_id = ? AND b.type = 'storage' AND b.status = 'active' AND b.is_system = 0 AND NOT EXISTS (SELECT 1 FROM {stock} s WHERE s.tenant_id = {tid} AND s.bin_id = b.id) ORDER BY b.sort, b.code LIMIT 1", [$wh]));
        }
        return $this->render('stock/putaway', ['rows' => $rows, 'wh' => $wh, 'bins' => Warehouses::bins($wh, ['storage', 'picking', 'production']), 'suggest' => $suggest], 'Put away');
    }

    public function moveForm()
    {
        $row = Db::row('SELECT s.*, i.sku, i.name, i.uom, i.decimals, b.code bin, l.lot_no, l.expiry_date, se.serial_no FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {bins} b ON b.id = s.bin_id
                         LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.tenant_id = {tid} AND s.id = ?', [(int) $this->request->getGet('stock_id')]);
        if (! $row) {
            return $this->notFound('Stock');
        }
        if ($r = $this->need('inventory.move', (int) $row['warehouse_id'])) {
            return $r;
        }
        return $this->render('stock/move', ['row' => $row, 'bins' => Warehouses::bins((int) $row['warehouse_id'])], 'Move stock');
    }

    public function move()
    {
        $back = previous_url();
        return $this->act(function () {
            $lines = (array) $this->request->getPost('lines');
            $first = Db::row('SELECT warehouse_id, item_id FROM {stock} WHERE tenant_id = {tid} AND id = ?', [(int) ($lines[0]['stock_id'] ?? 0)]);
            if (! $first) {
                throw new \App\Libraries\Core\BizException('That stock has already moved. Refresh the page.');
            }
            Moves::post(['warehouse_id' => (int) $first['warehouse_id'], 'note' => $this->in('note'), 'lines' => $lines], $this->in('op_token'));
            return $this->ok('Moved.', $this->in('return_to') === 'putaway' ? 'putaway' : 'stock/item/' . $first['item_id']);
        }, $back);
    }

    public function lots()
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        $q = trim((string) $this->request->getGet('q'));
        $b = [];
        $w = '1=1';
        if ($q !== '') {
            $w = '(l.lot_no LIKE ? OR i.sku LIKE ? OR i.name LIKE ?)';
            $b = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }
        $rows = Db::rows("SELECT l.*, i.sku, i.name, i.uom, i.decimals, (SELECT COALESCE(SUM(s.qty), 0) FROM {stock} s WHERE s.tenant_id = {tid} AND s.lot_id = l.id) on_hand FROM {lots} l JOIN {items} i ON i.id = l.item_id
                           WHERE l.tenant_id = {tid} AND $w ORDER BY on_hand > 0 DESC, l.expiry_date IS NULL, l.expiry_date, l.id DESC LIMIT 300", $b);
        $serials = $q !== '' ? Db::rows('SELECT se.*, i.sku, w.code wh FROM {serials} se JOIN {items} i ON i.id = se.item_id LEFT JOIN {warehouses} w ON w.id = se.warehouse_id WHERE se.tenant_id = {tid} AND se.serial_no LIKE ? ORDER BY se.serial_no LIMIT 50', ['%' . $q . '%']) : [];
        return $this->render('stock/lots', ['rows' => $rows, 'serials' => $serials, 'q' => $q], 'Batches and serial numbers');
    }

    public function lot(int $id)
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        try {
            $t = Trace::lot($id);
        } catch (\Throwable $e) {
            return $this->notFound('Batch');
        }
        return $this->render('stock/lot', ['t' => $t], 'Batch ' . $t['lot']['lot_no']);
    }

    public function lotStatus(int $id)
    {
        return $this->act(function () use ($id) {
            Lots::setStatus($id, (string) $this->in('status'), (string) $this->in('reason', ''));
            return $this->ok('Batch updated.', 'lots/' . $id);
        }, site_url('lots/' . $id));
    }

    public function serial(int $id)
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        try {
            $s = Trace::serial($id);
        } catch (\Throwable $e) {
            return $this->notFound('Serial number');
        }
        return $this->render('stock/serial', ['s' => $s], 'Serial ' . $s['serial_no']);
    }
}
