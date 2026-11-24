<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\NotFoundException;
use App\Libraries\Core\Tenancy;
use App\Libraries\Setup\Seed;

/**
 * Organisations on the platform. The platform owner sees each organisation's name, status and counts, never its
 * sales, people or settings. Self-service sign-up (after 2.0.0) will call create() as well.
 */
final class Tenants
{
    public const STATUSES = ['trial' => 'Trial', 'active' => 'Active', 'past_due' => 'Payment overdue', 'suspended' => 'Suspended (read-only)', 'cancelled' => 'Cancelled'];

    /**
     * New organisation with its first location and its account owner (an Administrator).
     * $d: name, slug?, country?, timezone?, status?, location_code, location_name, location_town?,
     *     owner: either ['user_id' => existing login] or ['username', 'name', 'email', 'password']
     * $system: called by the installer (no signed-in platform owner).
     */
    public static function create(array $d, bool $system = false): array
    {
        if (! $system) {
            Access::requirePlatform('platform.tenants');
            if (Tenancy::single()) {
                throw new BizException('This is a single-company installation: it has exactly one organisation.');
            }
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BizException('Give the organisation\'s name.');
        }
        $slug = self::slug((string) ($d['slug'] ?? '') ?: $name);
        $tz   = (string) ($d['timezone'] ?? 'Africa/Lusaka');
        if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new BizException('Unknown time zone ' . $tz . '.');
        }
        $status = (string) ($d['status'] ?? 'active');
        if (! isset(self::STATUSES[$status]) || $status === 'cancelled') {
            throw new BizException('Unknown status.');
        }
        $owner = $d['owner'] ?? [];
        if (empty($owner['user_id'])) {
            if ($err = Auth::validatePassword((string) ($owner['password'] ?? ''))) {
                throw new BizException('Account owner password: ' . $err);
            }
            if (! preg_match('/^[a-z0-9._-]{3,60}$/', strtolower(trim((string) ($owner['username'] ?? ''))))) {
                throw new BizException('Account owner username: 3 to 60 letters, digits, dot, dash or underscore.');
            }
            if (trim((string) ($owner['name'] ?? '')) === '') {
                throw new BizException('Give the account owner\'s name.');
            }
            $email = trim((string) ($owner['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BizException('The account owner\'s e-mail address is not valid.');
            }
        }
        return Db::atomic(static function () use ($d, $name, $slug, $tz, $status, $owner) {
            if (Db::value('SELECT 1 FROM {tenants} WHERE slug = ?', [$slug])) {
                throw new BizException('The short name "' . $slug . '" is already used by another organisation.');
            }
            $tid = Db::insert('tenants', [
                'slug' => $slug, 'name' => mb_substr($name, 0, 150), 'status' => $status, 'country' => strtoupper(substr((string) ($d['country'] ?? 'ZM'), 0, 2)) ?: 'ZM',
                'timezone' => $tz, 'trial_ends_at' => $status === 'trial' ? gmdate('Y-m-d H:i:s', time() + 14 * 86400) : null, 'created_at' => Db::now(),
            ]);
            if (! empty($owner['user_id'])) {
                $uid = (int) $owner['user_id'];
                if (! Db::value('SELECT 1 FROM {users} WHERE id = ? AND active = 1', [$uid])) {
                    throw new BizException('Unknown account owner.');
                }
            } else {
                $username = strtolower(trim((string) $owner['username']));
                if (Db::value('SELECT 1 FROM {users} WHERE username = ?', [$username])) {
                    throw new BizException('The username ' . $username . ' is taken.');
                }
                $email = trim((string) ($owner['email'] ?? '')) ?: null;
                if ($email && Db::value('SELECT 1 FROM {users} WHERE email = ?', [$email])) {
                    throw new BizException('Someone already signs in with ' . $email . '.');
                }
                $uid = Seed::login($username, trim((string) $owner['name']), (string) $owner['password'], $email, 'Administrator');
            }
            $platformLog = ['slug' => $slug, 'name' => $name, 'status' => $status];
            $result = Tenancy::run($tid, static function () use ($tid, $uid, $d, $name) {
                $loc = Db::insert('locations', [
                    'tenant_id' => $tid, 'code' => strtoupper(trim((string) ($d['location_code'] ?? 'HQ'))) ?: 'HQ', 'name' => trim((string) ($d['location_name'] ?? '')) ?: 'Head office',
                    'town' => trim((string) ($d['location_town'] ?? '')) ?: null, 'timezone' => (string) ($d['timezone'] ?? 'Africa/Lusaka'), 'active' => 1, 'created_at' => Db::now(),
                ]);
                \App\Libraries\Dining\Setup::starter($tid, $loc); // VAT classes, Kitchen and Bar stations, a first floor
                Seed::member($tid, $uid, [['tenant_admin', null]], true);
                Audit::log('tenant.create', 'tenant', $tid, null, ['name' => $name, 'owner' => $uid, 'location' => $loc]);
                return ['tenant' => $tid, 'owner' => $uid, 'location' => $loc];
            });
            Tenancy::run(null, static fn () => Audit::log('tenant.create', 'tenant', $tid, null, $platformLog));
            return $result;
        });
    }

    /** Every organisation with counts only (platform owner). */
    public static function list(): array
    {
        Access::requirePlatform('platform.tenants');
        return Db::unscoped(static fn () => Db::rows(
            "SELECT t.*, (SELECT COUNT(*) FROM {tenant_users} tu WHERE tu.tenant_id = t.id AND tu.status = 'active') users,
                    (SELECT COUNT(*) FROM {locations} l WHERE l.tenant_id = t.id AND l.active = 1) locations,
                    (SELECT COUNT(*) FROM {devices} d WHERE d.tenant_id = t.id AND d.revoked_at IS NULL AND d.paired_at IS NOT NULL) devices,
                    (SELECT u.name FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id WHERE tu.tenant_id = t.id AND tu.is_account_owner = 1 LIMIT 1) owner_name
               FROM {tenants} t ORDER BY t.status = 'cancelled', t.name"
        ));
    }

    public static function get(int $id): array
    {
        Access::requirePlatform('platform.tenants');
        $t = Db::row('SELECT * FROM {tenants} WHERE id = ?', [$id]);
        if (! $t) {
            throw new NotFoundException('Organisation not found.');
        }
        return $t;
    }

    public static function setStatus(int $id, string $status, string $reason): void
    {
        $t = self::get($id);
        if (! isset(self::STATUSES[$status])) {
            throw new BizException('Unknown status.');
        }
        if (trim($reason) === '') {
            throw new BizException('Give the reason for the change; it goes into the audit log.');
        }
        if ($t['status'] === 'cancelled') {
            throw new BizException('A cancelled organisation cannot be reopened here.');
        }
        Db::atomic(static function () use ($t, $status, $reason) {
            Db::exec('UPDATE {tenants} SET status = ? WHERE id = ?', [$status, $t['id']]);
            Audit::log('tenant.status', 'tenant', $t['id'], ['status' => $t['status']], ['status' => $status], 'success', $reason);
            Tenancy::run((int) $t['id'], static fn () => Audit::log('tenant.status', 'tenant', $t['id'], ['status' => $t['status']], ['status' => $status], 'success', $reason));
        });
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        $s = trim(substr(trim((string) $s, '-'), 0, 40), '-');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $s)) {
            throw new BizException('Short name: 2 to 40 letters, digits or dashes.');
        }
        return $s;
    }
}
