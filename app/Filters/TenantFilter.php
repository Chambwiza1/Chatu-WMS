<?php

namespace App\Filters;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Tenancy;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Pages of an organisation need an organisation. The one in the session is checked against the user's active
 * memberships on every request (a membership switched off or an organisation cancelled ends access at once).
 * Someone in one organisation goes straight in; someone in several chooses; the platform owner goes to the platform.
 * A suspended organisation is read-only: only sign-out and switching organisation are posted.
 */
class TenantFilter implements FilterInterface
{
    /** Set the current tenant from the session when it is still valid (called for every signed-in request). */
    public static function resolve(): void
    {
        $uid = Auth::id();
        if (! $uid) {
            Tenancy::set(null);
            return;
        }
        if (Tenancy::single()) {
            $only = Tenancy::only();
            Tenancy::set($only !== null && Tenancy::isMember($uid, $only) ? $only : null);
            return;
        }
        $tid = (int) session('tid');
        if ($tid && Tenancy::isMember($uid, $tid)) {
            Tenancy::set($tid);
            return;
        }
        session()->remove('tid');
        $mine = Tenancy::memberships($uid);
        if (count($mine) === 1) {
            session()->set('tid', (int) $mine[0]['id']);
            Tenancy::set((int) $mine[0]['id']);
            return;
        }
        Tenancy::set(null);
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if (Tenancy::id() !== null) {
            $route = trim($request->getUri()->getRoutePath(), '/');
            if (Tenancy::readOnly() && strtolower($request->getMethod()) === 'post' && ! str_starts_with($route, 'billing/')) {   // core 1.5: a suspended organisation can still pay
                session()->setFlashdata('error', 'This organisation is suspended: you can look things up, but nothing can be changed until the account is paid.');
                return redirect()->back();
            }
            // core 1.5: modules the platform owner or the organisation switched off answer 404
            if (\App\Libraries\Platform\Modules::offFor($route) !== null) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
            return null;
        }
        $uid = Auth::id();
        if ($uid && count(Tenancy::memberships($uid)) > 1) {
            return redirect()->to(site_url('org'));
        }
        if ($uid && Access::platform('platform.tenants')) {
            return redirect()->to(site_url('platform'));
        }
        Auth::logout();
        session()->setFlashdata('error', 'Your account is not part of any active organisation. Ask your administrator.');
        return redirect()->to(site_url('login'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
