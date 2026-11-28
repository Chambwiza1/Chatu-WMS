<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Tenancy;
use App\Libraries\Workflow\Notify;

/**
 * ChatuERP core 1.5.5: an organisation chooses its own plan and asks for its invoice, from Billing.
 *
 *  - Who: the account owner, and people with "Plan and billing" (billing.manage: accountants) or Settings.
 *  - The account owner's request is carried out at once: the plan is set and the invoice issued.
 *  - Anyone else's request waits for the account owner, who approves or rejects it with a reason.
 *  - One thing at a time: no new request while one waits or while an invoice is unpaid.
 *  - The plan's limits are checked against what the organisation uses, when asking and again when approving.
 *
 * The same file in every product on the shared billing library.
 */
final class PlanRequests
{
    /** May this person use Billing at all? */
    public static function may(?int $tenantId = null): bool
    {
        return self::isOwner($tenantId) || Access::can('admin.settings') || Access::can('billing.manage');
    }

    public static function requireMay(): void
    {
        if (! self::may()) {
            throw new DeniedException('Billing is for the account owner and people given "Plan and billing" (billing.manage), such as the accountant.');
        }
    }

    public static function isOwner(?int $tenantId = null): bool
    {
        $tenantId ??= Tenancy::require();
        return in_array((int) Auth::id(), Billing::owners($tenantId), true);
    }

    /** The plans an organisation may choose: active, with a price. */
    public static function offered(): array
    {
        return array_values(array_filter(Billing::plans(true), static fn ($p) => (float) $p['monthly_price'] > 0));
    }

    public static function list(int $tenantId, int $limit = 20): array
    {
        return Db::rows('SELECT r.*, p.code plan_code, p.name plan_name, u.name asked_by, d.name decided_name, i.number invoice_number
                           FROM {platform_plan_requests} r JOIN {platform_plans} p ON p.id = r.plan_id
                           JOIN {users} u ON u.id = r.requested_by LEFT JOIN {users} d ON d.id = r.decided_by
                           LEFT JOIN {platform_invoices} i ON i.id = r.invoice_id
                          WHERE r.tenant_id = ? ORDER BY r.id DESC LIMIT ' . max(1, $limit), [$tenantId]);
    }

    public static function waiting(int $tenantId): ?array
    {
        foreach (self::list($tenantId, 5) as $r) {
            if ($r['status'] === 'pending') {
                return $r;
            }
        }
        return null;
    }

    /**
     * Ask for an invoice for $months of a plan. The account owner's is carried out at once; anyone else's waits.
     *
     * @return array{request:int, invoice:?int}
     */
    public static function ask(int $planId, int $months): array
    {
        $tid = Tenancy::require();
        self::requireMay();
        $plan  = self::check($tid, $planId, $months);
        $owner = self::isOwner($tid);
        $uid   = (int) Auth::id();

        return Db::atomic(static function () use ($tid, $plan, $months, $owner, $uid) {
            Db::value('SELECT id FROM {tenants} WHERE id = ? FOR UPDATE', [$tid]);
            if (self::waiting($tid)) {
                throw new BizException('A request is already waiting for the account owner. Cancel it first to ask for something else.');
            }
            $amount = round((float) $plan['monthly_price'] * $months, 2);
            $id = Db::insert('platform_plan_requests', ['tenant_id' => $tid, 'plan_id' => (int) $plan['id'], 'months' => $months, 'amount' => $amount,
                'status' => 'pending', 'requested_by' => $uid, 'requested_at' => Db::now()]);
            Audit::log('billing.request', 'plan_request', $id, null, ['plan' => $plan['code'], 'months' => $months, 'amount' => $amount]);
            if ($owner) {
                return ['request' => $id, 'invoice' => self::carryOut($id, $tid, $plan, $months, $uid)];
            }
            $who = (string) Db::value('SELECT name FROM {users} WHERE id = ?', [$uid]);
            foreach (Billing::owners($tid) as $o) {
                Notify::user($o, 'billing', $who . ' asks for an invoice: ' . $plan['name'] . ', ' . self::monthsText($months),
                    Billing::currency() . ' ' . number_format($amount, 2) . '. Approve or reject it under Billing.', '/billing');
            }
            return ['request' => $id, 'invoice' => null];
        });
    }

    /** The account owner approves a waiting request: the plan is set and the invoice issued. */
    public static function approve(int $id): int
    {
        $tid = Tenancy::require();
        $r   = self::pending($id, $tid);
        if (! self::isOwner($tid)) {
            throw new DeniedException('Only the account owner approves a request for an invoice.');
        }
        $plan = self::check($tid, (int) $r['plan_id'], (int) $r['months']);
        return Db::atomic(static function () use ($id, $tid, $plan, $r) {
            $inv = self::carryOut($id, $tid, $plan, (int) $r['months'], (int) Auth::id());
            Notify::user((int) $r['requested_by'], 'billing', 'Your request for an invoice was approved', $plan['name'] . ', ' . self::monthsText((int) $r['months']) . '. The invoice is under Billing.', '/billing/invoices/' . $inv);
            return $inv;
        });
    }

    public static function reject(int $id, string $reason): void
    {
        $tid = Tenancy::require();
        $r   = self::pending($id, $tid);
        if (! self::isOwner($tid)) {
            throw new DeniedException('Only the account owner rejects a request for an invoice.');
        }
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new BizException('Say why, so the person who asked knows.');
        }
        Db::atomic(static function () use ($id, $tid, $r, $reason) {
            self::close($id, $tid, 'rejected', $reason);
            Audit::log('billing.request.reject', 'plan_request', $id, ['status' => 'pending'], ['status' => 'rejected'], 'success', $reason);
            Notify::user((int) $r['requested_by'], 'billing', 'Your request for an invoice was rejected', $reason, '/billing');
        });
    }

