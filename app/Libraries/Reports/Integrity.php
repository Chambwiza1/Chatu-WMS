<?php

namespace App\Libraries\Reports;

use App\Libraries\Core\Audit;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * The platform's integrity check (core 1.1): every audit chain, the rules on roles and people that keys cannot
 * express, and each organisation's own stock check (Reconcile). Composite foreign keys already stop a row pointing
 * at another organisation's row. Each list holds the problems found; all empty means everything agrees.
 */
final class Integrity
{
    public const LABELS = [
        'audit'  => 'Audit chains (one per organisation and one for the platform)',
        'heads'  => 'Audit chain heads',
        'owners' => 'At most one account owner per organisation',
        'roles'  => 'Platform and organisation roles kept apart',
        'access' => 'People hold only their own organisation\'s roles; owners have every warehouse',
        'stock'  => 'Each organisation\'s stock check (ledger, cost layers, reservations, serials, goods in transit)',
    ];

    public static function run(bool $record = true): array
    {
        $out = Db::unscoped(static function () {
            $out          = [];
            $bad          = Audit::verifyAll();
            $out['audit'] = array_map(static fn ($k, $id) => ($k === 0 ? 'platform' : 'organisation ' . $k) . ': chain breaks at event #' . $id, array_keys($bad), array_values($bad));
            $out['heads'] = array_map(static fn ($r) => 'organisation ' . $r['tenant_id'] . ' has events but no chain head',
                Db::rows('SELECT DISTINCT e.tenant_id FROM {audit_events} e LEFT JOIN {audit_heads} h ON h.tenant_key = e.tenant_id WHERE e.tenant_id IS NOT NULL AND h.tenant_key IS NULL'));
            $out['owners'] = array_map(static fn ($r) => $r['name'] . ': ' . (int) $r['n'] . ' active account owners',
                Db::rows("SELECT t.name, (SELECT COUNT(*) FROM {tenant_users} tu WHERE tu.tenant_id = t.id AND tu.is_account_owner = 1 AND tu.status = 'active') n
                            FROM {tenants} t WHERE t.status <> 'cancelled' HAVING n > 1"));
            $out['roles'] = array_map(static fn ($r) => 'user ' . $r['user_id'] . ' holds ' . $r['code'] . ' with scope ' . $r['scope_type'],
                Db::rows("SELECT ur.user_id, r.code, ur.scope_type FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE (r.level = 'platform') <> (ur.scope_type = 'platform')"));
            $out['access'] = array_merge(
                array_map(static fn ($r) => 'user ' . $r['user_id'] . ' holds role ' . $r['code'] . ' of another organisation or at a warehouse',
                    Db::rows("SELECT ur.user_id, r.code FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.scope_type <> 'platform' AND (r.tenant_id IS NULL OR r.tenant_id <> ur.tenant_id OR ur.scope_type <> 'tenant')")),
                array_map(static fn ($r) => 'account owner ' . $r['user_id'] . ' of organisation ' . $r['tenant_id'] . ' does not have every warehouse',
                    Db::rows('SELECT tenant_id, user_id FROM {tenant_users} WHERE is_account_owner = 1 AND all_warehouses = 0')),
                array_map(static fn ($r) => 'role ' . $r['id'] . ' of an organisation holds the platform permission ' . $r['permission_code'],
                    Db::rows("SELECT r.id, rp.permission_code FROM {roles} r JOIN {role_permissions} rp ON rp.role_id = r.id WHERE r.level = 'tenant' AND rp.permission_code LIKE 'platform.%'"))
            );
            return $out;
        });
        $out['stock'] = [];
        foreach (Db::unscoped(static fn () => Db::rows("SELECT id, name FROM {tenants} WHERE status <> 'cancelled' ORDER BY id")) as $t) {
            $r = Tenancy::run((int) $t['id'], static fn () => Reconcile::run(false));
            unset($r['audit']); // checked above, for every chain
            foreach ($r as $list) {
                foreach ($list as $p) {
                    $out['stock'][] = $t['name'] . ': ' . $p;
                }
            }
        }
        if ($record) {
            Tenancy::run(null, static fn () => Settings::set('last_integrity', json_encode(['at' => Db::now(), 'problems' => array_sum(array_map('count', $out)),
                'detail' => array_map(static fn ($l) => array_slice($l, 0, 20), $out)])));
        }
        return $out;
    }
}
