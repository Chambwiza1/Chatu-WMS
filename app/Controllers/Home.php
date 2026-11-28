<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\Tenancy;
use App\Libraries\Workflow\Notify;
use App\Libraries\Reports\Dashboard;

class Home extends BaseController
{
    public function index()
    {
        [$w, $b] = Access::whereWarehouse('stock.view', 'm.warehouse_id');
        return $this->render('home/index', ['todo' => Dashboard::todo(), 'fig' => Dashboard::figures(),
            'recent' => Access::can('stock.view') ? Db::rows("SELECT m.at, m.type, m.qty, i.sku, w.code wh, u.name user FROM {movements} m JOIN {items} i ON i.id = m.item_id JOIN {warehouses} w ON w.id = m.warehouse_id LEFT JOIN {users} u ON u.id = m.user_id WHERE m.tenant_id = {tid} AND $w ORDER BY m.id DESC LIMIT 8", $b) : []], 'Home');
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

    /** core 1.6 (C7): search everything, as a page or (format=json) as the top bar's suggestions. */
    public function search()
    {
        $q   = trim((string) $this->request->getGet('q'));
        $res = \App\Libraries\Core\Search::run($q, $this->request->getGet('format') === 'json' ? 5 : 25);
        if ($this->request->getGet('format') === 'json') {
            return $this->response->setJSON(['groups' => array_map(static fn ($g) => ['group' => $g['group'], 'rows' => array_map(static fn ($r) => ['title' => $r['title'], 'sub' => $r['sub'], 'href' => site_url($r['url'])], $g['rows'])], $res)]);
        }
        // an exact code (an item, a bin, a batch, a serial, a document) opens straight away, as Find on Home does
        if ($q !== '' && $this->request->getGet('go') === '1' && ($to = $this->exact($q))) {
            return redirect()->to($to);
        }
        return $this->render('search/index', ['q' => $q, 'groups' => $res], 'Search');
    }

    /** One search box: item code or barcode, batch number, serial number or a document number. */
    public function find()
    {
        $q = trim((string) $this->request->getGet('q'));
        if ($q === '') {
            return redirect()->to(site_url('/'));
        }
        $to = $this->exact($q);
        return redirect()->to($to ?? site_url('items?q=' . rawurlencode($q)));
    }

    /** The page of the one thing an exact code names (scanned or typed), or null. */
    private function exact(string $q): ?string
    {
        [$item] = \App\Libraries\Master\Items::byCode($q);
        if ($item) {
            return site_url('stock/item/' . $item['id']);
        }
        $ids = \App\Libraries\Core\Access::warehouses('stock.view');
        if ($ids) {
            $cur = (int) session('wh');
            $bin = Db::row('SELECT id FROM {bins} WHERE tenant_id = {tid} AND code = ? AND warehouse_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY warehouse_id = ? DESC, id LIMIT 1', [strtoupper($q), $cur]);
            if ($bin) {
                return site_url('bins/' . $bin['id']);
            }
        }
        if ($l = Db::row('SELECT id FROM {lots} WHERE tenant_id = {tid} AND lot_no = ? ORDER BY id DESC LIMIT 1', [strtoupper($q)])) {
            return site_url('lots/' . $l['id']);
        }
        if ($s = Db::row('SELECT id FROM {serials} WHERE tenant_id = {tid} AND serial_no = ? ORDER BY id DESC LIMIT 1', [$q])) {
            return site_url('serials/' . $s['id']);
        }
        $docs = ['purchase_orders' => 'purchase-orders', 'receipts' => 'receipts', 'transfers' => 'transfers', 'sales_orders' => 'orders', 'shipments' => 'shipments',
            'production_runs' => 'production/runs', 'adjustments' => 'adjustments', 'counts' => 'counts', 'trips' => 'fleet/trips', 'pick_waves' => 'waves'];
        foreach ($docs as $t => $url) {
            if ($d = Db::row("SELECT id FROM {{$t}} WHERE tenant_id = {tid} AND number = ?", [strtoupper($q)])) {
                return site_url($url . '/' . $d['id']);
            }
        }
        return null;
    }

    public function notifications()
    {
        return $this->render('home/notifications', ['rows' => Notify::list((int) Auth::id())], 'Messages');
    }

    /** core 1.1 (E2): the newest unread messages for the pop-ups; they stay until each is dealt with (Notify::popups). */
    public function live()
    {
        $uid = Auth::id();
        if (! $uid) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
        }
        $tid   = Tenancy::id();
        $after = max(0, (int) $this->request->getGet('after'));
        $pop   = Notify::popups((int) $uid, $after);
        $max   = (int) Db::value('SELECT COALESCE(MAX(id), 0) FROM {notifications} WHERE tenant_id <=> ? AND user_id = ?', [$tid, $uid]);
        $on    = static fn (string $k): bool => ! $tid || \App\Libraries\Core\Settings::tenant($k, '1') !== '0';
        // core 1.3 (E1): what waits for this person pops up too; never while signed in as someone
        $alerts = $tid && $on('live_alerts') && ! \App\Libraries\Core\SignInAs::current() ? \App\Libraries\Reports\Dashboard::alerts((array) (session('alerts_aside') ?? [])) : [];
        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON([
            'ok'       => true,
            'popups'   => $on('live_popups'),
            'sound'    => $on('live_sound'),
            'unread'   => Notify::unreadCount((int) $uid),
            'maxId'    => $max,
            'messages' => array_map(static fn ($m) => ['id' => $m['id'], 'title' => $m['title'], 'body' => $m['body'], 'url' => site_url('notifications/' . $m['id']), 'new' => $m['new']], $pop['messages']),
            'more'     => $pop['more'],
            'all'      => site_url('notifications'),
            'read'     => site_url('notifications'),
            'session'  => substr(hash('sha256', $uid . ':' . (string) session('skey') . ':' . (int) $tid), 0, 16),
            'alerts'   => $alerts,
            'alertCount' => count($alerts),
            'every'    => 45,
        ]);
    }

    /** core 1.3 (E1): put an alert aside for this sign-in; it comes back when its number changes. */
    public function aside()
    {
        $key = (string) $this->request->getPost('key');
        if (preg_match('/^a-[0-9a-f]{10}-\d+$/', $key)) {
            $kept   = array_slice(array_values(array_unique(array_merge((array) (session('alerts_aside') ?? []), [$key]))), -50);
            session()->set('alerts_aside', $kept);
        }
        return $this->response->setJSON(['ok' => true]);
    }

    /** Open one message: it is read, and its page opens. */
    public function openMessage(int $id)
    {
        $n = Notify::get((int) Auth::id(), $id);
        if (! $n) {
            return $this->notFound('Message');
        }
        if (! \App\Libraries\Core\SignInAs::current())   // core 1.2: a helper never reads the person's messages away
            Notify::readOne((int) Auth::id(), $id);
        return redirect()->to(site_url(ltrim((string) ($n['link'] ?: 'notifications'), '/')));
    }

    public function readOne(int $id)
    {
        Notify::readOne((int) Auth::id(), $id);
        if ($this->request->isAJAX() || str_contains((string) $this->request->getHeaderLine('Accept'), 'json')) {
            return $this->response->setJSON(['ok' => true]);
        }
        return redirect()->to(site_url('notifications'));
    }

    public function readAll()
    {
        Notify::readAll((int) Auth::id());
        return $this->ok('All messages marked as read.', 'notifications');
    }
}
