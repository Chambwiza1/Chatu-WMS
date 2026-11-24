<?php

namespace App\Filters;

use App\Libraries\Core\Auth;
use App\Libraries\Core\Settings;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Requires a signed-in user and ends idle sessions. */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = Auth::user();
        $idle = max(5, (int) Settings::get('session_idle_minutes')) * 60;
        if ($user && (time() - (int) session('last_seen')) > $idle) {
            Auth::logout();
            $user = null;
            session()->setFlashdata('error', 'You were signed out after a period of inactivity.');
        }
        if (! $user) {
            $path = '/' . ltrim($request->getUri()->getPath(), '/');
            if (str_starts_with($path, '/api/') || $path === '/live') {
                return service('response')->setStatusCode(401)->setJSON(['error' => 'Please sign in.']);
            }
            if ($request->getMethod() === 'GET') {
                session()->set('after_login', $path . ($request->getUri()->getQuery() ? '?' . $request->getUri()->getQuery() : ''));
            }
            return redirect()->to(site_url('login'));
        }
        if ('/' . ltrim($request->getUri()->getPath(), '/') !== '/live') {
            session()->set('last_seen', time()); // core 1.1 (E2): the pop-up check every minute is not activity
        }
        \App\Filters\TenantFilter::resolve();
        \App\Libraries\Jobs::maybeRunOnVisit();
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
