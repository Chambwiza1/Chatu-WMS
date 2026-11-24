<?php

namespace App\Libraries\Core;

use Throwable;

/**
 * ChatuERP core 1.5.1 (B12): a forgotten password, reset by e-mail. The same file in every product.
 *
 * - The answer to a request is always the same, whether or not the account exists, so nobody can find out who has one.
 * - The link holds 32 random bytes; only its SHA-256 is kept. It works once, for MINUTES, and a newer request
 *   retires the older links. At most PER_ACCOUNT requests an hour for one account and PER_ADDRESS from one address.
 * - A new password clears the lock after wrong passwords and ends the account's open sign-ins (its session key changes).
 * - The e-mail is account mail, so it goes through the installation's own mailer (mail_from and the smtp_* settings),
 *   after the page has been answered. Without a sender address, the pages say to ask an administrator instead.
 * - Accounts that are switched off, or have no e-mail address, get no link: an administrator sets their password.
 */
final class PasswordReset
{
    public const MINUTES     = 60;
    public const PER_ACCOUNT = 3;
    public const PER_ADDRESS = 10;

    /** Tests: when this is an array, e-mails are put here instead of being sent. */
    public static ?array $outbox = null;
    /** @var list<array{to:string,subject:string,body:string}> e-mails waiting until the page is answered */
    private static array $queue = [];

    /** Can this installation e-mail a link at all? */
    public static function available(): bool
    {
        return trim((string) Settings::get('mail_from')) !== '' && self::ready();
    }

