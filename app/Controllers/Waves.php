<?php

namespace App\Controllers;

use App\Libraries\Core\Db;
use App\Libraries\Docs\Waves as W;

class Waves extends BaseController
{
    public function index()
    {
        $wh = $this->wh('dispatch.manage');
        if ($r = $this->need('dispatch.manage', $wh ?: null)) {
            return $r;
        }
        $waves = Db::rows('SELECT v.*, u.name created_name, (SELECT COUNT(*) FROM {shipments} s WHERE s.wave_id = v.id) n FROM {pick_waves} v LEFT JOIN {users} u ON u.id = v.created_by
                            WHERE v.warehouse_id = ? ORDER BY v.status = \'open\' DESC, v.id DESC LIMIT 100', [$wh]);
        return $this->render('waves/index', ['wh' => $wh, 'waves' => $waves, 'candidates' => W::candidates($wh)], 'Wave picking');
    }

    public function create()
    {
        return $this->act(function () {
            $id = W::create((int) $this->in('warehouse_id'), (array) $this->request->getPost('shipments'), (string) $this->in('note', ''));
            return $this->ok('Wave created. Print the pick list and walk the bins in order.', 'waves/' . $id);
        }, site_url('waves'));
    }

    public function show(int $id)
    {
        try {
            $w = W::get($id);
        } catch (\Throwable $e) {
            return $this->notFound('Wave');
        }
        if ($r = $this->need('dispatch.manage', (int) $w['warehouse_id'])) {
            return $r;
        }
        $picks = W::pickList($id);
        return $this->render('waves/show', ['w' => $w, 'shipments' => W::shipments($id), 'picks' => $picks, 'totals' => W::totals($picks)], $w['number']);
    }

    public function step(int $id, string $what)
    {
        return $this->act(function () use ($id, $what) {
            if ($what === 'cancel') {
                W::cancel($id);
                return $this->ok('Wave cancelled. Its shipments are still allocated.', 'waves');
            }
            $short = [];
            foreach ((array) $this->request->getPost('picked') as $aid => $q) {
                if ($q !== '' && $q !== null) {
                    $short[(int) $aid] = $q;
                }
            }
            W::confirm($id, $short, $this->in('op_token'));
            return $this->ok('Wave picked: the goods are in the DISPATCH bin. Pack each shipment next.', 'waves/' . $id);
        }, site_url('waves/' . $id));
    }
}
