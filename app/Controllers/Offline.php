<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;

/**
 * Read-only snapshot for the offline look-up page: items, packs, bins and stock of the warehouses this user may
 * see, without costs. The browser keeps it (IndexedDB) so a scan still answers "what is this, where is it"
 * when the network is down. It is removed from the browser at sign-out.
 */
class Offline extends BaseController
{
    public function snapshot()
    {
        $ids = array_map('intval', Access::warehouses('stock.view'));
        $out = ['version' => 1, 'generated_at' => gmdate('c'), 'user' => ['id' => Auth::id(), 'name' => (string) Db::value('SELECT name FROM {users} WHERE id = ?', [Auth::id()])],
            'company' => (string) \App\Libraries\Core\Settings::get('company_name'), 'warehouses' => [], 'items' => [], 'bins' => [], 'stock' => []];
        if ($ids) {
            $in = implode(',', $ids);
            $out['warehouses'] = Db::rows("SELECT id, code, name FROM {warehouses} WHERE id IN ($in) ORDER BY code");
            $packs = [];
            foreach (Db::rows('SELECT item_id, name, factor, barcode FROM {item_units} ORDER BY factor') as $u) {
                $packs[(int) $u['item_id']][] = [$u['name'], (float) $u['factor'], (string) $u['barcode']];
            }
            foreach (Db::rows('SELECT id, sku, name, barcode, uom, tracking FROM {items} WHERE active = 1 ORDER BY sku LIMIT 20000') as $i) {
                $out['items'][] = [(int) $i['id'], $i['sku'], $i['name'], (string) $i['barcode'], $i['uom'], $i['tracking'], $packs[(int) $i['id']] ?? []];
            }
            foreach (Db::rows("SELECT id, warehouse_id, code, type, status FROM {bins} WHERE warehouse_id IN ($in) AND status <> 'inactive' ORDER BY warehouse_id, sort, code LIMIT 20000") as $b) {
                $out['bins'][] = [(int) $b['id'], (int) $b['warehouse_id'], $b['code'], $b['type'], $b['status']];
            }
            foreach (Db::rows("SELECT s.item_id, s.warehouse_id, s.bin_id, s.status, s.qty, s.qty_allocated, l.lot_no, l.expiry_date, se.serial_no FROM {stock} s LEFT JOIN {lots} l ON l.id = s.lot_id
                                LEFT JOIN {serials} se ON se.id = s.serial_id WHERE s.warehouse_id IN ($in) AND s.qty > 0 LIMIT 50000") as $s) {
                $out['stock'][] = [(int) $s['item_id'], (int) $s['warehouse_id'], (int) $s['bin_id'], $s['status'], (float) $s['qty'], (float) $s['qty_allocated'], (string) $s['lot_no'], (string) $s['expiry_date'], (string) $s['serial_no']];
            }
        }
        return $this->response->setHeader('Cache-Control', 'no-store, private')->setJSON($out);
    }
}
