<?php

namespace App\Controllers;

use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;

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

    public function account()
    {
        return $this->render('auth/account', ['roles' => Access::roles(Auth::id())], 'My account');
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
