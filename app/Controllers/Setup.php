<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Master\Warehouses as W;

/** Warehouses, zones and bins. */
class Setup extends BaseController
{
    public function index()
    {
        if ($r = $this->need('warehouse.setup')) {
            return $r;
        }
        $rows = Db::rows('SELECT w.*, (SELECT COUNT(*) FROM {bins} b WHERE b.warehouse_id = w.id) bins, (SELECT COUNT(*) FROM {zones} z WHERE z.warehouse_id = w.id) zones,
                                 (SELECT COALESCE(SUM(qty), 0) FROM {stock} s WHERE s.warehouse_id = w.id) units FROM {warehouses} w ORDER BY w.active DESC, w.code');
        return $this->render('setup/index', ['rows' => array_values(array_filter($rows, static fn ($w) => Access::canAt((int) $w['id'], 'warehouse.setup'))), 'all' => Access::companyWide('warehouse.setup')], 'Warehouses and bins');
    }

    public function create()
    {
        return $this->act(function () {
            $id = W::create((array) $this->request->getPost());
            return $this->ok('Warehouse created with its system bins (RECEIVING, STOCK, DISPATCH, QUARANTINE, DAMAGED, PRODUCTION). Add zones and bins next.', 'warehouses/' . $id);
        }, site_url('warehouses'));
    }

    public function show(int $id)
    {
        if ($r = $this->need('warehouse.setup', $id)) {
            return $r;
        }
        $w = W::get($id);
        $bins = Db::rows('SELECT b.*, z.code zone, (SELECT COUNT(*) FROM {stock} s WHERE s.bin_id = b.id) rows_in, (SELECT COALESCE(SUM(qty), 0) FROM {stock} s WHERE s.bin_id = b.id) units
                            FROM {bins} b LEFT JOIN {zones} z ON z.id = b.zone_id WHERE b.warehouse_id = ? ORDER BY b.is_system DESC, b.sort, b.code', [$id]);
        return $this->render('setup/show', ['w' => $w, 'bins' => $bins, 'zones' => Db::rows('SELECT * FROM {zones} WHERE warehouse_id = ? ORDER BY code', [$id])], $w['code']);
    }

    public function update(int $id)
    {
        return $this->act(function () use ($id) {
            $d = (array) $this->request->getPost();
            $d['active'] = ! empty($d['active']);
            W::update($id, $d);
            return $this->ok('Saved.', 'warehouses/' . $id);
        }, site_url('warehouses/' . $id));
    }

    public function zone(int $id)
    {
        return $this->act(function () use ($id) {
            W::saveZone($id, (array) $this->request->getPost());
            return $this->ok('Zone added.', 'warehouses/' . $id);
        }, site_url('warehouses/' . $id));
    }

    public function bins(int $id)
    {
        return $this->act(function () use ($id) {
            $n = W::addBins($id, (array) $this->request->getPost());
            return $this->ok($n . ' bin(s) added' . ($n ? '' : ' (they all existed already)') . '.', 'warehouses/' . $id);
        }, site_url('warehouses/' . $id));
    }

    public function binStatus(int $binId)
    {
        $wh = (int) Db::value('SELECT warehouse_id FROM {bins} WHERE id = ?', [$binId]);
        return $this->act(function () use ($binId, $wh) {
            W::setBinStatus($binId, (string) $this->in('status'), (string) $this->in('note', ''));
            return $this->ok('Bin updated.', 'warehouses/' . $wh);
        }, site_url('warehouses/' . $wh));
    }
}
