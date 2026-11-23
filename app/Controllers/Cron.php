<?php

namespace App\Controllers;

use App\Libraries\Core\Settings;
use App\Libraries\Jobs;

/** Cron by URL (for hosts that only offer web cron): /cron/run?key=... */
class Cron extends BaseController
{
    public function run()
    {
        $key = (string) Settings::get('cron_key');
        if ($key === '' || ! hash_equals($key, (string) $this->request->getGet('key'))) {
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }
        return $this->response->setJSON(Jobs::run());
    }

    /**
     * core 1.1 (A13, as VisitorPass): for an uptime monitor. 200 while the scheduled job has run in the last
     * 15 minutes (it runs every 5), otherwise 503. Says nothing about any organisation's data.
     */
    public function health()
    {
        $at  = (int) Settings::get('last_cron_at', '0');
        $age = $at > 0 ? max(0, time() - $at) : null;
        $ok  = $age !== null && $age <= 900;
        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode($ok ? 200 : 503)
            ->setJSON(['ok' => $ok, 'job_age_seconds' => $age, 'version' => \App\Libraries\Core\Audit::APP_VERSION]);
    }
}
