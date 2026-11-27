<?php

namespace App\Libraries\Setup;

use App\Libraries\Core\Db;
use App\Libraries\Core\Rights;
use App\Libraries\Core\Tenancy;

/**
 * Reference data: permissions, duty roles, segregation-of-duties rules, reason codes.
 * Additive: running it again on an upgraded installation adds what is new and keeps local changes.
 *
 * core 1.1 (0.3.0): the organisation's permissions are in Core\Rights; the roles here are templates (tenant_id NULL)
 * that every organisation copies to change as it likes; reason codes belong to each organisation.
 */
final class Seed
{
    /** Platform permissions (the SaaS operator only); the organisation's permissions are in Core\Rights. */
    public const PLATFORM = [
        'platform.tenants'  => 'See and manage organisations on the platform (no access to their stock)',
        'platform.settings' => 'Keep platform settings, the scheduled job and the integrity check',
        'platform.audit'    => 'See the platform audit log',
        'platform.support'  => 'Sign in as an organisation\'s account owner, only while that organisation allows it',
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
        'system_admin'      => ['System administrator', 1, 'Users, roles, settings and warehouse layout. Cannot move or approve stock.', ['user.signin_as', 'admin.users', 'admin.settings', 'warehouse.setup', 'audit.view']],
        'platform_owner'    => ['Platform owner', 9, 'Runs the online service: organisations, plans, platform settings. Sees no organisation\'s stock.', ['platform.tenants', 'platform.settings', 'platform.audit', 'platform.support']],
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
        // 0.3.0 needs roles.tenant_id; on a fresh install the update to 0.3.0 runs first and then seeds
        if (! Db::value("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'tenant_id'", [Db::t('roles')])) {
            return;
        }
        $perms = self::PLATFORM;
        foreach (Rights::all() as $code => [, , , $desc]) {
            $perms[$code] = $desc;
        }
        foreach ($perms as $code => $desc) {
            Db::exec('INSERT INTO {permissions} (code, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [$code, $desc]);
        }
        foreach (self::ROLES as $code => [$name, $rank, $desc, $list]) {
            $level = $code === 'platform_owner' ? 'platform' : 'tenant';
            Db::exec('INSERT INTO {roles} (tenant_id, code, name, level, rank_level, description) VALUES (NULL, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), level = VALUES(level)',
                [$code, $name, $level, $rank, $desc]);
            $rid = (int) Db::value('SELECT id FROM {roles} WHERE tenant_id IS NULL AND code = ?', [$code]);
            foreach ($level === 'platform' ? $list : Rights::upgrade($list) as $p) {
                Db::exec('INSERT IGNORE INTO {role_permissions} (role_id, permission_code) VALUES (?, ?)', [$rid, $p]);
            }
        }
        foreach (self::SOD as [$a, $b, $d]) {
            $exists = Db::value('SELECT 1 FROM {sod_rules} s JOIN {roles} ra ON ra.id = s.role_a_id JOIN {roles} rb ON rb.id = s.role_b_id WHERE ra.tenant_id IS NULL AND rb.tenant_id IS NULL AND ra.code = ? AND rb.code = ?', [$a, $b]);
            if (! $exists) {
                Db::exec('INSERT INTO {sod_rules} (role_a_id, role_b_id, description) SELECT ra.id, rb.id, ? FROM {roles} ra, {roles} rb WHERE ra.tenant_id IS NULL AND rb.tenant_id IS NULL AND ra.code = ? AND rb.code = ?', [$d, $a, $b]);
            }
        }
        if (! Db::value("SELECT value FROM {settings} WHERE name = 'cron_key'")) {
            Db::exec("INSERT INTO {settings} (name, value) VALUES ('cron_key', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [bin2hex(random_bytes(16))]);
        }
    }

    /** What a new organisation starts with: its own copies of the roles and the reason codes. */
    public static function tenantStart(int $tenantId): void
    {
        self::tenantRoles($tenantId);
        foreach (self::REASONS as $use => $list) {
            foreach ($list as $code => $label) {
                Db::exec('INSERT IGNORE INTO {reasons} (tenant_id, code, label, use_for) VALUES (?, ?, ?, ?)', [$tenantId, $code, $label, $use]);
            }
        }
    }

    /** The organisation's own copies of the templates, made once; returns code => role id. */
    public static function tenantRoles(int $tenantId): array
    {
        foreach (Db::rows("SELECT * FROM {roles} WHERE tenant_id IS NULL AND level = 'tenant' ORDER BY id") as $tpl) {
            if (Db::value('SELECT 1 FROM {roles} WHERE tenant_id = ? AND code = ?', [$tenantId, $tpl['code']])) {
                continue;
            }
            $id = Db::insert('roles', ['tenant_id' => $tenantId, 'code' => $tpl['code'], 'name' => $tpl['name'], 'description' => $tpl['description'], 'level' => 'tenant',
                'rank_level' => $tpl['rank_level'], 'template' => $tpl['code']]);
            Db::exec('INSERT INTO {role_permissions} (role_id, permission_code) SELECT ?, permission_code FROM {role_permissions} WHERE role_id = ?', [$id, $tpl['id']]);
        }
        $out = [];
        foreach (Db::rows('SELECT id, code FROM {roles} WHERE tenant_id = ?', [$tenantId]) as $r) {
            $out[$r['code']] = (int) $r['id'];
        }
        return $out;
    }

    /** A login with no memberships yet (the platform owner, or before membership is added). */
    public static function login(string $username, string $name, string $password, ?string $email = null, ?string $position = null): int
    {
        return Db::insert('users', [
            'username' => strtolower($username), 'name' => $name, 'email' => $email ?: null, 'position' => $position,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => Db::now(),
        ]);
    }

    /**
     * Make $userId a member of $tenantId and give roles: list of [role code, warehouse id or null]. A role with no
     * warehouse gives every warehouse (the person's warehouses, not the role's); the account owner always has all.
     */
    public static function member(int $tenantId, int $userId, array $roles, bool $owner = false): void
    {
        Db::exec('INSERT IGNORE INTO {tenant_users} (tenant_id, user_id, status, is_account_owner, created_at) VALUES (?, ?, \'active\', ?, ?)', [$tenantId, $userId, $owner ? 1 : 0, Db::now()]);
        $ids = self::tenantRoles($tenantId);
        foreach ($roles as [$code, $warehouseId]) {
            if (! isset($ids[$code])) {
                continue;
            }
            Db::exec("INSERT IGNORE INTO {user_roles} (tenant_id, user_id, role_id, scope_type, scope_id) VALUES (?, ?, ?, 'tenant', NULL)", [$tenantId, $userId, $ids[$code]]);
            if ($warehouseId) {
                Db::exec('INSERT IGNORE INTO {member_warehouses} (tenant_id, user_id, warehouse_id) VALUES (?, ?, ?)', [$tenantId, $userId, $warehouseId]);
            } else {
                Db::exec('UPDATE {tenant_users} SET all_warehouses = 1 WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
            }
        }
        if ($owner) {
            Db::exec('UPDATE {tenant_users} SET all_warehouses = 1 WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
        }
    }

    public static function platformRole(int $userId, string $code = 'platform_owner'): void
    {
        Db::exec("INSERT IGNORE INTO {user_roles} (tenant_id, user_id, role_id, scope_type, scope_id) SELECT NULL, ?, id, 'platform', NULL FROM {roles} WHERE tenant_id IS NULL AND code = ? AND level = 'platform'", [$userId, $code]);
    }

    /**
     * A login that is a member of the current organisation (installer, demo, tests). $roles: list of
     * [role code, 'system'|'warehouse', warehouse id or null], in the words of 0.2.
     */
    public static function user(string $username, string $name, string $password, ?string $email, array $roles, ?string $position = null, bool $owner = false): int
    {
        $uid = self::login($username, $name, $password, $email, $position);
        self::member(Tenancy::require(), $uid, array_map(static fn ($r) => [$r[0], $r[1] === 'warehouse' ? $r[2] : null], $roles), $owner);
        return $uid;
    }
}
