<?php

use App\Commands\TestKit;
use App\Libraries\Admin\Roles;
use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Rights;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Platform\Billing;
use App\Libraries\Platform\Modules;
use App\Libraries\Reports\Integrity;
use App\Libraries\Setup\Demo;
use App\Libraries\Setup\Seed;
use App\Libraries\Workflow\Notify;

/**
 * 0.3.0 verification: the agreed core points not already covered by 97_core and 98_core11, one test each.
 * E2 message rules, the user model (one login, shared people), settings in two layers, B6 duties kept apart,
 * B1 roles (copy, delete, never your own), A4 invoices (months, void, paid-until), A5 trials, A7 plans set modules,
 * A12 the integrity rules across organisations.
 */

function v_pat(): int
{
    $id = (int) Db::value("SELECT id FROM {users} WHERE username = 'pat'");
    if (! $id) {
        $id = Seed::login('pat', 'Pat Platform', Demo::PASSWORD, null, 'Platform owner');
        Seed::platformRole($id);
    }
    Tenancy::set(null);
    Auth::actAs($id);
    return $id;
}

return [
    'E2 messages: the newest three pop up, the rest counted; × reads one only; a record page reads its own messages; a list page reads none; another organisation\'s messages stay there' => static function (TestKit $t) {
        $f   = Demo::second($t->c);
        $mia = $f['users']['mia'];
        Tenancy::set($t->c['tenant']);
        foreach ([1, 2, 3, 4, 5] as $n) {
            Notify::user($mia, 'test', 'Message ' . $n, null, $n === 5 ? '/adjustments/77' : '/counts');
        }
        Notify::user($mia, 'test', 'Second about adjustment 77', null, '/adjustments/77');
        $p = Notify::popups($mia, 0);
        $t->eq(['Message 4', 'Message 5', 'Second about adjustment 77'], array_column($p['messages'], 'title'), 'newest three, oldest first');
        $t->eq(3, $p['more'], 'three more unread');
        $t->true($p['messages'][2]['new'], 'new since the last check');
        $t->eq([], array_filter(array_column(Notify::popups($mia, $p['messages'][2]['id'])['messages'], 'new')), 'nothing new after it');
        Notify::readOne($mia, $p['messages'][0]['id']);
        $t->eq(5, Notify::unreadCount($mia), 'x reads only that one');
        Notify::seenRecord($mia, 'counts');
        $t->eq(5, Notify::unreadCount($mia), 'a list page reads nothing');
        Notify::seenRecord($mia, 'adjustments/77');
        $t->eq(3, Notify::unreadCount($mia), 'the record page read both messages about it');
        Tenancy::set($f['tenant']);
        $t->eq(0, Notify::unreadCount($mia), 'in Demo Foods, none of these');
        Notify::user($mia, 'test', 'Foods message', null, null);
        $t->eq(1, Notify::unreadCount($mia));
        Tenancy::set($t->c['tenant']);
        $t->eq(3, Notify::unreadCount($mia), 'and back');
        Notify::readAll($mia);
        $t->eq(0, Notify::unreadCount($mia), 'Mark all read');
        Tenancy::set($f['tenant']);
        $t->eq(1, Notify::unreadCount($mia), 'only in this organisation');
    },

    'USR-1 one login per person: an existing login joins another organisation; its details and password stay the person\'s own' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        $t->as('sam');
        $t->throws(static fn () => Users::addExisting('nobody@nowhere'), 'Nobody signs in');
        $id = Users::addExisting('fay@demo-foods.example');
        $t->eq($f['users']['fay'], $id, 'found by e-mail');
        $t->throws(static fn () => Users::addExisting('fay'), 'already in this organisation');
        $t->true(Users::shared($id), 'the login is shared');
        $t->throws(static fn () => Users::setPassword($id, 'Another-pass-2026'), 'only they can change their password');
        Users::update($id, ['department' => 'Visiting', 'name' => 'Changed Name']);
        $t->eq('Fay Owner', Db::value('SELECT name FROM {users} WHERE id = ?', [$id]), 'the name is the person\'s');
        $t->eq('Visiting', Db::value('SELECT department FROM {tenant_users} WHERE tenant_id = {tid} AND user_id = ?', [$id]), 'the department is the organisation\'s');
        $t->true(! Access::can('stock.view', $id), 'no roles here yet: nothing to do');
        Tenancy::set($f['tenant']);
        $t->true(Access::can('stock.approve', $id), 'still the owner at Demo Foods');
        Tenancy::set($t->c['tenant']);
        $t->as('sam');
        $t->throws(static fn () => Users::create(['username' => 'FAY', 'name' => 'X', 'password' => 'Long-enough-2026']), 'taken');
        $t->throws(static fn () => Users::create(['username' => 'fay2', 'name' => 'X', 'email' => 'fay@demo-foods.example', 'password' => 'Long-enough-2026']), 'existing person');
        Users::setActive($id, false);
        $t->eq(['Demo Foods'], array_column(Tenancy::memberships($id), 'name'), 'switched off here, still in Demo Foods');
    },

    'SET-1 settings in two layers: each organisation keeps its own; the installation keeps its own' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        Settings::set('over_receipt_pct', '5');
        $t->eq('5', Settings::get('over_receipt_pct'));
        $t->eq('5', (string) Db::value("SELECT value FROM {tenant_settings} WHERE tenant_id = {tid} AND name = 'over_receipt_pct'"), 'stored for the organisation');
        $t->eq(null, Db::value("SELECT value FROM {settings} WHERE name = 'over_receipt_pct'"), 'not for the installation');
        Tenancy::set($f['tenant']);
        $t->eq('0', Settings::get('over_receipt_pct'), 'Demo Foods keeps the default');
        $t->eq('Demo Foods', Settings::get('company_name'));
        Settings::set('session_idle_minutes', '45');
        Tenancy::set($t->c['tenant']);
        $t->eq('45', Settings::get('session_idle_minutes'), 'an installation setting is the same in every organisation');
        $t->eq('Demo Distributors Ltd', Settings::get('company_name'));
    },

    'B6 duties kept apart: two roles that must stay apart are refused together (enforce), allowed and shown (report)' => static function (TestKit $t) {
        $t->as('sam');
        $roles = array_column(Roles::list(), 'id', 'code');
        $steve = Users::access($t->uid('steve'));
        $e = $t->throws(static fn () => Users::setAccess($t->uid('steve'), array_merge($steve['roles'], [$roles['accountant']]), false, $steve['warehouses'], []), 'Segregation of duties');
        $t->true(str_contains($e->getMessage(), 'Storekeeper') && str_contains($e->getMessage(), 'Accountant'), 'names both roles');
        // a renamed copy keeps its template, so the rule still holds
        $copy = Roles::copy((int) $roles['accountant'], 'Finance clerk');
        $t->throws(static fn () => Users::setAccess($t->uid('steve'), array_merge($steve['roles'], [$copy]), false, $steve['warehouses'], []), 'Segregation of duties');
        Settings::set('sod_mode', 'report');
        Users::setAccess($t->uid('steve'), array_merge($steve['roles'], [$roles['accountant']]), false, $steve['warehouses'], []);
        $t->eq(1, count(Access::sodConflicts($t->uid('steve'))), 'allowed, and listed on the person\'s page');
        Settings::set('sod_mode', 'enforce');
    },

    'B1 roles: add, copy, rename, delete one nobody holds; a role someone holds cannot be deleted; nobody edits a role they hold; unknown codes refused' => static function (TestKit $t) {
        $t->as('sam');
        $id = Roles::save(['name' => 'Night gate', 'description' => 'Checks loads out', 'perms' => ['stock.view', 'dispatch.manage']]);
        $t->eq(['dispatch.manage', 'stock.view'], Roles::perms($id));
        $t->throws(static fn () => Roles::save(['name' => 'Bad', 'perms' => ['stock.view', 'made.up']]), 'Unknown');
        $t->throws(static fn () => Roles::save(['name' => 'Bad', 'perms' => ['platform.tenants']]), 'Unknown');
        $copy = Roles::copy($id);
        $t->true(str_contains(Roles::get($copy)['name'], 'Night gate'), 'copied with its name');
        Roles::delete($copy);
        $t->throws(static fn () => Roles::get($copy), 'not found');
        $sk = (int) Db::value("SELECT id FROM {roles} WHERE tenant_id = {tid} AND code = 'storekeeper'");
        $t->throws(static fn () => Roles::delete($sk), 'held by');
        $t->as('steve');
        $t->throws(static fn () => Roles::save(['name' => 'X', 'perms' => []]), 'Roles, Create', DeniedException::class);
        // the grid lists every code once, in a known module and column
        foreach (array_keys(Rights::all()) as $code) {
            $t->true(isset(Rights::COLUMNS[Rights::all()[$code][1]]), $code . ' sits in a known column');
        }
        $t->true(isset(Rights::MODULES['items'][2]['select']), 'Items has a Select box');
    },

    'A4 invoices: 1 to 24 months from the paid-until date, numbered; paid by hand moves paid-until; void needs a reason and only an unpaid one' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        v_pat();
        Billing::assignPlan($tid, Billing::savePlan(['code' => 'STD', 'name' => 'Standard', 'monthly_price' => '300']));
        $t->throws(static fn () => Billing::issue($tid, 25), '1 to 24');
        $a = Billing::invoice(Billing::issue($tid, 3, null, null, '2026-01-01'));
        $t->eq([900.0, '2026-01-01', '2026-03-31'], [(float) $a['amount'], $a['period_start'], $a['period_end']]);
        $t->true((bool) preg_match('/^INV-\d{6}-\d{4}$/', $a['number']), 'numbered ' . $a['number']);
        Billing::pay((int) $a['id'], 'ZANACO 1');
        $t->eq('2026-03-31', Db::value('SELECT paid_until FROM {tenants} WHERE id = ?', [$tid]));
        $b = Billing::invoice(Billing::issue($tid, 1, null, null, '2026-02-15'));
        $t->eq('2026-04-01', $b['period_start'], 'the next one starts after paid-until');
        $t->throws(static fn () => Billing::void((int) $b['id'], ''), 'reason');
        Billing::void((int) $b['id'], 'Issued by mistake');
        $t->eq('void', Billing::invoice((int) $b['id'])['status']);
        $t->throws(static fn () => Billing::void((int) $a['id'], 'x'), 'unpaid');
        Tenancy::set($tid);
        $t->as('anna');
        $t->throws(static fn () => Billing::savePlan(['code' => 'X', 'name' => 'X']), 'platform owner', DeniedException::class);
    },

    'A5 trials: warned three days before (once), then suspended with the data kept; extended from the console; days left shown' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $f   = Demo::second($t->c);
        $tid = $f['tenant'];
        v_pat();
        Db::exec('UPDATE {tenants} SET trial_ends_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 2 * 86400 - 60), $tid]);
        $t->eq(2, Billing::trialDaysLeft(Billing::tenant($tid)));
        $t->eq(1, Billing::daily()['trial_warned'], 'warned');
        $t->eq(0, Billing::daily()['trial_warned'], 'once');
        Tenancy::set($tid);
        $t->true(Notify::unreadCount($f['users']['fay']) >= 1, 'the owner was told');
        v_pat();
        Db::exec('UPDATE {tenants} SET trial_ends_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $tid]);
        $t->eq(1, Billing::daily()['trial_ended']);
        $row = Billing::tenant($tid);
        $t->eq(['suspended', 'trial_ended'], [$row['status'], $row['status_reason']]);
        Tenancy::set($tid);
        $t->true(Tenancy::readOnly(), 'read-only');
        $t->true((int) Db::value('SELECT COUNT(*) FROM {items} WHERE tenant_id = {tid}') > 0, 'the data is kept');
        v_pat();
        $t->throws(static fn () => Billing::extendTrial($tid, 0, 'x'), '1 to 365');
        Billing::extendTrial($tid, 10, 'Asked for more time');
        $row = Billing::tenant($tid);
        $t->eq(['trial', 10], [$row['status'], Billing::trialDaysLeft($row)]);
        Tenancy::set($t->c['tenant']);
    },

    'A7 a plan sets the module rules; the organisation decides what is left to it; Settings shows each module\'s state' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        v_pat();
        Billing::assignPlan($tid, Billing::savePlan(['code' => 'LITE', 'name' => 'Lite', 'monthly_price' => '150', 'modules' => ['production' => 'off', 'fleet' => 'allow']]));
        $t->eq(['off', 'allow'], [Modules::rule('production', $tid), Modules::rule('fleet', $tid)], 'set by the plan');
        $t->as('sam');
        $t->throws(static fn () => Modules::setOwn('production', true), 'not available');
        Modules::setOwn('fleet', false);
        $t->true(! Modules::on('fleet'), 'the organisation switched Fleet off');
        $t->true(! isset(\App\Libraries\Core\Rights::modules()['fleet']), 'Fleet leaves the grid');
        $t->throws(static fn () => \App\Libraries\Fleet\Fleet::saveDriver(['name' => 'D', 'licence_no' => 'L9']), 'switched off');
        Modules::setOwn('fleet', true);
        $t->true(Modules::on('fleet'), 'and on again, with what was recorded');
        $t->as('steve');
        $t->throws(static fn () => Modules::setOwn('fleet', false), 'Settings', DeniedException::class);
        // the single-company edition: only the organisation's own switch counts
        Settings::set('edition', 'single');
        $t->eq('allow', Modules::rule('production', $tid));
    },

    'A12 the integrity check across organisations: two account owners, a role of another organisation, a platform permission in a role are all found' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        $t->eq([], array_filter(Integrity::run(false)), 'clean to start');
        Db::unscoped(static function () use ($t, $f) {
            Db::exec('UPDATE {tenant_users} SET is_account_owner = 1 WHERE tenant_id = ? AND user_id = ?', [$f['tenant'], $f['users']['mia']]);
            $other = (int) Db::value("SELECT id FROM {roles} WHERE tenant_id = ? AND code = 'auditor'", [$f['tenant']]);
            Db::exec("INSERT INTO {user_roles} (tenant_id, user_id, role_id, scope_type) VALUES (?, ?, ?, 'tenant')", [$t->c['tenant'], $t->uid('olive'), $other]);
            $mine = (int) Db::value("SELECT id FROM {roles} WHERE tenant_id = ? AND code = 'auditor'", [$t->c['tenant']]);
            Db::exec("INSERT INTO {role_permissions} (role_id, permission_code) VALUES (?, 'platform.tenants')", [$mine]);
        });
        $r = Integrity::run(false);
        $t->eq(1, count($r['owners']), 'two owners in Demo Foods');
        $t->true(count($r['access']) >= 3, 'the cross-organisation role, the owner without every warehouse, the platform permission: ' . json_encode($r['access']));
        $t->true(! Access::platform('platform.tenants', $t->uid('olive')), 'a platform code held through an organisation\'s role gives no platform access');
        Db::unscoped(static function () use ($t, $f) {
            Db::exec('UPDATE {tenant_users} SET is_account_owner = 0 WHERE tenant_id = ? AND user_id = ?', [$f['tenant'], $f['users']['mia']]);
            Db::exec('DELETE FROM {user_roles} WHERE tenant_id = ? AND user_id = ? AND role_id IN (SELECT id FROM {roles} WHERE tenant_id = ?)', [$t->c['tenant'], $t->uid('olive'), $f['tenant']]);
            Db::exec("DELETE FROM {role_permissions} WHERE permission_code = 'platform.tenants' AND role_id IN (SELECT id FROM {roles} WHERE level = 'tenant')");
        });
        $t->eq([], array_filter(Integrity::run(false)), 'clean again');
    },
];
