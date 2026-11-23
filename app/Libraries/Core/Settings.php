<?php

namespace App\Libraries\Core;

/** Key/value settings stored in the settings table, with defaults. */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'app_name'             => 'Chatu Warehouse',
        'company_name'         => '',
        'currency_symbol'      => 'K',
        'currency_code'        => 'ZMW',
        'session_idle_minutes' => '30',
        'sod_mode'             => 'enforce', // enforce | report
        'mail_from'            => '',
        'mail_from_name'       => 'Chatu Warehouse',
        'cron_key'             => '',
        'web_cron'             => '0',
        'single_session'       => '1',     // core 1.1: signing in again ends the account's older session
        'max_upload_mb'        => '8',
        'over_receipt_pct'     => '0',     // receive up to this % more than ordered
        'adjust_auto_limit'    => '0',     // adjustments worth up to this post without a second person (0 = always a second person)
        'count_auto_limit'     => '0',     // same for count results
        'expiry_warn_days'     => '60',
        'fleet_warn_days'      => '30',
        'transfer_loss_note'   => '1',     // a final transfer receipt with missing units needs a note
    ];


    public static function get(string $name, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::rows('SELECT name, value FROM {settings}') as $r) {
                self::$cache[$r['name']] = $r['value'];
            }
        }
        return self::$cache[$name] ?? $default ?? (self::DEFAULTS[$name] ?? null);
    }

    public static function int(string $name): int
    {
        return (int) self::get($name);
    }

    public static function set(string $name, ?string $value): void
    {
        Db::exec('INSERT INTO {settings} (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