    /** False until the database update has added the table. */
    public static function ready(): bool
    {
        try {
            Db::value('SELECT 1 FROM {password_resets} LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Ask for a link. Says nothing about whether the account exists. $link turns a token into the address to open.
     */
    public static function request(string $login, string $ip, callable $link): void
    {
        $login = trim($login);
        $ip    = mb_substr($ip, 0, 45);
        $now   = time();
        $since = gmdate('Y-m-d H:i:s', $now - 3600);
        Db::exec('DELETE FROM {password_resets} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', $now - 7 * 86400)]);
        if ($login === '') {
            return;
        }
        if ((int) Db::value('SELECT COUNT(*) FROM {password_resets} WHERE ip = ? AND created_at > ?', [$ip, $since]) >= self::PER_ADDRESS) {
            Audit::log('auth.reset_request', 'user', null, null, null, 'denied', 'Too many requests from one address');
            return;
        }
        $u = self::lookup($login);
        if (! $u || ! $u['active'] || ! filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) {
            Db::insert('password_resets', ['user_id' => null, 'token_hash' => null, 'ip' => $ip, 'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now)]);
            Audit::log('auth.reset_request', 'user', $u['id'] ?? null, null, null, 'denied', ! $u ? 'Unknown user: ' . mb_substr($login, 0, 60) : (! $u['active'] ? 'Account switched off' : 'No e-mail address on the account'));
            return;
        }
        if ((int) Db::value('SELECT COUNT(*) FROM {password_resets} WHERE user_id = ? AND created_at > ?', [$u['id'], $since]) >= self::PER_ACCOUNT) {
            Audit::log('auth.reset_request', 'user', $u['id'], null, null, 'denied', 'Too many requests for one account');
            return;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::atomic(static function () use ($u, $token, $ip, $now) {
            Db::exec('UPDATE {password_resets} SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s', $now), $u['id']]);
            Db::insert('password_resets', [
                'user_id' => $u['id'], 'token_hash' => hash('sha256', $token), 'ip' => $ip,
                'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now + self::MINUTES * 60),
            ]);
            Audit::log('auth.reset_request', 'user', $u['id'], null, null, 'success', 'Link sent to the account\'s e-mail address');
        });
        $app = (string) (Settings::get('app_name') ?: 'Chatu');
        self::$queue[] = [
            'to'      => (string) $u['email'],
            'subject' => $app . ': choose a new password',
            'body'    => "Hello {$u['name']},\n\nSomeone asked for a new password for your {$app} sign-in ({$u['username']}).\n\n"
                . 'Open this link within ' . self::MINUTES . " minutes to choose one:\n" . $link($token) . "\n\n"
                . "The link works once. If you did not ask for it, ignore this e-mail: your password stays as it is.\n",
        ];
    }

    /** The account a link belongs to, or null when the link is unknown, used, replaced or too old. */
    public static function find(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{40,50}$/', $token)) {
            return null;
        }
        $r = Db::row(
            'SELECT r.id reset_id, u.id, u.username, u.name, u.email FROM {password_resets} r JOIN {users} u ON u.id = r.user_id
              WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.active = 1',
            [hash('sha256', $token), gmdate('Y-m-d H:i:s')]
        );
        return $r ?: null;
    }

    /** Set the new password with a link. Returns the user id. */
    public static function complete(string $token, string $password, string $again): int
    {
        if ($password !== $again) {
            throw new BizException('The two passwords are not the same.');
        }
        if ($err = Auth::validatePassword($password)) {
            throw new BizException($err);
        }
        $u = Db::atomic(static function () use ($token, $password) {
            $u = self::find($token);
            if (! $u || ! Db::row('SELECT id FROM {password_resets} WHERE id = ? AND used_at IS NULL FOR UPDATE', [$u['reset_id']])) {
                throw new BizException('This link has been used or is too old. Ask for a new one.');
            }
            Db::exec('UPDATE {users} SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            Db::exec('UPDATE {password_resets} SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s'), $u['id']]);
            Audit::log('auth.reset_done', 'user', $u['id'], null, null, 'success', 'Password set with an e-mailed link');
            return $u;
        });
        try {
            // a new session key ends the sign-ins open with the old password (one sign-in per account, core 1.1)
            Db::exec('UPDATE {users} SET session_key = ? WHERE id = ?', [bin2hex(random_bytes(20)), $u['id']]);
        } catch (Throwable $e) {
            // before core 1.1's column
        }
        $app = (string) (Settings::get('app_name') ?: 'Chatu');
        self::$queue[] = [
            'to'      => (string) $u['email'],
            'subject' => $app . ': your password was changed',
            'body'    => "Hello {$u['name']},\n\nThe password of your {$app} sign-in ({$u['username']}) was changed with a link sent to this address.\n\n"
                . "If that was not you, tell your administrator at once.\n",
        ];
        return (int) $u['id'];
    }

    /** Send what is waiting. Controllers call sendAfterResponse(); tests and jobs may call this. */
    public static function flush(): int
    {
        $sent = 0;
        foreach (self::$queue as $m) {
            if (self::$outbox !== null) {
                self::$outbox[] = $m;
                $sent++;
                continue;
            }
            try {
                $from  = (string) Settings::get('mail_from');
                $email = service('email');
                $smtp  = (string) Settings::get('smtp_host');
                $email->initialize($smtp !== '' ? [
                    'protocol' => 'smtp', 'SMTPHost' => $smtp, 'SMTPPort' => (int) (Settings::get('smtp_port') ?: 587),
                    'SMTPUser' => (string) Settings::get('smtp_user'), 'SMTPPass' => (string) Settings::get('smtp_pass'),
                    'SMTPCrypto' => (string) Settings::get('smtp_crypto', 'tls'), 'SMTPTimeout' => 15, 'mailType' => 'text',
                ] : ['protocol' => 'mail', 'mailType' => 'text']);
                $email->clear();
                $email->setFrom($from, (string) Settings::get('mail_from_name'));
                $email->setTo($m['to']);
                $email->setSubject($m['subject']);
                $email->setMessage($m['body']);
                $email->setMailType('text');
                if ($email->send(false)) {
                    $sent++;
                } else {
                    log_message('error', 'Password e-mail not sent to ' . $m['to']);
                }
            } catch (Throwable $e) {
                log_message('error', 'Password e-mail failed: ' . $e->getMessage());
            }
        }
        self::$queue = [];
        return $sent;
    }

    /** Send once the page has been answered, so the answer takes the same time whether or not there is an account. */
    public static function sendAfterResponse(): void
    {
        if (! self::$queue || self::$outbox !== null) {
            self::flush();
            return;
        }
        register_shutdown_function(static function () {
            if (function_exists('session_write_close')) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            self::flush();
        });
    }

    /** By username, else by an e-mail address that exactly one account has. */
    private static function lookup(string $login): ?array
    {
        $u = Db::row('SELECT id, username, name, email, active FROM {users} WHERE username = ?', [strtolower($login)])
            ?: Db::row('SELECT id, username, name, email, active FROM {users} WHERE username = ?', [$login]);
        if ($u) {
            return $u;
        }
        $rows = Db::rows('SELECT id, username, name, email, active FROM {users} WHERE email IS NOT NULL AND email = ? LIMIT 2', [$login]);
        return count($rows) === 1 ? $rows[0] : null;
    }
}
