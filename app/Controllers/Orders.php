<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Docs\Sales;
use App\Libraries\Docs\Shipments;
use App\Libraries\Master\Parties;
use App\Libraries\Master\Warehouses;

/** Sales orders and their shipments (pick, pack, dispatch, delivery, failed delivery, returns). */
class Orders extends BaseController
{
    public function index()
    {
        if (! Access::can('sales.manage') && ! Access::can('dispatch.manage')) {
            return $this->need('sales.manage');
        }
        $st = (string) $this->request->getGet('status');
        $ids = array_values(array_unique(array_merge(Access::warehouses('sales.manage'), Access::warehouses('dispatch.manage')))) ?: [0];
        $w = 'so.warehouse_id IN (' . implode(',', $ids) . ')';
        $b = [];
        if ($st === 'to_pick') {
            $w .= " AND so.status IN ('confirmed','in_progress')";
        } elseif ($st === 'open') {
            $w .= " AND so.status IN ('draft','confirmed','in_progress','dispatched')";
        } elseif ($st !== '') {
            $w .= ' AND so.status = ?';
            $b[] = $st;
        }
        if (($q = trim((string) $this->request->getGet('q'))) !== '') {
            [$fw, $fb] = \App\Libraries\Core\Find::where($q, ['so.number', 'c.name', 'c.code', 'so.customer_ref']);   // core 1.7 (D1)
            $w .= ' AND ' . $fw;
            $b = array_merge($b, $fb);
        }
        $rows = Db::rows("SELECT so.*, c.name customer, w.code wh, (SELECT SUM(l.qty * l.unit_price) FROM {so_lines} l WHERE l.tenant_id = {tid} AND l.so_id = so.id) amount,
                                (SELECT SUM(l.qty) FROM {so_lines} l WHERE l.tenant_id = {tid} AND l.so_id = so.id) q, (SELECT SUM(l.qty_shipped) FROM {so_lines} l WHERE l.tenant_id = {tid} AND l.so_id = so.id) s
                           FROM {sales_orders} so JOIN {customers} c ON c.id = so.customer_id JOIN {warehouses} w ON w.id = so.warehouse_id WHERE so.tenant_id = {tid} AND $w ORDER BY so.id DESC LIMIT 300", $b);
        return $this->render('orders/index', ['rows' => $rows, 'st' => $st, 'q' => $q], 'Sales orders');
    }

    public function form(?int $id = null)
    {
        $so = $id ? Sales::get($id) : null;
        if ($r = $this->need('sales.manage', $so ? (int) $so['warehouse_id'] : null)) {
            return $r;
        }
        if ($so && $so['status'] !== 'draft') {
            return redirect()->to(site_url('orders/' . $id));
        }
        return $this->render('orders/form', ['so' => $so, 'lines' => $so ? Sales::lines($id) : [], 'customers' => Parties::options('customers'),
            'warehouses' => Warehouses::forUser('sales.manage'), 'wh' => $this->wh('sales.manage')], $so ? 'Edit ' . $so['number'] : 'New sales order');
    }

    public function save(?int $id = null)
    {
        return $this->act(function () use ($id) {
            $d          = (array) $this->request->getPost();
            $d['lines'] = $this->lines();
            $soId       = Sales::saveDraft($d, $id);
            if ($this->in('confirm')) {
                Sales::confirm($soId);
                return $this->ok('Order confirmed; the warehouse can pick it.', 'orders/' . $soId);
            }
            return $this->ok('Draft saved.', 'orders/' . $soId);
        }, $id ? site_url('orders/' . $id . '/edit') : site_url('orders/new'));
    }

    public function show(int $id)
    {
        $so = Db::row('SELECT so.*, c.name customer, c.phone, c.town, c.code customer_code, w.code wh, w.name wh_name, u.name created_name FROM {sales_orders} so JOIN {customers} c ON c.id = so.customer_id
                         JOIN {warehouses} w ON w.id = so.warehouse_id LEFT JOIN {users} u ON u.id = so.created_by WHERE so.tenant_id = {tid} AND so.id = ?', [$id]);
        if (! $so) {
            return $this->notFound('Order');
        }
        $wh = (int) $so['warehouse_id'];
        if ($r = $this->needAny(['sales.manage', 'dispatch.manage', 'stock.view'], $wh)) {
            return $r;
        }
        $lines = Sales::lines($id);
        foreach ($lines as &$l) {
            $l['on_ship'] = (float) Db::value("SELECT COALESCE(SUM(sl.qty), 0) FROM {shipment_lines} sl JOIN {shipments} s ON s.id = sl.shipment_id WHERE sl.tenant_id = {tid} AND sl.so_line_id = ? AND s.status IN ('allocated','picked','packed')", [$l['id']]);
            $l['free']    = \App\Libraries\Stock\Alloc::free($wh, (int) $l['item_id']);
        }
        return $this->render('orders/show', ['so' => $so, 'lines' => $lines, 'shipments' => Db::rows('SELECT * FROM {shipments} WHERE tenant_id = {tid} AND so_id = ? ORDER BY id', [$id])], $so['number']);
    }

    public function step(int $id, string $what)
    {
        return $this->act(function () use ($id, $what) {
            switch ($what) {
                case 'confirm':
                    Sales::confirm($id);
                    return $this->ok('Order confirmed.', 'orders/' . $id);
                case 'cancel':
                    Sales::cancel($id, (string) $this->in('why', ''));
                    return $this->ok('Order cancelled.', 'orders/' . $id);
                case 'ship':
                    $qtys = [];
                    foreach ((array) $this->request->getPost('qty') as $lineId => $q) {
                        if ($q !== '') {
                            $qtys[(int) $lineId] = $q;
                        }
                    }
                    $sid = Db::atomic(fn () => Shipments::create($id, $qtys, (bool) $this->in('partial')));
                    return $this->ok('Stock reserved. Print the pick list and pick.', 'shipments/' . $sid);
            }
            return $this->notFound();
        }, site_url('orders/' . $id));
    }

    public function shipments()
    {
        $wh = $this->wh('dispatch.manage');
        if ($r = $this->need('dispatch.manage', $wh ?: null)) {
            return $r;
        }
        $st = (string) ($this->request->getGet('status') ?: 'open');
        $w  = 's.warehouse_id = ?';
        $b  = [$wh];
        if ($st === 'open') {
            $w .= " AND s.status IN ('allocated','picked','packed')";
        } elseif ($st !== 'all') {
            $w .= ' AND s.status = ?';
            $b[] = $st;
        }
        $rows = Db::rows("SELECT s.*, so.number order_no, c.name customer, c.town, t.number trip_no FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id
                           LEFT JOIN {trips} t ON t.id = s.trip_id WHERE s.tenant_id = {tid} AND $w ORDER BY FIELD(s.status, 'allocated','picked','packed','dispatched','delivered','failed','cancelled'), s.id DESC LIMIT 300", $b);
        return $this->render('orders/shipments', ['rows' => $rows, 'st' => $st, 'wh' => $wh], 'Pick, pack, dispatch');
    }

    public function shipment(int $id)
    {
        $s = Db::row('SELECT s.*, so.number order_no, so.customer_ref, so.delivery_address, so.id so_id, c.name customer, c.phone, c.town, w.code wh, w.name wh_name, t.number trip_no
                        FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id JOIN {warehouses} w ON w.id = s.warehouse_id
                        LEFT JOIN {trips} t ON t.id = s.trip_id WHERE s.tenant_id = {tid} AND s.id = ?', [$id]);
        if (! $s) {
            return $this->notFound('Shipment');
        }
        if ($r = $this->needAny(['dispatch.manage', 'sales.manage', 'stock.view'], (int) $s['warehouse_id'])) {
            return $r;
        }
        return $this->render('orders/shipment', ['s' => $s, 'lines' => Shipments::lines($id), 'picks' => Shipments::pickList($id), 'units' => Shipments::units($id),
            'files' => Db::rows("SELECT * FROM {attachments} WHERE tenant_id = {tid} AND owner_type = 'shipment' AND owner_id = ?", [$id]),
            'msgs' => \App\Libraries\Messaging\Outbox::forObject('shipment', $id),
            'returns' => Db::rows('SELECT r.*, u.name user FROM {customer_returns} r LEFT JOIN {users} u ON u.id = r.posted_by WHERE r.tenant_id = {tid} AND r.shipment_id = ? ORDER BY r.id', [$id]),
            'failReasons' => Db::rows("SELECT * FROM {reasons} WHERE tenant_id = {tid} AND use_for = 'failed_delivery' AND active = 1 ORDER BY label"),
            'returnReasons' => Db::rows("SELECT * FROM {reasons} WHERE tenant_id = {tid} AND use_for = 'customer_return' AND active = 1 ORDER BY label")], $s['number']);
    }

    public function deliveryNote(int $id)
    {
        $s = Db::row('SELECT s.*, so.number order_no, so.customer_ref, so.delivery_address, c.name customer, c.phone, c.town, w.code wh, w.name wh_name, w.address wh_address, t.number trip_no, v.reg_no, d.name driver
                        FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id JOIN {warehouses} w ON w.id = s.warehouse_id
                        LEFT JOIN {trips} t ON t.id = s.trip_id LEFT JOIN {vehicles} v ON v.id = t.vehicle_id LEFT JOIN {drivers} d ON d.id = t.driver_id WHERE s.tenant_id = {tid} AND s.id = ?', [$id]);
        if (! $s) {
            return $this->notFound('Shipment');
        }
        if ($r = $this->needAny(['dispatch.manage', 'sales.manage', 'stock.view'], (int) $s['warehouse_id'])) {
            return $r;
        }
        return view('orders/note', ['s' => $s, 'lines' => Shipments::lines($id), 'units' => Shipments::units($id)]);
    }

    public function shipmentAct(int $id, string $what)
    {
        return $this->act(function () use ($id, $what) {
            $tok = $this->in('op_token');
            switch ($what) {
                case 'pick':
                    $short = [];
                    foreach ((array) $this->request->getPost('picked') as $aid => $q) {
                        if ($q !== '') {
                            $short[(int) $aid] = $q;
                        }
                    }
                    Shipments::pick($id, $short, $tok);
                    return $this->ok('Picked: the goods are in the DISPATCH bin. Pack next.', 'shipments/' . $id);
                case 'pack':
                    Shipments::pack($id, (int) $this->in('packages'), $this->in('weight_kg') !== '' && $this->in('weight_kg') !== null ? (float) $this->in('weight_kg') : null);
                    if ($this->in('dispatch')) {
                        Shipments::dispatch($id, $tok);
                        return $this->ok('Packed and dispatched.', 'shipments/' . $id);
                    }
                    return $this->ok('Packed. Dispatch it, or put it on a trip.', 'shipments/' . $id);
                case 'dispatch':
                    Shipments::dispatch($id, $tok);
                    return $this->ok('Dispatched. Record the delivery when the customer has signed.', 'shipments/' . $id);
                case 'deliver':
                    Db::atomic(function () use ($id) {
                        Shipments::deliver($id, (string) $this->in('pod_name', ''), (string) $this->in('pod_note', ''));
                        $this->storePod($id);
                    });
                    return $this->ok('Delivery recorded.', 'shipments/' . $id);
                case 'fail':
                    Shipments::fail($id, (string) $this->in('reason'), (string) $this->in('note', ''), (string) $this->in('status', 'available'), null, $tok);
                    return $this->ok('Failed delivery recorded; the goods are back in stock and the order can go out again.', 'shipments/' . $id);
                case 'return':
                    $q = [];
                    foreach ((array) $this->request->getPost('ret') as $uid => $n) {
                        if ((float) $n > 0) {
                            $q[(int) $uid] = $n;
                        }
                    }
                    Shipments::customerReturn($id, $q, (string) $this->in('reason'), (string) $this->in('note', ''), (string) $this->in('status', 'quarantine'), $tok);
                    return $this->ok('Return received.', 'shipments/' . $id);
                case 'cancel':
                    Shipments::cancel($id, (string) $this->in('why', 'Cancelled'));
                    return $this->ok('Shipment cancelled. Anything already picked is free in the DISPATCH bin: put it back.', 'shipments/' . $id);
            }
            return $this->notFound();
        }, site_url('shipments/' . $id));
    }

    /** Optional photo or scan of the signed delivery note. */
    private function storePod(int $id): void
    {
        $f = $this->request->getFile('pod_file');
        if (! $f || $f->getError() === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if (! $f->isValid()) {
            throw new BizException('The file did not upload: ' . $f->getErrorString());
        }
        $max = (int) Settings::get('max_upload_mb') * 1024 * 1024;
        if ($f->getSize() > $max) {
            throw new BizException('The file is larger than ' . Settings::get('max_upload_mb') . ' MB.');
        }
        $ext  = strtolower($f->getClientExtension());
        $mime = $f->getMimeType();
        $ok   = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'webp' => 'image/webp'];
        if (! isset($ok[$ext]) || $ok[$ext] !== $mime) {
            throw new BizException('Attach a photo (JPG, PNG, WEBP) or a PDF.');
        }
        $dir = WRITEPATH . 'uploads/pod';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $f->move($dir, $name);
        $aid = Db::insert('attachments', ['owner_type' => 'shipment', 'owner_id' => $id, 'filename' => mb_substr($f->getClientName(), 0, 255), 'stored_name' => $name, 'mime' => $mime, 'size' => $f->getSize(), 'uploaded_by' => Auth::id(), 'created_at' => Db::now()]);
        Audit::log('pod.upload', 'shipment', $id, null, ['attachment' => $aid]);
    }

    public function file(int $id)
    {
        $a = Db::row('SELECT a.*, s.warehouse_id FROM {attachments} a JOIN {shipments} s ON s.id = a.owner_id WHERE a.tenant_id = {tid} AND a.id = ? AND a.owner_type = \'shipment\'', [$id]);
        if (! $a) {
            return $this->notFound('File');
        }
        if (! Access::canAt((int) $a['warehouse_id'], 'dispatch.manage') && ! Access::canAt((int) $a['warehouse_id'], 'sales.manage') && ! Access::canAt((int) $a['warehouse_id'], 'stock.view')) {
            return $this->need('dispatch.manage', (int) $a['warehouse_id']);
        }
        $path = WRITEPATH . 'uploads/pod/' . basename($a['stored_name']);
        if (! is_file($path)) {
            return $this->notFound('File');
        }
        return $this->response->setHeader('Content-Type', $a['mime'])->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $a['filename']) . '"')->setBody(file_get_contents($path));
    }
}
