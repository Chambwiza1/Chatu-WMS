<?php

namespace App\Libraries\Setup;

use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Throwable;

/**
 * Installation, shared by the web installer and `php spark wms:install`.
 * Writes .env, creates the tables, seeds the role templates, sets the edition (core 1.1, A1), then creates either the
 * platform owner (online service for many organisations) or the one organisation with its first warehouse, its
 * account owner and an optional warehouse manager (single company), then writes writable/installed.lock.
 */
final class Installer
{
    public const MIN_PHP = '8.1.0';

    public static function lockFile(): string
    {
        return WRITEPATH . 'installed.lock';
    }

    public static function installed(): bool
    {
        return is_file(self::lockFile());
    }

    /** @return list<array{0:string,1:bool,2:string}> [check, ok, help] */
    public static function requirements(): array
    {
        $out   = [];
        $out[] = ['PHP ' . self::MIN_PHP . ' or newer (you have ' . PHP_VERSION . ')', version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'In cPanel, choose a newer version under MultiPHP Manager.'];
        foreach (['intl', 'mbstring', 'mysqli', 'json', 'openssl'] as $ext) {
            $out[] = ['PHP extension ' . $ext, extension_loaded($ext), 'In cPanel: Select PHP Version > Extensions. On Linux: install php-' . $ext . '.'];
        }
        foreach ([WRITEPATH, WRITEPATH . 'session', WRITEPATH . 'logs', WRITEPATH . 'cache', WRITEPATH . 'uploads'] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $out[] = ['Folder writable: ' . str_replace(ROOTPATH, '', $dir), is_dir($dir) && is_writable($dir), 'Set the folder permissions to 755 (File Manager > Permissions).'];
        }
        $env   = ROOTPATH . '.env';
        $out[] = ['Settings file can be written (.env)', is_file($env) ? is_writable($env) : is_writable(ROOTPATH), 'Allow writing to the application folder, or create an empty .env file with permissions 644.'];
        return $out;
    }

    /**
     * @param array{base_url:string, db_host:string, db_port:int|string, db_name:string, db_user:string, db_pass:string, db_prefix:string,
     *              edition:string, company:string, currency_symbol:string, wh_code:string, wh_name:string, wh_town:string,
     *              admin_username:string, admin_name:string, admin_email:string, admin_password:string,
     *              manager_username?:string, manager_name?:string, manager_password?:string, demo?:bool, timezone?:string} $c
     *        edition "saas": the account is the platform owner, who adds organisations and sees none of their stock.
     *        edition "single": the organisation is created from company/wh_*, and the account is its account owner.
     *        The optional manager account runs the warehouses (single company only).
     */
    public static function install(array $c): array
    {
        if (self::installed()) {
            throw new BizException('Already installed. Delete writable/installed.lock only if you really want to install again into an empty database.');
        }
        foreach (self::requirements() as [$label, $ok]) {
            if (! $ok) {
                throw new BizException('Requirement not met: ' . $label);
            }
        }
        $base = rtrim(trim($c['base_url']), '/') . '/';
        if (! preg_match('#^https?://#', $base)) {
            throw new BizException('The site address must start with http:// or https://.');
        }
        $edition = (string) ($c['edition'] ?? '');
        if (! in_array($edition, ['saas', 'single'], true)) {
            throw new BizException('Choose the edition: online service for many organisations, or one organisation on its own server.');
        }
        $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower($c['db_prefix'] ?? 'cw_'));
        if ($err = Auth::validatePassword((string) $c['admin_password'])) {
            throw new BizException('Administrator password: ' . $err);
        }
        foreach (['admin_username', 'manager_username'] as $k) {
            if (($c[$k] ?? '') !== '' && ! preg_match('/^[a-z0-9._-]{3,60}$/', strtolower(trim((string) $c[$k])))) {
                throw new BizException('Usernames: 3 to 60 letters, digits, dot, dash or underscore.');
            }
        }
        $withManager = $edition === 'single' && trim((string) ($c['manager_username'] ?? '')) !== '';
        if ($withManager) {
            if ($err = Auth::validatePassword((string) ($c['manager_password'] ?? ''))) {
                throw new BizException('Manager password: ' . $err);
            }
            if (strtolower(trim($c['manager_username'])) === strtolower(trim($c['admin_username']))) {
                throw new BizException('The administrator and the manager are two different accounts: choose two usernames.');
            }
        }
        if ($edition === 'single' && (trim((string) $c['company']) === '' || trim((string) $c['wh_code']) === '' || trim((string) $c['wh_name']) === '')) {
            throw new BizException('Give the company name and the first warehouse\'s code and name.');
        }

