<?php

namespace App\Libraries\Core;

/**
 * Sign-in, lockout, current user. Five wrong passwords lock the account for 10 minutes.
 */
final class Auth
{
    private static ?array $cli = null;
    private static ?array $cache = null;
    /** True while server code (installer demo data, jobs) acts as a chosen user inside a web request. */
    private static bool $override = false;

    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 10;

    public static function user(): ?array
    {
        if (is_cli() || defined('CHATUWMS_TESTING') || self::$override) {
            return self::$cli;
        }
        if (self::$cache !== null) {
            return self::$cache;
        }
        $id = session('uid');
        if (! $id) {
            return null;
        }
        $u = Db::row('SELECT id, username, name, email, phone, position, active FROM {users} WHERE id = ?', [$id]);
        if (! $u || ! $u['active']) {
            session()->destroy();
            return null;
        }
        self::$cache = $u;
        return $u;
    }

    /** Forget the cached user (after the session's user changes). */
    public static function flush(): void
    {
        self::$cache = null;
        Access::flush();
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    /** Used by CLI jobs, tests and the installer to act as a given user (null = system on the command line, the session user on the web). */
    public static function actAs(?int $userId): void
    {
        self::$cli      = $userId ? Db::row('SELECT id, username, name, email, phone, position, active FROM {users} WHERE id = ?', [$userId]) : null;
        self::$override = $userId !== null; // in a web request, actAs(null) hands back to the signed-in user
        self::$cache    = null;
        Access::flush();
    }

    /** @return array{ok:bool, message?:string, user?:array} */
    public static function attempt(string $username, string $password): array
    {
        $u = Db::row('SELECT * FROM {users} WHERE username = ?', [trim($username)]);
        if (! $u || ! $u['active']) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // even timing
            Audit::log('auth.login', 'user', null, null, null, 'denied', 'Unknown or inactive user: ' . mb_substr($username, 0, 60));
            return ['ok' => false, 'message' => 'Wrong username or password.'];
        }
        if ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time()) {
            Audit::log('auth.login', 'user', $u['id'], null, null, 'denied', 'Account locked');
            return ['ok' => false, 'message' => 'Too many wrong passwords. Try again after ' . self::LOCK_MINUTES . ' minutes.'];
        }
        if (! password_verify($password, $u['password_hash'])) {
            $fails = (int) $u['failed_logins'] + 1;
            $lock  = $fails >= self::MAX_FAILS ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            Db::exec('UPDATE {users} SET failed_logins = ?, locked_until = ? WHERE id = ?', [$lock ? 0 : $fails, $lock, $u['id']]);
            Audit::log('auth.login', 'user', $u['id'], null, null, 'denied', $lock ? 'Wrong password; account locked' : 'Wrong password');
            return ['ok' => false, 'message' => 'Wrong username or password.'];
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE {users} SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        Db::exec('UPDATE {users} SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [Db::now(), $u['id']]);
        return ['ok' => true, 'user' => $u];
    }

    public static function verifyPassword(int $userId, string $password): bool
    {
        $hash = Db::value('SELECT password_hash FROM {users} WHERE id = ?', [$userId]);
        return $hash && password_verify($password, $hash);
    }

    public static function startSession(array $u): void
    {
        session()->regenerate(true);
        session()->set(['uid' => (int) $u['id'], 'last_seen' => time()]);
        self::$cache = null;
        Access::flush();
        Audit::log('auth.login', 'user', $u['id'], null, null, 'success');
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id) {
            Audit::log('auth.logout', 'user', $id);
        }
        session()->destroy();
        self::$cache = null;
    }

    public static function validatePassword(string $p): ?string
    {
        if (strlen($p) < 10) {
            return 'Password must have at least 10 characters.';
        }
        if (! preg_match('/[A-Za-z]/', $p) || ! preg_match('/\d/', $p)) {
            return 'Password must contain letters and numbers.';
        }
        return null;
    }
}
