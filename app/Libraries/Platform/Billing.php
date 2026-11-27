<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Workflow\Notify;

/**
 * core 1.5 (ChatuERP): the platform owner billing organisations. The same file in every product on the tenant_id
 * core; what differs (what the limits count, which modules there are) is in Product.
 *
 *   plans      a price per month and limits; it also sets the organisation's modules (Modules rules)
 *   invoices   1 to 24 months of the plan, numbered INV-YYYYMM-NNNN, due after "invoice due days"
 *   payments   recorded by hand with a reference, or online through Lenco or DPO (Pay), either way through pay()
 *   daily      a trial whose end date has passed is suspended (read-only, data kept); an unpaid invoice past its due
 *              date makes the organisation "payment overdue", and after the grace days it is suspended. Paying the
 *              overdue invoices makes it active again.
 *
 * Nothing here touches an organisation's own sales or data. The single-company edition has no plans or invoices.
 */
final class Billing
{
    // ---- settings (installation) ------------------------------------------------------------------------------

    public static function dueDays(): int
    {
        return max(0, (int) (Settings::get('invoice_due_days') ?: 14));
    }

    public static function graceDays(): int
    {
        return max(0, (int) (Settings::get('grace_days') ?: 7));
    }

    public static function currency(): string
    {
        return strtoupper((string) (Settings::get('billing_currency') ?: 'ZMW'));
    }

    // ---- plans ------------------------------------------------------------------------------------------------

    public static function plans(bool $activeOnly = false): array
    {
        return Db::rows('SELECT p.*, (SELECT COUNT(*) FROM {tenants} t WHERE t.plan_id = p.id AND t.status <> \'cancelled\') tenants FROM {platform_plans} p'
            . ($activeOnly ? ' WHERE p.active = 1' : '') . ' ORDER BY p.active DESC, p.monthly_price, p.name');
    }

    public static function plan(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        $p = Db::row('SELECT * FROM {platform_plans} WHERE id = ?', [$id]);
        if ($p) {
            $p['module_rules'] = self::planModules($p);
        }
        return $p;
    }

    /** The plan's rule for each module (allow, on, off); modules it does not name are "organisation decides". */
    public static function planModules(array $plan): array
    {
        $set = json_decode((string) ($plan['modules'] ?? ''), true) ?: [];
        $out = [];
        foreach (Product::MODULES as $k => $_) {
            $out[$k] = isset(Modules::RULES[$set[$k] ?? '']) ? $set[$k] : 'allow';
        }
        return $out;
    }

