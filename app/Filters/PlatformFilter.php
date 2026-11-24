<?php

namespace App\Filters;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Tenancy;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Platform pages: only for platform roles, never inside an organisation's context, and not in the single-company edition. */
class PlatformFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        Tenancy::set(null);
        if (Tenancy::single() || ! Access::platform('platform.tenants') && ! Access::platform('platform.settings')) {
            Audit::denied('http.' . strtolower($request->getMethod()) . ' /' . ltrim($request->getUri()->getPath(), '/'), 'Not a platform role');
            helper(['url', 'form', 'app']);
            \App\Filters\TenantFilter::resolve(); // show the page in the user's own organisation, as any other 404
            $me = \App\Libraries\Core\Auth::user();
            return service('response')->setStatusCode(404)->setBody(view('errors/notfound', [
                'message' => 'Page not found.', 'title' => 'Not found', 'me' => $me, 'unread' => 0, 'path' => '/',
                'org' => Tenancy::current(), 'orgs' => $me ? Tenancy::memberships((int) $me['id']) : [],
            ]));
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
