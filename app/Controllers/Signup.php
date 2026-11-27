<?php

namespace App\Controllers;

use App\Libraries\Core\BizException;
use App\Libraries\Core\PasswordReset;
use App\Libraries\Core\Settings;
use App\Libraries\Platform\Product;
use App\Libraries\Platform\Signup as S;

/** ChatuERP core 1.5.4: sign-up by the customer, and the platform owner's list of sign-ups. The same file in every product. */
class Signup extends BaseController
{
    private function show(array $extra = [])
    {
        return $this->render('signup/form', $extra + ['open' => S::open(), 'mode' => S::mode(), 'fields' => Product::signupFields(), 'product' => Product::NAME, 'old' => [], 'error' => null],
            S::mode() === 'self' ? 'Sign up' : 'Ask for ' . Product::NAME);
    }

    public function form()
    {
        return $this->show(['old' => ['q' => array_intersect_key((array) $this->request->getGet(), Product::signupFields())]]);
    }

    public function submit()
    {
        $d = (array) $this->request->getPost();
        if (trim((string) ($d['website'] ?? '')) !== '') {   // a field people never see: only a robot fills it in
            return $this->render('signup/sent', ['mode' => S::mode(), 'product' => Product::NAME, 'hours' => S::HOURS], 'Thank you');
        }
        try {
            S::submit($d, (string) $this->request->getIPAddress(), static fn (string $token): string => site_url('signup/confirm/' . $token));
            PasswordReset::sendAfterResponse();
        } catch (BizException $e) {
            unset($d['password'], $d['password2']);
            return $this->show(['old' => $d, 'error' => $e->getMessage()]);
        }
        return $this->render('signup/sent', ['mode' => S::mode(), 'product' => Product::NAME, 'hours' => S::HOURS, 'email' => (string) ($d['email'] ?? '')], 'Thank you');
    }

    public function confirm(string $token)
    {
        $this->response->setHeader('Cache-Control', 'no-store')->setHeader('Referrer-Policy', 'no-referrer');
        return $this->render('signup/confirm', ['s' => S::ready() ? S::find($token) : null, 'action' => site_url('signup/confirm/' . $token), 'product' => Product::NAME], 'Confirm your sign-up');
    }

    /** The button on the confirm page: a link opened by a mail scanner creates nothing. */
    public function doConfirm(string $token)
    {
        try {
            $s = S::confirm($token);
            PasswordReset::sendAfterResponse();
        } catch (BizException $e) {
            return $this->render('signup/confirm', ['s' => null, 'action' => '', 'product' => Product::NAME, 'error' => $e->getMessage()], 'Confirm your sign-up');
        }
        session()->setFlashdata('ok', $s['org_name'] . ' is ready. Sign in as ' . $s['username'] . ' with the password you chose.');
        return redirect()->to(site_url('login'));
    }

    // ----- platform owner -----

    public function index()
    {
        return $this->guard(function () {
            $status = (string) $this->request->getGet('status');
            return $this->render('platform/signups', ['rows' => S::list(isset(S::STATUSES[$status]) ? $status : null), 'status' => $status, 'open' => Settings::get('signup_open', '1') === '1',
                'mode' => S::mode(), 'mailReady' => PasswordReset::available(), 'product' => Product::NAME], 'Sign-ups');
        });
    }

    public function decide(int $id)
    {
        return $this->act(function () use ($id) {
            S::decide($id, (string) $this->in('status', ''), (string) $this->in('note', ''));
            return $this->ok('Saved.', 'platform/signups');
        }, site_url('platform/signups'));
    }

    public function open()
    {
        return $this->act(function () {
            \App\Libraries\Core\Access::requirePlatform('platform.settings');
            Settings::set('signup_open', $this->in('open') === '1' ? '1' : '0');
            return $this->ok($this->in('open') === '1' ? 'The sign-up page is open.' : 'The sign-up page is closed.', 'platform/signups');
        }, site_url('platform/signups'));
    }

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\App\Libraries\Core\DeniedException $e) {
            return $this->forbidden($e->getMessage());
        }
    }
}
