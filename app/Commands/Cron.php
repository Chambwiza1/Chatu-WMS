<?php

namespace App\Commands;

use App\Libraries\Jobs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** php spark wms:cron   (run every 5 minutes from cron or cPanel Cron Jobs) */
class Cron extends BaseCommand
{
    protected $group       = 'Warehouse';
    protected $name        = 'wms:cron';
    protected $description = 'Run scheduled jobs: approval reminders, escalations and expiry, e-mails, periods, integrity checks.';

    public function run(array $params)
    {
        if (! is_file(WRITEPATH . 'installed.lock')) {
            CLI::error('Not installed yet.');
            return EXIT_ERROR;
        }
        $out = Jobs::run();
        CLI::write(json_encode($out));
        return isset($out['error']) ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
