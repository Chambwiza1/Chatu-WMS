<?php

namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * core 1.1 (B11, from Chatu Assets 1.9.1): CodeIgniter's CSRF check, but an expired form (left open past the idle
 * sign-out, or opened before signing in again in another tab) goes back with a plain message, with nothing saved,
 * instead of the bare "Whoops!" page. A form sent in the background (a popup, a script) gets the message as JSON.
 */
class CsrfFilter extends CSRF
{
    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            return parent::before($request, $arguments);
        } catch (SecurityException $e) {
            log_message('notice', 'Expired form refused: ' . $request->getMethod() . ' ' . current_url());
            // the session, not Auth::id(): this filter can run before the organisation (Construction: its tables) is known
            $signedIn = (bool) session('uid');
            $msg = $signedIn
                ? 'That page had expired, so nothing was saved. It has been reloaded: please try again.'
                : 'You were signed out, so that was not saved. Sign in and try again.';
            $background = $request->isAJAX() || $request->getHeaderLine('X-Chatu-Popup') !== ''
                || str_contains($request->getHeaderLine('Accept'), 'application/json');
            if ($background) {
                return service('response')->setStatusCode(403)->setJSON(['ok' => false, 'error' => $msg, 'expired' => true]);
            }
            session()->setFlashdata('error', $msg);
            if (! $signedIn) {
                return redirect()->to(site_url('login'));
            }
            $back = previous_url();
            $here = current_url();
            return redirect()->to($back && $back !== $here && str_starts_with($back, base_url()) ? $back : site_url('/'));
        }
    }
}
