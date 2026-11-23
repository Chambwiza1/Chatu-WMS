<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Fleet\Fleet as F;
use App\Libraries\Master\Warehouses;

class Fleet extends BaseController
{
    private function canSee(): bool
    {
        return Access::can('fleet.manage') || Access::can('dispatch.manage');
    }

    public function index()
    {
        if (! $this->canSee()) {
            return $this->need('fleet.manage');
        }
        return $this->render('fleet/index', [
            'vehicles' => Db::rows("SELECT v.*, w.code home, (SELECT t.number FROM {trips} t WHERE t.vehicle_id = v.id AND t.status = 'on_road' LIMIT 1) trip FROM {vehicles} v LEFT JOIN {warehouses} w ON w.id = v.home_warehouse_id ORDER BY v.status = 'retired', v.reg_no"),
            'drivers'  => Db::rows("SELECT d.*, (SELECT t.number FROM {trips} t WHERE t.driver_id = d.id AND t.status = 'on_road' LIMIT 1) trip FROM {drivers} d ORDER BY d.status, d.name"),
            'trips'    => Db::rows("SELECT t.*, v.reg_no, d.name driver, w.code wh, (SELECT COUNT(*) FROM {trip_stops} s WHERE s.trip_id = t.id) stops FROM {trips} t JOIN {vehicles} v ON v.id = t.vehicle_id JOIN {drivers} d ON d.id = t.driver_id
                                     JOIN {warehouses} w ON w.id = t.warehouse_id WHERE t.status IN ('planned','on_road') ORDER BY t.planned_date, t.id"),
            'expiring' => F::expiring(),
        ], 'Fleet and trips');
    }

    /** Vehicles from the Chatu Assets vehicle register CSV: check first, then apply. */
    public function importForm()
    {
        if ($r = $this->need('fleet.manage')) {
            return $r;
        }
        return $this->render('fleet/import', ['plan' => null, 'warehouses' => Warehouses::all()], 'Vehicles from Chatu Assets');
    }

    public function importPost()
    {
        return $this->act(function () {
            Access::require('fleet.manage');
            $f = $this->request->getFile('file');
            if (! $f || ! $f->isValid()) {
                throw new \App\Libraries\Core\BizException('Choose the CSV file downloaded from Chatu Assets.');
            }
            if ($f->getSize() > 2 * 1024 * 1024 || strtolower($f->getClientExtension()) !== 'csv') {
                throw new \App\Libraries\Core\BizException('Use the .csv file (up to 2 MB) from Chatu Assets → Reports → Vehicle register.');
            }
            $rows = \App\Libraries\Fleet\AssetsImport::parse($f->getTempName());
            if ($this->in('apply')) {
                [$l, $a, $s] = \App\Libraries\Fleet\AssetsImport::apply($rows, (bool) $this->in('add_new'), ($this->in('home_warehouse_id') ?? '') !== '' ? (int) $this->in('home_warehouse_id') : null);
                return $this->ok("Done: $l linked, $a added, $s skipped.", 'fleet');
            }
            return $this->render('fleet/import', ['plan' => \App\Libraries\Fleet\AssetsImport::plan($rows), 'warehouses' => Warehouses::all()], 'Vehicles from Chatu Assets');
        }, site_url('fleet/import'));
    }

    public function vehicleForm(?int $id = null)
    {
        if (! $this->canSee()) {
            return $this->need('fleet.manage');
        }
        $v = $id ? Db::row('SELECT * FROM {vehicles} WHERE id = ?', [$id]) : null;
        if ($id && ! $v) {
            return $this->notFound('Vehicle');
        }
        return $this->render('fleet/vehicle', ['v' => $v, 'warehouses' => Warehouses::all(),
            'logs' => $id ? Db::rows('SELECT g.*, u.name user, t.number trip FROM {vehicle_logs} g LEFT JOIN {users} u ON u.id = g.user_id LEFT JOIN {trips} t ON t.id = g.trip_id WHERE g.vehicle_id = ? ORDER BY g.on_date DESC, g.id DESC LIMIT 50', [$id]) : [],
            'trips' => $id ? Db::rows('SELECT t.*, d.name driver FROM {trips} t JOIN {drivers} d ON d.id = t.driver_id WHERE t.vehicle_id = ? ORDER BY t.id DESC LIMIT 20', [$id]) : []], $v ? $v['reg_no'] : 'New vehicle');
    }

    public function vehicleSave(?int $id = null)
    {
        return $this->act(function () use ($id) {
            $vid = F::saveVehicle((array) $this->request->getPost(), $id);
            return $this->ok('Vehicle saved.', 'fleet/vehicles/' . $vid);
        }, $id ? site_url('fleet/vehicles/' . $id) : site_url('fleet/vehicles/new'));
    }

    public function vehicleLog(int $id)
    {
        return $this->act(function () use ($id) {
            F::log($id, (array) $this->request->getPost());
            return $this->ok('Recorded.', 'fleet/vehicles/' . $id);
        }, site_url('fleet/vehicles/' . $id));
    }

    public function driverForm(?int $id = null)
    {
        if (! $this->canSee()) {
            return $this->need('fleet.manage');
        }
        $d = $id ? Db::row('SELECT * FROM {drivers} WHERE id = ?', [$id]) : null;
        if ($id && ! $d) {
            return $this->notFound('Driver');
        }
        return $this->render('fleet/driver', ['d' => $d, 'users' => Db::rows('SELECT id, name FROM {users} WHERE active = 1 ORDER BY name'),
            'trips' => $id ? Db::rows('SELECT t.*, v.reg_no FROM {trips} t JOIN {vehicles} v ON v.id = t.vehicle_id WHERE t.driver_id = ? ORDER BY t.id DESC LIMIT 20', [$id]) : []], $d ? $d['name'] : 'New driver');
    }

    public function driverSave(?int $id = null)
    {
        return $this->act(function () use ($id) {
            F::saveDriver((array) $this->request->getPost(), $id);
            return $this->ok('Driver saved.', 'fleet');
        }, $id ? site_url('fleet/drivers/' . $id) : site_url('fleet/drivers/new'));
    }

    public function trips()
    {
        if (! $this->canSee()) {
            return $this->need('fleet.manage');
        }
        $st = (string) $this->request->getGet('status');
        $w  = $st !== '' ? 't.status = ?' : '1=1';
        $rows = Db::rows("SELECT t.*, v.reg_no, d.name driver, w.code wh, (SELECT COUNT(*) FROM {trip_stops} s WHERE s.trip_id = t.id) stops FROM {trips} t JOIN {vehicles} v ON v.id = t.vehicle_id
                           JOIN {drivers} d ON d.id = t.driver_id JOIN {warehouses} w ON w.id = t.warehouse_id WHERE $w ORDER BY t.id DESC LIMIT 300", $st !== '' ? [$st] : []);
        return $this->render('fleet/trips', ['rows' => $rows, 'st' => $st], 'Trips');
    }

    public function tripForm()
    {
        if ($r = $this->need('fleet.manage')) {
            return $r;
        }
        $wh = $this->wh('stock.view') ?: (int) (Warehouses::all()[0]['id'] ?? 0);
        return $this->render('fleet/trip_form', ['wh' => $wh, 'warehouses' => Warehouses::all(),
            'vehicles' => Db::rows("SELECT * FROM {vehicles} WHERE status = 'available' ORDER BY reg_no"), 'drivers' => Db::rows("SELECT * FROM {drivers} WHERE status = 'active' ORDER BY name"),
            'shipments' => Db::rows("SELECT s.*, so.number order_no, c.name customer, c.town FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id
                                     WHERE s.warehouse_id = ? AND s.status IN ('allocated','picked','packed') AND s.trip_id IS NULL ORDER BY c.town, s.id", [$wh]),
            'transfers' => Db::rows("SELECT t.*, w.code to_code FROM {transfers} t JOIN {warehouses} w ON w.id = t.to_warehouse_id WHERE t.from_warehouse_id = ? AND t.status IN ('draft','allocated') AND t.trip_id IS NULL ORDER BY t.id", [$wh])], 'Plan a trip');
    }

    public function tripPlan()
    {
        return $this->act(function () {
            $id = F::planTrip((array) $this->request->getPost());
            return $this->ok('Trip planned. Pack everything, then record the departure at the gate.', 'fleet/trips/' . $id);
        }, site_url('fleet/trips/new?warehouse_id=' . (int) $this->in('warehouse_id')));
    }

    public function trip(int $id)
    {
        if (! $this->canSee() && ! Access::can('stock.view')) {
            return $this->need('fleet.manage');
        }
        $t = Db::row('SELECT t.*, v.reg_no, v.make, v.model, v.capacity_kg, v.odometer_km, d.name driver, d.phone driver_phone, d.licence_no, w.code wh, w.name wh_name FROM {trips} t
                        JOIN {vehicles} v ON v.id = t.vehicle_id JOIN {drivers} d ON d.id = t.driver_id JOIN {warehouses} w ON w.id = t.warehouse_id WHERE t.id = ?', [$id]);
        if (! $t) {
            return $this->notFound('Trip');
        }
        $stops = Db::rows("SELECT ts.*, COALESCE(s.number, tr.number) number, COALESCE(s.status, tr.status) doc_status, c.name customer, c.town, so.delivery_address, tw.code to_code, s.packages
                             FROM {trip_stops} ts LEFT JOIN {shipments} s ON ts.kind = 'shipment' AND s.id = ts.doc_id LEFT JOIN {sales_orders} so ON so.id = s.so_id LEFT JOIN {customers} c ON c.id = so.customer_id
                             LEFT JOIN {transfers} tr ON ts.kind = 'transfer' AND tr.id = ts.doc_id LEFT JOIN {warehouses} tw ON tw.id = tr.to_warehouse_id WHERE ts.trip_id = ? ORDER BY ts.seq", [$id]);
        return $this->render('fleet/trip', ['t' => $t, 'stops' => $stops, 'load' => F::load($id), 'blockers' => $t['status'] === 'planned' ? F::blockers((int) $t['vehicle_id'], (int) $t['driver_id'], date('Y-m-d')) : [],
            'more' => $t['status'] === 'planned' ? Db::rows("SELECT s.id, s.number, c.name customer FROM {shipments} s JOIN {sales_orders} so ON so.id = s.so_id JOIN {customers} c ON c.id = so.customer_id WHERE s.warehouse_id = ? AND s.status IN ('allocated','picked','packed') AND s.trip_id IS NULL", [$t['warehouse_id']]) : []], $t['number']);
    }

    public function tripAct(int $id, string $what)
    {
        return $this->act(function () use ($id, $what) {
            switch ($what) {
                case 'depart':
                    F::depart($id, (int) $this->in('odometer'), $this->in('op_token'));
                    return $this->ok('Departed: everything on the trip is dispatched.', 'fleet/trips/' . $id);
                case 'complete':
                    F::complete($id, (int) $this->in('odometer'), ['litres' => $this->in('litres'), 'cost' => $this->in('fuel_cost')]);
                    return $this->ok('Trip closed; the vehicle is available.', 'fleet/trips/' . $id);
                case 'cancel':
                    F::cancelTrip($id, (string) $this->in('why', ''));
                    return $this->ok('Trip cancelled.', 'fleet/trips/' . $id);
                case 'stop':
                    Db::atomic(fn () => F::addStop($id, 'shipment', (int) $this->in('shipment_id')));
                    return $this->ok('Added.', 'fleet/trips/' . $id);
                case 'unstop':
                    F::removeStop((int) $this->in('stop_id'));
                    return $this->ok('Removed.', 'fleet/trips/' . $id);
            }
            return $this->notFound();
        }, site_url('fleet/trips/' . $id));
    }
}
