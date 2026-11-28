<?php

use App\Commands\TestKit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Platform\Billing;
use App\Libraries\Platform\PlanRequests;
use App\Libraries\Platform\Product;

/** core 1.5.5: an organisation chooses its plan and asks for its invoice; the account owner approves others' requests. */

/** The platform owner of the test installation. */
function pr_pat(): void
{
    $id = (int) Db::value("SELECT id FROM {users} WHERE username = 'pat'");
    if (! $id) {
        $id = \App\Libraries\Setup\Seed::login('pat', 'Pat Platform', \App\Libraries\Setup\Demo::PASSWORD, 'pat@chatu.example', 'Platform owner');
        \App\Libraries\Setup\Seed::platformRole($id);
    }
    \App\Libraries\Core\Tenancy::set(null);
    \App\Libraries\Core\Auth::actAs($id);
}

/** A clean start: no unpaid invoice, no waiting request; two plans on offer and one that is not. */
function pr_start(TestKit $t, string $org): array
{
    pr_pat();
    $tid = (int) Db::value('SELECT MIN(id) FROM {tenants}');
    foreach (Db::rows("SELECT id FROM {platform_invoices} WHERE tenant_id = ? AND status = 'issued'", [$tid]) as $i) {
        Billing::void((int) $i['id'], 'test: clean start');
    }
    Db::exec("UPDATE {platform_plan_requests} SET status = 'cancelled' WHERE tenant_id = ? AND status = 'pending'", [$tid]);
    $n = substr(bin2hex(random_bytes(3)), 0, 5);
    return [$tid,
        Billing::savePlan(['code' => 'PA' . $n, 'name' => 'Plan A ' . $n, 'monthly_price' => '200']),
        Billing::savePlan(['code' => 'PB' . $n, 'name' => 'Plan B ' . $n, 'monthly_price' => '350']),
        Billing::savePlan(['code' => 'PT' . $n, 'name' => 'Too small ' . $n, 'monthly_price' => '50', 'max_users' => '1']),
        Billing::savePlan(['code' => 'PF' . $n, 'name' => 'Free ' . $n, 'monthly_price' => '0'])];
}

/** Messages to the signed-in person with a title like this. */
function pr_told(string $like): int
{
    $me = (int) \App\Libraries\Core\Auth::id();
    return (int) Db::unscoped(static fn () => Db::value('SELECT COUNT(*) FROM {notifications} WHERE user_id = ? AND title LIKE ?', [$me, $like]));
}

