<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Master\Items as I;

class Items extends BaseController
{
    public function index()
    {
        if (! Access::can('item.manage') && ! Access::can('stock.view')) {
            return $this->need('item.manage');
        }
        $q = trim((string) $this->request->getGet('q'));
        $w = '1=1';
        $b = [];
        if ($q !== '') {
            $w = '(i.sku LIKE ? OR i.name LIKE ? OR i.barcode = ?)';
            $b = ['%' . $q . '%', '%' . $q . '%', $q];
        }
        if (($k = (string) $this->request->getGet('kind')) !== '' && isset(I::KINDS[$k])) {
            $w .= ' AND i.kind = ?';
            $b[] = $k;
        }
        $rows = Db::rows("SELECT i.*, c.name category, (SELECT COALESCE(SUM(s.qty), 0) FROM {stock} s WHERE s.item_id = i.id) on_hand FROM {items} i LEFT JOIN {item_categories} c ON c.id = i.category_id WHERE $w ORDER BY i.active DESC, i.sku LIMIT 500", $b);
        return $this->render('items/index', ['rows' => $rows, 'q' => $q, 'kind' => $k], 'Items');
    }

    public function form(?int $id = null)
    {
        if ($r = $this->need('item.manage')) {
            return $r;
        }
        $item = $id ? Db::row('SELECT * FROM {items} WHERE id = ?', [$id]) : null;
        if ($id && ! $item) {
            return $this->notFound('Item');
        }
        $locked = $id && Db::value('SELECT 1 FROM {movements} WHERE item_id = ? LIMIT 1', [$id]);
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
