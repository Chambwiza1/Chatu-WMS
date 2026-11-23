<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Transfers as T;
use App\Libraries\Master\Warehouses;
use App\Libraries\Stock\Alloc;

class Transfers extends BaseController
{
    public function index()
    {
        if (! Access::can('transfer.manage') && ! Access::can('transfer.receive')) {
            return $this->need('transfer.manage');
        }
        $view = (string) $this->request->getGet('view');
        $out  = Access::warehouses('transfer.manage') ?: [0];
        $in   = Access::warehouses('transfer.receive') ?: [0];
        $w    = '(t.from_warehouse_id IN (' . implode(',', $out) . ') OR t.to_warehouse_id IN (' . implode(',', array_merge($in, $out)) . '))';
        if ($view === 'incoming') {
            $w = "t.to_warehouse_id IN (" . implode(',', $in) . ") AND t.status IN ('in_transit','partly_received')";
        } elseif ($view === 'outgoing') {
            $w = "t.from_warehouse_id IN (" . implode(',', $out) . ") AND t.status IN ('draft','allocated')";
        }
        $rows = Db::rows("SELECT t.*, fw.code from_code, tw.code to_code, (SELECT COUNT(*) FROM {transfer_lines} l WHERE l.transfer_id = t.id) n_lines FROM {transfers} t
                           JOIN {warehouses} fw ON fw.id = t.from_warehouse_id JOIN {warehouses} tw ON tw.id = t.to_warehouse_id WHERE $w ORDER BY t.id DESC LIMIT 300");
        return $this->render('transfers/index', ['rows' => $rows, 'view' => $view], 'Transfers');
    }

    public function form()
    {
        $wh = $this->wh('transfer.manage');
        if ($r = $this->need('transfer.manage', $wh ?: null)) {
            return $r;
        }
        return $this->render('transfers/form', ['from' => Warehouses::forUser('transfer.manage'), 'to' => Db::rows('SELECT * FROM {warehouses} WHERE active = 1 ORDER BY code'), 'wh' => $wh], 'New transfer');
    }

    public function create()
    {
        return $this->act(function () {
            $d          = (array) $this->request->getPost();
            $d['lines'] = $this->lines();
            $id         = T::create($d);
            if ($this->in('allocate')) {
                T::allocate($id);
                return $this->ok('Transfer created and stock reserved. Pick it and dispatch it (or put it on a trip).', 'transfers/' . $id);
            }
            return $this->ok('Transfer saved as a draft.', 'transfers/' . $id);
        }, site_url('transfers/new'));
    }

    public function show(int $id)
    {
        $t = Db::row('SELECT t.*, fw.code from_code, fw.name from_name, tw.code to_code, tw.name to_name, u.name created_name, d.name dispatched_name, tr.number trip_no
                        FROM {transfers} t JOIN {warehouses} fw ON fw.id = t.from_warehouse_id JOIN {warehouses} tw ON tw.id = t.to_warehouse_id
                        LEFT JOIN {users} u ON u.id = t.created_by LEFT JOIN {users} d ON d.id = t.dispatched_by LEFT JOIN {trips} tr ON tr.id = t.trip_id WHERE t.id = ?', [$id]);
        if (! $t) {
            return $this->notFound('Transfer');
        }
        $from = (int) $t['from_warehouse_id'];
        $to   = (int) $t['to_warehouse_id'];
        if (! Access::canAt($from, 'transfer.manage') && ! Access::canAt($from, 'stock.view') && ! Access::canAt($to, 'transfer.receive') && ! Access::canAt($to, 'stock.view')) {
            return $this->need('transfer.manage', $from);
        }
        return $this->render('transfers/show', ['t' => $t, 'lines' => T::lines($id), 'alloc' => Alloc::active('transfer', $id), 'transit' => T::transit($id),
            'receipts' => Db::rows('SELECT r.*, u.name user FROM {transfer_receipts} r LEFT JOIN {users} u ON u.id = r.received_by WHERE r.transfer_id = ? ORDER BY r.id', [$id]),
            'bins' => Warehouses::bins($to, ['receiving', 'storage', 'picking', 'production']), 'toW' => Warehouses::get($to)], $t['number']);
    }

    public function step(int $id, string $what)
    {
        $back = site_url('transfers/' . $id);
        return $this->act(function () use ($id, $what) {
            switch ($what) {
                case 'allocate':
                    T::allocate($id);
                    return $this->ok('Stock reserved. Print the pick list, pick it, then dispatch.', 'transfers/' . $id);
                case 'unallocate':
                    T::unallocate($id);
                    return $this->ok('Reservation released; the transfer is a draft again.', 'transfers/' . $id);
                case 'dispatch':
                    T::dispatch($id, $this->in('op_token'));
                    return $this->ok('Dispatched. The goods are in transit until the other warehouse receives them.', 'transfers/' . $id);
                case 'cancel':
                    T::cancel($id, (string) $this->in('why', ''));
                    return $this->ok('Transfer cancelled.', 'transfers/' . $id);
                case 'receive':
                    $rows = [];
                    foreach ((array) $this->request->getPost('rows') as $tid => $r) {
                        $rows[] = ['transit_id' => (int) $tid] + (array) $r;
                    }
                    T::receive($id, ['rows' => $rows, 'final' => (bool) $this->in('final'), 'notes' => $this->in('notes')], $this->in('op_token'));
                    return $this->ok('Receipt recorded.', 'transfers/' . $id);
            }
            return $this->notFound();
        }, $back);
    }
}
