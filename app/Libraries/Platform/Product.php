<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Db;

/**
 * core 1.5: what the shared billing needs to know about this product. Every product has its own copy of this file;
 * Billing, Pay and Modules are the same in all of them.
 */
final class Product
{
    public const NAME = 'Chatu Warehouse';

    /** What the plan limits count, and what the console calls them (the keys are the shared billing's). */
    public const LIMITS = ['locations' => 'Warehouses', 'users' => 'People'];

    /**
     * Modules the platform owner can allow, force on or make unavailable per organisation (core 1.5, as in Chatu
     * Assets 2.2.2). key => [name, what it covers, URL prefixes it owns]. Everything else is always there.
     */
    public const MODULES = [
        'production' => ['Production', 'Recipes and production runs: inputs drawn from stock, finished goods received.', ['production']],
        'fleet'      => ['Fleet and trips', 'Vehicles, drivers, their papers, and delivery trips.', ['fleet']],
    ];

    /** Modules that are off until switched on. */
    public const OFF_BY_DEFAULT = [];

    /** Current use of each limit for one organisation. */
    public static function usage(int $tenantId): array
    {
        return [
            'locations' => (int) Db::value('SELECT COUNT(*) FROM {warehouses} WHERE tenant_id = ? AND active = 1', [$tenantId]),
            'users'     => (int) Db::value("SELECT COUNT(*) FROM {tenant_users} tu JOIN {users} u ON u.id = tu.user_id AND u.active = 1 WHERE tu.tenant_id = ? AND tu.status = 'active'", [$tenantId]),
        ];
    }

    /** Why a module cannot be switched off now (it holds work in progress), or null when it can. */
    public static function inUse(string $key, int $tenantId): ?string
    {
        return match ($key) {
            'production' => ($n = (int) Db::value("SELECT COUNT(*) FROM {recipes} WHERE tenant_id = ? AND active = 1", [$tenantId]))
                ? $n . ' active recipe' . ($n > 1 ? 's' : '') . ' (switch them off first)' : null,
            'fleet'      => ($n = (int) Db::value("SELECT COUNT(*) FROM {trips} WHERE tenant_id = ? AND status IN ('planned','on_road')", [$tenantId]))
                ? $n . ' trip' . ($n > 1 ? 's' : '') . ' planned or on the road (complete or cancel them first)' : null,
            default      => null,
        };
    }
}
