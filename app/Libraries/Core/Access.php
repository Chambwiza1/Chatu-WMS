<?php

namespace App\Libraries\Core;

/**
 * core 1.1, as ChatuPOS 2.0 (beta12). Inside an organisation a person holds roles of that organisation and permissions
 * given to them directly; what they may do is the union of both, at the warehouses they are given (all of them, new
 * ones included, or a list). The account owner may do everything everywhere. Platform roles (the SaaS operator) are
 * held outside any organisation and give no access to its data. Every check runs on the server; the screens only
 * hide what the user cannot do.
 */
final class Access
{
    /** @var array<string, array<string, list<array{type:string,id:?int}>>> */
    private static array $perms = [];

    public static function flush(): void
    {
        self::$perms = [];
    }

    /** permission code => list of scopes, for the current tenant (plus platform roles). */
    public static function permissions(?int $userId = null): array
    {
        $userId ??= Auth::id();
        if (! $userId) {
            return [];
        }
        $tid = Tenancy::id();
        $key = $userId . ':' . ($tid ?? 0);
        if (isset(self::$perms[$key])) {
            return self::$perms[$key];
        }
        $out = [];
        foreach (Db::rows("SELECT rp.permission_code FROM {user_roles} ur JOIN {role_permissions} rp ON rp.role_id = ur.role_id
                            WHERE ur.user_id = ? AND ur.tenant_id IS NULL AND ur.scope_type = 'platform'", [$userId]) as $r) {
            $out[$r['permission_code']][] = ['type' => 'platform', 'id' => null];
        }
        // the organisation counts only while the membership is active
        $m = $tid ? Db::row("SELECT is_account_owner, all_warehouses FROM {tenant_users} WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [$tid, $userId]) : null;
        if ($m) {
            if ((int) $m['is_account_owner']) {
                $codes = array_keys(Rights::all());
            } else {
                $codes = array_column(Db::rows('SELECT rp.permission_code FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id AND r.tenant_id = ur.tenant_id
                                                  JOIN {role_permissions} rp ON rp.role_id = r.id WHERE ur.tenant_id = ? AND ur.user_id = ?
                                                 UNION SELECT permission_code FROM {member_permissions} WHERE tenant_id = ? AND user_id = ?', [$tid, $userId, $tid, $userId]), 'permission_code');
            }
            if ((int) $m['is_account_owner'] || (int) $m['all_warehouses']) {
                $scopes = [['type' => 'tenant', 'id' => null]];
            } else {
                $scopes = array_map(static fn ($r) => ['type' => 'warehouse', 'id' => (int) $r['warehouse_id']],
                    Db::rows('SELECT warehouse_id FROM {member_warehouses} WHERE tenant_id = ? AND user_id = ? ORDER BY warehouse_id', [$tid, $userId]));
                // no warehouse yet: the organisation's own pages still open (items, people); nothing at a warehouse
                $scopes = $scopes ?: [['type' => 'none', 'id' => null]];
            }
            foreach ($codes as $c) {
                $out[$c] = array_merge($out[$c] ?? [], $scopes);
            }
        }
        return self::$perms[$key] = $out;
    }

    /** Select on a module (item, customer, supplier, user): its Select, or any of its View, Create, Edit, Delete. */
    public static function canSelect(string $module, ?int $userId = null): bool
    {
        foreach (['select', 'view', 'create', 'update', 'delete'] as $k) {
            if (self::can($module . '.' . $k, $userId)) {
                return true;
            }
        }
        return false;
    }

    /** Saving a record of a module: Create when it is new, Edit when not, and Delete as well when it is being switched off. */
    public static function requireSave(string $module, bool $isNew, bool $switchingOff = false): void
    {
        self::require($module . '.' . ($isNew ? 'create' : 'update'));
        if ($switchingOff) {
            self::require($module . '.delete');
        }
    }

    /** The warehouses a member is given: ['all' => bool, 'ids' => list]. */
    public static function memberWarehouses(int $userId): array
    {
        $tid = Tenancy::require();
        $m   = Db::row('SELECT is_account_owner, all_warehouses FROM {tenant_users} WHERE tenant_id = ? AND user_id = ?', [$tid, $userId]);
        return ['all' => $m && ((int) $m['all_warehouses'] || (int) $m['is_account_owner']),
            'ids' => array_map('intval', array_column(Db::rows('SELECT warehouse_id FROM {member_warehouses} WHERE tenant_id = ? AND user_id = ?', [$tid, $userId]), 'warehouse_id'))];
    }

    /** True when the user holds the permission anywhere in the current tenant (or on the platform). */
    public static function can(string $perm, ?int $userId = null): bool
    {
        return isset(self::permissions($userId)[$perm]);
    }

    public static function require(string $perm): void
    {
        if (! Auth::id()) {
            throw new DeniedException('Please sign in.');
        }
        if (! self::can($perm)) {
            $l = Rights::label($perm);
            throw new DeniedException('You do not have permission to do this' . ($l ? ': ' . $l : '') . ' (' . $perm . ').');
        }
    }

    /** True when the permission is held for the whole organisation (every warehouse). */
    public static function companyWide(string $perm, ?int $userId = null): bool
    {
        return self::tenantWide($perm, $userId);
    }

    /** True when the permission is held for the whole tenant. */
    public static function tenantWide(string $perm, ?int $userId = null): bool
    {
        foreach (self::permissions($userId)[$perm] ?? [] as $s) {
            if ($s['type'] === 'tenant') {
                return true;
            }
        }
        return false;
    }

    /** True when the permission is held as a platform role. */
    public static function platform(string $perm, ?int $userId = null): bool
    {
        foreach (self::permissions($userId)[$perm] ?? [] as $s) {
            if ($s['type'] === 'platform') {
                return true;
            }
        }
        return false;
    }

    public static function requirePlatform(string $perm): void
    {
        if (! Auth::id() || ! self::platform($perm)) {
            throw new DeniedException('This is for the platform owner only (' . $perm . ').');
        }
    }

    /** Warehouse ids of the current organisation where the user holds $perm (organisation-wide = all of them). */
    public static function warehouses(string $perm, ?int $userId = null): array
    {
        $tid    = Tenancy::id();
        $scopes = self::permissions($userId)[$perm] ?? [];
        if (! $scopes || $tid === null) {
            return [];
        }
        $out = [];
        foreach ($scopes as $s) {
            if ($s['type'] === 'tenant') {
                return array_map('intval', array_column(Db::rows('SELECT id FROM {warehouses} WHERE tenant_id = ? ORDER BY code', [$tid]), 'id'));
            }
            if ($s['type'] === 'warehouse') {
                $out[] = (int) $s['id'];
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    public static function canAt(int $warehouseId, string $perm, ?int $userId = null): bool
    {
        return in_array($warehouseId, self::warehouses($perm, $userId), true);
    }

    public static function requireAt(int $warehouseId, string $perm): void
    {
        self::require($perm);
        if (! self::canAt($warehouseId, $perm)) {
            throw new DeniedException('This warehouse is not one of yours. Ask your administrator to give it to you (' . $perm . ').');
        }
    }

    /** SQL condition "column IN (...)" for the user's warehouses with $perm. */
    public static function whereWarehouse(string $perm, string $column): array
    {
        $ids = self::warehouses($perm);
        if (! $ids) {
            return ['1=0', []];
        }
        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** The user's roles in the current organisation and on the platform. */
    public static function roles(int $userId): array
    {
        return Db::rows("SELECT ur.id, r.id role_id, r.code, r.name, r.rank_level, r.level, ur.scope_type
                           FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
                          WHERE ur.user_id = ? AND ((ur.tenant_id = ? AND r.tenant_id = ur.tenant_id) OR (ur.tenant_id IS NULL AND ur.scope_type = 'platform'))
                          ORDER BY r.level = 'platform' DESC, r.rank_level DESC, r.name", [$userId, Tenancy::id() ?? 0]);
    }

    public static function hasRole(int $userId, string $code): bool
    {
        return (bool) Db::value("SELECT 1 FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = ?
                                   AND ((ur.tenant_id = ? AND r.tenant_id = ur.tenant_id) OR (ur.tenant_id IS NULL AND ur.scope_type = 'platform')) LIMIT 1",
            [$userId, $code, Tenancy::id() ?? 0]);
    }

    /** Active members of the current organisation who may use $perm at a warehouse (or anywhere), for notifications and approvals. */
    public static function usersWith(string $perm, ?int $warehouseId = null, bool $owners = true): array
    {
        $tid  = Tenancy::require();
        $rows = Db::rows(
            "SELECT tu.user_id FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id AND u.active = 1
              WHERE tu.tenant_id = ? AND tu.status = 'active'
                AND ((tu.is_account_owner = 1 AND ? = 1)
                     OR EXISTS (SELECT 1 FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id AND r.tenant_id = ur.tenant_id JOIN {role_permissions} rp ON rp.role_id = r.id
                                 WHERE ur.tenant_id = tu.tenant_id AND ur.user_id = tu.user_id AND rp.permission_code = ?)
                     OR EXISTS (SELECT 1 FROM {member_permissions} mp WHERE mp.tenant_id = tu.tenant_id AND mp.user_id = tu.user_id AND mp.permission_code = ?))
                AND (? = 0 OR tu.is_account_owner = 1 OR tu.all_warehouses = 1
                     OR EXISTS (SELECT 1 FROM {member_warehouses} mw WHERE mw.tenant_id = tu.tenant_id AND mw.user_id = tu.user_id AND mw.warehouse_id = ?))
              ORDER BY tu.user_id",
            [$tid, $owners ? 1 : 0, $perm, $perm, $warehouseId ?? 0, $warehouseId ?? 0]
        );
        return array_map('intval', array_column($rows, 'user_id'));
    }

    /**
     * Segregation-of-duties conflicts for a person's roles in the current organisation (plus $extraRoleIds): the rules
     * are between templates, and an organisation's role carries the template it was copied from.
     */
    public static function sodConflicts(int $userId, array $extraRoleIds = [], ?array $onlyRoleIds = null): array
    {
        $tid     = Tenancy::id() ?? 0;
        $roleIds = $onlyRoleIds ?? array_map('intval', array_column(Db::rows('SELECT role_id FROM {user_roles} WHERE user_id = ? AND tenant_id = ?', [$userId, $tid]), 'role_id'));
        $roleIds = array_values(array_unique(array_merge($roleIds, array_map('intval', $extraRoleIds))));
        if (count($roleIds) < 2) {
            return [];
        }
        $in    = implode(',', array_fill(0, count($roleIds), '?'));
        $tpls  = [];
        foreach (Db::rows("SELECT id, name, template FROM {roles} WHERE tenant_id = ? AND id IN ($in) AND template IS NOT NULL", array_merge([$tid], $roleIds)) as $r) {
            $tpls[$r['template']] = $r['name'];
        }
        $out = [];
        foreach (Db::rows('SELECT s.description, ra.code a, rb.code b FROM {sod_rules} s JOIN {roles} ra ON ra.id = s.role_a_id JOIN {roles} rb ON rb.id = s.role_b_id') as $s) {
            if (isset($tpls[$s['a']], $tpls[$s['b']])) {
                $out[] = ['description' => $s['description'], 'a_name' => $tpls[$s['a']], 'b_name' => $tpls[$s['b']]];
            }
        }
        return $out;
    }
}
