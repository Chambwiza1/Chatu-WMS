<?php

use App\Commands\TestKit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;

/** core 1.1: one sign-in per account (B7), with the setting to switch it off. */
return [
    'CORE11-1 one sign-in per account: the newest sign-in is the current one; older sessions end; the setting switches it off' => static function (TestKit $t) {
        $uid = (int) Db::value('SELECT MIN(id) FROM {users}');
        $t->true($uid > 0, 'a user to sign in');
        $t->true(Db::value('SELECT session_key FROM {users} WHERE id = ?', [$uid]) === null, 'no sign-in yet: no key');
        $t->eq(null, Auth::storedKey($uid), 'no key stored');
        $t->true(Auth::sessionCurrent(null, null), 'an account with no key yet (before its next sign-in) is not signed out');
        $t->eq('1', Settings::get('single_session'), 'on by default');

        $first = str_repeat('a', 40);
        Db::exec('UPDATE {users} SET session_key = ? WHERE id = ?', [$first, $uid]);
        $t->eq($first, Auth::storedKey($uid), 'a sign-in stores its key');
        $t->true(Auth::sessionCurrent(Auth::storedKey($uid), $first), 'the session holding the key is current');

        $second = str_repeat('b', 40);
        Db::exec('UPDATE {users} SET session_key = ? WHERE id = ?', [$second, $uid]);
        $t->true(! Auth::sessionCurrent(Auth::storedKey($uid), $first), 'a newer sign-in elsewhere ends the older session');
        $t->true(Auth::sessionCurrent(Auth::storedKey($uid), $second), 'the newer one goes on');
        $t->true(! Auth::sessionCurrent(Auth::storedKey($uid), null), 'a session with no key is not current while the account has one');

        Settings::set('single_session', '0');
        $t->true(Auth::sessionCurrent(Auth::storedKey($uid), $first), 'switched off: several sign-ins at once');
        Settings::set('single_session', '1');
    },
];