return [
    'PR-1 Billing is for the account owner, Settings, and "Plan and billing" (the accountant); nobody else' => static function (TestKit $t) {
        [$tid, $a, , , $free] = pr_start($t, '');
        $t->as('dora');
        $t->true(PlanRequests::isOwner($tid) && PlanRequests::may(), 'the account owner');
        $t->as('anna');
        $t->true(! PlanRequests::isOwner($tid) && PlanRequests::may(), 'the accountant has Plan and billing');
        $t->as('steve');
        $t->true(! PlanRequests::may(), 'a server has not');
        $t->throws(static fn () => PlanRequests::ask($a, 1), 'Plan and billing', DeniedException::class);
        $ids = array_column(PlanRequests::offered(), 'id');
        $t->true(in_array($a, $ids) && ! in_array($free, $ids), 'plans on offer have a price');
    },

    'PR-2 the account owner: the plan is set and the invoice issued at once; the platform owner is told' => static function (TestKit $t) {
        [$tid, $a, $b, $small, $free] = pr_start($t, '');
        $t->as('dora');
        $t->throws(static fn () => PlanRequests::ask($a, 0), '1 to 24 months');
        $t->throws(static fn () => PlanRequests::ask($a, 25), '1 to 24 months');
        $t->throws(static fn () => PlanRequests::ask($free, 1), 'plans on offer');
        $t->throws(static fn () => PlanRequests::ask(999999, 1), 'plans on offer');
        $t->throws(static fn () => PlanRequests::ask($small, 1), 'Choose a bigger plan');
        $r = PlanRequests::ask($b, 3);
        $t->true($r['invoice'] > 0, 'an invoice at once');
        $i = Billing::invoice($r['invoice'], $tid);
        $t->eq('1050.00', (string) $i['amount'], '3 months of plan B');
        $t->eq($b, (int) $i['plan_id']);
        $t->eq($b, (int) Db::value('SELECT plan_id FROM {tenants} WHERE id = ?', [$tid]), 'the plan is changed');
        $t->eq('approved', Db::value('SELECT status FROM {platform_plan_requests} WHERE id = ?', [$r['request']]));
        $t->throws(static fn () => PlanRequests::ask($a, 1), 'is not paid yet');
        $t->eq($b, (int) Db::value('SELECT plan_id FROM {tenants} WHERE id = ?', [$tid]), 'a refused request changes nothing');
        pr_pat();
        $t->true(pr_told('%raised invoice%') >= 1, 'the platform owner is told');
        Billing::pay($r['invoice'], 'BANK-1');
        $t->as('dora');
        $r2 = PlanRequests::ask($b, 1);
        $t->true($r2['invoice'] > 0, 'once paid, the next can be asked for');
        $i2 = Billing::invoice($r2['invoice'], $tid);
        $t->eq(date('Y-m-d', strtotime($i['period_end'] . ' +1 day')), $i2['period_start'], 'it follows the paid period');
    },

    'PR-3 the accountant asks; the account owner approves: only then is the plan set and the invoice issued' => static function (TestKit $t) {
        [$tid, $a, $b] = pr_start($t, '');
        $before = (int) Db::value('SELECT plan_id FROM {tenants} WHERE id = ?', [$tid]);
        $inv = (int) Db::value('SELECT COUNT(*) FROM {platform_invoices} WHERE tenant_id = ?', [$tid]);
        $t->as('anna');
        $r = PlanRequests::ask($a, 12);
        $t->eq(null, $r['invoice'], 'no invoice yet');
        $t->eq($before, (int) Db::value('SELECT plan_id FROM {tenants} WHERE id = ?', [$tid]), 'the plan waits');
        $t->eq($inv, (int) Db::value('SELECT COUNT(*) FROM {platform_invoices} WHERE tenant_id = ?', [$tid]));
        $t->throws(static fn () => PlanRequests::ask($b, 1), 'already waiting');
        $t->throws(static fn () => PlanRequests::approve($r['request']), 'Only the account owner', DeniedException::class);
        $t->throws(static fn () => PlanRequests::reject($r['request'], 'no'), 'Only the account owner', DeniedException::class);
        $t->as('dora');
        $t->true(pr_told('%asks for an invoice%') >= 1, 'the account owner is told');
        $id = PlanRequests::approve($r['request']);
        $t->eq('2400.00', (string) Billing::invoice($id, $tid)['amount'], '12 months of plan A');
        $t->eq($a, (int) Db::value('SELECT plan_id FROM {tenants} WHERE id = ?', [$tid]));
        $t->throws(static fn () => PlanRequests::approve($r['request']), 'already approved');
        $t->eq($id, (int) Db::value('SELECT invoice_id FROM {platform_plan_requests} WHERE id = ?', [$r['request']]));
    },

    'PR-4 reject with a reason; cancel by the person who asked; another organisation sees nothing' => static function (TestKit $t) {
        [$tid, $a, $b] = pr_start($t, '');
        $t->as('anna');
        $r = PlanRequests::ask($a, 1);
        $t->as('dora');
        $t->throws(static fn () => PlanRequests::reject($r['request'], '  '), 'Say why');
        PlanRequests::reject($r['request'], 'Stay on the plan we have');
        $t->eq('rejected', Db::value('SELECT status FROM {platform_plan_requests} WHERE id = ?', [$r['request']]));
        $t->eq(0, (int) Db::value("SELECT COUNT(*) FROM {platform_invoices} WHERE tenant_id = ? AND status = 'issued'", [$tid]), 'no invoice');
        $t->as('anna');
        $r2 = PlanRequests::ask($b, 1);
        $t->as('steve');
        $t->throws(static fn () => PlanRequests::cancel($r2['request']), 'Only the person who asked', DeniedException::class);
        $t->as('anna');
        PlanRequests::cancel($r2['request']);
        $t->throws(static fn () => PlanRequests::cancel($r2['request']), 'already cancelled');
        $r3 = PlanRequests::ask($b, 1);
        $other = (string) Db::value("SELECT slug FROM {tenants} WHERE id <> ? AND status <> 'cancelled' ORDER BY id LIMIT 1", [$tid]);
        if ($other !== '') {
            pr_pat();
            $oid = (int) Db::value('SELECT id FROM {tenants} WHERE slug = ?', [$other]);
            $ou  = (int) Db::value('SELECT user_id FROM {tenant_users} WHERE tenant_id = ? AND is_account_owner = 1 LIMIT 1', [$oid]);
            \App\Libraries\Core\Auth::actAs($ou);
            \App\Libraries\Core\Tenancy::set($oid);
            $t->throws(static fn () => PlanRequests::approve($r3['request']), 'Request not found');
            $t->eq(0, count(PlanRequests::list($oid)), 'its own list is empty');
        }
    },
];
