<?php

namespace App\Libraries\Admin;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\NotFoundException;
use App\Libraries\Core\Rights;
use App\Libraries\Core\Tenancy;
use App\Libraries\Setup\Seed;

/**
 * The organisation's own roles (beta12). Every organisation starts with copies of the templates (Administrator,
 * Manager, Cashier...), which it may rename and change; it may add roles, copy one, and delete one nobody holds.
 * Nobody changes a role they hold themselves, so nobody can give themselves more through a role.
 */
final class Roles
{
    /** The organisation's roles with how many people hold each and what they may do. */
    public static function list(): array
    {
        $tid = Tenancy::require();
        Seed::tenantRoles($tid);
        $rows = Db::rows("SELECT r.*, (SELECT COUNT(*) FROM {user_roles} ur JOIN {tenant_users} tu ON tu.tenant_id = ur.tenant_id AND tu.user_id = ur.user_id AND tu.status = 'active'
                                         WHERE ur.tenant_id = r.tenant_id AND ur.role_id = r.id) members
                            FROM {roles} r WHERE r.tenant_id = ? AND r.level = 'tenant' ORDER BY r.rank_level DESC, r.name", [$tid]);
        foreach ($rows as &$r) {
            $r['perms'] = self::perms((int) $r['id']);
        }
        return $rows;
    }

    public static function get(int $id): array
    {
        $r = Db::row("SELECT * FROM {roles} WHERE tenant_id = ? AND id = ? AND level = 'tenant'", [Tenancy::require(), $id]);
        if (! $r) {
            throw new NotFoundException('Role not found.');
        }
        $r['perms']   = self::perms($id);
        $r['members'] = Db::rows("SELECT u.id, u.name, tu.status FROM {user_roles} ur JOIN {users} u ON u.id = ur.user_id
                                    JOIN {tenant_users} tu ON tu.tenant_id = ur.tenant_id AND tu.user_id = ur.user_id
                                   WHERE ur.tenant_id = ? AND ur.role_id = ? ORDER BY u.name", [Tenancy::require(), $id]);
        return $r;
    }

    public static function perms(int $id): array
    {
        return array_column(Db::rows('SELECT permission_code FROM {role_permissions} WHERE role_id = ? ORDER BY permission_code', [$id]), 'permission_code');
    }

    /** New role, or a change to one: name, description, perms (codes). $copyOf: start from another role. */
    public static function save(array $d, ?int $id = null): int
    {
        Access::require($id ? 'role.update' : 'role.create');
        $tid  = Tenancy::require();
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BizException('Give the role a name, for example "Shift supervisor".');
        }
        $perms = array_values(array_unique(array_map('strval', (array) ($d['perms'] ?? []))));
        foreach ($perms as $p) {
            if (! Rights::exists($p)) {
                throw new BizException('Unknown permission ' . $p . '.');
            }
        }
        sort($perms);
        return Db::atomic(static function () use ($tid, $name, $perms, $id, $d) {
            if (Db::value("SELECT 1 FROM {roles} WHERE tenant_id = ? AND name = ? AND id <> ?", [$tid, $name, $id ?? 0])) {
                throw new BizException('There is already a role called ' . $name . '.');
            }
            $desc = trim((string) ($d['description'] ?? '')) ?: null;
            if ($id) {
                $old = self::get($id);
                if (Db::value('SELECT 1 FROM {user_roles} WHERE tenant_id = ? AND user_id = ? AND role_id = ?', [$tid, Auth::id(), $id])) {
                    throw new DeniedException('You hold this role yourself, so you cannot change it. Ask another administrator.');
                }
                Db::exec('UPDATE {roles} SET name = ?, description = ? WHERE tenant_id = ? AND id = ?', [mb_substr($name, 0, 100), $desc, $tid, $id]);
                Db::exec('DELETE FROM {role_permissions} WHERE role_id = ?', [$id]);
                $before = ['name' => $old['name'], 'description' => $old['description'], 'perms' => $old['perms']];
            } else {
                // a copy keeps the template it came from, so the duties kept apart (sod_rules) still hold for it
                $tpl    = isset($d['template']) && is_string($d['template']) && $d['template'] !== '' ? $d['template'] : null;
                $id     = Db::insert('roles', ['tenant_id' => $tid, 'code' => 'r' . bin2hex(random_bytes(5)), 'name' => mb_substr($name, 0, 100), 'description' => $desc, 'level' => 'tenant', 'rank_level' => 2, 'template' => $tpl]);
                $before = null;
            }
            foreach ($perms as $p) {
                Db::exec('INSERT INTO {role_permissions} (role_id, permission_code) VALUES (?, ?)', [$id, $p]);
            }
            Audit::log($before ? 'role.update' : 'role.create', 'role', $id, $before, ['name' => $name, 'description' => $desc, 'perms' => $perms]);
            Access::flush();
            return $id;
        });
    }

    /** A new role with the permissions of another one. */
    public static function copy(int $id, string $name = ''): int
    {
        $r = self::get($id);
        $tpl = Db::value('SELECT template FROM {roles} WHERE tenant_id = ? AND id = ?', [Tenancy::require(), $id]);
        return self::save(['name' => trim($name) ?: 'Copy of ' . $r['name'], 'description' => $r['description'], 'perms' => $r['perms'], 'template' => $tpl]);
    }

    /** Delete a role nobody holds. */
    public static function delete(int $id): void
    {
        Access::require('role.delete');
        $r = self::get($id);
        if ($r['members']) {
            throw new BizException($r['name'] . ' is held by ' . count($r['members']) . ' person(s). Give them another role first.');
        }
        Db::atomic(static function () use ($id, $r) {
            Db::exec('DELETE FROM {role_permissions} WHERE role_id = ?', [$id]);
            Db::exec('DELETE FROM {user_roles} WHERE tenant_id = ? AND role_id = ?', [Tenancy::require(), $id]);
            Db::exec('DELETE FROM {roles} WHERE tenant_id = ? AND id = ?', [Tenancy::require(), $id]);
            Audit::log('role.delete', 'role', $id, ['name' => $r['name'], 'perms' => $r['perms']]);
        });
    }
}
