<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Reports\Dashboard;

class Home extends BaseController
{
    public function index()
    {
        [$w, $b] = Access::whereWarehouse('stock.view', 'm.warehouse_id');
        return $this->render('home/index', ['todo' => Dashboard::todo(), 'fig' => Dashboard::figures(),
            'recent' => Access::can('stock.view') ? Db::rows("SELECT m.at, m.type, m.qty, i.sku, w.code wh, u.name user FROM {movements} m JOIN {items} i ON i.id = m.item_id JOIN {warehouses} w ON w.id = m.warehouse_id LEFT JOIN {users} u ON u.id = m.user_id WHERE $w ORDER BY m.id DESC LIMIT 8", $b) : []], 'Home');
    }

    public function ping()
    {
        return $this->response->setJSON(['ok' => true, 'signed_in' => (bool) Auth::id()]);
    }

    public function switchWarehouse()
    {
        $id  = (int) $this->in('warehouse_id');
        $ids = array_merge(...array_map(static fn ($p) => Access::warehouses($p), ['stock.view', 'receive.post', 'dispatch.manage', 'warehouse.setup']));
        if (in_array($id, $ids, true)) {
            session()->set('wh', $id);
        }
        $back = previous_url();
        return redirect()->to(preg_replace('/([?&])warehouse_id=\d+&?/', '$1', $back));
    }

    /** One search box: item code or barcode, batch number, serial number or a document number. */
    public function find()
    {
        $q = trim((string) $this->request->getGet('q'));
        if ($q === '') {
            return redirect()->to(site_url('/'));
        }
        [$item] = \App\Libraries\Master\Items::byCode($q);
        if ($item) {
            return redirect()->to(site_url('stock/item/' . $item['id']));
        }
        $ids = \App\Libraries\Core\Access::warehouses('stock.view');
        if ($ids) {
            $cur = (int) session('wh');
            $bin = Db::row('SELECT id FROM {bins} WHERE code = ? AND warehouse_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY warehouse_id = ? DESC, id LIMIT 1', [strtoupper($q), $cur]);
            if ($bin) {
                return redirect()->to(site_url('bins/' . $bin['id']));
            }
        }
        if ($l = Db::row('SELECT id FROM {lots} WHERE lot_no = ? ORDER BY id DESC LIMIT 1', [strtoupper($q)])) {
            return redirect()->to(site_url('lots/' . $l['id']));
        }
        if ($s = Db::row('SELECT id FROM {serials} WHERE serial_no = ? ORDER BY id DESC LIMIT 1', [$q])) {
            return redirect()->to(site_url('serials/' . $s['id']));
        }
        $docs = ['purchase_orders' => 'purchase-orders', 'receipts' => 'receipts', 'transfers' => 'transfers', 'sales_orders' => 'orders', 'shipments' => 'shipments',
            'production_runs' => 'production/runs', 'adjustments' => 'adjustments', 'counts' => 'counts', 'trips' => 'fleet/trips', 'pick_waves' => 'waves'];
        foreach ($docs as $t => $url) {
            if ($d = Db::row("SELECT id FROM {{$t}} WHERE number = ?", [strtoupper($q)])) {
                return redirect()->to(site_url($url . '/' . $d['id']));
            }
        }
        return redirect()->to(site_url('items?q=' . rawurlencode($q)));
    }

    public function notifications()
    {
        $rows = Db::rows('SELECT * FROM {notifications} WHERE user_id = ? ORDER BY id DESC LIMIT 100', [Auth::id()]);
        return $this->render('home/notifications', ['rows' => $rows], 'Messages');
    }

    public function readAll()
    {
        Db::exec('UPDATE {notifications} SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Db::now(), Auth::id()]);
        return redirect()->to(site_url('notifications'));
    }
}
