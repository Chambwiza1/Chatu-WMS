<?php

namespace App\Libraries\Core;

use App\Libraries\Platform\Product;
use App\Libraries\Workflow\Notify;

/**
 * ChatuERP core 1.2: signing in as someone else, to help them or to see what they see. The same file in every product
 * on the shared tenant model; what differs is Platform\Product::SIGNIN_AS:
 *   use     the permission to sign in as one of your people
 *   admins  permissions that make someone an administrator of people: nobody signs in as a holder of any
 *   manage  the permission to open or close support access and to read the list of sign-ins
 *   page    the person's page, to go back to (sprintf with the user id)
 *   blocked more paths (regular expressions) refused while signed in as someone
 *   give    for the update: [permission already held => [permissions to add]] on each organisation's own roles
 *
 * Two kinds:
 *   admin    the organisation's administrator (permission admin.signin_as) signs in as one of their own people.
 *            Never as themselves, the account owner, or anyone who administers people. At most an hour.
 *   support  the platform owner (permission platform.support) signs in as the organisation's account owner, only
 *            while the organisation allows it (tenant setting support_until), and no longer than that.
 *
 * In both: a reason is required; a banner shows on every page; what is saved is saved as that person, and every save
 * is listed against the sign-in (signin_as_log, signin_as_actions) so the organisation sees what was done; passwords
 * and secrets cannot be changed; Stop returns to where the helper came from.
 *
 * The session keeps the helper's own sign-in (uid, skey) and adds `imp`. Auth::user() answers with the person
 * signed in as, after checking the helper's sign-in is still good and the permission to continue still stands.
 */
final class SignInAs
{
    public const ADMIN_MINUTES = 60;
    public const SUPPORT_HOURS = [4 => '4 hours', 24 => '24 hours', 72 => '3 days', 168 => '7 days'];
    /** Paths refused while signed in as someone: their password, other people's passwords, keys, and this feature itself. */
    private const BLOCKED = ['#^account/(password|theme)$#', '#^admin/users/\d+/password$#', '#^admin/support#', '#^signin-as/\d+$#', '#^admin/payments#', '#^notifications#'];

    /** The sign-in-as in this session, or null. */
    public static function current(): ?array
    {
        if (is_cli()) {
            return self::$test;
        }
        $i = session('imp');
        return is_array($i) && ! empty($i['as']) ? $i : null;
    }

    /** @var array|null for tests, which have no session */
    public static ?array $test = null;

    // ----- the organisation allows support -----

    /** Until when the organisation lets platform support sign in (UTC), or null. */
    public static function supportUntil(?int $tenantId = null): ?string
    {
        $u = (string) ($tenantId === null ? Settings::tenant('support_until') : Tenancy::run($tenantId, static fn () => Settings::tenant('support_until')));
        return $u !== '' && $u > gmdate('Y-m-d H:i:s') ? $u : null;
    }

    /** The account owner, or an administrator of people, opens or closes support access. $hours 0 closes it. */
    public static function allowSupport(int $hours): void
    {
        Access::require(Product::SIGNIN_AS['manage']);
        if (self::current()) {
            throw new DeniedException('This cannot be changed while signed in as someone else.');
        }
        if ($hours !== 0 && ! isset(self::SUPPORT_HOURS[$hours])) {
            throw new BizException('Choose how long.');
        }
        $until = $hours ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : '';
        Settings::setTenant('support_until', $until);
        Settings::flushTenant();
        Audit::log($hours ? 'support.allow' : 'support.close', 'tenant', Tenancy::require(), null, $hours ? ['until' => $until, 'hours' => $hours] : null);
    }

    // ----- starting -----

