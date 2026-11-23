<?php

namespace App\Libraries\Setup;

use App\Libraries\Core\Db;

/**
 * Reference data: permissions, duty roles, segregation-of-duties rules, reason codes.
 * Additive: running it again on an upgraded installation adds what is new and keeps local changes.
 */
final class Seed
{
    public const PERMISSIONS = [
        'stock.view'        => 'See stock, documents and movements at the warehouse',
        'stock.view_cost'   => 'See costs and stock values',
        'item.manage'       => 'Maintain items and categories',
        'supplier.manage'   => 'Maintain suppliers',
        'customer.manage'   => 'Maintain customers',
        'purchase.manage'   => 'Raise, issue and close purchase orders',
        'receive.post'      => 'Receive goods and return goods to suppliers',
        'stock.opening'     => 'Enter opening stock balances',
        'inventory.move'    => 'Put away and move stock; put stock on hold or in quarantine',
        'lot.hold'          => 'Hold, recall and release batches',
        'transfer.manage'   => 'Raise, allocate and dispatch transfers from the warehouse',
        'transfer.receive'  => 'Receive transfers into the warehouse',
        'sales.manage'      => 'Take, confirm and cancel sales orders',
        'dispatch.manage'   => 'Pick, pack and dispatch orders; record deliveries and returns',
        'production.manage' => 'Post and void production runs; maintain recipes',
        'fleet.manage'      => 'Maintain vehicles and drivers; plan and close trips',
        'adjust.request'    => 'Raise stock adjustments',
        'count.manage'      => 'Start, submit and cancel stock counts',
        'count.enter'       => 'Enter counted quantities',
        'stock.approve'     => 'Approve adjustments and count results; release stock from quarantine (never your own)',
        'report.view'       => 'Run reports',
        'data.export'       => 'Export data to CSV',
        'warehouse.setup'   => 'Maintain warehouses, zones and bins',
        'admin.users'       => 'Maintain users and their roles',
        'admin.settings'    => 'Maintain system settings and reason codes',
        'audit.view'        => 'See the audit log',
    ];

    /** code => [name, rank, description, permissions] */
    public const ROLES = [
        'director'          => ['Director / owner', 6, 'Sees everything, approves adjustments and counts.', ['stock.view', 'stock.view_cost', 'stock.approve', 'lot.hold', 'report.view', 'data.export', 'audit.view']],
        'warehouse_manager' => ['Warehouse manager', 4, 'Runs a warehouse: receiving, moves, transfers, dispatch, counts and their approval.', ['stock.view', 'stock.view_cost', 'item.manage', 'supplier.manage', 'customer.manage', 'receive.post', 'stock.opening', 'inventory.move', 'lot.hold', 'transfer.manage', 'transfer.receive', 'dispatch.manage', 'adjust.request', 'count.manage', 'count.enter', 'stock.approve', 'report.view', 'data.export', 'warehouse.setup']],
        'storekeeper'       => ['Storekeeper', 2, 'Receives, puts away, picks, packs and counts.', ['stock.view', 'receive.post', 'inventory.move', 'transfer.receive', 'dispatch.manage', 'adjust.request', 'count.enter']],
        'buyer'             => ['Buyer', 3, 'Raises purchase orders and keeps suppliers and items.', ['stock.view', 'purchase.manage', 'supplier.manage', 'item.manage', 'report.view']],
        'sales_clerk'       => ['Sales and orders clerk', 2, 'Takes customer orders.', ['stock.view', 'sales.manage', 'customer.manage', 'report.view']],
        'production'        => ['Production supervisor', 3, 'Posts production runs and keeps recipes.', ['stock.view', 'production.manage', 'inventory.move', 'report.view']],
        'transport'         => ['Transport officer', 3, 'Keeps vehicles and drivers, plans and closes trips.', ['stock.view', 'fleet.manage', 'report.view']],
        'accountant'        => ['Accountant', 4, 'Sees costs and values, approves adjustments and counts.', ['stock.view', 'stock.view_cost', 'stock.approve', 'report.view', 'data.export', 'audit.view']],
        'auditor'           => ['Auditor (read-only)', 1, 'Sees everything, changes nothing.', ['stock.view', 'stock.view_cost', 'report.view', 'data.export', 'audit.view']],
        'system_admin'      => ['System administrator', 1, 'Users, roles, settings and warehouse layout. Cannot move or approve stock.', ['admin.users', 'admin.settings', 'warehouse.setup', 'audit.view']],
    ];

