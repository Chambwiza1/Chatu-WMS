<?php

use App\Commands\TestKit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\PasswordReset;
use App\Libraries\Core\Settings;

/** core 1.5.1 (B12): a forgotten password, reset by an e-mailed link. */
$token = static function (array $mail): string {
    preg_match('~reset-password/([A-Za-z0-9_-]+)~', $mail['body'], $m);
    return $m[1] ?? '';
};
$link = static fn (string $t): string => 'https://example.test/reset-password/' . $t;

return [
    'RST-1 a link is e-mailed only to an active account with an address; the answer never differs; requests are limited' => static function (TestKit $t) use ($token, $link) {
        PasswordReset::$outbox = [];
        Settings::set('mail_from', '');
        $t->true(! PasswordReset::available(), 'without a sender address there is no reset by e-mail');
        Settings::set('mail_from', 'no-reply@example.test');
        $t->true(PasswordReset::available(), 'with one there is');

        $who = Db::row('SELECT id, username FROM {users} WHERE active = 1 ORDER BY id DESC LIMIT 1');
        $uid = (int) $who['id'];
        $name = (string) $who['username'];
        Db::exec('UPDATE {users} SET email = ? WHERE id = ?', ['reset-me@example.test', $uid]);
        PasswordReset::request('nobody-here', '10.0.0.1', $link);
        PasswordReset::request('', '10.0.0.1', $link);
        $t->eq(0, PasswordReset::flush(), 'an unknown name sends nothing');
        PasswordReset::request(strtoupper($name), '10.0.0.1', $link);
        PasswordReset::request('reset-me@example.test', '10.0.0.1', $link);
        $t->eq(2, PasswordReset::flush(), 'by username (any case) or by e-mail address');
        $t->eq('reset-me@example.test', PasswordReset::$outbox[0]['to'], 'to the address on the account');
        $first  = $token(PasswordReset::$outbox[0]);
        $second = $token(PasswordReset::$outbox[1]);
        $t->true(strlen($first) >= 43 && $first !== $second, 'each link is its own');
        $t->eq(0, (int) Db::value('SELECT COUNT(*) FROM {password_resets} WHERE token_hash = ?', [$first]), 'the link itself is not kept');
        $t->eq(1, (int) Db::value('SELECT COUNT(*) FROM {password_resets} WHERE token_hash = ?', [hash('sha256', $first)]), 'only its hash');
        $t->eq(null, PasswordReset::find($first), 'a newer link retires the older one');
        $t->eq($uid, (int) (PasswordReset::find($second)['id'] ?? 0), 'the newest works');

        PasswordReset::request($name, '10.0.0.1', $link);
        PasswordReset::request($name, '10.0.0.1', $link);
        $t->eq(1, PasswordReset::flush(), 'at most ' . PasswordReset::PER_ACCOUNT . ' links an hour for one account');

        Db::exec('UPDATE {users} SET active = 0 WHERE id = ?', [$uid]);
        PasswordReset::request($name, '10.0.0.2', $link);
        $t->eq(0, PasswordReset::flush(), 'a switched-off account gets none');
        Db::exec('UPDATE {users} SET active = 1, email = NULL WHERE id = ?', [$uid]);
        Db::exec('DELETE FROM {password_resets} WHERE user_id = ?', [$uid]);
        PasswordReset::request($name, '10.0.0.2', $link);
        $t->eq(0, PasswordReset::flush(), 'nor one without an e-mail address');

        Db::exec('UPDATE {users} SET email = ? WHERE id = ?', ['reset-me@example.test', $uid]);
        for ($i = 0; $i < PasswordReset::PER_ADDRESS + 2; $i++) {
            PasswordReset::request('guess' . $i, '10.0.0.9', $link);
        }
        PasswordReset::request($name, '10.0.0.9', $link);
        $t->eq(0, PasswordReset::flush(), 'an address that keeps asking is stopped');
        PasswordReset::request($name, '10.0.0.3', $link);
        $t->eq(1, PasswordReset::flush(), 'others are not affected');
        PasswordReset::$outbox = null;
    },

    'RST-2 the link sets a new password once: rules kept, lock cleared, open sign-ins ended, old links dead, recorded' => static function (TestKit $t) use ($token, $link) {
        PasswordReset::$outbox = [];
        Settings::set('mail_from', 'no-reply@example.test');
        $who = Db::row('SELECT id, username FROM {users} WHERE active = 1 ORDER BY id DESC LIMIT 1');
        $uid = (int) $who['id'];
        $name = (string) $who['username'];
        Db::exec('UPDATE {users} SET email = ?, failed_logins = 3, locked_until = ?, session_key = ? WHERE id = ?', ['reset-me@example.test', gmdate('Y-m-d H:i:s', time() + 600), str_repeat('a', 40), $uid]);
        PasswordReset::request($name, '10.0.1.1', $link);
        PasswordReset::flush();
        $tok = $token(PasswordReset::$outbox[0]);

        $t->throws(static fn () => PasswordReset::complete($tok, 'New-pass-2026x', 'New-pass-2026y'), 'not the same');
        $t->throws(static fn () => PasswordReset::complete($tok, 'short1', 'short1'), 'at least 10');
        $t->throws(static fn () => PasswordReset::complete('x' . substr($tok, 1), 'New-pass-2026x', 'New-pass-2026x'), 'used or is too old');
        $t->true(! password_verify('New-pass-2026x', (string) Db::value('SELECT password_hash FROM {users} WHERE id = ?', [$uid])), 'nothing changed yet');

        $t->eq($uid, PasswordReset::complete($tok, 'New-pass-2026x', 'New-pass-2026x'), 'the link sets the password');
        $t->true(password_verify('New-pass-2026x', (string) Db::value('SELECT password_hash FROM {users} WHERE id = ?', [$uid])), 'the new password works');
        $u = Db::row('SELECT failed_logins, locked_until, session_key FROM {users} WHERE id = ?', [$uid]);
        $t->true((int) $u['failed_logins'] === 0 && $u['locked_until'] === null, 'the lock after wrong passwords is cleared');
        $t->true($u['session_key'] !== str_repeat('a', 40), 'sign-ins open with the old password are ended');
        $t->true(Auth::attempt($name, 'New-pass-2026x')['ok'], 'and they can sign in');
        $t->throws(static fn () => PasswordReset::complete($tok, 'Other-pass-2026', 'Other-pass-2026'), 'used or is too old');
        $t->eq(2, PasswordReset::flush() + 1, 'they are told by e-mail that the password changed');
        $t->true(str_contains(PasswordReset::$outbox[1]['subject'], 'was changed'), 'in a second e-mail');

        PasswordReset::request($name, '10.0.1.2', $link);
        PasswordReset::flush();
        $old = $token(PasswordReset::$outbox[2]);
        Db::exec('UPDATE {password_resets} SET expires_at = ? WHERE token_hash = ?', [gmdate('Y-m-d H:i:s', time() - 1), hash('sha256', $old)]);
        $t->eq(null, PasswordReset::find($old), 'a link older than ' . PasswordReset::MINUTES . ' minutes is dead');
        $count = static fn () => (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE action = 'auth.reset_done' AND object_id = ?", [$uid]);
        $t->eq(1, method_exists(Db::class, 'unscoped') ? Db::unscoped($count) : $count(), 'the change is in the audit log');
        PasswordReset::$outbox = null;
    },
];
