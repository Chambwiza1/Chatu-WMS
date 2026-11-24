<?php

namespace App\Controllers;

use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Reports\Reconcile;
use App\Libraries\Setup\Seed;

/** Users and roles, settings, reason codes, integrity check. */
class Admin extends BaseController
{
    public const EDITABLE = [
        'company_name'      => ['Company name', 'Shown in the header and on delivery notes.'],
        'company_address'   => ['Company address and phone', 'Printed under the name on delivery notes and other documents.'],
        'company_tpin'      => ['Company TPIN', 'Printed on delivery notes and purchase orders.'],
        'currency_symbol'   => ['Currency symbol', 'K for kwacha.'],
        'currency_code'     => ['Currency code', 'Three letters, ZMW for kwacha. Stock is valued in this currency; purchase orders may be in others.'],
        'over_receipt_pct'  => ['Over-receipt allowed (%)', 'How much more than ordered may be received against a purchase order.'],
        'adjust_auto_limit' => ['Adjustments: automatic limit', 'Adjustments worth up to this post without a second person, when raised by someone who may approve. 0 = always a second person.'],
        'count_auto_limit'  => ['Counts: automatic limit', 'The same for count differences.'],
        'expiry_warn_days'  => ['Expiry warning (days)', 'Batches expiring within this many days are flagged.'],
        'fleet_warn_days'   => ['Fleet warning (days)', 'Insurance, fitness, road tax, licences and services due within this many days are flagged.'],
        'transfer_loss_note' => ['Note required for transfer losses (1 = yes)', 'A final transfer receipt with missing units needs a note.'],
        'session_idle_minutes' => ['Sign out after idle (minutes)', ''],
        'single_session'    => ['One sign-in per account (1 = on, 0 = off)', 'On: signing in on another device or browser signs the first one out, and it is told why.'],
        'mail_from'         => ['E-mail sender address', 'Leave empty to send no e-mail.'],
        'mail_from_name'    => ['E-mail sender name', ''],
        'smtp_host'         => ['SMTP server', 'Empty: the server\'s own mail.'],
        'smtp_port'         => ['SMTP port', ''],
        'smtp_user'         => ['SMTP user', ''],
        'smtp_pass'         => ['SMTP password', ''],
        'web_cron'          => ['Run scheduled jobs on page visits (1 = yes)', 'Only if the host has no cron.'],
        'max_upload_mb'     => ['Largest upload (MB)', 'Proof-of-delivery photos and scans.'],
    ];

    public function index()
    {
        if (! Access::can('admin.users') && ! Access::can('admin.settings')) {
            return $this->need('admin.users');
        }
        $last = json_decode((string) Settings::get('last_reconcile'), true);
        $cron = (int) Settings::get('last_cron_at', '0');
        return $this->render('admin/index', ['last' => $last, 'cron' => $cron, 'users' => (int) Db::value('SELECT COUNT(*) FROM {users} WHERE active = 1'),
            'jobs' => Db::rows('SELECT * FROM {jobs_log} ORDER BY id DESC LIMIT 5'), 'cronKey' => Access::can('admin.settings') ? Settings::get('cron_key') : null,
            'pending' => Access::can('admin.settings') ? \App\Libraries\Setup\Upgrade::pending() : []], 'Administration');
    }

    /** Bring the database up to this version after the files were copied over (for hosts without a terminal). */
    public function upgrade()
    {
        return $this->act(function () {
            Access::require('admin.settings');
            $todo = \App\Libraries\Setup\Upgrade::pending();
            if (! $todo) {
                return $this->ok('The database is already up to date.', 'admin');
            }
            $runner = service('migrations');
            $runner->setNamespace(null);
            if (! $runner->latest()) {
                throw new \App\Libraries\Core\BizException('The database update did not finish. Check writable/logs, or run php spark migrate --all.');
            }
            \App\Libraries\Core\Audit::log('system.upgrade', 'settings', null, null, ['version' => \App\Libraries\Core\Audit::APP_VERSION, 'migrations' => $todo]);
            return $this->ok('Database updated to version ' . \App\Libraries\Core\Audit::APP_VERSION . '.', 'admin');
        }, site_url('admin'));
    }

    public function users()
    {
        if ($r = $this->need('admin.users')) {
            return $r;
        }
        return $this->render('admin/users', ['rows' => Users::list()], 'Users and roles');
    }

