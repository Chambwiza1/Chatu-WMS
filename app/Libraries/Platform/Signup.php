<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\PasswordReset;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use Throwable;

/**
 * ChatuERP core 1.5.4: sign-up by the customer, on the hosted service. The same file in every product; what differs
 * is in Platform\Product:
 *   Product::SIGNUP_MODE      'self': the customer creates the organisation, after opening an e-mailed link, and it
 *                             starts on a trial. 'request': the form sums up what they need and the platform owner
 *                             is told; the owner creates the organisation.
 *   Product::signupFields()   the product's own questions: [key => ['label', 'options' => [value => label]?, 'type' => 'number'|'text'?, 'hint'?, 'required'?]]
 *   Product::signupCreate()   self only: creates the organisation from a confirmed sign-up; returns ['tenant' => id, 'owner' => user id].
 * The platform owner can close sign-up (setting signup_open) and sees every sign-up under Platform > Sign-ups.
 */
final class Signup
{
    public const HOURS      = 24;   // the e-mailed link is good this long
    public const PER_IP     = 5;    // sign-ups an hour from one address
    public const STATUSES   = ['pending' => 'Waiting for the e-mailed link', 'requested' => 'Request to answer', 'done' => 'Organisation created', 'dismissed' => 'Dismissed'];

    public static function mode(): string
    {
        return Product::SIGNUP_MODE;
    }

