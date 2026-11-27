<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\SignInAs;
use App\Libraries\Core\Tenancy;
use App\Libraries\Platform\Product;

/** ChatuERP core 1.2: signing in as someone; the organisation's support access. The same file in every product on the shared tenant model. */
class SignIn extends BaseController
{
    // ----- signing in as someone -----

    public function start(int $userId)
    {
        return $this->act(function () use ($userId) {
            SignInAs::startAdmin($userId, (string) $this->in('reason', ''));
            return redirect()->to(site_url('/'));
        }, site_url(sprintf(Product::SIGNIN_AS['page'], $userId)));
    }

    public function support(int $tenantId)
    {
        return $this->act(function () use ($tenantId) {
            SignInAs::startSupport($tenantId, (string) $this->in('reason', ''));
            return redirect()->to(site_url('/'));
        }, site_url('platform/tenants/' . $tenantId));
    }

    public function stop()
    {
        $to = SignInAs::stop();
        return $this->ok('You are back in your own sign-in.', $to);
    }

    // ----- the organisation's side -----

    public function index()
    {
        return $this->page(function () {
            Access::require(Product::SIGNIN_AS['manage']);
            return $this->render('admin/support', ['until' => SignInAs::supportUntil(), 'rows' => SignInAs::history(), 'hours' => SignInAs::SUPPORT_HOURS, 'saas' => ! Tenancy::single(),
                'tz' => (string) (Tenancy::current()['timezone'] ?? 'Africa/Lusaka')], 'Support access');
        });
    }

    public function allow()
    {
        return $this->act(function () {
            $h = (int) $this->in('hours', 0);
            SignInAs::allowSupport($h);
            return $this->ok($h ? 'Support may sign in until the time shown.' : 'Support access is closed. Any support sign-in ends at its next page.', 'admin/support');
        }, site_url('admin/support'));
    }

    public function actions(int $logId)
    {
        return $this->page(function () use ($logId) {
            $rows = SignInAs::actions($logId);
            $log  = array_values(array_filter(SignInAs::history(500), static fn ($r) => (int) $r['id'] === $logId))[0];
            return $this->render('admin/support_actions', ['log' => $log, 'rows' => $rows], 'What was saved');
        });
    }
}
