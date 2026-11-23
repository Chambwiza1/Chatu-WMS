<?php

namespace App\Controllers;

use App\Libraries\Core\BizException;
use App\Libraries\Setup\Installer;
use Config\App;
use Throwable;

/** Web installer, for cPanel and any host without shell access. Disabled once installed. */
class Install extends BaseController
{
    public function index()
    {
        if (Installer::installed()) {
            return redirect()->to(site_url('login'));
        }
        return view('install/index', ['req' => Installer::requirements(), 'base' => App::detectBaseURL(), 'error' => session()->getFlashdata('error'), 'old' => session()->getFlashdata('old') ?: []]);
    }

    public function run()
    {
        if (Installer::installed()) {
            return redirect()->to(site_url('login'));
        }
        $in = $this->request->getPost();
        try {
            if (($in['admin_password'] ?? '') !== ($in['admin_password2'] ?? '')) {
                throw new BizException('Your two passwords do not match.');
            }
            $res = Installer::install([
                'base_url' => (string) ($in['base_url'] ?? ''), 'db_host' => (string) ($in['db_host'] ?? 'localhost'), 'db_port' => (string) ($in['db_port'] ?? '3306'),
                'db_name' => (string) ($in['db_name'] ?? ''), 'db_user' => (string) ($in['db_user'] ?? ''), 'db_pass' => (string) ($in['db_pass'] ?? ''), 'db_prefix' => (string) ($in['db_prefix'] ?? 'cw_'),
                'company' => (string) ($in['company'] ?? ''), 'currency_symbol' => (string) ($in['currency_symbol'] ?? 'K'),
                'wh_code' => (string) ($in['wh_code'] ?? 'MAIN'), 'wh_name' => (string) ($in['wh_name'] ?? 'Main warehouse'), 'wh_town' => (string) ($in['wh_town'] ?? ''),
                'admin_username' => (string) ($in['admin_username'] ?? 'admin'), 'admin_name' => (string) ($in['admin_name'] ?? 'Administrator'), 'admin_email' => (string) ($in['admin_email'] ?? ''), 'admin_password' => (string) ($in['admin_password'] ?? ''),
                'manager_username' => (string) ($in['manager_username'] ?? ''), 'manager_name' => (string) ($in['manager_name'] ?? ''), 'manager_password' => (string) ($in['manager_password'] ?? ''),
                'demo' => ! empty($in['demo']), 'timezone' => (string) ($in['timezone'] ?? 'Africa/Lusaka'),
            ]);
            return view('install/done', ['base' => $res['base_url'], 'demo' => ! empty($in['demo'])]);
        } catch (Throwable $e) {
            $msg = $e instanceof BizException ? $e->getMessage() : 'Installation failed: ' . $e->getMessage();
            log_message('error', 'Install failed: ' . $e->getMessage());
            unset($in['db_pass'], $in['admin_password'], $in['admin_password2'], $in['manager_password'], $in['csrf_test_name']);
            session()->setFlashdata(['error' => $msg, 'old' => $in]);
            return redirect()->to(site_url('install'));
        }
    }
}
