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
}
