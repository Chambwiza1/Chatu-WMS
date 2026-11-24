<?php

namespace App\Controllers;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Jobs;
use App\Libraries\Platform\Tenants;
use App\Libraries\Reports\Integrity;
use App\Libraries\Setup\Upgrade;

/** The platform owner's console (SaaS edition): organisations, installation settings, scheduled job, integrity check. */
class Platform extends BaseController
{
    public function index()
    {
        return $this->page(fn () => $this->render('platform/index', [
            'rows'    => Access::platform('platform.tenants') ? Tenants::list() : [],
            'tick'    => Jobs::heartbeatAge(),
            'pending' => Access::platform('platform.settings') ? Upgrade::pending() : [],
            'last'    => json_decode((string) Settings::get('last_integrity'), true),
            'cronKey' => Access::platform('platform.settings') ? Settings::get('cron_key') : null,
            'jobs'    => Db::rows('SELECT * FROM {jobs_log} ORDER BY id DESC LIMIT 5'),
        ], 'Platform'));
    }

    public function newTenant()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.tenants');
            return $this->render('platform/new', [], 'New organisation');
        });
    }

    public function createTenant()
    {
        return $this->act(function () {
            $in    = $this->request->getPost();
            $owner = ['username' => $in['owner_username'] ?? '', 'name' => $in['owner_name'] ?? '', 'email' => $in['owner_email'] ?? '', 'password' => $in['owner_password'] ?? ''];
            $r     = Tenants::create(['name' => $in['name'] ?? '', 'slug' => $in['slug'] ?? '', 'country' => $in['country'] ?? 'ZM', 'timezone' => $in['timezone'] ?? 'Africa/Lusaka',
                'status' => $in['status'] ?? 'active', 'location_code' => $in['location_code'] ?? 'HQ', 'location_name' => $in['location_name'] ?? '', 'location_town' => $in['location_town'] ?? '', 'owner' => $owner]);
            return $this->ok('Organisation added. Its account owner can sign in now.', 'platform/tenants/' . $r['tenant']);
        }, site_url('platform/tenants/new'));
    }

    public function tenant(int $id)
    {
        return $this->page(function () use ($id) {
            $t   = Tenants::get($id);
            $row = array_values(array_filter(Tenants::list(), static fn ($r) => (int) $r['id'] === $id))[0];
            $log = Db::rows('SELECT * FROM {audit_events} WHERE tenant_id IS NULL AND object_type = ? AND object_id = ? ORDER BY id DESC LIMIT 50', ['tenant', (string) $id]);
            return $this->render('platform/tenant', ['t' => $t, 'row' => $row, 'log' => $log], $t['name']);
        });
    }

    public function status(int $id)
    {
        return $this->act(function () use ($id) {
            Tenants::setStatus($id, (string) $this->in('status'), (string) $this->in('reason', ''));
            return $this->ok('Status changed.', 'platform/tenants/' . $id);
        }, site_url('platform/tenants/' . $id));
    }

    public function settings()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.settings');
            return $this->render('platform/settings', ['fields' => Admin::platformFields()], 'Platform settings');
        });
    }

    public function saveSettings()
    {
        return $this->act(function () {
            Access::requirePlatform('platform.settings');
            Db::atomic(function () {
                [$before, $after] = PlatformTools::savePlatformSettings($this, Admin::platformFields());
                if ($after) {
                    Audit::log('settings.update', 'settings', null, $before, $after);
                }
            });
            return $this->ok('Settings saved.', 'platform/settings');
        }, site_url('platform/settings'));
    }

    public function integrity()
    {
        return $this->page(function () {
            Access::requirePlatform('platform.settings');
            return $this->render('platform/integrity', ['last' => json_decode((string) Settings::get('last_integrity'), true), 'result' => session()->getFlashdata('integrity')], 'Integrity check');
        });
    }

    public function runIntegrity()
    {
        return $this->act(function () {
            Access::requirePlatform('platform.settings');
            $r = Integrity::run();
            $n = array_sum(array_map('count', $r));
            Audit::log('integrity.run', 'system', null, null, ['problems' => $n], $n ? 'failed' : 'success');
            session()->setFlashdata('integrity', $r);
            return $this->ok($n ? $n . ' problem(s) found.' : 'Everything agrees.', 'platform/integrity');
        }, site_url('platform/integrity'));
    }

    public function upgrade()
    {
        return $this->act(function () {
            Access::requirePlatform('platform.settings');
            return PlatformTools::upgrade($this, 'platform');
        }, site_url('platform'));
    }
}
