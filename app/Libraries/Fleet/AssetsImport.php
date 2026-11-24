<?php

namespace App\Libraries\Fleet;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;

/**
 * Bring vehicles in from the Chatu Assets vehicle register (Reports → Vehicle register → CSV). Matched on
 * registration number: matched vehicles get the asset number and VIN (and make, model and insurance date
 * where ours are empty); unmatched ones can be added. Retired assets are skipped. Nothing else is linked:
 * the two systems stay separate.
 */
final class AssetsImport
{
    private const COLS = ['asset' => 'Asset', 'reg' => 'Registration', 'desc' => 'Description', 'makemodel' => 'Make and model', 'vin' => 'VIN (chassis)', 'state' => 'State', 'insured' => 'Insured until'];

    public static function norm(string $reg): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($reg));
    }

    /** Parse the file into rows [asset, reg, desc, make, model, vin, retired, insured]. */
    public static function parse(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            throw new BizException('Could not read the file.');
        }
        $head = fgetcsv($fh, 0, ',', '"', '');
        if (! $head) {
            throw new BizException('The file is empty.');
        }
        $head = array_map(static fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $head);
        $idx  = [];
        foreach (self::COLS as $k => $label) {
            $i = array_search($label, $head, true);
            if ($i !== false) {
                $idx[$k] = $i;
            }
        }
        if (! isset($idx['reg'], $idx['asset'])) {
            throw new BizException('This is not the Chatu Assets vehicle register: the file needs the columns "Asset" and "Registration". In Chatu Assets open Reports → Vehicle register and download the CSV.');
        }
        $out = [];
        $n   = 0;
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (++$n > 5000) {
                throw new BizException('Up to 5,000 vehicles per file.');
            }
            $get = static function (string $k) use ($r, $idx) {
                $v = isset($idx[$k]) ? trim((string) ($r[$idx[$k]] ?? '')) : '';
                $v = ltrim($v, "'");
                return in_array($v, ['Missing', 'Not insured'], true) ? '' : $v;
            };
            if ($get('reg') === '' && $get('asset') === '') {
                continue;
            }
            $mm    = $get('makemodel');
            $parts = $mm === '' ? ['', ''] : array_pad(explode(' ', $mm, 2), 2, '');
            $ins   = $get('insured');
            $out[] = ['asset' => mb_substr($get('asset'), 0, 40), 'reg' => strtoupper(preg_replace('/\s+/', ' ', $get('reg'))), 'desc' => $get('desc'), 'make' => $parts[0], 'model' => $parts[1],
                'vin' => strtoupper(mb_substr($get('vin'), 0, 40)), 'retired' => strcasecmp($get('state'), 'Retired') === 0 || stripos($get('state'), 'disposed') !== false,
                'insured' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ins) ? $ins : null];
        }
        fclose($fh);
        return $out;
    }

    /** What would happen: each row with action match|add|skip and a reason. */
    public static function plan(array $rows): array
    {
        $ours = [];
        foreach (Db::rows('SELECT * FROM {vehicles} WHERE tenant_id = {tid}') as $v) {
            $ours[self::norm($v['reg_no'])] = $v;
        }
        $seen = [];
        foreach ($rows as &$r) {
            $k = self::norm($r['reg']);
            if ($r['reg'] === '') {
                [$r['action'], $r['why']] = ['skip', 'No registration in Chatu Assets'];
            } elseif (isset($seen[$k])) {
                [$r['action'], $r['why']] = ['skip', 'Registration appears twice in the file'];
            } elseif ($r['retired']) {
                [$r['action'], $r['why']] = ['skip', 'Retired or disposed in Chatu Assets'];
            } elseif (isset($ours[$k])) {
                $v = $ours[$k];
                $r['vehicle_id'] = (int) $v['id'];
                $same = (string) $v['asset_code'] === $r['asset'] && (string) $v['vin'] === $r['vin'];
                [$r['action'], $r['why']] = $same ? ['skip', 'Already linked'] : ['match', 'Linked to ' . $v['reg_no']];
            } else {
                [$r['action'], $r['why']] = ['add', 'Not in the fleet yet'];
            }
            $seen[$k] = true;
        }
        return $rows;
    }

    /** Apply the plan. Returns [linked, added, skipped]. */
    public static function apply(array $rows, bool $addNew, ?int $homeWarehouse = null): array
    {
        Access::require('fleet.manage');
        return Db::atomic(static function () use ($rows, $addNew, $homeWarehouse) {
            $linked = $added = $skipped = 0;
            foreach (self::plan($rows) as $r) {
                if ($r['action'] === 'match') {
                    $v = Db::row('SELECT * FROM {vehicles} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$r['vehicle_id']]);
                    $set = ['asset_code' => $r['asset'] ?: null, 'vin' => $r['vin'] ?: $v['vin']];
                    foreach (['make' => 'make', 'model' => 'model'] as $col => $k) {
                        if (! $v[$col] && $r[$k] !== '') {
                            $set[$col] = $r[$k];
                        }
                    }
                    if (! $v['insurance_expiry'] && $r['insured']) {
                        $set['insurance_expiry'] = $r['insured'];
                    }
                    Db::exec('UPDATE {vehicles} SET ' . implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($set))) . ' WHERE tenant_id = {tid} AND id = ?', array_merge(array_values($set), [$v['id']]));
                    Audit::log('vehicle.link_assets', 'vehicle', (int) $v['id'], array_intersect_key($v, $set), $set);
                    $linked++;
                } elseif ($r['action'] === 'add' && $addNew) {
                    if (mb_strlen($r['reg']) > 20) {
                        $skipped++;
                        continue;
                    }
                    $id = Fleet::saveVehicle(['reg_no' => $r['reg'], 'make' => $r['make'], 'model' => $r['model'], 'body' => self::guessBody($r['desc']), 'ownership' => 'own',
                        'home_warehouse_id' => $homeWarehouse, 'insurance_expiry' => $r['insured'], 'notes' => $r['desc'] !== '' ? 'From Chatu Assets: ' . mb_substr($r['desc'], 0, 200) : null]);
                    Db::exec('UPDATE {vehicles} SET asset_code = ?, vin = ? WHERE tenant_id = {tid} AND id = ?', [$r['asset'] ?: null, $r['vin'] ?: null, $id]);
                    $added++;
                } else {
                    $skipped++;
                }
            }
            Audit::log('vehicle.import_assets', 'vehicle', null, null, ['linked' => $linked, 'added' => $added, 'skipped' => $skipped]);
            return [$linked, $added, $skipped];
        });
    }

    private static function guessBody(string $desc): string
    {
        $d = strtolower($desc);
        foreach (['trailer' => 'trailer', 'motorbike' => 'motorbike', 'motorcycle' => 'motorbike', 'pickup' => 'pickup', 'pick-up' => 'pickup', 'bakkie' => 'pickup', 'van' => 'van', 'truck' => 'truck', 'lorry' => 'truck', 'tipper' => 'truck', 'car' => 'car', 'sedan' => 'car', 'suv' => 'car'] as $w => $b) {
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $d)) {
                return $b;
            }
        }
        return 'other';
    }
}
