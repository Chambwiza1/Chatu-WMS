<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;

/**
 * Barcode labels on A4 sheets (3 x 8, the common 63.5 x 33.9 mm label stock), or one label per page for a
 * label printer. Items carry their barcode (or code), bins their code, batches their batch number and expiry,
 * serial numbers their serial. Scanning any of them into the Find box opens it.
 */
class Labels extends BaseController
{
    private function sheet(array $labels, string $title)
    {
        $per = $this->request->getGet('roll') ? 'roll' : 'a4';
        return view('labels/sheet', ['labels' => $labels, 'title' => $title, 'mode' => $per]);
    }

    private function copies(): int
    {
        return max(1, min(500, (int) ($this->request->getGet('copies') ?: 1)));
    }

    public function items()
    {
        if ($r = $this->needAny(['item.manage', 'stock.view'])) {
            return $r;
        }
        $ids = array_filter(array_map('intval', (array) $this->request->getGet('ids')));
        if (! $ids) {
            return redirect()->to(site_url('items'));
        }
        $n = $this->copies();
        $out = [];
        foreach (Db::rows('SELECT * FROM {items} WHERE id IN (' . implode(',', $ids) . ') ORDER BY sku') as $i) {
            $price = $this->request->getGet('price') && (float) $i['sell_price'] > 0 ? money($i['sell_price']) : null;
            for ($k = 0; $k < $n; $k++) {
                $out[] = ['code' => $i['barcode'] ?: $i['sku'], 'top' => $i['name'], 'line' => $i['sku'] . ' · ' . $i['uom'], 'extra' => $price];
            }
            if ($this->request->getGet('packs')) {
                foreach (\App\Libraries\Master\Items::units((int) $i['id']) as $u) {
                    if ($u['barcode']) {
                        $out[] = ['code' => $u['barcode'], 'top' => $i['name'], 'line' => strtoupper($u['name']) . ' of ' . (float) $u['factor'] . ' ' . $i['uom'], 'extra' => $i['sku']];
                    }
                }
            }
        }
        return $this->sheet($out, 'Item labels');
    }

    public function bins(int $wh)
    {
        if ($r = $this->needAny(['warehouse.setup', 'inventory.move', 'stock.view'], $wh)) {
            return $r;
        }
        $w = Db::row('SELECT * FROM {warehouses} WHERE id = ?', [$wh]);
        $where = 'b.warehouse_id = ?';
        $b = [$wh];
        if ($z = (int) $this->request->getGet('zone_id')) {
            $where .= ' AND b.zone_id = ?';
            $b[] = $z;
        }
        if (! $this->request->getGet('system')) {
            $where .= ' AND b.is_system = 0';
        }
        $out = [];
        foreach (Db::rows("SELECT b.*, z.name zone FROM {bins} b LEFT JOIN {zones} z ON z.id = b.zone_id WHERE $where AND b.status <> 'inactive' ORDER BY b.sort, b.code", $b) as $bin) {
            $out[] = ['code' => $bin['code'], 'top' => $w['code'] . ' ' . ($bin['zone'] ?: \App\Libraries\Master\Warehouses::BIN_TYPES[$bin['type']]), 'line' => $bin['code'], 'extra' => null, 'big' => true];
        }
        return $this->sheet($out, 'Bin labels ' . $w['code']);
    }

    public function lot(int $id)
    {
        if ($r = $this->needAny(['stock.view', 'receive.post'])) {
            return $r;
        }
        $l = Db::row('SELECT l.*, i.name, i.sku FROM {lots} l JOIN {items} i ON i.id = l.item_id WHERE l.id = ?', [$id]);
        if (! $l) {
            return $this->notFound('Batch');
        }
        return $this->sheet(array_fill(0, $this->copies(), self::lotLabel($l)), 'Batch ' . $l['lot_no']);
    }

    private static function lotLabel(array $l): array
    {
        return ['code' => $l['lot_no'], 'top' => $l['name'], 'line' => $l['sku'] . ' · batch ' . $l['lot_no'], 'extra' => $l['expiry_date'] ? 'Best before ' . date('d/m/Y', strtotime($l['expiry_date'])) : ($l['mfg_date'] ? 'Made ' . date('d/m/Y', strtotime($l['mfg_date'])) : null)];
    }

    /** Labels for what a goods receipt or production run brought in: one per serial, or the chosen number per batch. */
    public function receipt(int $id)
    {
        $r = Db::row('SELECT * FROM {receipts} WHERE id = ?', [$id]);
        if (! $r) {
            return $this->notFound('Receipt');
        }
        if ($x = $this->needAny(['receive.post', 'stock.view'], (int) $r['warehouse_id'])) {
            return $x;
        }
        $n = $this->copies();
        $out = [];
        foreach (Db::rows('SELECT rl.*, i.name, i.sku, i.barcode, i.uom, l.lot_no, l.expiry_date, l.mfg_date FROM {receipt_lines} rl JOIN {items} i ON i.id = rl.item_id LEFT JOIN {lots} l ON l.id = rl.lot_id WHERE rl.receipt_id = ?', [$id]) as $line) {
            if ($line['serials']) {
                foreach (array_filter(explode("\n", $line['serials'])) as $sn) {
                    $out[] = ['code' => $sn, 'top' => $line['name'], 'line' => $line['sku'] . ' · serial', 'extra' => $line['lot_no'] ? 'Batch ' . $line['lot_no'] : null];
                }
            } elseif ($line['lot_no']) {
                for ($k = 0; $k < $n; $k++) {
                    $out[] = self::lotLabel($line);
                }
            } else {
                for ($k = 0; $k < $n; $k++) {
                    $out[] = ['code' => $line['barcode'] ?: $line['sku'], 'top' => $line['name'], 'line' => $line['sku'] . ' · ' . $line['uom'], 'extra' => null];
                }
            }
        }
        return $this->sheet($out, 'Labels ' . $r['number']);
    }

    public function run(int $id)
    {
        $p = Db::row('SELECT p.*, l.lot_no, l.expiry_date, l.mfg_date, i.name, i.sku FROM {production_runs} p JOIN {items} i ON i.id = p.output_item_id LEFT JOIN {lots} l ON l.id = p.lot_id WHERE p.id = ?', [$id]);
        if (! $p || ! $p['lot_no']) {
            return $this->notFound('Batch');
        }
        if ($x = $this->needAny(['production.manage', 'stock.view'], (int) $p['warehouse_id'])) {
            return $x;
        }
        return $this->sheet(array_fill(0, $this->copies(), self::lotLabel($p)), 'Batch ' . $p['lot_no']);
    }
}
