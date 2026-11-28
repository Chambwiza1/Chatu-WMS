<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Docs\Adjustments;
use App\Libraries\Docs\Counts;
use App\Libraries\Master\Warehouses;

/** Stock adjustments and counts, both approved by a second person. */
class Control extends BaseController
{
    public function adjustments()
    {
        if (! Access::can('adjust.request') && ! Access::can('stock.approve')) {
            return $this->need('adjust.request');
        }
        $st  = (string) $this->request->getGet('status');
        $ids = array_values(array_unique(array_merge(Access::warehouses('adjust.request'), Access::warehouses('stock.approve')))) ?: [0];
        $w   = 'a.warehouse_id IN (' . implode(',', $ids) . ')' . ($st !== '' ? ' AND a.status = ?' : '');
        $rows = Db::rows("SELECT a.*, w.code wh, u.name created_name, d.name decided_name, r.label reason FROM {adjustments} a JOIN {warehouses} w ON w.id = a.warehouse_id
                           LEFT JOIN {users} u ON u.id = a.created_by LEFT JOIN {users} d ON d.id = a.decided_by LEFT JOIN {reasons} r ON r.use_for = 'adjust' AND r.code = a.reason_code
                          WHERE a.tenant_id = {tid} AND $w ORDER BY a.status = 'pending' DESC, a.id DESC LIMIT 300", $st !== '' ? [$st] : []);
        return $this->render('control/adjustments', ['rows' => $rows, 'st' => $st], 'Adjustments');
    }

    public function adjustmentForm()
    {
        $wh = $this->wh('adjust.request');
        if ($r = $this->need('adjust.request', $wh ?: null)) {
            return $r;
        }
        $q = trim((string) $this->request->getGet('q'));
        $rows = [];
        if ($q !== '') {
            [$fw, $fb] = \App\Libraries\Core\Find::where($q, ['i.sku', 'i.name', 'l.lot_no', 'se.serial_no'], ['b.code']);   // core 1.7 (D1)
            $rows = Db::rows('SELECT s.*, i.sku, i.name, i.uom, i.decimals, b.code bin, l.lot_no, l.expiry_date, se.serial_no FROM {stock} s JOIN {items} i ON i.id = s.item_id JOIN {bins} b ON b.id = s.bin_id
                               LEFT JOIN {lots} l ON l.id = s.lot_id LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.tenant_id = {tid} AND s.warehouse_id = ? AND ' . $fw . '
                               ORDER BY i.sku, b.code LIMIT 100', array_merge([$wh], $fb));
        }
        return $this->render('control/adjustment_form', ['wh' => $wh, 'q' => $q, 'rows' => $rows, 'bins' => Warehouses::bins($wh),
            'reasons' => Db::rows("SELECT * FROM {reasons} WHERE tenant_id = {tid} AND use_for = 'adjust' AND active = 1 ORDER BY label")], 'New adjustment');
    }

    public function adjustmentPost()
    {
        $back = previous_url();
        return $this->act(function () {
            $lines = [];
            foreach ((array) $this->request->getPost('change') as $sid => $q) {
                if ($q !== '' && (float) $q !== 0.0) {
                    $lines[] = ['stock_id' => (int) $sid, 'qty' => $q];
                }
            }
            foreach ($this->lines('add') as $l) {
                $lines[] = $l;
            }
            $id = Adjustments::request(['warehouse_id' => (int) $this->in('warehouse_id'), 'reason_code' => $this->in('reason_code'), 'notes' => $this->in('notes'), 'lines' => $lines], $this->in('op_token'));
            $a  = Db::row('SELECT status, number FROM {adjustments} WHERE tenant_id = {tid} AND id = ?', [$id]);
            return $this->ok($a['status'] === 'posted' ? 'Adjustment ' . $a['number'] . ' posted.' : 'Adjustment ' . $a['number'] . ' sent for approval. The units being written off are held meanwhile.', 'adjustments/' . $id);
        }, $back);
    }

    public function adjustment(int $id)
    {
        $a = Db::row("SELECT a.*, w.code wh, u.name created_name, d.name decided_name, r.label reason FROM {adjustments} a JOIN {warehouses} w ON w.id = a.warehouse_id LEFT JOIN {users} u ON u.id = a.created_by
                        LEFT JOIN {users} d ON d.id = a.decided_by LEFT JOIN {reasons} r ON r.use_for = 'adjust' AND r.code = a.reason_code WHERE a.tenant_id = {tid} AND a.id = ?", [$id]);
        if (! $a) {
            return $this->notFound('Adjustment');
        }
        if ($r = $this->needAny(['adjust.request', 'stock.approve', 'stock.view'], (int) $a['warehouse_id'])) {
            return $r;
        }
        $lines = Db::rows('SELECT l.*, i.sku, i.name, i.uom, i.decimals, b.code bin, lo.lot_no lot FROM {adjustment_lines} l JOIN {items} i ON i.id = l.item_id JOIN {bins} b ON b.id = l.bin_id LEFT JOIN {lots} lo ON lo.id = l.lot_id WHERE l.tenant_id = {tid} AND l.adjustment_id = ? ORDER BY l.id', [$id]);
        return $this->render('control/adjustment', ['a' => $a, 'lines' => $lines, 'mine' => (int) $a['created_by'] === Auth::id()], $a['number']);
    }

    public function adjustmentAct(int $id, string $what)
    {
        return $this->act(function () use ($id, $what) {
            if ($what === 'approve') {
                Adjustments::approve($id, (string) $this->in('note', ''));
                return $this->ok('Approved and posted.', 'adjustments/' . $id);
            }
            Adjustments::reject($id, (string) $this->in('note', ''));
            return $this->ok('Rejected; the held units are free again.', 'adjustments/' . $id);
        }, site_url('adjustments/' . $id));
    }

    public function counts()
    {
        if (! Access::can('count.enter') && ! Access::can('count.manage') && ! Access::can('stock.approve')) {
            return $this->need('count.manage');
        }
        $wh  = $this->wh('stock.view') ?: $this->wh('count.enter');
        $st  = (string) $this->request->getGet('status');
        $rows = Db::rows('SELECT c.*, z.code zone, i.sku, u.name created_name, (SELECT COUNT(*) FROM {count_lines} l WHERE l.tenant_id = {tid} AND l.count_id = c.id) n_lines, (SELECT COUNT(*) FROM {count_lines} l WHERE l.tenant_id = {tid} AND l.count_id = c.id AND l.counted_qty IS NOT NULL) counted
                           FROM {counts} c LEFT JOIN {zones} z ON z.id = c.zone_id LEFT JOIN {items} i ON i.id = c.item_id LEFT JOIN {users} u ON u.id = c.created_by WHERE c.tenant_id = {tid} AND c.warehouse_id = ?' . ($st !== '' ? ' AND c.status = ?' : '') . ' ORDER BY c.id DESC LIMIT 200', $st !== '' ? [$wh, $st] : [$wh]);
        return $this->render('control/counts', ['rows' => $rows, 'wh' => $wh, 'st' => $st, 'zones' => Db::rows('SELECT * FROM {zones} WHERE tenant_id = {tid} AND warehouse_id = ? ORDER BY code', [$wh]), 'items' => \App\Libraries\Master\Items::options()], 'Counts');
    }

    public function countStart()
    {
        return $this->act(function () {
            $d = (array) $this->request->getPost();
            if (($d['sku'] ?? '') !== '') {
                $it = \App\Libraries\Master\Items::bySku(trim(explode(' — ', (string) $d['sku'])[0]));
                $d['item_id'] = $it ? $it['id'] : null;
            }
            $id = Counts::start($d);
            return $this->ok('Count started. Print the count sheet or enter counts on screen.', 'counts/' . $id);
        }, site_url('counts'));
    }

    public function count(int $id)
    {
        $c = Db::row('SELECT c.*, w.code wh, w.name wh_name, z.code zone, i.sku, u.name created_name, s.name submitted_name, p.name posted_name FROM {counts} c JOIN {warehouses} w ON w.id = c.warehouse_id LEFT JOIN {zones} z ON z.id = c.zone_id
                        LEFT JOIN {items} i ON i.id = c.item_id LEFT JOIN {users} u ON u.id = c.created_by LEFT JOIN {users} s ON s.id = c.submitted_by LEFT JOIN {users} p ON p.id = c.posted_by WHERE c.tenant_id = {tid} AND c.id = ?', [$id]);
        if (! $c) {
            return $this->notFound('Count');
        }
        $wh = (int) $c['warehouse_id'];
        if (! Access::canAt($wh, 'count.enter') && ! Access::canAt($wh, 'count.manage') && ! Access::canAt($wh, 'stock.approve')) {
            return $this->need('count.enter', $wh);
        }
        $showExpected = ! $c['blind'] || Access::canAt($wh, 'stock.approve') || $c['status'] !== 'open';
        return $this->render('control/count', ['c' => $c, 'lines' => Counts::variances($id), 'show' => $showExpected, 'bins' => Warehouses::bins($wh), 'items' => \App\Libraries\Master\Items::options()], $c['number']);
    }

    public function countSheet(int $id)
    {
        $c = Counts::get($id);
        if ($r = $this->needAny(['count.enter', 'count.manage'], (int) $c['warehouse_id'])) {
            return $r;
        }
        $rows = Counts::variances($id);
        $out  = [];
        foreach ($rows as $l) {
            $out[] = [$l['id'], $l['bin_code'], $l['sku'], $l['name'], $l['lot_no'], $l['serial_no'], $l['status'], $c['blind'] ? '' : $l['expected_qty'], ''];
        }
        return $this->csv('count-' . $c['number'], ['line', 'bin', 'item', 'description', 'batch', 'serial', 'status', 'expected', 'counted'], $out);
    }

    public function countAct(int $id, string $what)
    {
        return $this->act(function () use ($id, $what) {
            switch ($what) {
                case 'enter':
                    $extras = [];
                    foreach ($this->lines('extra') as $x) {
                        if (! empty($x['item_id'])) {
                            $extras[] = $x;
                        }
                    }
                    Counts::enter($id, (array) $this->request->getPost('counted'), $extras);
                    if ($this->in('submit')) {
                        Counts::submit($id);
                        return $this->ok('Counts saved and submitted.', 'counts/' . $id);
                    }
                    return $this->ok('Counts saved.', 'counts/' . $id);
                case 'submit':
                    Counts::submit($id);
                    return $this->ok('Submitted.', 'counts/' . $id);
                case 'approve':
                    Counts::approve($id, (string) $this->in('note', ''));
                    return $this->ok('Approved: the differences are posted.', 'counts/' . $id);
                case 'reopen':
                    Counts::reopen($id, (string) $this->in('why', ''));
                    return $this->ok('Sent back for a recount.', 'counts/' . $id);
                case 'cancel':
                    Counts::cancel($id, (string) $this->in('why', ''));
                    return $this->ok('Count cancelled.', 'counts');
            }
            return $this->notFound();
        }, site_url('counts/' . $id));
    }
}