    public function createUser()
    {
        return $this->act(function () {
            $id = Users::create((array) $this->request->getPost());
            return $this->ok('User created. Give them their roles below.', 'admin/users/' . $id);
        }, site_url('admin/users'));
    }

    public function user(int $id)
    {
        if ($r = $this->need('admin.users')) {
            return $r;
        }
        $u = Db::row('SELECT * FROM {users} WHERE id = ?', [$id]);
        if (! $u) {
            return $this->notFound('User');
        }
        return $this->render('admin/user', ['u' => $u, 'roles' => Access::roles($id), 'allRoles' => Db::rows('SELECT * FROM {roles} ORDER BY rank_level DESC, name'),
            'warehouses' => Db::rows('SELECT * FROM {warehouses} WHERE tenant_id = {tid} AND active = 1 ORDER BY code'), 'conflicts' => Access::sodConflicts($id)], $u['name']);
    }

    public function saveUser(int $id)
    {
        return $this->act(function () use ($id) {
            $do = (string) $this->in('do', 'save');
            if ($do === 'password') {
                Users::setPassword($id, (string) $this->in('password'));
                return $this->ok('Password set. Give it to the person privately; they can change it under their name.', 'admin/users/' . $id);
            }
            if ($do === 'deactivate' || $do === 'activate') {
                Users::setActive($id, $do === 'activate');
                return $this->ok($do === 'activate' ? 'Account switched on.' : 'Account switched off; the person cannot sign in.', 'admin/users/' . $id);
            }
            Users::update($id, (array) $this->request->getPost());
            return $this->ok('Saved.', 'admin/users/' . $id);
        }, site_url('admin/users/' . $id));
    }

    public function grant(int $id)
    {
        return $this->act(function () use ($id) {
            $scope = (string) $this->in('scope', 'system');
            Users::grant($id, (string) $this->in('role'), $scope === 'system' ? 'system' : 'warehouse', $scope === 'system' ? null : (int) $scope);
            return $this->ok('Role given.', 'admin/users/' . $id);
        }, site_url('admin/users/' . $id));
    }

    public function revoke(int $userRoleId)
    {
        $uid = (int) Db::value('SELECT user_id FROM {user_roles} WHERE tenant_id = {tid} AND id = ?', [$userRoleId]);
        return $this->act(function () use ($userRoleId, $uid) {
            Users::revoke($userRoleId);
            return $this->ok('Role removed.', 'admin/users/' . $uid);
        }, site_url('admin/users/' . $uid));
    }

    /** What each role may do: one row per permission, one column per role. */
    public function roles()
    {
        if (! Access::can('admin.users') && ! Access::can('audit.view')) {
            return $this->need('admin.users');
        }
        $grid = [];
        foreach (Db::rows('SELECT r.code, rp.permission_code FROM {role_permissions} rp JOIN {roles} r ON r.id = rp.role_id') as $g) {
            $grid[$g['permission_code']][$g['code']] = true;
        }
        return $this->render('admin/roles', ['roles' => Db::rows('SELECT * FROM {roles} ORDER BY rank_level DESC, name'), 'perms' => Seed::PERMISSIONS, 'grid' => $grid,
            'sod' => Db::rows('SELECT s.description, ra.name a, rb.name b FROM {sod_rules} s JOIN {roles} ra ON ra.id = s.role_a_id JOIN {roles} rb ON rb.id = s.role_b_id')], 'Roles');
    }

    public function settings()
    {
        if ($r = $this->need('admin.settings')) {
            return $r;
        }
        return $this->render('admin/settings', ['fields' => self::EDITABLE, 'reasons' => Db::rows('SELECT * FROM {reasons} WHERE tenant_id = {tid} ORDER BY use_for, label')], 'Settings');
    }

