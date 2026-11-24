<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * core 1.5 (ChatuERP, from Chatu Assets 2.2.2): parts of the product an organisation may use. The platform owner
 * sets a rule per module and organisation (directly, or through the organisation's plan):
 *
 *   allow  organisation decides: its administrator switches the module on or off under Settings, Modules
 *   on     always on
 *   off    not available
 *
 * A module that is off leaves the menu, its pages answer "not found", and nothing new can be started in it; what
 * was recorded stays. A module that holds work in progress cannot be switched off (Product::inUse). The list of
 * modules is per product (Product::MODULES). In the single-company edition only the organisation's own switch counts.
 */
final class Modules
{
    public const RULES = ['allow' => 'Organisation decides', 'on' => 'Always on', 'off' => 'Not available'];

    /** Is the module on for the current organisation? Unknown keys are always on. */
    public static function on(string $key, ?int $tenantId = null): bool
    {
        if (! isset(Product::MODULES[$key])) {
            return true;
        }
        $tenantId ??= Tenancy::id();
        if ($tenantId === null) {
            return true;
        }
        $rule = self::rule($key, $tenantId);
        if ($rule !== 'allow') {
            return $rule === 'on';
        }
        $own = self::setting($tenantId, 'module_' . $key);
        if ($own === null) {
            return ! in_array($key, Product::OFF_BY_DEFAULT, true);
        }
        return ! in_array($own, ['off', '0'], true);
    }

    /** The platform owner's rule for an organisation: allow, on or off. Always "allow" in the single-company edition. */
    public static function rule(string $key, int $tenantId): string
    {
        if (Tenancy::single()) {
            return 'allow';
        }
        $v = self::setting($tenantId, 'module_' . $key . '_platform');
        return isset(self::RULES[(string) $v]) ? (string) $v : 'allow';
    }

    public static function rules(int $tenantId): array
    {
        $out = [];
        foreach (Product::MODULES as $k => $_) {
            $out[$k] = self::rule($k, $tenantId);
        }
        return $out;
    }

    /** The platform owner sets the rules for one organisation. Returns what changed. */
    public static function setRules(int $tenantId, array $rules, string $reason = ''): array
    {
        if (! Access::platform('platform.tenants')) {
            throw new DeniedException('Only the platform owner sets what an organisation may use.');
        }
        return self::write($tenantId, $rules, $reason, true);
    }

    /** Apply a plan's rules (called by Billing when the plan is chosen or changed). */
    public static function applyPlan(int $tenantId, array $rules, string $reason): array
    {
        return self::write($tenantId, $rules, $reason, true);
    }

    /** The organisation's administrator switches a module it is allowed to decide on. */
    public static function setOwn(string $key, bool $on): void
    {
        Access::require('admin.settings');
        $tid = Tenancy::require();
        if (! isset(Product::MODULES[$key])) {
            throw new BizException('Unknown module.');
        }
        if (self::rule($key, $tid) !== 'allow') {
            throw new BizException(Product::MODULES[$key][0] . ' is ' . (self::rule($key, $tid) === 'on' ? 'always on' : 'not available') . ' on your plan. Ask Chatu Innovations to change it.');
        }
        $was = self::on($key, $tid);
        if ($was === $on) {
            return;
        }
        if (! $on && ($why = Product::inUse($key, $tid))) {
            throw new BizException(Product::MODULES[$key][0] . ' cannot be switched off while it is in use: ' . $why . '.');
        }
        Db::atomic(static function () use ($tid, $key, $on, $was) {
            self::put($tid, 'module_' . $key, $on ? '1' : '0');
            Audit::log('settings.module', 'settings', null, ['module_' . $key => $was ? 'on' : 'off'], ['module_' . $key => $on ? 'on' : 'off']);
        });
    }

    /** Which module a path belongs to when that module is off for the current organisation. */
    public static function offFor(string $path): ?string
    {
        $path = trim($path, '/');
        foreach (Product::MODULES as $key => [, , $prefixes]) {
            foreach ($prefixes as $p) {
                if (($path === $p || str_starts_with($path, $p . '/')) && ! self::on($key)) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Refuse a step in a module that is off (for the libraries, whatever the page). */
    public static function require(string $key): void
    {
        if (! self::on($key)) {
            throw new BizException(Product::MODULES[$key][0] . ' is switched off for your organisation (Settings, Modules).');
        }
    }

    private static function write(int $tenantId, array $rules, string $reason, bool $platform): array
    {
        $changed = [];
        $refused = [];
        foreach (Product::MODULES as $key => [$name]) {
            $v = (string) ($rules[$key] ?? 'allow');
            if (! isset(self::RULES[$v])) {
                continue;
            }
            $old = self::rule($key, $tenantId);
            if ($old === $v) {
                continue;
            }
            $wasOn = self::on($key, $tenantId);
            if ($v === 'off' && $wasOn && ($why = Product::inUse($key, $tenantId))) {
                $refused[] = $name . ': ' . $why;
                continue;
            }
            $changed[$key] = [$old, $v];
        }
        if ($refused) {
            throw new BizException('Cannot make these unavailable while they are in use. ' . implode('; ', $refused) . '.');
        }
        if (! $changed) {
            return [];
        }
        Db::atomic(static function () use ($tenantId, $changed, $reason) {
            foreach ($changed as $key => [, $v]) {
                self::put($tenantId, 'module_' . $key . '_platform', $v);
            }
            $before = array_map(static fn ($c) => $c[0], $changed);
            $after  = array_map(static fn ($c) => $c[1], $changed);
            Tenancy::run(null, static fn () => Audit::log('tenant.modules', 'tenant', $tenantId, $before, $after, 'success', $reason !== '' ? $reason : null));
            Tenancy::run($tenantId, static fn () => Audit::log('tenant.modules', 'tenant', $tenantId, $before, $after, 'success', 'By the platform owner' . ($reason !== '' ? ': ' . $reason : '')));
        });
        return $changed;
    }

    private static function setting(int $tenantId, string $name): ?string
    {
        $v = Db::value('SELECT value FROM {tenant_settings} WHERE tenant_id = ? AND name = ?', [$tenantId, $name]);
        return $v === null ? null : (string) $v;
    }

    private static function put(int $tenantId, string $name, string $value): void
    {
        Db::exec('INSERT INTO {tenant_settings} (tenant_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$tenantId, $name, $value]);
        if (Tenancy::id() === $tenantId) {
            Settings::flushTenant();
        }
    }
}
