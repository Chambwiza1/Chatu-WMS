<?php

use App\Commands\TestKit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\NotFoundException;
use App\Libraries\Core\Settings;
use App\Libraries\Core\SignInAs;
use App\Libraries\Core\Tenancy;
use App\Libraries\Setup\Demo;
use App\Libraries\Setup\Seed;

/** ChatuERP core 1.2: signing in as someone, by an administrator and by support with the organisation's leave. */
if (! function_exists('signin_pat')) {
    function signin_pat(): void
    {
        $id = (int) Db::value("SELECT id FROM {users} WHERE username = 'pat'");
        if (! $id) {
            $id = Seed::login('pat', 'Pat Platform', Demo::PASSWORD, 'pat@chatu.example', 'Platform owner');
            Seed::platformRole($id);
        }
        Tenancy::set(null);
        Auth::actAs($id);
    }
}
$admin = static function (TestKit $t): void { Settings::set('edition', 'saas'); $t->as('sam'); };
$pat   = static function (TestKit $t): void { Settings::set('edition', 'saas'); signin_pat(); };
$tid   = static fn (TestKit $t): int => (int) $t->c['tenant'];

return [
    'C12-2 an administrator signs in as one of their people: with a reason, never as themselves, the owner or another administrator; every save is listed; passwords are refused; stopping closes the record' => static function (TestKit $t) use ($admin, $pat, $tid) {
        SignInAs::$test = null;
        $admin($t);
        $me    = (int) Auth::id();
        $T     = $tid($t);
        $owner = (int) Db::value('SELECT user_id FROM {tenant_users} WHERE tenant_id = ? AND is_account_owner = 1', [$T]);
        $t->eq('That is you.', SignInAs::adminBlocked($me));
        if ($owner !== $me) {
            $t->true(str_contains((string) SignInAs::adminBlocked($owner), 'account owner'), 'never as the account owner');
        }
        $t->true(str_contains((string) SignInAs::adminBlocked($t->uid('dora')), 'account owner'), 'not as someone who administers people');
        $t->eq(null, SignInAs::adminBlocked($t->uid('steve')));
        $t->throws(static fn () => SignInAs::startAdmin($t->uid('steve'), 'x'), 'Give the reason');
        $log = SignInAs::startAdmin($t->uid('steve'), 'Cannot find the page');
        $imp = SignInAs::current();
        $t->eq(['admin', $me, $t->uid('steve'), $T, $T], [$imp['kind'], $imp['by'], $imp['as'], $imp['tenant'], $imp['back']]);
        $t->true($imp['until'] <= gmdate('Y-m-d H:i:s', time() + 3600), 'for an hour at most');
        $t->true(str_contains((string) SignInAs::adminBlocked($t->uid('kim')), 'Stop the sign-in'), 'not a second one on top');
        $t->eq(null, SignInAs::record('GET', 'admin/users'), 'looking is not listed');
        $t->eq(null, SignInAs::record('POST', 'something/3/save'));
        $t->true(str_contains((string) SignInAs::record('POST', 'account/password'), 'cannot be done'), 'their password stays theirs');
        $t->true(str_contains((string) SignInAs::record('POST', 'admin/users/7/password'), 'cannot be done'), 'nor anyone else\'s');
        $t->eq('time', SignInAs::ended(['until' => gmdate('Y-m-d H:i:s', time() - 5)] + $imp));
        $t->eq(['/admin/users/' . $t->uid('steve'), null], [SignInAs::stop(), SignInAs::current()]);
        $row = Db::row('SELECT * FROM {signin_as_log} WHERE tenant_id = ? AND id = ?', [$T, $log]);
        $t->eq([1, 'stopped', 'Cannot find the page'], [(int) $row['actions'], $row['ended_how'], $row['reason']]);
        $t->eq([['POST', 'something/3/save']], array_map(static fn ($a) => [$a['method'], $a['path']], SignInAs::actions($log)));
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = ? AND action = 'signin_as.start'", [$T]), 'in the organisation\'s audit log');
        $t->as('steve');
        $t->true(str_contains((string) SignInAs::adminBlocked($t->uid('kim')), 'user.signin_as'), 'only with the permission');
        $t->throws(static fn () => SignInAs::startAdmin($t->uid('kim'), 'Trying it on'), 'user.signin_as', DeniedException::class);
        $t->throws(static fn () => SignInAs::history(), 'role.update', DeniedException::class);
    },

    'C12-3 support signs in as the account owner only while the organisation allows it: closed by default, opened for a set time by its administrator, told to the owner, in both audit logs, ended when access is closed' => static function (TestKit $t) use ($admin, $pat, $tid) {
        SignInAs::$test = null;
        $admin($t);
        $T     = $tid($t);
        $owner = (int) Db::value('SELECT user_id FROM {tenant_users} WHERE tenant_id = ? AND is_account_owner = 1', [$T]);
        $t->throws(static fn () => SignInAs::startSupport($T, 'I am not the platform owner'), 'platform owner', DeniedException::class);
        $pat($t);
        $t->eq(null, SignInAs::supportUntil($T), 'closed by default');
        $t->throws(static fn () => SignInAs::startSupport($T, 'They asked for help'), 'has not allowed support', DeniedException::class);
        $admin($t);
        $t->throws(static fn () => SignInAs::allowSupport(5), 'Choose how long');
        SignInAs::allowSupport(24);
        $until = SignInAs::supportUntil();
        $t->true($until > gmdate('Y-m-d H:i:s', time() + 23 * 3600) && $until <= gmdate('Y-m-d H:i:s', time() + 24 * 3600 + 5), 'open for the time chosen');
        $pat($t);
        $t->throws(static fn () => SignInAs::startSupport($T, ''), 'Give the reason');
        $log = SignInAs::startSupport($T, 'They asked for help');
        $imp = SignInAs::current();
        $t->eq(['support', $owner, $T, null, $until], [$imp['kind'], $imp['as'], $imp['tenant'], $imp['back'], $imp['until']], 'as the account owner, no longer than the organisation allowed');
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {notifications} WHERE tenant_id = ? AND user_id = ? AND type = 'support'", [$T, $owner]), 'the owner is told at once');
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id IS NULL AND action = 'signin_as.start' AND object_id = ?", [(string) $T]), 'in the platform audit log');
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = ? AND action = 'signin_as.start' AND detail = 'They asked for help'", [$T]), 'and in the organisation\'s');
        $t->true(str_contains((string) SignInAs::record('POST', 'admin/support'), 'cannot be done'), 'support cannot extend its own access');
        $t->eq(null, SignInAs::record('POST', 'something'));
        SignInAs::$test = null;
        $admin($t);
        $t->true(str_starts_with(SignInAs::history()[0]['by_name'], 'Chatu support'), 'the organisation sees who and why');
        SignInAs::allowSupport(0);
        $t->eq('closed', SignInAs::ended($imp));
        SignInAs::$test = $imp;
        $t->eq('/platform/tenants/' . $T, SignInAs::stop('closed'));
        $pat($t);
        $t->throws(static fn () => SignInAs::startSupport($T, 'Once more'), 'has not allowed support', DeniedException::class);
    },
];