        $cfg = [
            'DSN' => '', 'hostname' => $c['db_host'], 'username' => $c['db_user'], 'password' => $c['db_pass'], 'database' => $c['db_name'],
            'DBDriver' => 'MySQLi', 'DBPrefix' => $prefix, 'pConnect' => false, 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
            'swapPre' => '', 'encrypt' => false, 'compress' => false, 'strictOn' => false, 'failover' => [], 'port' => (int) ($c['db_port'] ?: 3306),
            'numberNative' => false, 'foundRows' => false, 'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ];
        try {
            $conn = Database::connect($cfg, false);
            $conn->initialize();
            $ver = (string) $conn->getVersion();
        } catch (Throwable $e) {
            throw new BizException('Could not connect to the database: ' . $e->getMessage());
        }
        if (stripos($ver, 'mariadb') === false && version_compare($ver, '8.0.16', '<')) {
            throw new BizException('MySQL 8.0.16 or newer (or MariaDB 10.5 or newer) is needed; this server has ' . $ver . '.');
        }
        if (stripos($ver, 'mariadb') !== false && preg_match('/(\d+\.\d+\.\d+)/', $ver, $m) && version_compare($m[1], '10.5.0', '<')) {
            throw new BizException('MariaDB 10.5 or newer is needed; this server has ' . $ver . '.');
        }
        if ($conn->tableExists($prefix . 'settings', false) || $conn->tableExists('settings', false) && $prefix === '') {
            throw new BizException('This database already contains an installation with the prefix "' . $prefix . '". Use an empty database or another prefix.');
        }

        // 1. tables (through the migration runner so later upgrades know where they start).
        // If any later step fails, the tables made here are removed again so the installer can simply be re-run.
        $before = array_map('strtolower', $conn->listTables(true) ?: []);
        try {
        $runner = new MigrationRunner(config('Migrations'), $conn);
        $runner->setNamespace('App');
        $runner->latest();

        // 2. edition, then the platform owner or the one organisation
        Db::reset($conn);
        \App\Libraries\Core\Settings::flush();
        Seed::base();
        $result = Db::atomic(static function () use ($c, $withManager, $edition) {
            \App\Libraries\Core\Settings::set('edition', $edition);
            \App\Libraries\Core\Settings::set('installed_at', Db::now());
            \App\Libraries\Core\Settings::set('app_version', \App\Libraries\Core\Audit::APP_VERSION);
            $owner = ['username' => strtolower(trim($c['admin_username'])), 'name' => trim($c['admin_name']) ?: 'Administrator', 'email' => trim((string) $c['admin_email']), 'password' => $c['admin_password']];
            if ($edition === 'saas') {
                $uid = Seed::login($owner['username'], $owner['name'], $owner['password'], $owner['email'] ?: null, 'Platform owner');
                Seed::platformRole($uid);
                return ['admin' => $uid, 'tenant' => null, 'warehouse' => null, 'manager' => null];
            }
            $t = \App\Libraries\Platform\Tenants::create(['name' => trim((string) $c['company']), 'slug' => trim((string) $c['company']), 'timezone' => (string) ($c['timezone'] ?? 'Africa/Lusaka'),
                'location_code' => $c['wh_code'], 'location_name' => $c['wh_name'], 'location_town' => $c['wh_town'] ?? '', 'owner' => $owner], true);
            return \App\Libraries\Core\Tenancy::run((int) $t['tenant'], static function () use ($c, $t, $withManager) {
                \App\Libraries\Core\Settings::set('currency_symbol', trim($c['currency_symbol'] ?: 'K'));
                $mgr = null;
                if ($withManager) {
                    $mgr = Seed::user(strtolower(trim($c['manager_username'])), trim($c['manager_name'] ?? '') ?: 'Warehouse manager', $c['manager_password'], null,
                        [['warehouse_manager', 'system', null], ['buyer', 'system', null], ['sales_clerk', 'system', null], ['production', 'system', null], ['transport', 'system', null]], 'Warehouse manager');
                }
                return ['admin' => $t['owner'], 'tenant' => (int) $t['tenant'], 'warehouse' => $t['location'], 'manager' => $mgr];
            });
        });
        if (! empty($c['demo'])) {
            @set_time_limit(600);
            $demo = Demo::build($result['tenant']);
            Demo::samples($demo);
            if ($edition === 'saas') {
                Demo::second($demo);
            }
        }
        Auth::actAs(null);
        \App\Libraries\Core\Tenancy::set(null);
        \App\Libraries\Core\Audit::log('system.install', 'system', null, null, ['version' => \App\Libraries\Core\Audit::APP_VERSION, 'edition' => $edition, 'base_url' => $base]);
        } catch (Throwable $e) {
            try {
                $conn->query('SET FOREIGN_KEY_CHECKS = 0');
                foreach (array_merge(Schema::tables(), ['migrations']) as $t) {
                    if (! in_array(strtolower($prefix . $t), $before, true)) {
                        $conn->query('DROP TABLE IF EXISTS `' . $prefix . $t . '`');
                    }
                }
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) {
                // leave the original error as the one reported
            }
            throw $e;
        }

        // 3. settings file and lock
        $https = str_starts_with($base, 'https://');
        $env = "# Written by the installer on " . date('Y-m-d H:i') . "\n"
            . "CI_ENVIRONMENT = production\n"
            . "app.baseURL = '" . $base . "'\n"
            . "app.indexPage = ''\n"
            . "app.appTimezone = '" . ($c['timezone'] ?? 'Africa/Lusaka') . "'\n"
            . 'app.forceGlobalSecureRequests = ' . ($https ? 'true' : 'false') . "\n"
            . 'cookie.secure = ' . ($https ? 'true' : 'false') . "\n"
            . "cookie.samesite = 'Lax'\n"
            . 'cookie.httponly = true' . "\n"
            . "session.cookieName = 'cw_session'\n"
            . "encryption.key = hex2bin:" . bin2hex(random_bytes(32)) . "\n"
            . "database.default.hostname = " . self::envValue($c['db_host']) . "\n"
            . "database.default.database = " . self::envValue($c['db_name']) . "\n"
            . "database.default.username = " . self::envValue($c['db_user']) . "\n"
            . "database.default.password = " . self::envValue($c['db_pass']) . "\n"
            . "database.default.DBDriver = MySQLi\n"
            . "database.default.DBPrefix = " . $prefix . "\n"
            . "database.default.port = " . (int) ($c['db_port'] ?: 3306) . "\n"
            . "database.default.charset = utf8mb4\n"
            . "database.default.DBCollat = utf8mb4_unicode_ci\n";
        if (file_put_contents(ROOTPATH . '.env', $env) === false) {
            throw new BizException('Could not write the .env file. Check the folder permissions.');
        }
        @chmod(ROOTPATH . '.env', 0640);
        file_put_contents(self::lockFile(), date('c') . "\n");
        return $result + ['base_url' => $base, 'edition' => $edition];
    }

    private static function envValue(string $v): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
    }
}