    /** Why the signed-in administrator may not sign in as this person; null when they may. */
    public static function adminBlocked(int $userId): ?string
    {
        if (! Access::can(Product::SIGNIN_AS['use'])) {
            return 'You do not have permission to sign in as someone else (' . Product::SIGNIN_AS['use'] . ').';
        }
        if (self::current()) {
            return 'Stop the sign-in you are in first.';
        }
        if ($userId === Auth::id()) {
            return 'That is you.';
        }
        $tid = Tenancy::require();
        $m   = Db::row("SELECT tu.*, u.active FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id WHERE tu.tenant_id = ? AND tu.user_id = ?", [$tid, $userId]);
        if (! $m) {
            return 'This person is not in the organisation.';
        }
        if ($m['status'] !== 'active' || ! $m['active']) {
            return 'This person is switched off.';
        }
        if ($m['is_account_owner']) {
            return 'Nobody signs in as the account owner.';
        }
        foreach (Product::SIGNIN_AS['admins'] as $perm) {
            if (Access::can($perm, $userId)) {
                return 'Nobody signs in as another administrator.';
            }
        }
        if (Access::platform('platform.tenants', $userId)) {
            return 'Nobody signs in as another administrator.';
        }
        return null;
    }

    /** An administrator signs in as one of their people. Returns the log id. */
    public static function startAdmin(int $userId, string $reason): int
    {
        if ($why = self::adminBlocked($userId)) {
            throw new DeniedException($why);
        }
        return self::start('admin', Tenancy::require(), $userId, $reason, gmdate('Y-m-d H:i:s', time() + self::ADMIN_MINUTES * 60), Tenancy::require());
    }

    /** The platform owner signs in as the organisation's account owner, while the organisation allows it. */
    public static function startSupport(int $tenantId, string $reason): int
    {
        Access::requirePlatform('platform.support');
        if (self::current()) {
            throw new DeniedException('Stop the sign-in you are in first.');
        }
        $until = self::supportUntil($tenantId);
        if (! $until) {
            throw new DeniedException('This organisation has not allowed support to sign in. Ask its administrator to open it under Set up > Support access.');
        }
        $t = Db::row('SELECT status FROM {tenants} WHERE id = ?', [$tenantId]);
        if (! $t || $t['status'] === 'cancelled') {
            throw new BizException('The organisation is cancelled.');
        }
        $owner = Db::unscoped(static fn () => (int) Db::value("SELECT tu.user_id FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id AND u.active = 1
                                                                 WHERE tu.tenant_id = ? AND tu.is_account_owner = 1 AND tu.status = 'active' LIMIT 1", [$tenantId]));
        if (! $owner) {
            throw new BizException('The organisation has no active account owner to sign in as.');
        }
        if ($owner === Auth::id()) {
            throw new BizException('You are this organisation\'s account owner: choose it at the top of the page.');
        }
        return self::start('support', $tenantId, $owner, $reason, $until, null);
    }

    private static function start(string $kind, int $tenantId, int $asUser, string $reason, string $until, ?int $backTenant): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BizException('Give the reason: it is kept with the record of this sign-in.');
        }
        $me   = Auth::user();
        $ip   = is_cli() ? null : (string) service('request')->getIPAddress();
        $name = (string) Db::value('SELECT name FROM {users} WHERE id = ?', [$asUser]);
        $id   = Tenancy::run($tenantId, static function () use ($kind, $tenantId, $asUser, $reason, $me, $ip, $name) {
            $id = Db::insert('signin_as_log', ['tenant_id' => $tenantId, 'kind' => $kind, 'by_user_id' => (int) $me['id'], 'by_name' => $kind === 'support' ? 'Chatu support (' . $me['name'] . ')' : (string) $me['name'],
                'as_user_id' => $asUser, 'reason' => mb_substr($reason, 0, 255), 'ip' => $ip, 'started_at' => Db::now()]);
            Audit::log('signin_as.start', 'user', $asUser, null, ['kind' => $kind, 'as' => $name, 'log' => $id], 'success', mb_substr($reason, 0, 255));
            if ($kind === 'support') {   // the organisation is told at once
                Notify::user($asUser, 'support', 'Chatu support signed in to help', $reason, '/admin/support');
            }
            return $id;
        });
        if ($kind === 'support') {
            Tenancy::run(null, static fn () => Audit::log('signin_as.start', 'tenant', $tenantId, null, ['as' => $name, 'log' => $id], 'success', mb_substr($reason, 0, 255)));
        }
        $imp = ['kind' => $kind, 'by' => (int) $me['id'], 'by_name' => (string) $me['name'], 'as' => $asUser, 'tenant' => $tenantId, 'log' => $id, 'until' => $until, 'back' => $backTenant, 'reason' => mb_substr($reason, 0, 120)];
        if (is_cli()) {
            self::$test = $imp;
        } else {
            session()->set(['imp' => $imp, 'tid' => $tenantId]);
        }
        Auth::flush();
        return $id;
    }