    public const SOD = [
        ['system_admin', 'director', 'An administrator who also approves can give themselves the rights they approve'],
        ['system_admin', 'accountant', 'An administrator who also approves can give themselves the rights they approve'],
        ['system_admin', 'warehouse_manager', 'An administrator who also runs a warehouse can change who checks their work'],
        ['storekeeper', 'accountant', 'The person who holds the stock should not approve its counts and adjustments'],
    ];

    public const REASONS = [
        'adjust'          => ['DAMAGE' => 'Damaged in the warehouse', 'EXPIRED' => 'Expired, destroyed', 'THEFT' => 'Theft or loss', 'FOUND' => 'Found stock', 'SAMPLE' => 'Samples and promotion', 'OWNUSE' => 'Own use', 'CORRECT' => 'Correction of an earlier error'],
        'supplier_return' => ['DAMAGED' => 'Arrived damaged', 'WRONG' => 'Wrong item or quantity', 'QUALITY' => 'Failed inspection', 'SHORTDATE' => 'Too close to expiry', 'RECALL' => 'Supplier recall'],
        'failed_delivery' => ['CLOSED' => 'Customer closed', 'REFUSED' => 'Customer refused', 'ADDRESS' => 'Address not found', 'VEHICLE' => 'Vehicle breakdown', 'TIME' => 'Ran out of time'],
        'customer_return' => ['DAMAGED' => 'Damaged on delivery', 'WRONG' => 'Wrong item delivered', 'EXCESS' => 'Customer over-ordered', 'QUALITY' => 'Quality complaint', 'EXPIRED' => 'Expired at customer'],
        'hold'            => ['QC' => 'Quality check', 'RECALL' => 'Recall', 'COMPLAINT' => 'Customer complaint'],
    ];

    public static function base(): void
    {
        foreach (self::PERMISSIONS as $code => $desc) {
            Db::exec('INSERT INTO {permissions} (code, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [$code, $desc]);
        }
        foreach (self::ROLES as $code => [$name, $rank, $desc, $perms]) {
            Db::exec('INSERT INTO {roles} (code, name, rank_level, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)', [$code, $name, $rank, $desc]);
            $rid = (int) Db::value('SELECT id FROM {roles} WHERE code = ?', [$code]);
            foreach ($perms as $p) {
                Db::exec('INSERT IGNORE INTO {role_permissions} (role_id, permission_code) VALUES (?, ?)', [$rid, $p]);
            }
        }
        foreach (self::SOD as [$a, $b, $d]) {
            $exists = Db::value('SELECT 1 FROM {sod_rules} s JOIN {roles} ra ON ra.id = s.role_a_id JOIN {roles} rb ON rb.id = s.role_b_id WHERE ra.code = ? AND rb.code = ?', [$a, $b]);
            if (! $exists) {
                Db::exec('INSERT INTO {sod_rules} (role_a_id, role_b_id, description) SELECT ra.id, rb.id, ? FROM {roles} ra, {roles} rb WHERE ra.code = ? AND rb.code = ?', [$d, $a, $b]);
            }
        }
        foreach (self::REASONS as $use => $list) {
            foreach ($list as $code => $label) {
                Db::exec('INSERT IGNORE INTO {reasons} (code, label, use_for) VALUES (?, ?, ?)', [$code, $label, $use]);
            }
        }
        if (! Db::value("SELECT value FROM {settings} WHERE name = 'cron_key'")) {
            Db::exec("INSERT INTO {settings} (name, value) VALUES ('cron_key', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [bin2hex(random_bytes(16))]);
        }
    }

    /** $roles: list of [role code, 'system'|'warehouse', warehouse id or null]. */
    public static function user(string $username, string $name, string $password, ?string $email, array $roles, ?string $position = null): int
    {
        $uid = Db::insert('users', [
            'username' => $username, 'name' => $name, 'email' => $email, 'position' => $position,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => Db::now(),
        ]);
        foreach ($roles as [$code, $scopeType, $scopeId]) {
            Db::exec('INSERT INTO {user_roles} (user_id, role_id, scope_type, scope_id) SELECT ?, id, ?, ? FROM {roles} WHERE code = ?', [$uid, $scopeType, $scopeId, $code]);
        }
        return $uid;
    }
}