    public static function savePlan(array $d, ?int $id = null): int
    {
        Access::requirePlatform('platform.tenants');
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        $name = trim((string) ($d['name'] ?? ''));
        if (! preg_match('/^[A-Z0-9_-]{2,20}$/', $code) || $name === '') {
            throw new BizException('Give the plan a code (2 to 20 letters or digits) and a name.');
        }
        if (Db::value('SELECT 1 FROM {platform_plans} WHERE code = ? AND id <> ?', [$code, (int) $id])) {
            throw new BizException('Plan code ' . $code . ' is already used.');
        }
        $price = (string) ($d['monthly_price'] ?? '0');
        if (! is_numeric($price) || (float) $price < 0) {
            throw new BizException('Price per month: give an amount, 0 or more.');
        }
        $lim = static function ($v, string $label): ?int {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            if (! ctype_digit($v) || (int) $v < 1) {
                throw new BizException($label . ': a whole number of 1 or more, or empty for no limit.');
            }
            return (int) $v;
        };
        $mods = [];
        foreach (Product::MODULES as $k => $_) {
            $v = (string) ($d['modules'][$k] ?? 'allow');
            $mods[$k] = isset(Modules::RULES[$v]) ? $v : 'allow';
        }
        $trial = trim((string) ($d['trial_days'] ?? '14'));
        if (! ctype_digit($trial) || (int) $trial > 365) {
            throw new BizException('Trial days: 0 to 365.');
        }
        $row = ['code' => $code, 'name' => mb_substr($name, 0, 80), 'monthly_price' => round((float) $price, 2), 'currency' => self::currency(),
            'max_locations' => $lim($d['max_locations'] ?? '', Product::LIMITS['locations']), 'max_users' => $lim($d['max_users'] ?? '', Product::LIMITS['users']),
            'modules' => json_encode($mods), 'trial_days' => (int) $trial, 'active' => ! $id || ! empty($d['active']) ? 1 : 0];
        return Db::atomic(static function () use ($row, $id) {
            $before = $id ? Db::row('SELECT code, name, monthly_price, max_locations, max_users, modules, trial_days, active FROM {platform_plans} WHERE id = ?', [$id]) : null;
            if ($id && ! $before) {
                throw new BizException('Plan not found.');
            }
            if ($id) {
                $sets = implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($row)));
                Db::exec('UPDATE {platform_plans} SET ' . $sets . ' WHERE id = ?', array_merge(array_values($row), [$id]));
                // organisations on the plan follow its module rules
                foreach (Db::rows("SELECT id FROM {tenants} WHERE plan_id = ? AND status <> 'cancelled'", [$id]) as $t) {
                    Modules::applyPlan((int) $t['id'], json_decode($row['modules'], true), 'Plan ' . $row['code'] . ' changed');
                }
            } else {
                $id = Db::insert('platform_plans', $row + ['created_at' => Db::now()]);
            }
            Tenancy::run(null, static fn () => Audit::log('platform.plan.save', 'plan', $id, $before, $row));
            return $id;
        });
    }

    /** Put an organisation on a plan (or none). The plan's module rules apply from now. */
    public static function assignPlan(int $tenantId, ?int $planId, string $reason = ''): void
    {
        Access::requirePlatform('platform.tenants');
        $t = self::tenant($tenantId);
        $plan = $planId ? self::plan($planId) : null;
        if ($planId && (! $plan || ! $plan['active'])) {
            throw new BizException('Choose an active plan.');
        }
        if ($plan) {
            $use = Product::usage($tenantId);
            foreach (['locations' => 'max_locations', 'users' => 'max_users'] as $k => $col) {
                if ($plan[$col] !== null && $use[$k] > (int) $plan[$col]) {
                    throw new BizException('The organisation has ' . $use[$k] . ' ' . strtolower(Product::LIMITS[$k]) . '; plan ' . $plan['code'] . ' allows ' . (int) $plan[$col] . '. Choose a bigger plan, or ask them to switch some off first.');
                }
            }
        }
        Db::atomic(static function () use ($t, $plan, $planId, $reason, $tenantId) {
            Db::exec('UPDATE {tenants} SET plan_id = ? WHERE id = ?', [$planId ?: null, $tenantId]);
            if ($plan) {
                Modules::applyPlan($tenantId, $plan['module_rules'], 'Plan ' . $plan['code']);
            }
            $before = ['plan_id' => $t['plan_id']];
            $after  = ['plan_id' => $planId ?: null, 'plan' => $plan['code'] ?? null];
            Tenancy::run(null, static fn () => Audit::log('tenant.plan', 'tenant', $tenantId, $before, $after, 'success', $reason !== '' ? $reason : null));
            Tenancy::run($tenantId, static fn () => Audit::log('tenant.plan', 'tenant', $tenantId, $before, $after, 'success', 'By the platform owner'));
        });
    }

    /** The organisation's plan, its limits and what it uses now. */
    public static function limits(int $tenantId): array
    {
        $t    = self::tenant($tenantId);
        $plan = self::plan($t['plan_id'] ? (int) $t['plan_id'] : null);
        $use  = Product::usage($tenantId);
        $out  = ['plan' => $plan, 'items' => []];
        foreach (Product::LIMITS as $k => $label) {
            $max = $plan ? $plan['max_' . $k] : null;
            $out['items'][$k] = ['label' => $label, 'used' => $use[$k], 'max' => $max === null ? null : (int) $max];
        }
        return $out;
    }

    /**
     * Refuse adding (or switching back on) one more location or person when the plan's limit is reached. Called by
     * the product where people and locations are added. No limit in the single-company edition or without a plan.
     */
    public static function checkLimit(string $what, int $adding = 1, ?int $tenantId = null): void
    {
        $tenantId ??= Tenancy::id();
        if ($tenantId === null || Tenancy::single() || ! isset(Product::LIMITS[$what])) {
            return;
        }
        $planId = Db::value('SELECT plan_id FROM {tenants} WHERE id = ?', [$tenantId]);
        $max    = $planId ? Db::value('SELECT max_' . $what . ' FROM {platform_plans} WHERE id = ?', [(int) $planId]) : null;
        if ($max === null) {
            return;
        }
        $used = Product::usage($tenantId)[$what];
        if ($used + $adding > (int) $max) {
            throw new BizException('Your plan allows ' . (int) $max . ' ' . strtolower(Product::LIMITS[$what]) . ' and you have ' . $used
                . '. To add more, ask Chatu Innovations to move you to a bigger plan (info@chatuzm.com).');
        }
    }

    // ---- invoices ---------------------------------------------------------------------------------------------

    public static function invoices(?int $tenantId = null, int $limit = 500): array
    {
        return Db::rows('SELECT i.*, t.name tenant, t.slug FROM {platform_invoices} i JOIN {tenants} t ON t.id = i.tenant_id'
            . ($tenantId ? ' WHERE i.tenant_id = ' . (int) $tenantId : '') . ' ORDER BY i.id DESC LIMIT ' . max(1, $limit));
    }

    public static function invoice(int $id, ?int $tenantId = null): array
    {
        $i = Db::row('SELECT i.*, t.name tenant, t.slug, t.billing_email, p.code plan_code, p.name plan_name FROM {platform_invoices} i JOIN {tenants} t ON t.id = i.tenant_id
                       LEFT JOIN {platform_plans} p ON p.id = i.plan_id WHERE i.id = ?', [$id]);
        if (! $i || ($tenantId !== null && (int) $i['tenant_id'] !== $tenantId)) {
            throw new BizException('Invoice not found.');
        }
        return $i;
    }

    /** Invoice an organisation for $months of its plan, starting the day after it is paid until (or invoiced to, or today). */
    public static function issue(int $tenantId, int $months = 1, ?float $amount = null, ?string $note = null, ?string $today = null, bool $system = false): int
    {
        if (! $system) {   // $system: an invoice the product raises itself (ChatuPass: a one-event pass the organisation orders)
            Access::requirePlatform('platform.tenants');
        }
        $today ??= date('Y-m-d');
        $t = self::tenant($tenantId);
        if ($t['status'] === 'cancelled') {
            throw new BizException('The organisation is cancelled.');
        }
        if ($months < 1 || $months > 24) {
            throw new BizException('Invoice 1 to 24 months at a time.');
        }
        $plan = self::plan($t['plan_id'] ? (int) $t['plan_id'] : null);
        $amount ??= $plan ? (float) $plan['monthly_price'] * $months : 0.0;
        if ($amount <= 0) {
            throw new BizException('Nothing to invoice: put the organisation on a plan with a price, or type the amount.');
        }
        return Db::atomic(static function () use ($t, $tenantId, $months, $amount, $note, $today, $plan) {
            $last  = Db::value("SELECT MAX(period_end) FROM {platform_invoices} WHERE tenant_id = ? AND status <> 'void'", [$tenantId]);
            $start = max($today, $last ? date('Y-m-d', strtotime($last . ' +1 day')) : '', $t['paid_until'] ? date('Y-m-d', strtotime($t['paid_until'] . ' +1 day')) : '');
            $end   = date('Y-m-d', strtotime($start . ' +' . $months . ' months -1 day'));
            $month = date('Ym', strtotime($today));
            $seq   = (int) Db::value('SELECT COUNT(*) FROM {platform_invoices} WHERE number LIKE ? FOR UPDATE', ['INV-' . $month . '-%']) + 1;
            $num   = 'INV-' . $month . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $id    = Db::insert('platform_invoices', ['tenant_id' => $tenantId, 'number' => $num, 'plan_id' => $plan['id'] ?? null, 'months' => $months,
                'period_start' => $start, 'period_end' => $end, 'amount' => round($amount, 2), 'currency' => self::currency(), 'status' => 'issued',
                'issued_at' => Db::now(), 'issued_by' => Auth::id(), 'due_on' => date('Y-m-d', strtotime($today . ' +' . self::dueDays() . ' days')),
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null]);
            $after = ['number' => $num, 'amount' => round($amount, 2), 'from' => $start, 'to' => $end];
            Tenancy::run(null, static fn () => Audit::log('platform.invoice.issue', 'platform_invoice', $id, null, $after));
            Tenancy::run($tenantId, static function () use ($tenantId, $num, $amount, $start, $end, $id) {
                Audit::log('platform.invoice.issue', 'platform_invoice', $id, null, ['number' => $num, 'amount' => round($amount, 2)], 'success', 'Invoice from ' . Product::NAME);
                foreach (self::owners($tenantId) as $uid) {
                    Notify::user($uid, 'billing', 'New invoice ' . $num . ': ' . self::currency() . ' ' . number_format($amount, 2),
                        'For ' . date('j M Y', strtotime($start)) . ' to ' . date('j M Y', strtotime($end)) . '. Pay it online or by bank or mobile money.', '/billing/invoices/' . $id);
                }
            });
            return $id;
        });
    }

    /**
     * Mark an invoice paid: by the platform owner with a reference (bank, mobile money, receipt), or by Pay when the
     * gateway confirms it. The organisation is paid until the invoice's end and, once nothing is overdue, active again.
     */
    public static function pay(int $invoiceId, string $reference, string $via = 'manual', bool $system = false): void
    {
        if (! $system) {
            Access::requirePlatform('platform.tenants');
        }
        $reference = trim($reference);
        if ($reference === '') {
            throw new BizException('Give the payment reference (bank, mobile money or receipt number).');
        }
        Db::atomic(static function () use ($invoiceId, $reference, $via) {
            $i = Db::row('SELECT * FROM {platform_invoices} WHERE id = ? FOR UPDATE', [$invoiceId]);
            if (! $i || $i['status'] !== 'issued') {
                throw new BizException('Only an unpaid invoice can be paid.');
            }
            Db::exec("UPDATE {platform_invoices} SET status = 'paid', paid_at = ?, paid_ref = ?, paid_via = ? WHERE id = ?", [Db::now(), mb_substr($reference, 0, 100), $via, $i['id']]);
            $tid = (int) $i['tenant_id'];
            Db::exec('UPDATE {tenants} SET paid_until = GREATEST(COALESCE(paid_until, ?), ?) WHERE id = ?', [$i['period_end'], $i['period_end'], $tid]);
            $t = Db::row('SELECT * FROM {tenants} WHERE id = ?', [$tid]);
            $overdue = Db::value("SELECT 1 FROM {platform_invoices} WHERE tenant_id = ? AND status = 'issued' AND due_on < ?", [$tid, date('Y-m-d')]);
            $reopen = ! $overdue && in_array($t['status'], ['trial', 'past_due', 'suspended'], true)
                && ($t['status'] !== 'suspended' || in_array((string) $t['status_reason'], ['billing', 'trial_ended'], true));
            if ($reopen) {
                Db::exec("UPDATE {tenants} SET status = 'active', status_reason = NULL WHERE id = ?", [$tid]);
            }
            $after = ['number' => $i['number'], 'paid_ref' => $reference, 'via' => $via] + ($reopen ? ['status' => 'active'] : []);
            Tenancy::run(null, static fn () => Audit::log('platform.invoice.pay', 'platform_invoice', $i['id'], ['status' => 'issued'], $after));
            Tenancy::run($tid, static function () use ($i, $after, $tid, $reopen) {
                Audit::log('platform.invoice.pay', 'platform_invoice', $i['id'], ['status' => 'issued'], $after);
                foreach (self::owners($tid) as $uid) {
                    Notify::user($uid, 'billing', 'Payment received for ' . $i['number'] . ', thank you', $reopen ? 'Your organisation is active again.' : null, '/billing/invoices/' . $i['id']);
                }
            });
        });
    }

    public static function void(int $invoiceId, string $reason): void
    {
        Access::requirePlatform('platform.tenants');
        if (trim($reason) === '') {
            throw new BizException('Give a reason.');
        }
        Db::atomic(static function () use ($invoiceId, $reason) {
            $i = Db::row('SELECT * FROM {platform_invoices} WHERE id = ? FOR UPDATE', [$invoiceId]);
            if (! $i || $i['status'] !== 'issued') {
                throw new BizException('Only an unpaid invoice can be cancelled.');
            }
            if (Db::value("SELECT 1 FROM {gateway_payments} WHERE purpose = 'platform_invoice' AND object_id = ? AND status = 'pending'", [$invoiceId])) {
                throw new BizException('An online payment for this invoice is still in progress. Check it first (Payments, Check now).');
            }
            Db::exec("UPDATE {platform_invoices} SET status = 'void', note = ? WHERE id = ?", [mb_substr(trim($reason), 0, 255), $invoiceId]);
            Tenancy::run(null, static fn () => Audit::log('platform.invoice.void', 'platform_invoice', $invoiceId, ['status' => 'issued'], ['status' => 'void'], 'success', trim($reason)));
        });
    }

    // ---- trials -----------------------------------------------------------------------------------------------

    /** Days left in a trial (null when it is not on trial). */
    public static function trialDaysLeft(?array $t): ?int
    {
        if (! $t || $t['status'] !== 'trial' || empty($t['trial_ends_at'])) {
            return null;
        }
        return max(0, (int) ceil((strtotime($t['trial_ends_at'] . ' UTC') - time()) / 86400));
    }

    /** Extend (or restart) a trial by some days from today; a trial that ended and was suspended opens again. */
    public static function extendTrial(int $tenantId, int $days, string $reason): void
    {
        Access::requirePlatform('platform.tenants');
        if ($days < 1 || $days > 365) {
            throw new BizException('Extend by 1 to 365 days.');
        }
        if (trim($reason) === '') {
            throw new BizException('Give a reason.');
        }
        $t = self::tenant($tenantId);
        if (! in_array($t['status'], ['trial', 'suspended'], true) || ($t['status'] === 'suspended' && $t['status_reason'] !== 'trial_ended')) {
            throw new BizException('Only an organisation on trial, or whose trial has ended, can have its trial extended.');
        }
        $until = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        Db::atomic(static function () use ($tenantId, $t, $until, $reason) {
            Db::exec("UPDATE {tenants} SET status = 'trial', status_reason = NULL, trial_ends_at = ? WHERE id = ?", [$until, $tenantId]);
            $before = ['status' => $t['status'], 'trial_ends_at' => $t['trial_ends_at']];
            $after  = ['status' => 'trial', 'trial_ends_at' => $until];
            Tenancy::run(null, static fn () => Audit::log('tenant.trial', 'tenant', $tenantId, $before, $after, 'success', trim($reason)));
            Tenancy::run($tenantId, static fn () => Audit::log('tenant.trial', 'tenant', $tenantId, $before, $after, 'success', 'By the platform owner: ' . trim($reason)));
        });
    }

    // ---- the daily check --------------------------------------------------------------------------------------

    /**
     * Once a day (from the scheduled tick). Returns counts. $now is for tests.
     *  - a trial ending within 3 days: the account owners are told once;
     *  - a trial that has ended: suspended (read-only), owners and platform owners told;
     *  - an unpaid invoice past its due date: payment overdue; after the grace days, suspended.
     */
    public static function daily(?string $now = null): array
    {
        $now ??= gmdate('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $out   = ['trial_warned' => 0, 'trial_ended' => 0, 'overdue' => 0, 'suspended' => 0];
        if (Tenancy::single()) {
            return $out;
        }
        foreach (Db::rows("SELECT * FROM {tenants} WHERE status = 'trial' AND trial_ends_at IS NOT NULL") as $t) {
            $tid = (int) $t['id'];
            if ($t['trial_ends_at'] <= $now) {
                if ($t['paid_until'] && $t['paid_until'] >= $today) {
                    Db::exec("UPDATE {tenants} SET status = 'active', status_reason = NULL WHERE id = ? AND status = 'trial'", [$tid]);
                    continue;
                }
                Db::exec("UPDATE {tenants} SET status = 'suspended', status_reason = 'trial_ended' WHERE id = ? AND status = 'trial'", [$tid]);
                self::told($tid, 'tenant.trial_ended', ['status' => 'trial'], ['status' => 'suspended'], 'Trial ended: read-only until the account is taken up',
                    'Your ' . Product::NAME . ' trial has ended', 'Your data is kept and you can still look things up. To carry on working, pay an invoice under Billing, or contact Chatu Innovations: info@chatuzm.com, +260 773818370.');
                $out['trial_ended']++;
            } elseif ($t['trial_ends_at'] <= gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + 3 * 86400)
                && ! Db::value("SELECT 1 FROM {audit_events} WHERE tenant_id = ? AND action = 'tenant.trial_warning' AND object_id = ? AND at >= ?", [$tid, (string) $tid, gmdate('Y-m-d H:i:s', strtotime($t['trial_ends_at'] . ' UTC') - 4 * 86400)])) {
                $days = max(1, (int) ceil((strtotime($t['trial_ends_at'] . ' UTC') - strtotime($now . ' UTC')) / 86400));
                Tenancy::run($tid, static function () use ($tid, $days) {
                    Audit::log('tenant.trial_warning', 'tenant', $tid, null, ['days_left' => $days]);
                    foreach (self::owners($tid) as $uid) {
                        Notify::user($uid, 'billing', 'Your trial ends in ' . $days . ' day' . ($days > 1 ? 's' : ''), 'To carry on without a break, pay your first invoice under Billing.', '/billing');
                    }
                });
                $out['trial_warned']++;
            }
        }
        $grace = self::graceDays();
        foreach (Db::rows("SELECT * FROM {tenants} WHERE status IN ('trial','active','past_due')") as $t) {
            $tid    = (int) $t['id'];
            $oldest = Db::value("SELECT MIN(due_on) FROM {platform_invoices} WHERE tenant_id = ? AND status = 'issued' AND due_on < ?", [$tid, $today]);
            if ($oldest === null) {
                continue;
            }
            if (strtotime($oldest . ' +' . $grace . ' days') < strtotime($today)) {
                Db::exec("UPDATE {tenants} SET status = 'suspended', status_reason = 'billing' WHERE id = ?", [$tid]);
                self::told($tid, 'tenant.status', ['status' => $t['status']], ['status' => 'suspended'], 'Unpaid since ' . $oldest,
                    'Your organisation is suspended for an unpaid invoice', 'You can still look things up. Pay the invoice under Billing and it opens again at once.');
                $out['suspended']++;
            } elseif ($t['status'] !== 'past_due') {
                Db::exec("UPDATE {tenants} SET status = 'past_due', status_reason = 'billing' WHERE id = ?", [$tid]);
                self::told($tid, 'tenant.status', ['status' => $t['status']], ['status' => 'past_due'], 'Invoice due ' . $oldest,
                    'An invoice is overdue', 'Please pay it under Billing. After ' . $grace . ' days the organisation becomes read-only until it is paid.');
                $out['overdue']++;
            }
        }
        return $out;
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    public static function tenant(int $id): array
    {
        $t = Db::row('SELECT * FROM {tenants} WHERE id = ?', [$id]);
        if (! $t) {
            throw new BizException('Organisation not found.');
        }
        return $t;
    }

    /** Account owners of an organisation (user ids). */
    public static function owners(int $tenantId): array
    {
        return array_map('intval', array_column(Db::unscoped(static fn () => Db::rows("SELECT user_id FROM {tenant_users} WHERE tenant_id = ? AND is_account_owner = 1 AND status = 'active'", [$tenantId])), 'user_id'));
    }

    /** Platform owners (user ids). */
    public static function platformOwners(): array
    {
        return array_map('intval', array_column(Db::unscoped(static fn () => Db::rows("SELECT DISTINCT ur.user_id FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id
                                                                                       WHERE ur.tenant_id IS NULL AND ur.scope_type = 'platform' AND r.code = 'platform_owner'")), 'user_id'));
    }

    /** Log a status change in both audit logs and tell the account owners and the platform owners. */
    private static function told(int $tid, string $action, array $before, array $after, string $why, string $title, string $body): void
    {
        $name = (string) Db::value('SELECT name FROM {tenants} WHERE id = ?', [$tid]);
        Tenancy::run(null, static function () use ($tid, $action, $before, $after, $why, $name) {
            Audit::log($action, 'tenant', $tid, $before, $after, 'success', $why);
            foreach (self::platformOwners() as $uid) {
                Notify::user($uid, 'billing', $name . ': ' . $why, null, '/platform/tenants/' . $tid);
            }
        });
        Tenancy::run($tid, static function () use ($tid, $action, $before, $after, $why, $title, $body) {
            Audit::log($action, 'tenant', $tid, $before, $after, 'success', $why);
            foreach (self::owners($tid) as $uid) {
                Notify::user($uid, 'billing', $title, $body, '/billing');
            }
        });
    }
}