    /** False until the database update has added the table. */
    public static function ready(): bool
    {
        try {
            Db::value('SELECT 1 FROM {signups} LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Is the page open? Only on the hosted service, while the platform owner keeps it open; self-service also needs e-mail. */
    public static function open(): bool
    {
        if (Tenancy::single() || Settings::get('signup_open', '1') !== '1' || ! self::ready()) {
            return false;
        }
        return self::mode() === 'request' || PasswordReset::available();
    }

    /**
     * The form is sent. Self: a link is e-mailed and nothing exists until it is opened. Request: the platform owner is
     * told. Returns the row's id. $link turns a token into the address to open.
     */
    public static function submit(array $d, string $ip, callable $link): int
    {
        if (! self::open()) {
            throw new BizException('Sign-up is not open. Please contact us.');
        }
        $mode = self::mode();
        $org  = trim((string) ($d['org_name'] ?? ''));
        $name = trim((string) ($d['contact_name'] ?? ''));
        $mail = strtolower(trim((string) ($d['email'] ?? '')));
        $tel  = trim((string) ($d['phone'] ?? ''));
        if ($org === '' || mb_strlen($org) > 150) {
            throw new BizException('Give the name of your organisation.');
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new BizException('Give your own name.');
        }
        if (! filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($mail) > 190) {
            throw new BizException('Give an e-mail address we can reach you at.');
        }
        if ($tel !== '' && ! preg_match('/^\+?[0-9 ()-]{7,25}$/', $tel)) {
            throw new BizException('The phone number is not valid.');
        }
        if ($mode === 'request' && $tel === '') {
            throw new BizException('Give a phone number we can reach you on.');
        }
        if (empty($d['agree'])) {
            throw new BizException('Tick the box to agree that we may keep these details to set up and run your account.');
        }
        $answers = [];
        foreach (Product::signupFields() as $key => $f) {
            $v = trim((string) ($d['q'][$key] ?? ''));
            if ($v === '' && ! empty($f['required'])) {
                throw new BizException($f['label'] . ': please answer.');
            }
            if ($v !== '' && isset($f['options']) && ! isset($f['options'][$v])) {
                throw new BizException($f['label'] . ': choose from the list.');
            }
            if ($v !== '' && ($f['type'] ?? '') === 'number' && (! ctype_digit($v) || (int) $v > 100000)) {
                throw new BizException($f['label'] . ': give a whole number.');
            }
            $answers[$key] = mb_substr($v, 0, 500);
        }
        $username = null;
        $hash     = null;
        if ($mode === 'self') {
            $username = strtolower(trim((string) ($d['username'] ?? '')));
            if (! preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                throw new BizException('Username: 3 to 60 letters, digits, dot, dash or underscore.');
            }
            if ($err = Auth::validatePassword((string) ($d['password'] ?? ''))) {
                throw new BizException($err);
            }
            if ((string) $d['password'] !== (string) ($d['password2'] ?? '')) {
                throw new BizException('The two passwords are not the same.');
            }
            if (Db::value('SELECT 1 FROM {users} WHERE username = ?', [$username])
                || Db::value("SELECT 1 FROM {signups} WHERE username = ? AND status = 'pending' AND expires_at > ? AND email <> ?", [$username, Db::now(), $mail])) {
                throw new BizException('The username ' . $username . ' is taken. Choose another.');
            }
            if (Db::value('SELECT 1 FROM {users} WHERE email = ?', [$mail])) {
                throw new BizException('Someone already signs in with ' . $mail . '. Sign in, or use "Forgotten your password?".');
            }
            $hash = password_hash((string) $d['password'], PASSWORD_DEFAULT);
        }
        if ((int) Db::value('SELECT COUNT(*) FROM {signups} WHERE ip = ? AND created_at > ?', [$ip, gmdate('Y-m-d H:i:s', time() - 3600)]) >= self::PER_IP) {
            throw new BizException('Too many sign-ups from this connection. Please try again in an hour.');
        }
        $token = $mode === 'self' ? bin2hex(random_bytes(32)) : null;
        if ($mode === 'self') {   // a newer sign-up for the same address retires the older links
            Db::exec("UPDATE {signups} SET status = 'dismissed', note = 'Replaced by a newer sign-up', token_hash = NULL, password_hash = NULL WHERE email = ? AND status = 'pending'", [$mail]);
        }
        $id = Db::insert('signups', [
            'mode' => $mode, 'status' => $mode === 'self' ? 'pending' : 'requested', 'org_name' => $org, 'contact_name' => $name, 'email' => $mail, 'phone' => $tel !== '' ? $tel : null,
            'username' => $username, 'password_hash' => $hash, 'answers' => json_encode($answers), 'token_hash' => $token ? hash('sha256', $token) : null, 'ip' => mb_substr($ip, 0, 45),
            'created_at' => Db::now(), 'expires_at' => $token ? gmdate('Y-m-d H:i:s', time() + self::HOURS * 3600) : null,
        ]);
        Tenancy::run(null, static fn () => Audit::log('signup.' . ($mode === 'self' ? 'start' : 'request'), 'signup', $id, null, ['organisation' => $org, 'email' => $mail]));
        if ($mode === 'self') {
            PasswordReset::queue($mail, Product::NAME . ': confirm your sign-up',
                "Hello {$name},\n\nOpen this link within " . self::HOURS . " hours to create {$org} on " . Product::NAME . ":\n\n" . $link($token)
                . "\n\nIf you did not ask for this, ignore this message: nothing is created until the link is opened.\n");
        } else {
            PasswordReset::queue($mail, Product::NAME . ': we have your request',
                "Hello {$name},\n\nThank you. We have your request for {$org} and will contact you on {$tel} or at this address to set up your account.\n\n" . self::summary($answers) . "\n");
            self::tellOwners('New ' . Product::NAME . ' request: ' . $org, "{$name} ({$mail}, {$tel}) asks for " . Product::NAME . " for {$org}.\n\n" . self::summary($answers) . "\n\nAnswer it under Platform > Sign-ups.\n");
        }
        return $id;
    }

    /** The sign-up a link belongs to, while it is good. */
    public static function find(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $s = Db::row("SELECT * FROM {signups} WHERE token_hash = ? AND status = 'pending' AND expires_at > ?", [hash('sha256', $token), Db::now()]);
        return $s ?: null;
    }

    /** The link is opened and confirmed: the organisation is created on a trial, with its owner. Returns the sign-up row. */
    public static function confirm(string $token): array
    {
        return Db::atomic(static function () use ($token) {
            $s = self::find($token);
            $s = $s ? Db::row('SELECT * FROM {signups} WHERE id = ? AND status = ? FOR UPDATE', [$s['id'], 'pending']) : null;
            if (! $s) {
                throw new BizException('This link has been used or is too old. Sign up again.');
            }
            $s['answers'] = json_decode((string) $s['answers'], true) ?: [];
            $made = Product::signupCreate($s);
            Db::exec('UPDATE {users} SET password_hash = ? WHERE id = ?', [$s['password_hash'], $made['owner']]);
            Db::exec("UPDATE {signups} SET status = 'done', tenant_id = ?, decided_at = ?, token_hash = NULL, password_hash = NULL WHERE id = ?", [$made['tenant'], Db::now(), $s['id']]);
            Tenancy::run(null, static fn () => Audit::log('signup.confirm', 'signup', (int) $s['id'], null, ['organisation' => $s['org_name'], 'tenant' => $made['tenant']]));
            self::tellOwners('New ' . Product::NAME . ' organisation: ' . $s['org_name'], "{$s['contact_name']} ({$s['email']}" . ($s['phone'] ? ', ' . $s['phone'] : '') . ") signed up {$s['org_name']}. It is on a trial.\n\n" . self::summary($s['answers']) . "\n");
            return $s + ['tenant' => $made['tenant']];
        });
    }

    // ----- platform owner -----

    public static function list(?string $status = null): array
    {
        Access::requirePlatform('platform.tenants');
        $rows = $status ? Db::rows('SELECT * FROM {signups} WHERE status = ? ORDER BY id DESC LIMIT 300', [$status]) : Db::rows('SELECT * FROM {signups} ORDER BY id DESC LIMIT 300');
        foreach ($rows as &$r) {
            $r['answers'] = json_decode((string) $r['answers'], true) ?: [];
            $r['summary'] = self::summary($r['answers']);
        }
        return $rows;
    }

    /** Requests waiting for the platform owner. */
    public static function waiting(): int
    {
        return self::ready() ? (int) Db::value("SELECT COUNT(*) FROM {signups} WHERE status = 'requested'") : 0;
    }

    /** The platform owner answers a request: done (the organisation was created) or dismissed, with a note. */
    public static function decide(int $id, string $status, string $note, ?int $tenantId = null): void
    {
        Access::requirePlatform('platform.tenants');
        if (! in_array($status, ['done', 'dismissed'], true)) {
            throw new BizException('Unknown answer.');
        }
        $s = Db::row('SELECT * FROM {signups} WHERE id = ?', [$id]);
        if (! $s || ! in_array($s['status'], ['requested', 'pending'], true)) {
            throw new BizException('This sign-up is already answered.');
        }
        if ($status === 'dismissed' && trim($note) === '') {
            throw new BizException('Say why it is dismissed.');
        }
        Db::exec('UPDATE {signups} SET status = ?, note = ?, tenant_id = ?, decided_at = ?, decided_by = ?, token_hash = NULL, password_hash = NULL WHERE id = ?',
            [$status, mb_substr(trim($note), 0, 255) ?: null, $tenantId, Db::now(), Auth::id(), $id]);
        Tenancy::run(null, static fn () => Audit::log('signup.' . $status, 'signup', $id, null, ['organisation' => $s['org_name']], 'success', mb_substr(trim($note), 0, 255) ?: null));
    }

    /** Housekeeping: links never opened are dropped a week after they stop working. */
    public static function purge(): int
    {
        return self::ready() ? Db::exec("DELETE FROM {signups} WHERE status = 'pending' AND expires_at < ?", [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]) : 0;
    }

    /** The product's questions and their answers, as lines of text. */
    public static function summary(array $answers): string
    {
        $out = [];
        foreach (Product::signupFields() as $key => $f) {
            $v = (string) ($answers[$key] ?? '');
            if ($v !== '') {
                $out[] = $f['label'] . ': ' . ($f['options'][$v] ?? $v);
            }
        }
        return implode("\n", $out);
    }

    /** E-mail everyone who manages organisations on the platform. */
    private static function tellOwners(string $subject, string $body): void
    {
        $to = Db::rows("SELECT DISTINCT u.email FROM {users} u JOIN {user_roles} ur ON ur.user_id = u.id AND ur.tenant_id IS NULL AND ur.scope_type = 'platform'
                          JOIN {role_permissions} rp ON rp.role_id = ur.role_id AND rp.permission_code = 'platform.tenants' WHERE u.active = 1 AND u.email IS NOT NULL AND u.email <> ''");
        foreach ($to as $r) {
            PasswordReset::queue((string) $r['email'], $subject, $body);
        }
    }
}