    public function saveSettings()
    {
        return $this->act(function () {
            Access::require('admin.settings');
            $before = $after = [];
            Db::atomic(function () use (&$before, &$after) {
                foreach (self::EDITABLE as $k => $_) {
                    $v = $this->in($k);
                    if ($v === null) {
                        continue;
                    }
                    $v = trim((string) $v);
                    if ($k === 'smtp_pass' && $v === '') {
                        continue; // keep the stored password unless a new one is typed
                    }
                    if (in_array($k, ['over_receipt_pct', 'adjust_auto_limit', 'count_auto_limit', 'expiry_warn_days', 'fleet_warn_days', 'session_idle_minutes', 'max_upload_mb', 'smtp_port'], true) && $v !== '' && (! is_numeric($v) || (float) $v < 0)) {
                        throw new BizException(self::EDITABLE[$k][0] . ': give a number, 0 or more.');
                    }
                    if ($k === 'currency_code') {
                        $v = strtoupper($v);
                        if (! preg_match('/^[A-Z]{3}$/', $v)) {
                            throw new BizException('Currency code: three letters, such as ZMW.');
                        }
                    }
                    if ($v !== (string) Settings::get($k)) {
                        $before[$k] = $k === 'smtp_pass' ? '***' : Settings::get($k);
                        $after[$k]  = $k === 'smtp_pass' ? '***' : $v;
                        Settings::set($k, $v);
                    }
                }
                if ($after) {
                    Audit::log('settings.update', 'settings', null, $before, $after);
                }
            });
            return $this->ok($after ? 'Settings saved.' : 'Nothing changed.', 'admin/settings');
        }, site_url('admin/settings'));
    }

    public function saveReason()
    {
        return $this->act(function () {
            Access::require('admin.settings');
            $use  = (string) $this->in('use_for');
            $code = strtoupper(trim((string) $this->in('code')));
            if (! in_array($use, ['adjust', 'supplier_return', 'failed_delivery', 'customer_return', 'hold'], true) || ! preg_match('/^[A-Z0-9_]{2,30}$/', $code)) {
                throw new BizException('Choose where the reason is used, and a code of 2 to 30 capital letters or digits.');
            }
            $label = trim((string) $this->in('label'));
            Db::atomic(static function () use ($use, $code, $label) {
                $exists = Db::row('SELECT * FROM {reasons} WHERE tenant_id = {tid} AND use_for = ? AND code = ?', [$use, $code]);
                if ($exists) {
                    Db::exec('UPDATE {reasons} SET label = ?, active = ? WHERE tenant_id = {tid} AND id = ?', [$label ?: $exists['label'], $label === '-' ? 0 : 1, $exists['id']]);
                } else {
                    if ($label === '') {
                        throw new BizException('Give the reason a description.');
                    }
                    Db::insert('reasons', ['code' => $code, 'label' => mb_substr($label, 0, 100), 'use_for' => $use]);
                }
                Audit::log('reason.save', 'reason', $code, null, ['use_for' => $use, 'label' => $label]);
            });
            return $this->ok('Reason saved.', 'admin/settings');
        }, site_url('admin/settings'));
    }

    public function logo()
    {
        return $this->act(function () {
            if ($this->in('remove')) {
                \App\Libraries\Admin\Brand::remove();
                return $this->ok('Logo removed.', 'admin/settings');
            }
            \App\Libraries\Admin\Brand::upload($this->request->getFile('logo'));
            return $this->ok('Logo saved. It now appears on printed documents.', 'admin/settings');
        }, site_url('admin/settings'));
    }

    public function logoFile()
    {
        $p = \App\Libraries\Admin\Brand::path();
        if (! $p) {
            return $this->notFound('Logo');
        }
        return $this->response->setHeader('Content-Type', \App\Libraries\Admin\Brand::mime())->setHeader('Cache-Control', 'private, max-age=3600')->setBody(file_get_contents($p));
    }

    public function reconcile()
    {
        if (! Access::can('admin.settings') && ! Access::can('stock.approve') && ! Access::can('audit.view')) {
            return $this->need('admin.settings');
        }
        return $this->render('admin/reconcile', ['last' => json_decode((string) Settings::get('last_reconcile'), true)], 'Integrity check');
    }

    public function runReconcile()
    {
        return $this->act(function () {
            if (! Access::can('admin.settings') && ! Access::can('stock.approve') && ! Access::can('audit.view')) {
                Access::require('admin.settings');
            }
            $r = Reconcile::run();
            $n = array_sum(array_map('count', $r));
            Audit::log('reconcile.run', 'system', null, null, ['problems' => $n]);
            return $this->ok($n ? 'The check found ' . $n . ' problem(s).' : 'Everything agrees.', 'admin/reconcile');
        }, site_url('admin/reconcile'));
    }
}
