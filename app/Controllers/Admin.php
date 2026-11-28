<?php

namespace App\Controllers;

use App\Libraries\Admin\Roles;
use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Reports\Reconcile;

/** People and access, roles, settings, reason codes, integrity check. */
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
        'live_popups'       => ['Messages pop up (1 = yes, 0 = no)', 'New messages appear on every page until each is opened or closed.'],
        'live_sound'        => ['Sound with them (1 = yes, 0 = no)', 'A short tone when a new message or alert pops up. Each device can still mute it.'],
        'escalate_days'     => ['Chase late work after (working days)', 'Reminders go to those who should act after this many working days; their managers are told after twice as many.'],
        'working_days'      => ['Working days (1 = Monday … 7 = Sunday)', 'For example 1,2,3,4,5 or 1,2,3,4,5,6.'],
        'holidays'          => ['Public holidays', 'Dates as YYYY-MM-DD, separated by commas. They do not count as working days.'],
        'live_alerts'       => ['Alerts pop up (1 = yes, 0 = no)', 'What waits for each person (approvals, transfers arriving, expired batches, items to reorder, fleet papers) pops up once per sign-in, and again when it changes.'],
        'session_idle_minutes' => ['Sign out after idle (minutes)', ''],
        'single_session'    => ['One sign-in per account (1 = on, 0 = off)', 'On: signing in on another device or browser signs the first one out, and it is told why.'],
        'mail_from'         => ['E-mail sender address (the installation)', 'For password resets and the platform\'s own messages. On the online service each organisation sends its own e-mail from Administration > Messages. Leave empty to send no e-mail.'],
        'mail_from_name'    => ['E-mail sender name', ''],
        'smtp_host'         => ['SMTP server', 'Empty: the server\'s own mail.'],
        'smtp_port'         => ['SMTP port', ''],
        'smtp_user'         => ['SMTP user', ''],
        'smtp_pass'         => ['SMTP password', ''],
        'web_cron'          => ['Run scheduled jobs on page visits (1 = yes)', 'Only if the host has no cron.'],
        'wa_verify_token'   => ['WhatsApp webhook word', 'Any word you choose; give Meta the same one with the address /hooks/whatsapp, so it can send delivery reports.'],
        'wa_transport'      => ['WhatsApp: send for real (live) or only write to the log (log)', 'log: to try an installation out before Meta approves the template.'],
        'max_upload_mb'     => ['Largest upload (MB)', 'Proof-of-delivery photos and scans.'],
    ];

    /** Settings an organisation keeps; the others belong to the installation (platform settings, or here in the single-company edition). */
    public const PLATFORM_KEYS = ['session_idle_minutes', 'single_session', 'mail_from', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'web_cron', 'max_upload_mb', 'wa_verify_token', 'wa_transport'];

    /** The installation's settings, kept by the platform owner (or by the administrator in the single-company edition). */
    public static function platformFields(): array
    {
        return array_intersect_key(self::EDITABLE, array_flip(self::PLATFORM_KEYS));
    }

    public function index()
    {
        if ($r = $this->needAny(['user.view', 'role.view', 'admin.settings'])) {
            return $r;
        }
        $single = Tenancy::single();
        $last   = json_decode((string) Settings::get('last_reconcile'), true);
        $cron   = (int) Settings::get('last_cron_at', '0');
        return $this->render('admin/index', ['last' => $last, 'cron' => $cron, 'single' => $single,
            'users' => (int) Db::value("SELECT COUNT(*) FROM {tenant_users} WHERE tenant_id = {tid} AND status = 'active'"),
            'jobs' => $single ? Db::rows('SELECT * FROM {jobs_log} ORDER BY id DESC LIMIT 5') : [], 'cronKey' => $single && Access::can('admin.settings') ? Settings::get('cron_key') : null,
            'pending' => Access::can('admin.settings') ? \App\Libraries\Setup\Upgrade::pending() : []], 'Administration');
    }

    /** Single-company edition: the administrator runs the database update (online service: the platform owner does, for every organisation at once). */
    public function upgrade()
    {
        return $this->act(function () {
            if (! Tenancy::single()) {
                throw new BizException('The platform owner updates the database for every organisation.');
            }
            Access::require('admin.settings');
            return PlatformTools::upgrade($this, 'admin');
        }, site_url('admin'));
    }

    public function users()
    {
        if ($r = $this->need('user.view')) {
            return $r;
        }
        return $this->render('admin/users', ['rows' => Users::list()], 'People and access');
    }

    public function createUser()
    {
        return $this->act(function () {
            $id = Users::create((array) $this->request->getPost());
            return $this->ok('Added. Now give them a role and their warehouses.', 'admin/users/' . $id);
        }, site_url('admin/users'));
    }

    public function addExisting()
    {
        return $this->act(function () {
            $id = Users::addExisting((string) $this->in('login', ''));
            return $this->ok('Added. Now give them a role and their warehouses.', 'admin/users/' . $id);
        }, site_url('admin/users'));
    }

    public function user(int $id)
    {
        return $this->page(function () use ($id) {
            Access::require('user.view');
            $u = Users::get($id);
            return $this->render('admin/user', ['u' => $u, 'shared' => Users::shared($id), 'access' => Users::access($id), 'allRoles' => Roles::list(),
                'whs' => Db::rows('SELECT id, code, name, active FROM {warehouses} WHERE tenant_id = {tid} ORDER BY active DESC, code'), 'conflicts' => Access::sodConflicts($id)], $u['name']);
        });
    }

    public function saveUser(int $id)
    {
        return $this->act(function () use ($id) {
            switch ((string) $this->in('do', 'save')) {
                case 'password':
                    Users::setPassword($id, (string) $this->in('password'));
                    return $this->ok('Password set. Give it to the person privately; they can change it under their name.', 'admin/users/' . $id);
                case 'deactivate':
                    Users::setActive($id, false);
                    return $this->ok('Switched off in this organisation; the person cannot work here.', 'admin/users/' . $id);
                case 'activate':
                    Users::setActive($id, true);
                    return $this->ok('Switched on.', 'admin/users/' . $id);
                default:
                    Users::update($id, (array) $this->request->getPost());
                    return $this->ok('Saved.', 'admin/users/' . $id);
            }
        }, site_url('admin/users/' . $id));
    }

    /** Who works where (core 1.2, B4): people by warehouse. */
    public function where()
    {
        return $this->page(function () {
            return $this->render('admin/where', Users::whoWorksWhere(), 'Who works where');
        });
    }

    /** One warehouse's team, to add people to it or take them off. */
    public function team(int $warehouseId)
    {
        return $this->page(function () use ($warehouseId) {
            $t = Users::team($warehouseId);
            return $this->render('admin/team', $t, 'Team at ' . $t['w']['code']);
        });
    }

    public function saveTeam(int $warehouseId)
    {
        return $this->act(function () use ($warehouseId) {
            $add    = (array) ($this->request->getPost('add') ?? []);
            $remove = (string) $this->in('remove', '') !== '' ? [(int) $this->in('remove')] : [];
            $d = Users::setTeam($warehouseId, $add, $remove);
            $msg = ! $d['added'] && ! $d['removed'] ? 'Nothing changed.'
                : trim(($d['added'] ? count($d['added']) . ' added to the team. ' : '') . ($d['removed'] ? count($d['removed']) . ' taken off the team. ' : '')
                . ($d['left_without'] ? 'Note: ' . implode(', ', array_map(static fn ($u) => Users::get($u)['name'], $d['left_without'])) . ' now works at no warehouse.' : ''));
            return $this->ok($msg, 'admin/where/' . $warehouseId);
        }, site_url('admin/where/' . $warehouseId));
    }

    /** Roles, warehouses and direct permissions of a person, in one save. */
    public function saveAccess(int $id)
    {
        return $this->act(function () use ($id) {
            Users::setAccess($id, (array) ($this->request->getPost('roles') ?? []), (string) $this->in('all_warehouses') === '1',
                (array) ($this->request->getPost('warehouses') ?? []), (array) ($this->request->getPost('perms') ?? []));
            return $this->ok('Access saved.', 'admin/users/' . $id);
        }, site_url('admin/users/' . $id));
    }

    public function roles()
    {
        if ($r = $this->need('role.view')) {
            return $r;
        }
        $sod = Db::rows('SELECT ra.name a, rb.name b, s.description FROM {sod_rules} s JOIN {roles} ra ON ra.id = s.role_a_id JOIN {roles} rb ON rb.id = s.role_b_id');
        return $this->render('admin/roles', ['roles' => Roles::list(), 'sod' => $sod], 'Roles');
    }

    public function role(?int $id = null)
    {
        return $this->page(function () use ($id) {
            Access::require($id ? 'role.view' : 'role.create');
            $r = $id ? Roles::get($id) : null;
            return $this->render('admin/role', ['r' => $r], $r ? $r['name'] : 'New role');
        });
    }

    public function saveRole(?int $id = null)
    {
        return $this->act(function () use ($id) {
            $rid = Roles::save(['name' => $this->in('name'), 'description' => $this->in('description'), 'perms' => (array) ($this->request->getPost('perms') ?? [])], $id);
            return $this->ok($id ? 'Role saved.' : 'Role added. Give it to people under People.', 'admin/roles/' . $rid);
        }, site_url($id ? 'admin/roles/' . $id : 'admin/roles/new'));
    }

    public function copyRole(int $id)
    {
        return $this->act(function () use ($id) {
            $rid = Roles::copy($id);
            return $this->ok('Copied. Rename it and change what it may do.', 'admin/roles/' . $rid);
        }, site_url('admin/roles'));
    }

    public function deleteRole(int $id)
    {
        return $this->act(function () use ($id) {
            Roles::delete($id);
            return $this->ok('Role deleted.', 'admin/roles');
        }, site_url('admin/roles/' . $id));
    }

    public function settings()
    {
        if ($r = $this->need('admin.settings')) {
            return $r;
        }
        $fields = Tenancy::single() ? self::EDITABLE : array_diff_key(self::EDITABLE, array_flip(self::PLATFORM_KEYS));
        return $this->render('admin/settings', ['fields' => $fields, 'reasons' => Db::rows('SELECT * FROM {reasons} WHERE tenant_id = {tid} ORDER BY use_for, label')], 'Settings');
    }

    public function saveSettings()
    {
        return $this->act(function () {
            Access::require('admin.settings');
            $before = $after = [];
            Db::atomic(function () use (&$before, &$after) {
                foreach (self::EDITABLE as $k => $_) {
                    if (! Tenancy::single() && in_array($k, self::PLATFORM_KEYS, true)) {
                        continue; // the platform owner keeps these for every organisation
                    }
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
                    if (in_array($k, ['live_popups', 'live_sound', 'live_alerts', 'transfer_loss_note', 'single_session', 'web_cron'], true) && ! in_array($v, ['0', '1'], true)) {
                        throw new BizException(self::EDITABLE[$k][0] . ': 1 or 0.');
                    }
                    if ($k === 'wa_transport' && ! in_array($v, ['live', 'log'], true)) {
                        throw new BizException('WhatsApp: live or log.');
                    }
                    if ($k === 'escalate_days' && (! ctype_digit($v) || (int) $v < 1 || (int) $v > 30)) {
                        throw new BizException('Chase late work after 1 to 30 working days.');
                    }
                    if ($k === 'working_days') {
                        $d = array_values(array_unique(array_filter(array_map('trim', explode(',', $v)), 'strlen')));
                        if (! $d || array_filter($d, static fn ($x) => ! in_array($x, ['1', '2', '3', '4', '5', '6', '7'], true))) {
                            throw new BizException('Working days: numbers 1 (Monday) to 7 (Sunday), separated by commas.');
                        }
                        sort($d);
                        $v = implode(',', $d);
                    }
                    if ($k === 'holidays') {
                        preg_match_all('/\d{4}-\d{2}-\d{2}/', $v, $hm);
                        foreach ($hm[0] as $h) {
                            if (! strtotime($h)) {
                                throw new BizException('Public holidays: ' . $h . ' is not a date.');
                            }
                        }
                        $v = implode(', ', array_unique($hm[0]));
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

    /** core 1.3 (C2): the organisation's colours: a ready palette or its own two colours, checked for legibility. */
    public function theme()
    {
        return $this->act(function () {
            \App\Libraries\Admin\Theme::save((string) $this->in('brand_theme', ''), (string) $this->in('brand_primary', ''), (string) $this->in('brand_accent', ''));
            return $this->ok('Colours saved. Everyone in the organisation sees them on their next page.', 'admin/settings#colours');
        }, site_url('admin/settings#colours'));
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
