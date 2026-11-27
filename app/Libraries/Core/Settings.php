<?php

namespace App\Libraries\Core;

/**
 * Settings in two layers (core 1.1, as ChatuPOS). Platform settings (settings table) belong to the installation:
 * edition, mail server, scheduled job, sign-in rules. Organisation settings (tenant_settings) belong to one
 * organisation and fall back to TENANT_DEFAULTS. get() and set() pick the layer by the name, so the warehouse code
 * asks for over_receipt_pct the same way as before and gets its own organisation's answer.
 */
final class Settings
{
    private static ?array $cache = null;
    private static array $tenantCache = [];

    public const DEFAULTS = [
        'app_name'             => 'Chatu Warehouse',
        'edition'              => 'single', // single: one organisation on its own server (and every 0.2 installation) | saas: many organisations
        'session_idle_minutes' => '30',
        'mail_from'            => '',
        'mail_from_name'       => 'Chatu Warehouse',
        'cron_key'             => '',
        'web_cron'             => '0',
        'single_session'       => '1',     // core 1.1: signing in again ends the account's older session
        'max_upload_mb'        => '8',
    ];

    /** The organisation's own settings (per tenant). */
    public const TENANT_DEFAULTS = [
        'company_name'         => '',
        'company_address'      => '',
        'company_tpin'         => '',
        'company_phone'        => '',
        'currency_symbol'      => 'K',
        'currency_code'        => 'ZMW',
        'sod_mode'             => 'enforce', // enforce | report
        'over_receipt_pct'     => '0',     // receive up to this % more than ordered
        'adjust_auto_limit'    => '0',     // adjustments worth up to this post without a second person (0 = always a second person)
        'count_auto_limit'     => '0',     // same for count results
        'expiry_warn_days'     => '60',
        'fleet_warn_days'      => '30',
        'transfer_loss_note'   => '1',     // a final transfer receipt with missing units needs a note
        'live_popups'          => '1',     // core 1.1 (E2): new messages pop up on screen and stay until closed
        'live_sound'           => '1',
        'live_alerts'          => '1',     // core 1.3 (E1): what waits for a person pops up too (approvals, expiry, reorder)
        'brand_theme'          => 'green', // core 1.3 (C2): a ready palette, or 'custom'
        'brand_primary'        => '',
        'brand_accent'         => '',
        'company_logo'         => '',
        'last_reconcile'       => '',      // the organisation's last integrity check (JSON)
        'last_daily'           => '',      // the day its daily reminders went out
    ];

    public static function isTenantKey(string $name): bool
    {
        return array_key_exists($name, self::TENANT_DEFAULTS) || str_starts_with($name, 'module_') || str_starts_with($name, 'pay_t_');
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        if (self::isTenantKey($name)) {
            return Tenancy::id() === null ? ($default ?? self::TENANT_DEFAULTS[$name] ?? null) : self::tenant($name, $default);
        }
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
        if (self::isTenantKey($name)) {
            self::setTenant($name, $value);
            return;
        }
        Db::exec('INSERT INTO {settings} (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
        self::$cache = null;
    }

    /** A setting of the current organisation. */
    public static function tenant(string $name, ?string $default = null): ?string
    {
        $tid = Tenancy::require();
        if (! isset(self::$tenantCache[$tid])) {
            self::$tenantCache[$tid] = [];
            foreach (Db::rows('SELECT name, value FROM {tenant_settings} WHERE tenant_id = ?', [$tid]) as $r) {
                self::$tenantCache[$tid][$r['name']] = $r['value'];
            }
        }
        return self::$tenantCache[$tid][$name] ?? $default ?? (self::TENANT_DEFAULTS[$name] ?? null);
    }

    public static function setTenant(string $name, ?string $value): void
    {
        $tid = Tenancy::require();
        Db::exec('INSERT INTO {tenant_settings} (tenant_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$tid, $name, $value]);
        unset(self::$tenantCache[$tid]);
    }

    public static function flush(): void
    {
        self::$cache       = null;
        self::$tenantCache = [];
    }

    public static function flushTenant(): void
    {
        self::$tenantCache = [];
    }
}
