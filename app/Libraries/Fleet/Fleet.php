<?php

namespace App\Libraries\Fleet;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Docs\Shipments;
use App\Libraries\Docs\Transfers;
use App\Libraries\Master\Numbers;
use App\Libraries\Stock\Lots;

/**
 * Vehicles, drivers and delivery trips.
 *
 * A trip (planned → on the road → completed) carries shipments and transfers from one warehouse.
 * It cannot leave with a vehicle whose insurance, fitness or road tax has run out, a driver whose licence has
 * run out, or more weight than the vehicle carries. Departure dispatches everything on it in one step.
 */
final class Fleet
{
    public const VEHICLE_FIELDS = ['reg_no', 'make', 'model', 'vin', 'asset_code', 'body', 'ownership', 'home_warehouse_id', 'capacity_kg', 'capacity_m3', 'odometer_km', 'insurance_expiry', 'fitness_expiry', 'road_tax_expiry', 'next_service_date', 'next_service_km', 'status', 'notes'];

    public static function saveVehicle(array $d, ?int $id = null): int
    {
        Access::require('fleet.manage');
        $reg = strtoupper(preg_replace('/\s+/', ' ', trim((string) ($d['reg_no'] ?? ''))));
        if ($reg === '' || mb_strlen($reg) > 20) {
            throw new BizException('Give the registration number.');
        }
        $row = ['reg_no' => $reg];
        foreach (['make', 'model', 'notes'] as $f) {
            $row[$f] = trim((string) ($d[$f] ?? '')) ?: null;
        }
        foreach (['vin', 'asset_code'] as $f) {
            if (array_key_exists($f, $d)) {
                $row[$f] = mb_substr(trim((string) $d[$f]), 0, 40) ?: null;
            }
        }
        if (isset($row['vin'])) {
            $row['vin'] = strtoupper($row['vin']);
        }
        $row['body']      = in_array($d['body'] ?? '', ['truck', 'van', 'pickup', 'car', 'motorbike', 'trailer', 'other'], true) ? $d['body'] : 'truck';
        $row['ownership'] = in_array($d['ownership'] ?? '', ['own', 'hired', 'contractor'], true) ? $d['ownership'] : 'own';
        $row['home_warehouse_id'] = ($d['home_warehouse_id'] ?? '') !== '' ? (int) $d['home_warehouse_id'] : null;
        foreach (['capacity_kg', 'capacity_m3'] as $f) {
            $row[$f] = ($d[$f] ?? '') !== '' ? max(0, (float) $d[$f]) : null;
        }
        $row['odometer_km']     = max(0, (int) ($d['odometer_km'] ?? 0));
        $row['next_service_km'] = ($d['next_service_km'] ?? '') !== '' ? max(0, (int) $d['next_service_km']) : null;
        foreach (['insurance_expiry', 'fitness_expiry', 'road_tax_expiry', 'next_service_date'] as $f) {
            $row[$f] = Lots::date($d[$f] ?? '');
        }
        return Db::atomic(static function () use ($row, $id, $reg, $d) {
            if (Db::value('SELECT id FROM {vehicles} WHERE tenant_id = {tid} AND reg_no = ? AND id <> ?', [$reg, $id ?? 0])) {
                throw new BizException('Vehicle ' . $reg . ' is already registered.');
            }
            if ($id) {
                $old = Db::row('SELECT * FROM {vehicles} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$id]);
                if (! $old) {
                    throw new BizException('Unknown vehicle.');
                }
                $status = in_array($d['status'] ?? '', ['available', 'in_service', 'retired'], true) ? $d['status'] : $old['status'];
                if ($old['status'] === 'on_trip') {
                    $status = 'on_trip';
                    $row['odometer_km'] = max($row['odometer_km'], (int) $old['odometer_km']);
                }
                $row['status'] = $status;
                Db::exec('UPDATE {vehicles} SET ' . implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($row))) . ' WHERE tenant_id = {tid} AND id = ?', array_merge(array_values($row), [$id]));
                Audit::log('vehicle.update', 'vehicle', $id, array_intersect_key($old, $row), $row);
            } else {
                $id = Db::insert('vehicles', $row + ['status' => 'available', 'created_at' => Db::now()]);
                Audit::log('vehicle.create', 'vehicle', $id, null, $row);
            }
            return $id;
        });
    }

    public static function saveDriver(array $d, ?int $id = null): int
    {
        Access::require('fleet.manage');
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BizException('Give the driver\'s name.');
        }
        $row = ['name' => mb_substr($name, 0, 150), 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'licence_no' => trim((string) ($d['licence_no'] ?? '')) ?: null,
            'licence_class' => trim((string) ($d['licence_class'] ?? '')) ?: null, 'licence_expiry' => Lots::date($d['licence_expiry'] ?? ''),
            'user_id' => ($d['user_id'] ?? '') !== '' ? (int) $d['user_id'] : null, 'status' => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null];
        return Db::atomic(static function () use ($row, $id) {
            if ($id) {
                Db::exec('UPDATE {drivers} SET ' . implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($row))) . ' WHERE tenant_id = {tid} AND id = ?', array_merge(array_values($row), [$id]));
                Audit::log('driver.update', 'driver', $id, null, $row);
            } else {
                $id = Db::insert('drivers', $row + ['created_at' => Db::now()]);
                Audit::log('driver.create', 'driver', $id, null, $row);
            }
            return $id;
        });
    }

    /** Fuel, service, repair, inspection or incident. A service can set the next service date and kilometres. */
    public static function log(int $vehicleId, array $d): int
    {
        Access::require('fleet.manage');
        $kind = (string) ($d['kind'] ?? '');
        if (! in_array($kind, ['fuel', 'service', 'repair', 'inspection', 'incident'], true)) {
            throw new BizException('Choose what is being recorded.');
        }
        return Db::atomic(static function () use ($vehicleId, $d, $kind) {
            $v = Db::row('SELECT * FROM {vehicles} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$vehicleId]);
            if (! $v) {
                throw new BizException('Unknown vehicle.');
            }
            $odo = ($d['odometer_km'] ?? '') !== '' ? (int) $d['odometer_km'] : null;
            if ($odo !== null && $odo < (int) $v['odometer_km']) {
                throw new BizException('The odometer reading is lower than the last one recorded (' . number_format((int) $v['odometer_km']) . ' km).');
            }
            $id = Db::insert('vehicle_logs', [
                'vehicle_id' => $vehicleId, 'kind' => $kind, 'on_date' => Lots::date($d['on_date'] ?? '') ?? date('Y-m-d'), 'odometer_km' => $odo,
                'litres' => ($d['litres'] ?? '') !== '' ? (float) $d['litres'] : null, 'cost' => round((float) str_replace(',', '', (string) ($d['cost'] ?? 0)), 2),
                'trip_id' => ($d['trip_id'] ?? '') !== '' ? (int) $d['trip_id'] : null, 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'user_id' => Auth::id(), 'created_at' => Db::now(),
            ]);
            if ($odo !== null) {
                Db::exec('UPDATE {vehicles} SET odometer_km = ? WHERE tenant_id = {tid} AND id = ?', [$odo, $vehicleId]);
            }
            if ($kind === 'service') {
                Db::exec('UPDATE {vehicles} SET next_service_date = COALESCE(?, next_service_date), next_service_km = COALESCE(?, next_service_km) WHERE tenant_id = {tid} AND id = ?',
                    [Lots::date($d['next_service_date'] ?? ''), ($d['next_service_km'] ?? '') !== '' ? (int) $d['next_service_km'] : null, $vehicleId]);
            }
            Audit::log('vehicle.' . $kind, 'vehicle', $vehicleId, null, ['log' => $id, 'odometer' => $odo]);
            return $id;
        });
    }

    /** Problems that stop a vehicle or driver from going out on $date. */
    public static function blockers(int $vehicleId, int $driverId, string $date): array
    {
        $out = [];
        $v   = Db::row('SELECT * FROM {vehicles} WHERE tenant_id = {tid} AND id = ?', [$vehicleId]);
        $dr  = Db::row('SELECT * FROM {drivers} WHERE tenant_id = {tid} AND id = ?', [$driverId]);
        if (! $v) {
            return ['Unknown vehicle.'];
        }
        if (! $dr) {
            return ['Unknown driver.'];
        }
        if (in_array($v['status'], ['retired', 'in_service'], true)) {
            $out[] = $v['reg_no'] . ' is ' . ($v['status'] === 'retired' ? 'retired' : 'in the workshop') . '.';
        }
        foreach (['insurance_expiry' => 'insurance', 'fitness_expiry' => 'fitness certificate', 'road_tax_expiry' => 'road tax'] as $f => $label) {
            if ($v[$f] && $v[$f] < $date) {
                $out[] = $v['reg_no'] . ': ' . $label . ' ran out on ' . $v[$f] . '.';
            }
        }
        if ($dr['status'] !== 'active') {
            $out[] = $dr['name'] . ' is not an active driver.';
        }
        if (! $dr['licence_expiry'] || $dr['licence_expiry'] < $date) {
            $out[] = $dr['name'] . ($dr['licence_expiry'] ? '\'s licence ran out on ' . $dr['licence_expiry'] : ' has no licence expiry date recorded') . '.';
        }
        return $out;
    }

    /** $d: warehouse_id, vehicle_id, driver_id, planned_date, route_note, shipments [ids], transfers [ids]. */
    public static function planTrip(array $d): int
    {
        Access::require('fleet.manage');
        $wh   = (int) ($d['warehouse_id'] ?? 0);
        $date = Lots::date($d['planned_date'] ?? '') ?? date('Y-m-d');
        return Db::atomic(static function () use ($d, $wh, $date) {
            $vid = (int) ($d['vehicle_id'] ?? 0);
            $did = (int) ($d['driver_id'] ?? 0);
            if ($b = self::blockers($vid, $did, $date)) {
                throw new BizException(implode(' ', $b));
            }
            if (! Db::value('SELECT 1 FROM {warehouses} WHERE tenant_id = {tid} AND id = ? AND active = 1', [$wh])) {
                throw new BizException('Choose the warehouse the trip leaves from.');
            }
            $id = Db::insert('trips', ['number' => Numbers::next('trip'), 'warehouse_id' => $wh, 'vehicle_id' => $vid, 'driver_id' => $did, 'status' => 'planned',
                'planned_date' => $date, 'route_note' => trim((string) ($d['route_note'] ?? '')) ?: null, 'created_by' => Auth::id(), 'created_at' => Db::now()]);
            $seq = 0;
            foreach ($d['shipments'] ?? [] as $sid) {
                self::addStop($id, 'shipment', (int) $sid, ++$seq);
            }
            foreach ($d['transfers'] ?? [] as $tid) {
                self::addStop($id, 'transfer', (int) $tid, ++$seq);
            }
            self::checkLoad($id);
            Audit::log('trip.plan', 'trip', $id, null, ['vehicle' => $vid, 'driver' => $did, 'date' => $date, 'stops' => $seq]);
            return $id;
        });
    }

    public static function addStop(int $tripId, string $kind, int $docId, ?int $seq = null): void
    {
        Access::require('fleet.manage');
        $trip = Db::row('SELECT * FROM {trips} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$tripId]);
        if (! $trip || $trip['status'] !== 'planned') {
            throw new BizException('Stops can only be added to a planned trip.');
        }
        if ($kind === 'shipment') {
            $s = Shipments::get($docId, true);
            if ((int) $s['warehouse_id'] !== (int) $trip['warehouse_id'] || ! in_array($s['status'], ['allocated', 'picked', 'packed'], true)) {
                throw new BizException('Shipment ' . $s['number'] . ' is not waiting to leave this warehouse.');
            }
        } elseif ($kind === 'transfer') {
            $s = Transfers::get($docId, true);
            if ((int) $s['from_warehouse_id'] !== (int) $trip['warehouse_id'] || ! in_array($s['status'], ['draft', 'allocated'], true)) {
                throw new BizException('Transfer ' . $s['number'] . ' is not waiting to leave this warehouse.');
            }
        } else {
            throw new BizException('Unknown stop.');
        }
        $busy = Db::value("SELECT t.number FROM {trip_stops} ts JOIN {trips} t ON t.id = ts.trip_id WHERE ts.tenant_id = {tid} AND ts.kind = ? AND ts.doc_id = ? AND t.status IN ('planned','on_road') AND ts.status = 'pending'", [$kind, $docId]);
        if ($busy) {
            throw new BizException($s['number'] . ' is already on trip ' . $busy . '.');
        }
        Db::insert('trip_stops', ['trip_id' => $tripId, 'seq' => $seq ?? ((int) Db::value('SELECT COALESCE(MAX(seq), 0) FROM {trip_stops} WHERE tenant_id = {tid} AND trip_id = ?', [$tripId]) + 1), 'kind' => $kind, 'doc_id' => $docId]);
        Db::exec('UPDATE {' . ($kind === 'shipment' ? 'shipments' : 'transfers') . '} SET trip_id = ? WHERE tenant_id = {tid} AND id = ?', [$tripId, $docId]);
    }

    public static function removeStop(int $stopId): void
    {
        Access::require('fleet.manage');
        Db::atomic(static function () use ($stopId) {
            $st = Db::row('SELECT ts.*, t.status trip_status FROM {trip_stops} ts JOIN {trips} t ON t.id = ts.trip_id WHERE ts.tenant_id = {tid} AND ts.id = ? FOR UPDATE', [$stopId]);
            if (! $st || $st['trip_status'] !== 'planned') {
                throw new BizException('Stops can only be removed from a planned trip.');
            }
            Db::exec('DELETE FROM {trip_stops} WHERE tenant_id = {tid} AND id = ?', [$stopId]);
            Db::exec('UPDATE {' . ($st['kind'] === 'shipment' ? 'shipments' : 'transfers') . '} SET trip_id = NULL WHERE tenant_id = {tid} AND id = ?', [$st['doc_id']]);
        });
    }

    /** Weight on the trip (shipments' recorded or computed weight, transfers from item weights) against capacity. */
    public static function load(int $tripId): float
    {
        $kg = (float) Db::value("SELECT COALESCE(SUM(COALESCE(s.weight_kg, (SELECT SUM(sl.qty * COALESCE(i.weight_kg, 0)) FROM {shipment_lines} sl JOIN {items} i ON i.id = sl.item_id WHERE sl.tenant_id = {tid} AND sl.shipment_id = s.id))), 0)
                                   FROM {trip_stops} ts JOIN {shipments} s ON s.id = ts.doc_id WHERE ts.tenant_id = {tid} AND ts.trip_id = ? AND ts.kind = 'shipment'", [$tripId]);
        $kg += (float) Db::value("SELECT COALESCE(SUM(tl.qty * COALESCE(i.weight_kg, 0)), 0) FROM {trip_stops} ts JOIN {transfer_lines} tl ON tl.transfer_id = ts.doc_id JOIN {items} i ON i.id = tl.item_id
                                   WHERE ts.tenant_id = {tid} AND ts.trip_id = ? AND ts.kind = 'transfer'", [$tripId]);
        return round($kg, 1);
    }

    private static function checkLoad(int $tripId): void
    {
        $cap = Db::value('SELECT v.capacity_kg FROM {trips} t JOIN {vehicles} v ON v.id = t.vehicle_id WHERE t.tenant_id = {tid} AND t.id = ?', [$tripId]);
        $kg  = self::load($tripId);
        if ($cap !== null && (float) $cap > 0 && $kg > (float) $cap) {
            throw new BizException('The load (' . number_format($kg, 1) . ' kg) is more than the vehicle carries (' . number_format((float) $cap, 1) . ' kg).');
        }
    }

    /**
     * The vehicle leaves: every shipment on the trip must be packed and every transfer allocated; all of them are
     * dispatched now. Done at the gate by someone who dispatches at that warehouse.
     */
    public static function depart(int $tripId, int $odometer, ?string $token = null): void
    {
        Db::atomic(static function () use ($tripId, $odometer, $token) {
            Db::once($token, Auth::id(), static function () use ($tripId, $odometer) {
                $trip = Db::row('SELECT * FROM {trips} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$tripId]);
                if (! $trip) {
                    throw new BizException('Unknown trip.');
                }
                Access::requireAt((int) $trip['warehouse_id'], 'dispatch.manage');
                if ($trip['status'] !== 'planned') {
                    throw new BizException('This trip has already left.');
                }
                if ($b = self::blockers((int) $trip['vehicle_id'], (int) $trip['driver_id'], date('Y-m-d'))) {
                    throw new BizException(implode(' ', $b));
                }
                $v = Db::row('SELECT * FROM {vehicles} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$trip['vehicle_id']]);
                if ($v['status'] !== 'available') {
                    throw new BizException($v['reg_no'] . ' is not available (' . str_replace('_', ' ', $v['status']) . ').');
                }
                if ($odometer < (int) $v['odometer_km']) {
                    throw new BizException('The odometer reading is lower than the last one recorded (' . number_format((int) $v['odometer_km']) . ' km).');
                }
                $stops = Db::rows('SELECT * FROM {trip_stops} WHERE tenant_id = {tid} AND trip_id = ? ORDER BY seq', [$tripId]);
                if (! $stops) {
                    throw new BizException('The trip carries nothing.');
                }
                self::checkLoad($tripId);
                foreach ($stops as $s) {
                    if ($s['kind'] === 'shipment') {
                        $sh = Shipments::get((int) $s['doc_id']);
                        if ($sh['status'] !== 'packed') {
                            throw new BizException('Shipment ' . $sh['number'] . ' is not packed yet.');
                        }
                        Shipments::dispatch((int) $s['doc_id']);
                    } else {
                        $tr = Transfers::get((int) $s['doc_id']);
                        if ($tr['status'] !== 'allocated') {
                            throw new BizException('Transfer ' . $tr['number'] . ' is not allocated yet.');
                        }
                        Transfers::dispatch((int) $s['doc_id'], null, true);
                    }
                }
                Db::exec("UPDATE {trips} SET status = 'on_road', departed_at = ?, odometer_out = ? WHERE tenant_id = {tid} AND id = ?", [Db::now(), $odometer, $tripId]);
                Db::exec("UPDATE {vehicles} SET status = 'on_trip', odometer_km = ? WHERE tenant_id = {tid} AND id = ?", [$odometer, $trip['vehicle_id']]);
                Audit::log('trip.depart', 'trip', $tripId, null, ['odometer' => $odometer, 'stops' => count($stops)]);
                return $tripId;
            });
        });
    }

    /** The vehicle is back. Every delivery must be recorded (delivered or failed) first. */
    public static function complete(int $tripId, int $odometer, array $fuel = []): void
    {
        Access::require('fleet.manage');
        Db::atomic(static function () use ($tripId, $odometer, $fuel) {
            $trip = Db::row('SELECT * FROM {trips} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$tripId]);
            if (! $trip || $trip['status'] !== 'on_road') {
                throw new BizException('Only a trip on the road can be completed.');
            }
            $open = Db::value("SELECT s.number FROM {trip_stops} ts JOIN {shipments} s ON s.id = ts.doc_id WHERE ts.tenant_id = {tid} AND ts.trip_id = ? AND ts.kind = 'shipment' AND ts.status = 'pending' LIMIT 1", [$tripId]);
            if ($open) {
                throw new BizException('Record the delivery (or failed delivery) of ' . $open . ' first.');
            }
            if ($odometer < (int) $trip['odometer_out']) {
                throw new BizException('The odometer reading in is lower than when the trip left (' . number_format((int) $trip['odometer_out']) . ' km).');
            }
            Db::exec("UPDATE {trip_stops} SET status = 'done', done_at = ? WHERE tenant_id = {tid} AND trip_id = ? AND kind = 'transfer' AND status = 'pending'", [Db::now(), $tripId]);
            Db::exec("UPDATE {trips} SET status = 'completed', returned_at = ?, odometer_in = ? WHERE tenant_id = {tid} AND id = ?", [Db::now(), $odometer, $tripId]);
            Db::exec("UPDATE {vehicles} SET status = 'available', odometer_km = ? WHERE tenant_id = {tid} AND id = ?", [$odometer, $trip['vehicle_id']]);
            if (! empty($fuel['litres']) || ! empty($fuel['cost'])) {
                self::log((int) $trip['vehicle_id'], ['kind' => 'fuel', 'litres' => $fuel['litres'] ?? null, 'cost' => $fuel['cost'] ?? 0, 'odometer_km' => $odometer, 'trip_id' => $tripId]);
            }
            Audit::log('trip.complete', 'trip', $tripId, null, ['odometer' => $odometer, 'km' => $odometer - (int) $trip['odometer_out']]);
        });
    }

    public static function cancelTrip(int $tripId, string $why): void
    {
        Access::require('fleet.manage');
        Db::atomic(static function () use ($tripId, $why) {
            $trip = Db::row('SELECT * FROM {trips} WHERE tenant_id = {tid} AND id = ? FOR UPDATE', [$tripId]);
            if (! $trip || $trip['status'] !== 'planned') {
                throw new BizException('Only a planned trip can be cancelled.');
            }
            foreach (Db::rows('SELECT * FROM {trip_stops} WHERE tenant_id = {tid} AND trip_id = ?', [$tripId]) as $s) {
                Db::exec('UPDATE {' . ($s['kind'] === 'shipment' ? 'shipments' : 'transfers') . '} SET trip_id = NULL WHERE tenant_id = {tid} AND id = ? AND trip_id = ?', [$s['doc_id'], $tripId]);
            }
            Db::exec('DELETE FROM {trip_stops} WHERE tenant_id = {tid} AND trip_id = ?', [$tripId]);
            Db::exec("UPDATE {trips} SET status = 'cancelled', route_note = CONCAT(COALESCE(route_note, ''), ?) WHERE tenant_id = {tid} AND id = ?", [' (cancelled: ' . mb_substr($why, 0, 100) . ')', $tripId]);
            Audit::log('trip.cancel', 'trip', $tripId, null, null, 'success', $why);
        });
    }

    /** Vehicles and drivers whose papers run out within the warning period (or already have). */
    public static function expiring(?int $days = null): array
    {
        $days ??= (int) Settings::get('fleet_warn_days');
        $until = date('Y-m-d', strtotime('+' . $days . ' days'));
        $out   = [];
        foreach (Db::rows("SELECT * FROM {vehicles} WHERE tenant_id = {tid} AND status <> 'retired'") as $v) {
            foreach (['insurance_expiry' => 'Insurance', 'fitness_expiry' => 'Fitness certificate', 'road_tax_expiry' => 'Road tax', 'next_service_date' => 'Service due'] as $f => $label) {
                if ($v[$f] && $v[$f] <= $until) {
                    $out[] = ['what' => $v['reg_no'], 'label' => $label, 'date' => $v[$f], 'link' => '/fleet/vehicles/' . $v['id'], 'overdue' => $v[$f] < date('Y-m-d')];
                }
            }
            if ($v['next_service_km'] && (int) $v['odometer_km'] >= (int) $v['next_service_km'] - 500) {
                $out[] = ['what' => $v['reg_no'], 'label' => 'Service due at ' . number_format((int) $v['next_service_km']) . ' km (now ' . number_format((int) $v['odometer_km']) . ')', 'date' => null, 'link' => '/fleet/vehicles/' . $v['id'], 'overdue' => (int) $v['odometer_km'] >= (int) $v['next_service_km']];
            }
        }
        foreach (Db::rows("SELECT * FROM {drivers} WHERE tenant_id = {tid} AND status = 'active'") as $dr) {
            if (! $dr['licence_expiry'] || $dr['licence_expiry'] <= $until) {
                $out[] = ['what' => $dr['name'], 'label' => 'Driving licence', 'date' => $dr['licence_expiry'], 'link' => '/fleet/drivers/' . $dr['id'], 'overdue' => ! $dr['licence_expiry'] || $dr['licence_expiry'] < date('Y-m-d')];
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $out;
    }
}
