<?php

namespace App\Libraries\Admin;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\NotFoundException;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * People in an organisation and their access (core 1.1, as ChatuPOS beta12): roles of the organisation, permissions
 * given directly, and the warehouses they work at (all, new ones included, or a list), with segregation-of-duties checks.
 * Nobody changes their own access, the account owner cannot be switched off, and the last person who can change
 * people's access keeps it, so an organisation never locks itself out. A person has one login; an Admin can change the login's details only when the person belongs to their
 * organisation alone, because the same login may be used in another organisation.
 */
final class Users
{
    /** A member of the current tenant, with their membership. */
    public static function get(int $userId): array
    {
        $u = Db::row('SELECT u.*, tu.status member_status, tu.is_account_owner, tu.department FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id
                        WHERE tu.tenant_id = ? AND tu.user_id = ?', [Tenancy::require(), $userId]);
        if (! $u) {
            throw new NotFoundException('User not found.');
        }
        unset($u['password_hash']);
        return $u;
    }

    /** True when the login is a member of any other organisation too. */
    public static function shared(int $userId): bool
    {
        return (bool) Db::unscoped(static fn () => Db::value('SELECT 1 FROM {tenant_users} WHERE user_id = ? AND tenant_id <> ? LIMIT 1', [$userId, Tenancy::require()]))
            || (bool) Db::value("SELECT 1 FROM {user_roles} WHERE user_id = ? AND tenant_id IS NULL AND scope_type = 'platform' LIMIT 1", [$userId]);
    }

    /** New person with a new login, made a member of the current organisation. */
    public static function create(array $d): int
    {
        Access::require('user.create');
        $tid = Tenancy::require();
        return Db::atomic(static function () use ($d, $tid) {
            $username = strtolower(trim((string) ($d['username'] ?? '')));
            if (! preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                throw new BizException('Username: 3 to 60 letters, digits, dot, dash or underscore.');
            }
            if (Db::value('SELECT 1 FROM {users} WHERE username = ?', [$username])) {
                throw new BizException('That username is taken. Choose another one.');
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
            if ($email !== '' && Db::value('SELECT 1 FROM {users} WHERE email = ?', [$email])) {
                throw new BizException('Someone already signs in with that e-mail address. Add them as an existing person instead.');
            }
            $id = Db::insert('users', [
                'username' => $username, 'name' => mb_substr($name, 0, 150), 'email' => $email ?: null, 'phone' => trim((string) ($d['phone'] ?? '')) ?: null,
                'position' => trim((string) ($d['position'] ?? '')) ?: null, 'password_hash' => password_hash((string) $d['password'], PASSWORD_DEFAULT),
                'active' => 1, 'created_at' => Db::now(),
            ]);
            \App\Libraries\Platform\Billing::checkLimit('users');   // core 1.5: the plan's limit
            Db::insert('tenant_users', ['tenant_id' => $tid, 'user_id' => $id, 'status' => 'active', 'department' => trim((string) ($d['department'] ?? '')) ?: null, 'created_at' => Db::now()]);
            Audit::log('user.create', 'user', $id, null, ['username' => $username, 'name' => $name]);
            return $id;
        });
    }

    /** Add someone who already has a login (from another organisation) by username or e-mail. */
    public static function addExisting(string $login): int
    {
        Access::require('user.create');
        $tid   = Tenancy::require();
        $login = trim($login);
        return Db::atomic(static function () use ($tid, $login) {
            $u = Db::row('SELECT id, active FROM {users} WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1', [strtolower($login), $login]);
            if (! $u || ! $u['active']) {
                throw new BizException('Nobody signs in with that username or e-mail address.');
            }
            if (Db::value('SELECT 1 FROM {tenant_users} WHERE tenant_id = ? AND user_id = ?', [$tid, $u['id']])) {
                throw new BizException('That person is already in this organisation.');
            }
            \App\Libraries\Platform\Billing::checkLimit('users');   // core 1.5: the plan's limit
            Db::insert('tenant_users', ['tenant_id' => $tid, 'user_id' => $u['id'], 'status' => 'active', 'created_at' => Db::now()]);
            Audit::log('user.add_existing', 'user', $u['id']);
            return (int) $u['id'];
        });
    }

    public static function update(int $userId, array $d): void
    {
        Access::require('user.update');
        $u = self::get($userId);
        Db::atomic(static function () use ($userId, $u, $d) {
            $dept = trim((string) ($d['department'] ?? '')) ?: null;
            Db::exec('UPDATE {tenant_users} SET department = ? WHERE tenant_id = ? AND user_id = ?', [$dept, Tenancy::require(), $userId]);
            if (self::shared($userId)) {
                Audit::log('user.update', 'user', $userId, ['department' => $u['department']], ['department' => $dept]);
                return; // the login's own details belong to the person, who keeps them under My account
            }
            $email = trim((string) ($d['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BizException('That e-mail address is not valid.');
            }
            if ($email !== '' && Db::value('SELECT 1 FROM {users} WHERE email = ? AND id <> ?', [$email, $userId])) {
                throw new BizException('Someone else signs in with that e-mail address.');
            }
            $row = ['name' => trim((string) ($d['name'] ?? $u['name'])) ?: $u['name'], 'email' => $email ?: null, 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'position' => trim((string) ($d['position'] ?? '')) ?: null];
            Db::exec('UPDATE {users} SET name = ?, email = ?, phone = ?, position = ? WHERE id = ?', array_merge(array_values($row), [$userId]));
            Audit::log('user.update', 'user', $userId, array_intersect_key($u, $row) + ['department' => $u['department']], $row + ['department' => $dept]);
        });
    }

    public static function setPassword(int $userId, string $password, bool $self = false): void
    {
        if (! $self) {
            Access::require('user.update');
            self::get($userId);
            if (self::shared($userId)) {
                throw new BizException('This person also signs in to another organisation, so only they can change their password (under My account).');
            }
        }
        if ($err = Auth::validatePassword($password)) {
            throw new BizException($err);
        }
        Db::atomic(static function () use ($userId, $password, $self) {
            Db::exec('UPDATE {users} SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            Audit::log($self ? 'user.password_own' : 'user.password_reset', 'user', $userId);
        });
    }

    /** Switch someone's membership of this organisation on or off (their login stays for other organisations). */
    public static function setActive(int $userId, bool $active): void
    {
        Access::require($active ? 'user.update' : 'user.delete');
        $u = self::get($userId);
        if ($userId === Auth::id() && ! $active) {
            throw new BizException('You cannot switch off your own account.');
        }
        if (! $active && $u['is_account_owner']) {
            throw new BizException('The account owner cannot be switched off. Make someone else the account owner first.');
        }
        Db::atomic(static function () use ($userId, $active, $u) {
            if ($active && ($u['member_status'] ?? '') !== 'active') {
                \App\Libraries\Platform\Billing::checkLimit('users');   // core 1.5
            }
            Db::exec('UPDATE {tenant_users} SET status = ? WHERE tenant_id = ? AND user_id = ?', [$active ? 'active' : 'off', Tenancy::require(), $userId]);
            if (! self::shared($userId)) {
                Db::exec('UPDATE {users} SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$userId]);
            }
            Access::flush();
            if (! $active) {
                self::keepAdmin();
            }
            Audit::log($active ? 'user.activate' : 'user.deactivate', 'user', $userId);
        });
        Access::flush();
    }

    /**
     * A person's access, all at once: $roles (role ids of this organisation), $allWarehouses (every warehouse, new ones
     * included) or $warehouses (ids), $perms (codes given directly, on top of the roles). Nobody changes their
     * own access; the account owner may always do everything everywhere.
     */
    public static function setAccess(int $userId, array $roles, bool $allWarehouses, array $warehouses, array $perms): void
    {
        Access::require('user.update');
        $u   = self::get($userId);
        $tid = Tenancy::require();
        if ($userId === Auth::id()) {
            throw new DeniedException('You cannot change your own access. Ask another administrator.');
        }
        $roles = array_values(array_unique(array_map('intval', $roles)));
        foreach ($roles as $r) {
            if (! Db::value("SELECT 1 FROM {roles} WHERE tenant_id = ? AND id = ? AND level = 'tenant'", [$tid, $r])) {
                throw new NotFoundException('Role not found.');
            }
        }
        $warehouses = array_values(array_unique(array_map('intval', $warehouses)));
        foreach ($warehouses as $w) {
            \App\Libraries\Master\Warehouses::get($w);
        }
        $perms = array_values(array_unique(array_map('strval', $perms)));
        foreach ($perms as $p) {
            if (! \App\Libraries\Core\Rights::exists($p)) {
                throw new BizException('Unknown permission ' . $p . '.');
            }
        }
        sort($perms);
        sort($warehouses);
        if (Settings::get('sod_mode') === 'enforce') {
            foreach (Access::sodConflicts($userId, [], $roles) as $c) {
                throw new BizException('Segregation of duties: ' . $c['description'] . ' (' . $c['a_name'] . ' and ' . $c['b_name'] . '). Give one of the roles to someone else.');
            }
        }
        Db::atomic(static function () use ($userId, $u, $tid, $roles, $allWarehouses, $warehouses, $perms) {
            $old = self::access($userId);
            Db::exec('DELETE FROM {user_roles} WHERE tenant_id = ? AND user_id = ?', [$tid, $userId]);
            foreach ($roles as $r) {
                Db::exec("INSERT INTO {user_roles} (tenant_id, user_id, role_id, scope_type, scope_id) VALUES (?, ?, ?, 'tenant', NULL)", [$tid, $userId, $r]);
            }
            Db::exec('UPDATE {tenant_users} SET all_warehouses = ? WHERE tenant_id = ? AND user_id = ?', [$allWarehouses || $u['is_account_owner'] ? 1 : 0, $tid, $userId]);
            Db::exec('DELETE FROM {member_warehouses} WHERE tenant_id = ? AND user_id = ?', [$tid, $userId]);
            foreach ($allWarehouses ? [] : $warehouses as $w) {
                Db::insert('member_warehouses', ['tenant_id' => $tid, 'user_id' => $userId, 'warehouse_id' => $w]);
            }
            Db::exec('DELETE FROM {member_permissions} WHERE tenant_id = ? AND user_id = ?', [$tid, $userId]);
            foreach ($perms as $p) {
                Db::insert('member_permissions', ['tenant_id' => $tid, 'user_id' => $userId, 'permission_code' => $p]);
            }
            $new = self::access($userId);
            Access::flush();
            self::keepAdmin();
            if ($old !== $new) {
                Audit::log('user.access', 'user', $userId, $old, $new);
            }
        });
        Access::flush();
    }

    /**
     * Who works where (core 1.2, B4): every active member with their roles, and the warehouses they work at. People
     * who work at every warehouse (the account owner, "every warehouse") are listed once, apart.
     */
    public static function whoWorksWhere(): array
    {
        Access::require('user.view');
        $tid  = Tenancy::require();
        $whs  = Db::rows('SELECT id, code, name, active FROM {warehouses} WHERE tenant_id = ? ORDER BY active DESC, code', [$tid]);
        $ppl  = Db::rows("SELECT u.id, u.name, u.username, tu.is_account_owner, tu.all_warehouses, tu.department,
                                 (SELECT GROUP_CONCAT(r.name ORDER BY r.rank_level DESC, r.name SEPARATOR ', ')
                                    FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id AND r.tenant_id = ur.tenant_id
                                   WHERE ur.tenant_id = tu.tenant_id AND ur.user_id = u.id) roles
                            FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id
                           WHERE tu.tenant_id = ? AND tu.status = 'active' ORDER BY u.name", [$tid]);
        $at = [];
        foreach (Db::rows('SELECT user_id, warehouse_id FROM {member_warehouses} WHERE tenant_id = ?', [$tid]) as $r) {
            $at[(int) $r['user_id']][(int) $r['warehouse_id']] = true;
        }
        foreach ($ppl as &$p) {
            $p['every'] = (int) $p['is_account_owner'] || (int) $p['all_warehouses'];
            $p['at']    = $p['every'] ? [] : array_keys($at[(int) $p['id']] ?? []);
        }
        unset($p);
        return ['warehouses' => $whs, 'people' => $ppl];
    }

    /** One warehouse's team: who works there (by name or as "every warehouse"), and who could be added. */
    public static function team(int $warehouseId): array
    {
        Access::require('user.view');
        $w   = \App\Libraries\Master\Warehouses::get($warehouseId);
        $all = self::whoWorksWhere()['people'];
        $here = $every = $others = [];
        foreach ($all as $p) {
            if ($p['every']) {
                $every[] = $p;
            } elseif (in_array($warehouseId, $p['at'], true)) {
                $here[] = $p;
            } else {
                $others[] = $p;
            }
        }
        return ['w' => $w, 'here' => $here, 'every' => $every, 'others' => $others];
    }

    /**
     * Give a warehouse its team (core 1.2, B4): $add and $remove are people whose usual warehouses gain or lose this
     * one. The same rules as a person's own page: People, Edit; never your own access; the account owner and people
     * at every warehouse stay as they are; only a warehouse you work at yourself. Each change is in the audit log.
     */
    public static function setTeam(int $warehouseId, array $add, array $remove): array
    {
        Access::require('user.update');
        $tid = Tenancy::require();
        \App\Libraries\Master\Warehouses::get($warehouseId);
        $mine = Access::memberWarehouses((int) Auth::id());
        if (! $mine['all'] && ! in_array($warehouseId, $mine['ids'], true)) {
            throw new DeniedException('That warehouse is not one of yours, so you cannot change who works there.');
        }
        $add    = array_values(array_unique(array_map('intval', $add)));
        $remove = array_values(array_diff(array_unique(array_map('intval', $remove)), $add));
        $done   = ['added' => [], 'removed' => [], 'left_without' => []];
        if (! $add && ! $remove) {
            return $done;
        }
        foreach (array_merge($add, $remove) as $uid) {
            if ($uid === (int) Auth::id()) {
                throw new DeniedException('You cannot change your own access. Ask another administrator.');
            }
            $m = Db::row("SELECT status, is_account_owner, all_warehouses FROM {tenant_users} WHERE tenant_id = ? AND user_id = ?", [$tid, $uid]);
            if (! $m) {
                throw new NotFoundException('Person not found.');
            }
            if ((int) $m['is_account_owner'] || (int) $m['all_warehouses']) {
                throw new BizException(self::get($uid)['name'] . ' works at every warehouse. Change that on their own page.');
            }
            if (in_array($uid, $add, true) && $m['status'] !== 'active') {
                throw new BizException(self::get($uid)['name'] . ' is switched off here.');
            }
        }
        Db::atomic(static function () use ($tid, $warehouseId, $add, $remove, &$done) {
            foreach ($add as $uid) {
                if (Db::value('SELECT 1 FROM {member_warehouses} WHERE tenant_id = ? AND user_id = ? AND warehouse_id = ?', [$tid, $uid, $warehouseId])) {
                    continue;
                }
                $old = self::access($uid);
                Db::insert('member_warehouses', ['tenant_id' => $tid, 'user_id' => $uid, 'warehouse_id' => $warehouseId]);
                Access::flush();
                Audit::log('user.access', 'user', $uid, $old, self::access($uid) + ['via' => 'warehouse team']);
                $done['added'][] = $uid;
            }
            foreach ($remove as $uid) {
                if (! Db::value('SELECT 1 FROM {member_warehouses} WHERE tenant_id = ? AND user_id = ? AND warehouse_id = ?', [$tid, $uid, $warehouseId])) {
                    continue;
                }
                $old = self::access($uid);
                Db::exec('DELETE FROM {member_warehouses} WHERE tenant_id = ? AND user_id = ? AND warehouse_id = ?', [$tid, $uid, $warehouseId]);
                Access::flush();
                $new = self::access($uid);
                Audit::log('user.access', 'user', $uid, $old, $new + ['via' => 'warehouse team']);
                $done['removed'][] = $uid;
                if (! $new['warehouses']) {
                    $done['left_without'][] = $uid;
                }
            }
            self::keepAdmin();
        });
        Access::flush();
        return $done;
    }

    /** What a person holds here: roles (ids), all_warehouses, warehouses (ids), perms (direct codes). */
    public static function access(int $userId): array
    {
        $tid = Tenancy::require();
        $loc = Access::memberWarehouses($userId);
        sort($loc['ids']);
        return [
            'roles'         => array_map('intval', array_column(Db::rows('SELECT ur.role_id FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id AND r.tenant_id = ur.tenant_id
                                                                          WHERE ur.tenant_id = ? AND ur.user_id = ? ORDER BY ur.role_id', [$tid, $userId]), 'role_id')),
            'all_warehouses' => $loc['all'],
            'warehouses'     => $loc['all'] ? [] : $loc['ids'],
            'perms'         => array_column(Db::rows('SELECT permission_code FROM {member_permissions} WHERE tenant_id = ? AND user_id = ? ORDER BY permission_code', [$tid, $userId]), 'permission_code'),
        ];
    }

    /**
     * The organisation must keep someone who can change people's access (People, Edit): the account owner, or an
     * active member holding it. Called inside the change, so a change that would leave nobody is rolled back.
     */
    public static function keepAdmin(): void
    {
        $tid = Tenancy::require();
        if (Access::usersWith('user.update') || ! Db::value("SELECT 1 FROM {tenant_users} WHERE tenant_id = ? AND status = 'active' LIMIT 1", [$tid])) {
            return;
        }
        throw new BizException('Nobody would be left who can change people\'s access (People, Edit). Give it to someone else first.');
    }

    /** Members of the current organisation with their roles and warehouses here. */
    public static function list(): array
    {
        return Db::rows("SELECT u.id, u.username, u.name, u.email, u.position, u.last_login_at, u.locked_until, u.active login_active, tu.status member_status, tu.is_account_owner, tu.department, tu.all_warehouses,
                                (SELECT GROUP_CONCAT(r.name ORDER BY r.rank_level DESC, r.name SEPARATOR ', ')
                                   FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id AND r.tenant_id = ur.tenant_id
                                  WHERE ur.tenant_id = tu.tenant_id AND ur.user_id = u.id) roles,
                                (SELECT GROUP_CONCAT(w.code ORDER BY w.code SEPARATOR ', ') FROM {member_warehouses} mw JOIN {warehouses} w ON w.tenant_id = mw.tenant_id AND w.id = mw.warehouse_id
                                  WHERE mw.tenant_id = tu.tenant_id AND mw.user_id = u.id) warehouses,
                                (SELECT COUNT(*) FROM {member_permissions} mp WHERE mp.tenant_id = tu.tenant_id AND mp.user_id = u.id) direct
                           FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id
                          WHERE tu.tenant_id = ? ORDER BY tu.status = 'active' DESC, u.name", [Tenancy::require()]);
    }

    /** People of the organisation who may be picked elsewhere (People, Select). */
    public static function pickable(): array
    {
        if (! Access::canSelect('user')) {
            return [];
        }
        return Db::rows("SELECT u.id, u.name FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id WHERE tu.tenant_id = ? AND tu.status = 'active' ORDER BY u.name", [Tenancy::require()]);
    }
}
