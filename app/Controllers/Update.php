<?php

namespace App\Controllers;

use App\Filters\TenantFilter;
use App\Filters\UpdateFilter;
use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Setup\Upgrade;
use Throwable;

/**
 * core 1.1 (A9, as ChatuPOS beta19 and Chatu Assets 2.0): the in-app database update. Every page comes here while
 * steps wait (UpdateFilter). One press updates the database of every organisation, since they share it. Who may press
 * it: the platform owner, or in the single-company edition someone with admin.settings (in a 0.2 database, before
 * organisations exist, a holder of admin.settings in the old roles). The page uses its own small template: the full layout reads tables a waiting step may change.
 */
class Update extends BaseController
{
    public static function mayUpdate(): bool
    {
        if (! Auth::id()) {
            return false;
        }
        try {
            if (! Db::value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [Db::t('tenants')])) {
                // 0.2.x: one company, roles held for the whole company or a warehouse
                return (bool) Db::value("SELECT 1 FROM {user_roles} ur JOIN {role_permissions} rp ON rp.role_id = ur.role_id WHERE ur.user_id = ? AND rp.permission_code = 'admin.settings' LIMIT 1", [Auth::id()]);
            }
            if (Access::platform('platform.settings')) {
                return true;
            }
            if (Tenancy::single()) {
                TenantFilter::resolve();
                return Tenancy::id() !== null && Access::can('admin.settings');     // the account owner holds every right
            }
        } catch (Throwable $e) {
            log_message('error', 'Update rights: ' . $e->getMessage());
        }
        return false;
    }

    public function index()
    {
        $todo = UpdateFilter::waiting();
        if (! $todo) {
            return redirect()->to(site_url('/'));
        }
        $this->response->setStatusCode(503)->setHeader('Retry-After', '120');
        return view('update', ['todo' => $todo, 'may' => self::mayUpdate(), 'signedIn' => (bool) Auth::id(), 'single' => Tenancy::single(),
            'from' => (string) Settings::get('app_version'), 'error' => session()->getFlashdata('error')]);
    }

    public function run()
    {
        if (! self::mayUpdate()) {
            session()->setFlashdata('error', 'Only the platform owner (or, in the single-company edition, the administrator) can update the database.');
            return redirect()->to(site_url('update'));
        }
        $todo = Upgrade::pending();
        if (! $todo) {
            return redirect()->to(site_url('/'));
        }
        try {
            $runner = service('migrations');
            $runner->setNamespace('App');
            $runner->latest();
        } catch (Throwable $e) {
            log_message('error', 'Database update failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            try {
                Audit::log('system.upgrade_failed', 'system', null, null, ['steps' => $todo, 'version' => Audit::APP_VERSION, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            } catch (Throwable $ignored) {
            }
            session()->setFlashdata('error', 'The update stopped: ' . $e->getMessage() . ' Nothing after that step was changed. Send this message to Chatu Innovations; restore the backup only if they ask you to.');
            return redirect()->to(site_url('update'));
        }
        \App\Libraries\Core\Db::reset();
        Settings::flush();
        Settings::set('app_version', Audit::APP_VERSION);
        cache()->delete(UpdateFilter::cacheKey());
        Audit::log('system.upgrade', 'system', null, null, ['steps' => $todo, 'version' => Audit::APP_VERSION]);
        session()->setFlashdata('ok', 'Database updated to ' . Audit::APP_VERSION . ' (' . count($todo) . ' step' . (count($todo) > 1 ? 's' : '') . ')' . (Tenancy::single() ? '' : ' for every organisation') . '. Everyone can carry on.');
        return redirect()->to(site_url(Access::platform('platform.settings') ? 'platform' : '/'));     // straight there, so the message is not lost on the way
    }
}
