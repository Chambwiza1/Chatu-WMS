<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Purchasing as P;
use App\Libraries\Master\Parties;
use App\Libraries\Master\Warehouses;

class Purchasing extends BaseController
{
    public function index()
    {
        if (! Access::can('purchase.manage') && ! Access::can('receive.post')) {
            return $this->need('purchase.manage');
        }
        $st = (string) $this->request->getGet('status');
        [$w, $b] = Access::whereWarehouse(Access::can('purchase.manage') ? 'purchase.manage' : 'receive.post', 'p.warehouse_id');
        if ($st === 'overdue') {
            $w .= " AND p.status IN ('open','partly_received') AND p.expected_date < CURDATE()";
        } elseif ($st === 'open') {
            $w .= " AND p.status IN ('draft','open','partly_received')";
        } elseif ($st !== '') {
            $w .= ' AND p.status = ?';
            $b[] = $st;
        }
        $rows = Db::rows("SELECT p.*, s.name supplier, w.code wh, (SELECT SUM(qty_ordered * unit_cost) FROM {po_lines} l WHERE l.tenant_id = {tid} AND l.po_id = p.id) value FROM {purchase_orders} p JOIN {suppliers} s ON s.id = p.supplier_id
                           JOIN {warehouses} w ON w.id = p.warehouse_id WHERE p.tenant_id = {tid} AND $w ORDER BY p.id DESC LIMIT 300", $b);
        return $this->render('purchasing/index', ['rows' => $rows, 'st' => $st], 'Purchase orders');
    }

    public function form(?int $id = null)
    {
        $po = $id ? P::get($id) : null;
        if ($r = $this->need('purchase.manage', $po ? (int) $po['warehouse_id'] : null)) {
            return $r;
        }
        if ($po && $po['status'] !== 'draft') {
            return redirect()->to(site_url('purchase-orders/' . $id));
        }
        return $this->render('purchasing/form', ['po' => $po, 'lines' => $po ? P::lines($id) : [], 'suppliers' => Parties::options('suppliers'),
            'warehouses' => Warehouses::forUser('purchase.manage'), 'wh' => $this->wh('purchase.manage')], $po ? 'Edit ' . $po['number'] : 'New purchase order');
    }

    public function save(?int $id = null)
    {
        return $this->act(function () use ($id) {
            $d          = (array) $this->request->getPost();
            $d['lines'] = $this->lines();
            $poId       = P::saveDraft($d, $id);
            if ($this->in('issue')) {
                P::issue($poId);
                return $this->ok('Purchase order issued.', 'purchase-orders/' . $poId);
            }
            return $this->ok('Draft saved.', 'purchase-orders/' . $poId);
        }, $id ? site_url('purchase-orders/' . $id . '/edit') : site_url('purchase-orders/new'));
    }

    public function show(int $id)
    {
        $po = Db::row('SELECT p.*, s.name supplier, s.phone supplier_phone, s.email supplier_email, s.address supplier_address, w.code wh, w.name wh_name, w.address wh_address, u.name created_name
                         FROM {purchase_orders} p JOIN {suppliers} s ON s.id = p.supplier_id JOIN {warehouses} w ON w.id = p.warehouse_id LEFT JOIN {users} u ON u.id = p.created_by WHERE p.tenant_id = {tid} AND p.id = ?', [$id]);
        if (! $po) {
            return $this->notFound('Purchase order');
        }
        if ($r = $this->needAny(['purchase.manage', 'receive.post', 'stock.view'], (int) $po['warehouse_id'])) {
            return $r;
        }
        return $this->render('purchasing/show', ['po' => $po, 'lines' => P::lines($id), 'receipts' => Db::rows('SELECT * FROM {receipts} WHERE tenant_id = {tid} AND po_id = ? ORDER BY id', [$id])], $po['number']);
    }

    public function issue(int $id)
    {
        return $this->act(function () use ($id) {
            P::issue($id);
            return $this->ok('Issued. Send it to the supplier (print it from this page).', 'purchase-orders/' . $id);
        }, site_url('purchase-orders/' . $id));
    }

    public function close(int $id)
    {
        return $this->act(function () use ($id) {
            P::close($id, (string) $this->in('why', ''));
            return $this->ok('Order closed.', 'purchase-orders/' . $id);
        }, site_url('purchase-orders/' . $id));
    }
}
