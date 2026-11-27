<?php

namespace App\Controllers;

use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Tenancy;

class AuthController extends BaseController
{
    public function login()
    {
        if (Auth::user()) {
            return redirect()->to(site_url('/'));
        }
        return $this->render('auth/login', [], 'Sign in');
    }

    public function doLogin()
    {
        $res = Auth::attempt((string) $this->in('username', ''), (string) $this->in('password', ''));
        if (! $res['ok']) {
            session()->setFlashdata('error', $res['message']);
            return redirect()->to(site_url('login'))->withInput();
        }
        Auth::startSession($res['user']);
        $uid = (int) $res['user']['id'];
        if (\App\Filters\UpdateFilter::waiting()) {
            return redirect()->to(site_url('update')); // the database waits for the update: nothing else works yet
        }
        // core 1.1: someone in several organisations chooses; the platform owner goes to the console
        $mine = Tenancy::single() ? [] : Tenancy::memberships($uid);
        if (! Tenancy::single() && count($mine) !== 1) {
            if (count($mine) > 1) {
                return redirect()->to(site_url('org'));
            }
            if (Access::platform('platform.tenants', $uid)) {
                return redirect()->to(site_url('platform'));
            }
        }
        $to = session('after_login') ?: '/';
        session()->remove('after_login');
        if (! str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }
        return redirect()->to(site_url(ltrim($to, '/')));
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->to(site_url('login'));
    }

    /** Choose the organisation to work in (people who belong to more than one). */
    public function org()
    {
        $uid = Auth::id();
        return $this->render('auth/org', ['rows' => Tenancy::memberships($uid), 'platform' => Access::platform('platform.tenants')], 'Choose an organisation');
    }

    public function switchOrg()
    {
        $uid = Auth::id();
        $tid = (int) $this->in('tenant_id');
        if (! Tenancy::isMember($uid, $tid)) {
            Audit::denied('org.switch', 'Not a member of organisation ' . $tid);
            return $this->notFound('Organisation');
        }
        session()->set('tid', $tid);
        session()->remove('wh');
        Tenancy::set($tid);
        Audit::log('org.switch', 'tenant', $tid);
        return redirect()->to(site_url('/'));
    }

    public function account()
    {
        $wh    = Tenancy::id() !== null ? Access::memberWarehouses((int) Auth::id()) : ['all' => false, 'ids' => []];
        $codes = $wh['ids'] ? array_column(Db::rows('SELECT code FROM {warehouses} WHERE tenant_id = {tid} AND id IN (' . implode(',', array_map('intval', $wh['ids'])) . ') ORDER BY code'), 'code') : [];
        return $this->render('auth/account', ['roles' => Access::roles((int) Auth::id()), 'wh' => $wh, 'whCodes' => $codes], 'My account');
    }

    /** core 1.3 (C1): light, dark or follow the device, kept on the person's account (a device may override it). */
    public function theme()
    {
        $t = (string) $this->in('theme', '');
        if (! in_array($t, ['auto', 'light', 'dark'], true)) {
            return $this->request->isAJAX() ? $this->response->setStatusCode(422)->setJSON(['ok' => false]) : $this->ok('Choose Follow the device, Light or Dark.', 'account');
        }
        Auth::setTheme((int) Auth::id(), $t);
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => true, 'theme' => $t]);
        }
        return $this->ok('Saved: ' . ['auto' => 'follow the device', 'light' => 'light', 'dark' => 'dark'][$t] . ', on every device you sign in on.', 'account');
    }

    public function password()
    {
        return $this->act(function () {
            $uid = Auth::id();
            if (! Auth::verifyPassword($uid, (string) $this->in('current', ''))) {
                throw new BizException('Your current password is not correct.');
            }
            if ($this->in('new') !== $this->in('confirm')) {
                throw new BizException('The new passwords do not match.');
            }
            Users::setPassword($uid, (string) $this->in('new'), true);
            return $this->ok('Password changed.', 'account');
        }, site_url('account'));
    }

    /** core 1.5.1 (B12): a forgotten password, reset by an e-mailed link. */
    public function forgot()
    {
        return $this->render('auth/forgot', ['available' => \App\Libraries\Core\PasswordReset::available(), 'minutes' => \App\Libraries\Core\PasswordReset::MINUTES], 'Forgotten password');
    }

    public function doForgot()
    {
        $PR = \App\Libraries\Core\PasswordReset::class;
        if ($PR::available()) {
            $PR::request((string) $this->in('username', ''), (string) $this->request->getIPAddress(), static fn (string $token): string => site_url('reset-password/' . $token));
            $PR::sendAfterResponse();
        }
        // the same answer whether or not there is such an account
        return $this->render('auth/forgot', ['available' => $PR::available(), 'sent' => true, 'minutes' => $PR::MINUTES], 'Forgotten password');
    }

    public function reset(string $token)
    {
        $PR = \App\Libraries\Core\PasswordReset::class;
        $this->response->setHeader('Cache-Control', 'no-store')->setHeader('Referrer-Policy', 'no-referrer');
        return $this->render('auth/reset', ['u' => $PR::ready() ? $PR::find($token) : null, 'action' => site_url('reset-password/' . $token), 'minutes' => $PR::MINUTES], 'Choose a new password');
    }

    public function doReset(string $token)
    {
        $PR = \App\Libraries\Core\PasswordReset::class;
        try {
            $PR::complete($token, (string) $this->in('password', ''), (string) $this->in('password2', ''));
        } catch (BizException $e) {
            session()->setFlashdata('error', $e->getMessage());
            return redirect()->to(site_url('reset-password/' . $token));
        }
        $PR::sendAfterResponse();
        // whoever is signed in on this browser is signed out, without losing the message below
        session()->remove(['uid', 'skey', 'tid', 'last_seen', 'confirmed_at']);
        session()->regenerate(true);
        session()->setFlashdata('ok', 'Your password is changed. Sign in with the new one.');
        return redirect()->to(site_url('login'));
    }
}
