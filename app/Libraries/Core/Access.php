<?php

namespace App\Libraries\Core;

/**
 * Role-based access with scopes. A role is held for the whole company (system) or for one warehouse.
 * Every check runs on the server; the screens only hide what the user cannot do.
 */
final class Access
{
    /** @var array<int, array<string, list<array{type:string,id:?int}>>> */
    private static array $perms = [];

    public static function flush(): void
    {
        self::$perms = [];
    }

    /** permission code => list of scopes */
    public static function permissions(?int $userId = null): array
    {
        $userId ??= Auth::id();
        if (! $userId) {
            return [];
        }
        if (isset(self::$perms[$userId])) {
            return self::$perms[$userId];
        }
        $rows = Db::rows(
            'SELECT rp.permission_code, ur.scope_type, ur.scope_id
               FROM {user_roles} ur JOIN {role_permissions} rp ON rp.role_id = ur.role_id
              WHERE ur.user_id = ?',
            [$userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['permission_code']][] = ['type' => $r['scope_type'], 'id' => $r['scope_id'] === null ? null : (int) $r['scope_id']];
        }
        return self::$perms[$userId] = $out;
    }

    /** True when the user holds the permission anywhere (any warehouse or company-wide). */
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
            throw new DeniedException('You do not have permission to do this (' . $perm . ').');
        }
    }

    /** True when the permission is held company-wide. */
    public static function companyWide(string $perm, ?int $userId = null): bool
    {
        foreach (self::permissions($userId)[$perm] ?? [] as $s) {
            if ($s['type'] === 'system') {
                return true;
            }
        }
        return false;
    }

    /** Warehouse ids where the user holds $perm (company-wide = every warehouse). */
    public static function warehouses(string $perm, ?int $userId = null): array
    {
        $scopes = self::permissions($userId)[$perm] ?? [];
        if (! $scopes) {
            return [];
        }
        $out = [];
        foreach ($scopes as $s) {
            if ($s['type'] === 'system') {
                return array_map('intval', array_column(Db::rows('SELECT id FROM {warehouses} ORDER BY code'), 'id'));
            }
            $out[] = (int) $s['id'];
        }
        return array_values(array_unique($out));
    }

    public static function canAt(int $warehouseId, string $perm, ?int $userId = null): bool
    {
        return in_array($warehouseId, self::warehouses($perm, $userId), true);
    }

    public static function requireAt(int $warehouseId, string $perm): void
    {
        self::require($perm);
        if (! self::canAt($warehouseId, $perm)) {
            throw new DeniedException('This warehouse is outside your area (' . $perm . ').');
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

    public static function roles(int $userId): array
    {
        return Db::rows('SELECT ur.id, r.code, r.name, r.rank_level, ur.scope_type, ur.scope_id, w.code wh_code, w.name wh_name
                           FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id LEFT JOIN {warehouses} w ON w.id = ur.scope_id AND ur.scope_type = \'warehouse\'
                          WHERE ur.user_id = ? ORDER BY r.rank_level DESC, r.name', [$userId]);
    }

    public static function hasRole(int $userId, string $code): bool
    {
        return (bool) Db::value('SELECT 1 FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = ? LIMIT 1', [$userId, $code]);
    }

    /** Active users holding $perm at a warehouse (for notifications). */
    public static function usersWith(string $perm, ?int $warehouseId = null): array
    {
        $rows = Db::rows(
            "SELECT DISTINCT ur.user_id, ur.scope_type, ur.scope_id FROM {user_roles} ur JOIN {role_permissions} rp ON rp.role_id = ur.role_id
              JOIN {users} u ON u.id = ur.user_id AND u.active = 1 WHERE rp.permission_code = ?",
            [$perm]
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r['scope_type'] === 'system' || $warehouseId === null || (int) $r['scope_id'] === $warehouseId) {
                $out[] = (int) $r['user_id'];
            }
        }
        return array_values(array_unique($out));
    }

    /** Segregation-of-duties conflicts for a user's role set. */
    public static function sodConflicts(int $userId, array $extraRoleIds = []): array
    {
        $roleIds = array_map('intval', array_column(Db::rows('SELECT role_id FROM {user_roles} WHERE user_id = ?', [$userId]), 'role_id'));
        $roleIds = array_values(array_unique(array_merge($roleIds, $extraRoleIds)));
        if (count($roleIds) < 2) {
            return [];
        }
        $in = implode(',', array_fill(0, count($roleIds), '?'));
        return Db::rows("SELECT s.*, ra.name a_name, rb.name b_name FROM {sod_rules} s JOIN {roles} ra ON ra.id = s.role_a_id JOIN {roles} rb ON rb.id = s.role_b_id
                          WHERE s.role_a_id IN ($in) AND s.role_b_id IN ($in)", array_merge($roleIds, $roleIds));
    }
}