    /** Taken back by the person who asked, or by the account owner. */
    public static function cancel(int $id): void
    {
        $tid = Tenancy::require();
        $r   = self::pending($id, $tid);
        if ((int) $r['requested_by'] !== (int) Auth::id() && ! self::isOwner($tid)) {
            throw new DeniedException('Only the person who asked, or the account owner, cancels a request.');
        }
        Db::atomic(static function () use ($id, $tid) {
            self::close($id, $tid, 'cancelled', null);
            Audit::log('billing.request.cancel', 'plan_request', $id, ['status' => 'pending'], ['status' => 'cancelled']);
        });
    }

    public static function monthsText(int $m): string
    {
        return $m . ' month' . ($m === 1 ? '' : 's');
    }

    // ---- inside -----------------------------------------------------------------------------------------------

    /** What must hold both when asking and when approving. Returns the plan. */
    private static function check(int $tid, int $planId, int $months): array
    {
        if (Tenancy::single()) {
            throw new BizException('This is the single-company edition: there are no plans or invoices here.');
        }
        $t = Billing::tenant($tid);
        if ($t['status'] === 'cancelled') {
            throw new BizException('The organisation is cancelled.');
        }
        if ($months < 1 || $months > 24) {
            throw new BizException('Choose 1 to 24 months.');
        }
        $plan = Billing::plan($planId);
        if (! $plan || ! (int) $plan['active'] || (float) $plan['monthly_price'] <= 0) {
            throw new BizException('Choose one of the plans on offer.');
        }
        $unpaid = Db::value("SELECT number FROM {platform_invoices} WHERE tenant_id = ? AND status = 'issued' ORDER BY id LIMIT 1", [$tid]);
        if ($unpaid) {
            throw new BizException('Invoice ' . $unpaid . ' is not paid yet. Pay it first, or ask ' . 'Chatu Innovations' . ' to cancel it.');
        }
        $use = Product::usage($tid);
        foreach (['locations' => 'max_locations', 'users' => 'max_users'] as $k => $col) {
            if ($plan[$col] !== null && $use[$k] > (int) $plan[$col]) {
                throw new BizException('You have ' . $use[$k] . ' ' . strtolower(Product::LIMITS[$k]) . '; ' . $plan['name'] . ' allows ' . (int) $plan[$col] . '. Choose a bigger plan, or switch some off first.');
            }
        }
        return $plan;
    }

    private static function pending(int $id, int $tid): array
    {
        $r = Db::row('SELECT * FROM {platform_plan_requests} WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        if (! $r) {
            throw new BizException('Request not found.');
        }
        if ($r['status'] !== 'pending') {
            throw new BizException('This request is already ' . $r['status'] . '.');
        }
        return $r;
    }

    private static function close(int $id, int $tid, string $status, ?string $reason, ?int $invoice = null): void
    {
        $n = Db::exec("UPDATE {platform_plan_requests} SET status = ?, decided_by = ?, decided_at = ?, reason = ?, invoice_id = ? WHERE id = ? AND tenant_id = ? AND status = 'pending'",
            [$status, (int) Auth::id(), Db::now(), $reason, $invoice, $id, $tid]);
        if ($n !== 1) {
            throw new BizException('This request was already dealt with.');
        }
    }

    /** Set the plan (if it changes), issue the invoice, close the request, and tell the platform owners. */
    private static function carryOut(int $id, int $tid, array $plan, int $months, int $by): int
    {
        $t = Billing::tenant($tid);
        if ((int) ($t['plan_id'] ?? 0) !== (int) $plan['id']) {
            Billing::assignPlan($tid, (int) $plan['id'], 'Chosen by the organisation', true);
        }
        $who = (string) Db::value('SELECT name FROM {users} WHERE id = ?', [$by]);
        $inv = Billing::issue($tid, $months, null, 'Asked for by the organisation (' . $who . ')', null, true);
        self::close($id, $tid, 'approved', null, $inv);
        Audit::log('billing.request.approve', 'plan_request', $id, ['status' => 'pending'], ['status' => 'approved', 'invoice_id' => $inv]);
        $num = (string) Db::value('SELECT number FROM {platform_invoices} WHERE id = ?', [$inv]);
        Tenancy::run(null, static function () use ($t, $tid, $plan, $months, $num) {
            foreach (Billing::platformOwners() as $uid) {
                Notify::user($uid, 'billing', $t['name'] . ' chose ' . $plan['name'] . ' and raised invoice ' . $num, self::monthsText($months) . '.', '/platform/tenants/' . $tid);
            }
        });
        return $inv;
    }
}