    // ----- while it lasts -----

    /** Is the sign-in-as still allowed to go on? Null, or how it ended. */
    public static function ended(array $imp): ?string
    {
        if ($imp['until'] < gmdate('Y-m-d H:i:s')) {
            return 'time';
        }
        if ($imp['kind'] === 'support' && ! self::supportUntil((int) $imp['tenant'])) {
            return 'closed';   // the organisation closed support access
        }
        $ok = Db::unscoped(static fn () => Db::value("SELECT 1 FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id AND u.active = 1 WHERE tu.tenant_id = ? AND tu.user_id = ? AND tu.status = 'active'", [$imp['tenant'], $imp['as']]));
        return $ok ? null : 'gone';
    }

    /** A page that changes something is asked for: refuse the few that are never allowed, and list the rest. */
    public static function record(string $method, string $path): ?string
    {
        $imp = self::current();
        if (! $imp || strtoupper($method) === 'GET' || strtoupper($method) === 'HEAD') {
            return null;
        }
        $path = trim($path, '/');
        if ($path === 'signin-as/stop' || $path === 'logout') {
            return null;
        }
        foreach (array_merge(self::BLOCKED, Product::SIGNIN_AS['blocked'] ?? []) as $re) {
            if (preg_match($re, $path)) {
                return 'This cannot be done while signed in as someone else: passwords, keys and support access stay with the person.';
            }
        }
        Tenancy::run((int) $imp['tenant'], static function () use ($imp, $method, $path) {
            Db::insert('signin_as_actions', ['tenant_id' => $imp['tenant'], 'log_id' => $imp['log'], 'at' => Db::now(), 'method' => strtoupper(substr($method, 0, 8)), 'path' => mb_substr($path, 0, 190)]);
            Db::exec('UPDATE {signin_as_log} SET actions = actions + 1 WHERE tenant_id = ? AND id = ?', [$imp['tenant'], $imp['log']]);
        });
        return null;
    }

    /** Stop: back to the helper's own sign-in. Returns where to send them. */
    public static function stop(string $how = 'stopped'): string
    {
        $imp = self::current();
        if (! $imp) {
            return '/';
        }
        Tenancy::run((int) $imp['tenant'], static function () use ($imp, $how) {
            Db::exec('UPDATE {signin_as_log} SET ended_at = ?, ended_how = ? WHERE tenant_id = ? AND id = ? AND ended_at IS NULL', [Db::now(), $how, $imp['tenant'], $imp['log']]);
        });
        if (is_cli()) {
            self::$test = null;
        } else {
            session()->remove('imp');
            if ($imp['back']) {
                session()->set('tid', (int) $imp['back']);
            } else {
                session()->remove('tid');
            }
        }
        Auth::flush();
        return $imp['kind'] === 'support' ? '/platform/tenants/' . (int) $imp['tenant'] : '/' . ltrim(sprintf(Product::SIGNIN_AS['page'], (int) $imp['as']), '/');
    }

    // ----- what the organisation sees -----

    public static function history(int $limit = 100): array
    {
        Access::require(Product::SIGNIN_AS['manage']);
        return Db::rows('SELECT l.*, u.name as_name FROM {signin_as_log} l LEFT JOIN {users} u ON u.id = l.as_user_id WHERE l.tenant_id = ? ORDER BY l.id DESC LIMIT ' . (int) $limit, [Tenancy::require()]);
    }

    public static function actions(int $logId): array
    {
        Access::require(Product::SIGNIN_AS['manage']);
        $tid = Tenancy::require();
        if (! Db::value('SELECT 1 FROM {signin_as_log} WHERE tenant_id = ? AND id = ?', [$tid, $logId])) {
            throw new NotFoundException('Sign-in not found.');
        }
        return Db::rows('SELECT at, method, path FROM {signin_as_actions} WHERE tenant_id = ? AND log_id = ? ORDER BY id', [$tid, $logId]);
    }
}
