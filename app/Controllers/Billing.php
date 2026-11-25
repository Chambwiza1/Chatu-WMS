<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Tenancy;
use App\Libraries\Platform\Billing as B;
use App\Libraries\Platform\Modules;
use App\Libraries\Platform\Pay;
use App\Libraries\Platform\Product;

/**
 * core 1.5: the organisation's side. Billing (its plan, limits, trial, invoices from Chatu Innovations and paying
 * them online) and Administration > Modules (the modules it may decide on). Billing works while the organisation is
 * suspended, so that it can pay. (Chatu Warehouse takes no payments of its own, so there are no merchant keys here.)
 */
class Billing extends BaseController
{
    private function mayBill(): void
    {
        $tid = Tenancy::require();
        $owner = (bool) Db::value('SELECT is_account_owner FROM {tenant_users} WHERE tenant_id = ? AND user_id = ? AND status = \'active\'', [$tid, (int) Auth::id()]);
        if (! $owner && ! Access::can('admin.settings') && ! Access::can('billing.manage')) {
            throw new DeniedException('Billing is for the account owner and those given Settings, Plan and billing (billing.manage).');
        }
    }

    public function index()
    {
        return $this->page(function () {
            $this->mayBill();
            $tid = Tenancy::require();
            $t = B::tenant($tid);
            return $this->render('billing/index', ['t' => $t, 'limits' => B::limits($tid), 'days' => B::trialDaysLeft($t), 'rows' => B::invoices($tid, 100),
                'single' => Tenancy::single(), 'currency' => B::currency()], 'Billing');
        });
    }

    public function invoice(int $id)
    {
        return $this->page(function () use ($id) {
            $this->mayBill();
            $tid = Tenancy::require();
            return $this->render('billing/invoice', ['i' => B::invoice($id, $tid), 'platform' => false, 'gateways' => Pay::available(null),
                'payments' => Db::rows("SELECT * FROM {gateway_payments} WHERE purpose = 'platform_invoice' AND object_id = ? AND tenant_id = ? ORDER BY id DESC", [$id, $tid])], 'Invoice');
        });
    }

    /** Start paying a Chatu invoice: Lenco asks the phone to approve; DPO sends the person to its payment page. */
    public function pay(int $id)
    {
        return $this->act(function () use ($id) {
            $this->mayBill();
            $tid = Tenancy::require();
            $i = B::invoice($id, $tid);
            if ($i['status'] !== 'issued') {
                throw new BizException('This invoice is ' . $i['status'] . '.');
            }
            if (Db::value("SELECT 1 FROM {gateway_payments} WHERE purpose = 'platform_invoice' AND object_id = ? AND status = 'pending' AND created_at > ?", [$id, gmdate('Y-m-d H:i:s', time() - 600)])) {
                throw new BizException('A payment for this invoice was started a moment ago. Approve it on the phone, or wait ten minutes and try again.');
            }
            $gw = (string) $this->in('gateway', '');
            $p = Pay::start('platform_invoice', $id, $tid, (float) $i['amount'], $gw, false, ['phone' => $this->in('phone', ''), 'operator' => $this->in('operator', ''),
                'return_url' => site_url('pay/return'), 'description' => $i['number'] . ' ' . Product::NAME]);
            if ($gw === 'dpo') {
                return redirect()->to(site_url('pay/go/' . $p['reference'])); // not straight to DPO: form-action 'self' blocks that
            }
            return $this->ok($p['status'] === 'paid' ? 'Paid. Thank you.' : 'Check your phone and approve the payment of ' . $p['currency'] . ' ' . number_format((float) $p['amount'], 2) . '. This page shows it once it is through.', 'billing/invoices/' . $id);
        }, site_url('billing/invoices/' . $id));
    }

    public function check(int $paymentId)
    {
        return $this->act(function () use ($paymentId) {
            $this->mayBill();
            $p = Pay::get($paymentId);
            if ((int) $p['tenant_id'] !== Tenancy::require() || $p['purpose'] !== 'platform_invoice') {
                throw new BizException('Payment not found.');
            }
            $p = Pay::check($paymentId);
            return $this->ok(['paid' => 'Paid. Thank you.', 'pending' => 'Not through yet. Approve it on the phone, then check again.', 'failed' => 'It did not go through.', 'cancelled' => 'It was cancelled.'][$p['status']], 'billing/invoices/' . $p['object_id']);
        }, site_url('billing'));
    }

    // ---- Settings > Modules ---------------------------------------------------------------------------------------

    public function modules()
    {
        return $this->page(function () {
            Access::require('admin.settings');
            $tid = Tenancy::require();
            $rows = [];
            foreach (Product::MODULES as $k => [$name, $what]) {
                $rows[$k] = ['name' => $name, 'what' => $what, 'rule' => Modules::rule($k, $tid), 'on' => Modules::on($k, $tid), 'busy' => Product::inUse($k, $tid)];
            }
            return $this->render('billing/modules', ['rows' => $rows], 'Modules');
        });
    }

    public function saveModule()
    {
        return $this->act(function () {
            Modules::setOwn((string) $this->in('module', ''), $this->in('on') === '1');
            return $this->ok('Saved.', 'admin/modules');
        }, site_url('admin/modules'));
    }
}
