<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Master\Warehouses;
use App\Libraries\Reports\Reports as R;

class Reports extends BaseController
{
    public function index()
    {
        if ($r = $this->needAny(['report.view', 'stock.view'])) {
            return $r;
        }
        return $this->render('reports/index', ['list' => array_filter(R::LIST, static fn ($v, $k) => R::allowed($k), ARRAY_FILTER_USE_BOTH)], 'Reports');
    }

    public function show(string $code)
    {
        if (! R::allowed($code)) {
            return isset(R::LIST[$code]) ? $this->need(R::LIST[$code][2] ?? 'report.view') : $this->notFound('Report');
        }
        $f = [
            'warehouse_id' => (int) $this->request->getGet('warehouse_id'),
            'from'         => $this->request->getGet('from') ?: date('Y-m-01'),
            'to'           => $this->request->getGet('to') ?: date('Y-m-d'),
            'q'            => trim((string) $this->request->getGet('q')),
            'days'         => $this->request->getGet('days'),
        ];
        foreach (['from', 'to'] as $k) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
                $f[$k] = $k === 'from' ? date('Y-m-01') : date('Y-m-d');
            }
        }
        $res = R::run($code, $f);
        if ($this->request->getGet('csv')) {
            $rows = array_map(static fn ($r) => array_map(static fn ($c) => $r[$c] ?? '', array_keys($res['columns'])), $res['rows']);
            return $this->csv($code, array_values($res['columns']), $rows);
        }
        return $this->render('reports/show', ['code' => $code, 'meta' => R::LIST[$code], 'res' => $res, 'f' => $f, 'warehouses' => Warehouses::forUser('stock.view')], R::LIST[$code][0]);
    }

    /** Recall look-up: a batch number or a serial number. */
    /** core 1.6 (C12): the dashboard, for the person's own warehouses. */
    public function overview()
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        $days = (int) ($this->request->getGet('days') ?? 30);
        $wh   = (int) ($this->request->getGet('warehouse') ?? 0);
        $d    = \App\Libraries\Reports\Overview::build($days, $wh);
        $mine = \App\Libraries\Core\Access::warehouses('stock.view');
        $whs  = $mine ? Db::rows('SELECT id, code, name FROM {warehouses} WHERE tenant_id = {tid} AND id IN (' . implode(',', $mine) . ') ORDER BY code') : [];
        $sel  = in_array($wh, $mine, true) ? $wh : 0;
        $name = '';
        foreach ($whs as $x) {
            if ((int) $x['id'] === $sel) {
                $name = $x['code'] . ' ' . $x['name'];
            }
        }
        return $this->render('reports/overview', $d + ['warehouses' => $whs, 'whSel' => $sel, 'whName' => $name], 'Dashboard');
    }

    public function trace()
    {
        if ($r = $this->need('stock.view')) {
            return $r;
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $lots = Db::rows('SELECT l.id, l.lot_no, i.sku, i.name FROM {lots} l JOIN {items} i ON i.id = l.item_id WHERE l.tenant_id = {tid} AND (l.lot_no = ? OR l.supplier_lot = ?)', [strtoupper($q), $q]);
            if (count($lots) === 1) {
                return redirect()->to(site_url('lots/' . $lots[0]['id']));
            }
            $ser = Db::rows('SELECT s.id, s.serial_no, i.sku FROM {serials} s JOIN {items} i ON i.id = s.item_id WHERE s.tenant_id = {tid} AND s.serial_no = ?', [$q]);
            if (! $lots && count($ser) === 1) {
                return redirect()->to(site_url('serials/' . $ser[0]['id']));
            }
            return $this->render('reports/trace', ['q' => $q, 'lots' => $lots, 'serials' => $ser], 'Trace');
        }
        return $this->render('reports/trace', ['q' => '', 'lots' => [], 'serials' => []], 'Trace');
    }
}
