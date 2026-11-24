<?php

namespace App\Commands;

use App\Libraries\Setup\Installer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Command-line installer for Linux servers.
 *   php spark wms:install                 (asks each question)
 *   php spark wms:install --non-interactive --base-url https://wms.example.com --db-name wms --db-user wms --db-pass ... \
 *        --edition single|saas --company "Acme Ltd" --wh-code MAIN --wh-name "Main warehouse" --admin-password ... [--manager-username ... --manager-password ...] [--demo]
 */
class Install extends BaseCommand
{
    protected $group       = 'Warehouse';
    protected $name        = 'wms:install';
    protected $description = 'Install Chatu Warehouse: write .env, create the tables, the first warehouse and users.';

    public function run(array $params)
    {
        if (Installer::installed()) {
            CLI::error('Already installed (writable/installed.lock exists).');
            return EXIT_ERROR;
        }
        $ni  = CLI::getOption('non-interactive') !== null;
        $ask = static function (string $opt, string $q, string $default = '', bool $secret = false) use ($ni): string {
            $v = CLI::getOption($opt);
            if ($v !== null && $v !== true) {
                return (string) $v;
            }
            if ($ni) {
                return $default;
            }
            if ($secret) {
                CLI::write($q . ': ', 'yellow');
                system('stty -echo 2>/dev/null');
                $r = trim((string) fgets(STDIN));
                system('stty echo 2>/dev/null');
                CLI::newLine();
                return $r;
            }
            return (string) CLI::prompt($q, $default !== '' ? $default : null);
        };
        foreach (Installer::requirements() as [$label, $ok, $help]) {
            CLI::write(($ok ? '  ok   ' : '  FIX  ') . $label . ($ok ? '' : ' - ' . $help), $ok ? 'green' : 'red');
        }
        $c = [
            'base_url'       => $ask('base-url', 'Site address (https://...)', 'http://localhost:8080/'),
            'db_host'        => $ask('db-host', 'Database server', '127.0.0.1'),
            'db_port'        => $ask('db-port', 'Database port', '3306'),
            'db_name'        => $ask('db-name', 'Database name', 'chatuwms'),
            'db_user'        => $ask('db-user', 'Database user', 'chatuwms'),
            'db_pass'        => $ask('db-pass', 'Database password', '', true),
            'db_prefix'      => $ask('db-prefix', 'Table prefix', 'cw_'),
            'edition'        => $ask('edition', 'Edition: single (one company) or saas (online service for many organisations)', 'single'),
            'company'        => $ask('company', 'Company name (single company)', 'My Company Ltd'),
            'currency_symbol' => $ask('currency-symbol', 'Currency symbol', 'K'),
            'wh_code'        => $ask('wh-code', 'First warehouse code', 'MAIN'),
            'wh_name'        => $ask('wh-name', 'First warehouse name', 'Main warehouse'),
            'wh_town'        => $ask('wh-town', 'Town', ''),
            'admin_username' => $ask('admin-username', 'Your username (account owner, or platform owner)', 'admin'),
            'admin_name'     => $ask('admin-name', 'Your name', 'Administrator'),
            'admin_email'    => $ask('admin-email', 'Your e-mail', ''),
            'admin_password' => $ask('admin-password', 'Your password (10+ characters, letters and numbers)', '', true),
            'manager_username' => $ask('manager-username', 'Warehouse manager username (empty to skip)', ''),
            'manager_name'   => $ask('manager-name', 'Warehouse manager name', ''),
            'manager_password' => (string) (CLI::getOption('manager-password') ?? ''),
            'demo'           => CLI::getOption('demo') !== null,
            'timezone'       => $ask('timezone', 'Time zone', 'Africa/Lusaka'),
        ];
        try {
            $r = Installer::install($c);
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
        CLI::write('Installed. Open ' . $r['base_url'] . ' and sign in as ' . $c['admin_username'] . '.', 'green');
        CLI::write('Add the scheduled job:  */5 * * * * php ' . ROOTPATH . 'spark wms:cron >/dev/null 2>&1');
        return EXIT_SUCCESS;
    }
}
