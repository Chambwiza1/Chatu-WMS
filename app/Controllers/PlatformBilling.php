<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Platform\Billing;
use App\Libraries\Platform\Modules;
use App\Libraries\Platform\Pay;

/** core 1.5: the platform owner's plans, invoices, payments and billing settings, and an organisation's billing box. */
class PlatformBilling extends BaseController
{
    public const SETTINGS = [
        'billing_currency' => ['Currency', 'Three letters, for example ZMW.'],
        'invoice_due_days' => ['Invoices are due after (days)', ''],
        'grace_days'       => ['Grace days before suspending', 'After an invoice is overdue, the organisation stays usable this many days, then becomes read-only until it pays.'],
        'billing_details'  => ['Bank and mobile money details on invoices', 'For those who pay by hand. Shown on every invoice.'],
    ];

    public function plans()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.tenants');
            $edit = $this->request->getGet('edit');
            return $this->render('platform/plans', ['rows' => Billing::plans(), 'edit' => $edit ? Billing::plan((int) $edit) : null], 'Plans');
        });
    }

    public function savePlan()
    {
        $id = (int) $this->in('id', 0) ?: null;
        return $this->act(function () use ($id) {
            Billing::savePlan($this->request->getPost(), $id);
            return $this->ok('Plan saved.', 'platform/plans');
        }, site_url('platform/plans' . ($id ? '?edit=' . $id : '')));
    }

    public function invoices()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.tenants');
            $rows = Billing::invoices();
            $owing = array_sum(array_map(static fn ($r) => $r['status'] === 'issued' ? (float) $r['amount'] : 0, $rows));
            return $this->render('platform/invoices', ['rows' => $rows, 'owing' => $owing, 'currency' => Billing::currency()], 'Invoices');
        });
    }

    public function invoice(int $id)
    {
        return $this->page(function () use ($id) {
            Access::requirePlatform('platform.tenants');
            return $this->render('billing/invoice', ['i' => Billing::invoice($id), 'platform' => true,
                'payments' => Db::rows("SELECT * FROM {gateway_payments} WHERE purpose = 'platform_invoice' AND object_id = ? ORDER BY id DESC", [$id])], 'Invoice');
        });
    }

    public function payments()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.tenants');
            return $this->render('platform/payments', ['rows' => Db::rows("SELECT g.*, t.name tenant FROM {gateway_payments} g JOIN {tenants} t ON t.id = g.tenant_id
                                                                            WHERE g.detail NOT LIKE 'own%' ORDER BY g.id DESC LIMIT 300")], 'Online payments');
        });
    }

    public function checkPayment(int $id)
    {
        return $this->act(function () use ($id) {
            Access::requirePlatform('platform.tenants');
            $p = Pay::check($id);
            return $this->ok('Payment ' . $p['reference'] . ': ' . $p['status'] . '.', 'platform/payments');
        }, site_url('platform/payments'));
    }

    // ---- one organisation ----------------------------------------------------------------------------------------

    public function setPlan(int $tid)
    {
        return $this->act(function () use ($tid) {
            Billing::assignPlan($tid, (int) $this->in('plan_id', 0) ?: null, (string) $this->in('reason', ''));
            Db::exec('UPDATE {tenants} SET billing_email = ? WHERE id = ?', [trim((string) $this->in('billing_email', '')) ?: null, $tid]);
            return $this->ok('Plan saved.', 'platform/tenants/' . $tid);
        }, site_url('platform/tenants/' . $tid));
    }

    public function issue(int $tid)
    {
        return $this->act(function () use ($tid) {
            $amount = trim((string) $this->in('amount', ''));
            if ($amount !== '' && ! is_numeric($amount)) {
                throw new BizException('Amount: a number, or empty for the plan price.');
            }
            $id = Billing::issue($tid, (int) $this->in('months', 1), $amount === '' ? null : (float) $amount, (string) $this->in('note', ''));
            return $this->ok('Invoice issued. The account owner has been told.', 'platform/invoices/' . $id);
        }, site_url('platform/tenants/' . $tid));
    }

    public function pay(int $id)
    {
        return $this->act(function () use ($id) {
            Billing::pay($id, (string) $this->in('reference', ''), 'manual');
            return $this->ok('Payment recorded.', 'platform/invoices/' . $id);
        }, site_url('platform/invoices/' . $id));
    }

    public function void(int $id)
    {
        return $this->act(function () use ($id) {
            Billing::void($id, (string) $this->in('reason', ''));
            return $this->ok('Invoice cancelled.', 'platform/invoices/' . $id);
        }, site_url('platform/invoices/' . $id));
    }

    public function extendTrial(int $tid)
    {
        return $this->act(function () use ($tid) {
            Billing::extendTrial($tid, (int) $this->in('days', 0), (string) $this->in('reason', ''));
            return $this->ok('Trial extended.', 'platform/tenants/' . $tid);
        }, site_url('platform/tenants/' . $tid));
    }

    public function modules(int $tid)
    {
        return $this->act(function () use ($tid) {
            $changed = Modules::setRules($tid, (array) $this->request->getPost('modules'), (string) $this->in('reason', ''));
            return $this->ok($changed ? 'Modules saved.' : 'Nothing changed.', 'platform/tenants/' . $tid);
        }, site_url('platform/tenants/' . $tid));
    }

    // ---- settings ------------------------------------------------------------------------------------------------

    public function settings()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.settings');
            return $this->render('platform/billing_settings', ['fields' => self::SETTINGS, 'keys' => Pay::config(null), 'available' => Pay::available(null)], 'Billing settings');
        });
    }

    public function saveSettings()
    {
        return $this->act(function () {
            Access::requirePlatform('platform.settings');
            Db::atomic(function () {
                $before = $after = [];
                foreach (array_keys(self::SETTINGS) as $k) {
                    $v = trim((string) $this->in($k, ''));
                    if ($k === 'billing_currency' && ! preg_match('/^[A-Z]{3}$/', strtoupper($v))) {
                        throw new BizException('Currency: three letters, for example ZMW.');
                    }
                    if (in_array($k, ['invoice_due_days', 'grace_days'], true) && ($v === '' || ! ctype_digit($v) || (int) $v > 90)) {
                        throw new BizException(self::SETTINGS[$k][0] . ': 0 to 90.');
                    }
                    $v = $k === 'billing_currency' ? strtoupper($v) : $v;
                    if ((string) Settings::get($k) !== $v) {
                        $before[$k] = Settings::get($k);
                        $after[$k]  = $v;
                        Settings::set($k, $v);
                    }
                }
                [$b2, $a2] = Pay::saveKeys(null, $this->request->getPost());
                if ($after || $a2) {
                    Tenancy::run(null, static fn () => Audit::log('settings.billing', 'settings', null, $before + $b2, $after + $a2));
                }
            });
            return $this->ok('Billing settings saved.', 'platform/billing');
        }, site_url('platform/billing'));
    }
}
