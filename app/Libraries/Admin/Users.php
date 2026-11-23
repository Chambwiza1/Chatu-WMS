<?php

namespace App\Libraries\Admin;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Settings;

/** User accounts and their roles (company-wide or for one warehouse), with segregation-of-duties checks. */
final class Users
{
    public static function create(array $d): int
    {
        Access::require('admin.users');
        return Db::atomic(static function () use ($d) {
            $username = strtolower(trim((string) ($d['username'] ?? '')));
            if (! preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                throw new BizException('Username: 3 to 60 letters, digits, dot, dash or underscore.');
            }
            if (Db::value('SELECT 1 FROM {users} WHERE username = ?', [$username])) {
                throw new BizException('That username is taken.');
            }
            if ($err = Auth::validatePassword((string) ($d['password'] ?? ''))) {
                throw new BizException($err);
            }
            $name = trim((string) ($d['name'] ?? ''));
            if ($name === '') {
                throw new BizException('Name is required.');
            }
            $email = trim((string) ($d['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BizException('That e-mail address is not valid.');
            }
            $id = Db::insert('users', [
                'username' => $username, 'name' => mb_substr($name, 0, 150), 'email' => $email ?: null, 'phone' => trim((string) ($d['phone'] ?? '')) ?: null,
                'position' => trim((string) ($d['position'] ?? '')) ?: null, 'password_hash' => password_hash((string) $d['password'], PASSWORD_DEFAULT),
                'active' => 1, 'created_at' => Db::now(),
            ]);
            Audit::log('user.create', 'user', $id, null, ['username' => $username, 'name' => $name]);
            return $id;
        });
    }

    public static function update(int $userId, array $d): void
    {
        Access::require('admin.users');
        Db::atomic(static function () use ($userId, $d) {
            $u = Db::row('SELECT * FROM {users} WHERE id = ? FOR UPDATE', [$userId]);
            if (! $u) {
                throw new BizException('Unknown user.');
            }
            $email = trim((string) ($d['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BizException('That e-mail address is not valid.');
            }
            $row = ['name' => trim((string) ($d['name'] ?? $u['name'])) ?: $u['name'], 'email' => $email ?: null, 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'position' => trim((string) ($d['position'] ?? '')) ?: null];
            Db::exec('UPDATE {users} SET name = ?, email = ?, phone = ?, position = ? WHERE id = ?', array_merge(array_values($row), [$userId]));
            Audit::log('user.update', 'user', $userId, array_intersect_key($u, $row), $row);
        });
    }

    public static function setPassword(int $userId, string $password, bool $self = false): void
    {
        if (! $self) {
            Access::require('admin.users');
        }
        if ($err = Auth::validatePassword($password)) {
            throw new BizException($err);
        }
        Db::atomic(static function () use ($userId, $password, $self) {
            Db::exec('UPDATE {users} SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            Audit::log($self ? 'user.password_own' : 'user.password_reset', 'user', $userId);
        });
    }

    public static function setActive(int $userId, bool $active): void
    {
        Access::require('admin.users');
        if ($userId === Auth::id() && ! $active) {
            throw new BizException('You cannot switch off your own account.');
        }
        Db::atomic(static function () use ($userId, $active) {
            Db::exec('UPDATE {users} SET active = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [$active ? 1 : 0, $userId]);
            Audit::log($active ? 'user.activate' : 'user.deactivate', 'user', $userId);
        });
    }

    /** Give a role for the whole company or one warehouse. Nobody grants roles to themselves. */
    public static function grant(int $userId, string $roleCode, string $scopeType, ?int $scopeId): void
    {
        Access::require('admin.users');
        Db::atomic(static function () use ($userId, $roleCode, $scopeType, $scopeId) {
            $role = Db::row('SELECT * FROM {roles} WHERE code = ?', [$roleCode]);
            if (! $role) {
                throw new BizException('Unknown role.');
            }
            if (! in_array($scopeType, ['system', 'warehouse'], true)) {
                throw new BizException('Choose company-wide or one warehouse.');
            }
            if ($scopeType === 'warehouse' && ! Db::value('SELECT 1 FROM {warehouses} WHERE id = ?', [(int) $scopeId])) {
                throw new BizException('Choose the warehouse this role applies to.');
            }
            if ($scopeType === 'system') {
                $scopeId = null;
            }
            if ($userId === Auth::id()) {
                throw new DeniedException('You cannot give roles to yourself.');
            }
            if (Settings::get('sod_mode') === 'enforce') {
                foreach (Access::sodConflicts($userId, [(int) $role['id']]) as $c) {
                    throw new BizException('Segregation of duties: ' . $c['description'] . ' (' . $c['a_name'] . ' and ' . $c['b_name'] . '). Give the role to someone else.');
                }
            }
            Db::exec('INSERT IGNORE INTO {user_roles} (user_id, role_id, scope_type, scope_id) VALUES (?, ?, ?, ?)', [$userId, $role['id'], $scopeType, $scopeId]);
            Audit::log('role.grant', 'user', $userId, null, ['role' => $roleCode, 'scope' => $scopeType, 'scope_id' => $scopeId]);
        });
        Access::flush();
    }

    public static function revoke(int $userRoleId): void
    {
        Access::require('admin.users');
        Db::atomic(static function () use ($userRoleId) {
            $ur = Db::row('SELECT ur.*, r.code FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.id = ?', [$userRoleId]);
            if (! $ur) {
                throw new BizException('Not found.');
            }
            if ((int) $ur['user_id'] === Auth::id() && $ur['code'] === 'system_admin') {
                throw new BizException('You cannot remove your own administrator role.');
            }
            Db::exec('DELETE FROM {user_roles} WHERE id = ?', [$userRoleId]);
            Audit::log('role.revoke', 'user', $ur['user_id'], ['role' => $ur['code'], 'scope' => $ur['scope_type'], 'scope_id' => $ur['scope_id']]);
        });
        Access::flush();
    }

    public static function list(): array
    {
        return Db::rows("SELECT u.*, (SELECT GROUP_CONCAT(CONCAT(r.name, IF(ur.scope_type = 'warehouse', CONCAT(' (', w.code, ')'), '')) ORDER BY r.rank_level DESC SEPARATOR ', ')
                                       FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id LEFT JOIN {warehouses} w ON w.id = ur.scope_id AND ur.scope_type = 'warehouse' WHERE ur.user_id = u.id) roles
                           FROM {users} u ORDER BY u.active DESC, u.name");
    }
}
