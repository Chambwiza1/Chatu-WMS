<?php

namespace App\Commands;

use App\Libraries\Core\Access;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Setup\Demo;
use App\Libraries\Setup\Schema;
use App\Libraries\Setup\Seed;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Automated acceptance tests. Runs against a separate database whose name
 * must contain "test"; every test file starts from a freshly built demo database.
 *
 *   php spark wms:test --db chatuwms_test [--only dep]
 */
class SelfTest extends BaseCommand
{
    protected $group       = 'Warehouse';
    protected $name        = 'wms:test';
    protected $description = 'Run the automated acceptance tests against a test database.';
    protected $usage       = 'wms:test --db <test database> [--only <file prefix>]';

    public function run(array $params)
    {
        $dbName = CLI::getOption('db') ?? ($params['db'] ?? null);
        if (! $dbName || ! str_contains($dbName, 'test')) {
            CLI::error('Give a test database whose name contains "test": --db chatuwms_test');
            return EXIT_ERROR;
        }
        if (! defined('CHATUWMS_TESTING')) {
            define('CHATUWMS_TESTING', true);
        }
        $cfg             = config(\Config\Database::class)->default;
        $cfg['database'] = $dbName;
        $cfg['DBDebug']  = true;
        $only            = CLI::getOption('only');

        $files = glob(ROOTPATH . 'tests/acceptance/*.php');
        sort($files);
        $pass = $fail = 0;
        $failures = [];
        foreach ($files as $file) {
            $base = basename($file, '.php');
            if ($only && ! str_starts_with(strtolower($base), strtolower($only))) {
                continue;
            }
            $tests = require $file;
            CLI::write($base, 'yellow');
            foreach ($tests as $name => $fn) {
                $conn = Database::connect($cfg, false);
                Db::reset($conn);
                $ctx = self::freshDatabase($conn);
                Settings::flush();
                Access::flush();
                Auth::actAs(null);
                \App\Libraries\Core\Tenancy::set($ctx['tenant']);
                $t0 = microtime(true);
                try {
                    $fn(new TestKit($ctx));
                    Auth::actAs(null);
                    $problems = [];
                    foreach (Db::unscoped(static fn () => array_column(Db::rows('SELECT id FROM {tenants} ORDER BY id'), 'id')) as $tid) {
                        $problems += array_filter(\App\Libraries\Core\Tenancy::run((int) $tid, static fn () => \App\Libraries\Reports\Reconcile::run(false)));
                    }
                    if ($problems) {
                        throw new \RuntimeException('Reconciliation failed after the test: ' . json_encode($problems));
                    }
                    $pass++;
                    CLI::write('  ✔ ' . $name . sprintf(' (%.1fs)', microtime(true) - $t0));
                } catch (Throwable $e) {
                    $fail++;
                    $failures[] = $base . ' / ' . $name;
                    CLI::write('  ✘ ' . $name, 'red');
                    CLI::write('    ' . get_class($e) . ': ' . $e->getMessage(), 'red');
                    CLI::write('    at ' . str_replace(ROOTPATH, '', $e->getFile()) . ':' . $e->getLine());
                    foreach (array_slice($e->getTrace(), 0, 4) as $tr) {
                        if (isset($tr['file'])) {
                            CLI::write('       ' . str_replace(ROOTPATH, '', $tr['file']) . ':' . $tr['line']);
                        }
                    }
                }
                $conn->close();
            }
        }
        CLI::newLine();
        if ($fail) {
            CLI::write("FAILED: $fail failed, $pass passed", 'red');
            foreach ($failures as $f) {
                CLI::write('  - ' . $f, 'red');
            }
            return EXIT_ERROR;
        }
        CLI::write("OK: $pass tests passed", 'green');
        return EXIT_SUCCESS;
    }

    private static function freshDatabase($conn): array
    {
        $conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($conn->query('SHOW TABLES')->getResultArray() as $r) {
            $conn->query('DROP TABLE IF EXISTS `' . reset($r) . '`');
        }
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
        foreach (Schema::all($conn->getPrefix()) as $sql) {
            if ($conn->query($sql) === false) {
                throw new \RuntimeException('Schema failed: ' . $conn->error()['message']);
            }
        }
        \App\Libraries\Setup\Upgrade::v020($conn);
        \App\Libraries\Setup\Upgrade::v021($conn);
        \App\Libraries\Setup\UpgradeCore::v030($conn);
        \App\Libraries\Setup\UpgradeCore15::run($conn);
        \App\Libraries\Setup\UpgradeReset::run($conn);
        \App\Libraries\Setup\UpgradeSignup::run($conn);
        \App\Libraries\Setup\UpgradeSignIn::run($conn);
        \App\Libraries\Setup\Upgrade::v040($conn);
        Db::reset($conn);
        \App\Libraries\Core\Tenancy::set(null);
        Settings::flush();
        Settings::set('edition', 'saas');
        Seed::base();
        \App\Libraries\Master\Items::flush();
        return Demo::build();
    }
}
