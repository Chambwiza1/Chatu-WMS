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
}
