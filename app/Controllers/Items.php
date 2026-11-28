<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items as I;

class Items extends BaseController
{
    public function index()
    {
        if (! Access::can('item.view') && ! Access::can('stock.view')) {
            return $this->need('item.view');
        }
        $q = trim((string) $this->request->getGet('q'));
        // core 1.7 (D1): words in any order, part of a code, an exact barcode or a pack's
        [$w, $b] = \App\Libraries\Core\Find::where($q, ['i.sku', 'i.name', 'i.description'], ['i.barcode']);
        if ($q !== '') {
            $w = '(' . $w . " OR EXISTS (SELECT 1 FROM {item_units} u WHERE u.tenant_id = i.tenant_id AND u.item_id = i.id AND u.barcode = ? AND u.barcode <> ''))";
            $b[] = trim($q);
        }
        if (($k = (string) $this->request->getGet('kind')) !== '' && isset(I::KINDS[$k])) {
            $w .= ' AND i.kind = ?';
            $b[] = $k;
        }
        $rows = Db::rows("SELECT i.*, c.name category, (SELECT COALESCE(SUM(s.qty), 0) FROM {stock} s WHERE s.tenant_id = {tid} AND s.item_id = i.id) on_hand FROM {items} i LEFT JOIN {item_categories} c ON c.id = i.category_id WHERE i.tenant_id = {tid} AND $w ORDER BY i.active DESC, i.sku LIMIT 500", $b);
        return $this->render('items/index', ['rows' => $rows, 'q' => $q, 'kind' => $k], 'Items');
    }

    /** core 1.7 (D4): change the ticked items at once; all or nothing, each change in the audit log. */
    public function bulk()
    {
        return $this->act(function () {
            $n = I::bulkUpdate((array) ($this->request->getPost('ids') ?? []), (array) $this->request->getPost());
            return $this->ok($n === 0 ? 'Nothing needed changing.' : $n . ' item' . ($n === 1 ? '' : 's') . ' changed.', 'items' . ($this->request->getPost('back') ? '?' . ltrim((string) $this->request->getPost('back'), '?') : ''));
        }, site_url('items'));
    }

    public function form(?int $id = null)
    {
        if ($r = $this->need($id ? 'item.update' : 'item.create')) {
            return $r;
        }
        $item = $id ? Db::row('SELECT * FROM {items} WHERE tenant_id = {tid} AND id = ?', [$id]) : null;
        if ($id && ! $item) {
            return $this->notFound('Item');
        }
        $locked = $id && Db::value('SELECT 1 FROM {movements} WHERE tenant_id = {tid} AND item_id = ? LIMIT 1', [$id]);
        return $this->render('items/form', ['item' => $item, 'cats' => I::categories(), 'locked' => (bool) $locked, 'units' => $id ? I::units($id) : []], $item ? 'Edit ' . $item['sku'] : 'New item');
    }

    public function save(?int $id = null)
    {
        return $this->act(function () use ($id) {
            $d = (array) $this->request->getPost();
            $d['expiry_required'] = ! empty($d['expiry_required']);
            $d['active'] = ! empty($d['active']) || ! $id;
            $d['units']  = (array) ($d['units'] ?? []);
            if (($d['new_category'] ?? '') !== '') {
                $d['category_id'] = I::addCategory((string) $d['new_category']);
            }
            $newId = I::save($d, $id);
            return $this->ok('Item saved.', 'stock/item/' . $newId);
        }, $id ? site_url('items/' . $id) : site_url('items/new'));
    }
}
