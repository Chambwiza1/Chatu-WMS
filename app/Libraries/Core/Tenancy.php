<?php

namespace App\Libraries\Core;

/**
 * core 1.1 (as ChatuPOS): the organisation a request or job works for. Web requests take it from the session (checked
 * against the user's memberships by TenantFilter), jobs set it per organisation with run().
 *
 * In the single-company edition there is exactly one tenant and it is always the current one.
 */
final class Tenancy
{
    /** Tenant-owned tables: every query on them must name tenant_id (Db guard). */
    public const TABLES = ['tenant_settings', 'tenant_users', 'user_roles', 'member_warehouses', 'member_permissions', 'notifications', 'audit_events',
        'attachments', 'doc_counters', 'item_categories', 'items', 'suppliers', 'customers', 'reasons', 'warehouses', 'zones', 'bins', 'lots', 'serials',
        'stock', 'stock_locks', 'movements', 'cost_layers', 'cost_entries', 'allocations', 'purchase_orders', 'po_lines', 'receipts', 'receipt_lines',
        'supplier_returns', 'moves', 'transfers', 'transfer_lines', 'transit', 'transit_costs', 'transfer_receipts', 'sales_orders', 'so_lines',
        'shipments', 'shipment_lines', 'shipment_units', 'customer_returns', 'recipes', 'recipe_lines', 'production_runs', 'production_inputs',
        'adjustments', 'adjustment_lines', 'counts', 'count_lines', 'vehicles', 'drivers', 'vehicle_logs', 'trips', 'trip_stops', 'item_units', 'pick_waves', 'signin_as_log', 'signin_as_actions', 'message_outbox', 'escalations', 'saved_filters'];

    /** The warehouse business tables (TABLES without the membership, message and audit tables). */
    public static function business(): array
    {
        // core 1.2: the record of sign-ins was born with its organisation column, so the 0.3 converter leaves it alone
        return array_values(array_diff(array_slice(self::TABLES, 7), ['signin_as_log', 'signin_as_actions', 'message_outbox', 'escalations', 'saved_filters']));
    }

    private static ?int $id = null;
    private static ?array $row = null;

    public static function id(): ?int
    {
        return self::$id;
    }

    /** The current tenant id; throws when no tenant is chosen (so tenant code never runs without one). */
    public static function require(): int
    {
        if (self::$id === null) {
            throw new DeniedException('Choose an organisation first.');
        }
        return self::$id;
    }

    public static function set(?int $tenantId): void
    {
        if ($tenantId !== self::$id) {
            self::$row = null;
            Access::flush();
            Settings::flushTenant();
        }
        self::$id = $tenantId;
    }

    /** Run $fn for one tenant and put the previous tenant back afterwards. */
    public static function run(?int $tenantId, callable $fn)
    {
        $before = self::$id;
        self::set($tenantId);
        try {
            return $fn();
        } finally {
            self::set($before);
        }
    }

    public static function current(): ?array
    {
        if (self::$id === null) {
            return null;
        }
        return self::$row ??= Db::row('SELECT * FROM {tenants} WHERE id = ?', [self::$id]);
    }

    public static function name(): string
    {
        return (string) (self::current()['name'] ?? '');
    }

    /** True when installed as the single-company edition. */
    public static function single(): bool
    {
        return Settings::get('edition') === 'single';
    }

    /** The one tenant of a single-company installation. */
    public static function only(): ?int
    {
        $id = Db::value('SELECT MIN(id) FROM {tenants}');
        return $id === null ? null : (int) $id;
    }

    /** Active tenants the user belongs to (and may sign in to), by name. */
    public static function memberships(int $userId): array
    {
        return Db::unscoped(static fn () => Db::rows(
            "SELECT t.id, t.slug, t.name, t.status, tu.is_account_owner FROM {tenant_users} tu JOIN {tenants} t ON t.id = tu.tenant_id
              WHERE tu.user_id = ? AND tu.status = 'active' AND t.status <> 'cancelled' ORDER BY t.name",
            [$userId]
        ));
    }

    public static function isMember(int $userId, int $tenantId): bool
    {
        return (bool) Db::value("SELECT 1 FROM {tenant_users} tu JOIN {tenants} t ON t.id = tu.tenant_id
                                  WHERE tu.tenant_id = ? AND tu.user_id = ? AND tu.status = 'active' AND t.status <> 'cancelled'", [$tenantId, $userId]);
    }

    /** Tenants that can no longer add or change data (suspended for non-payment). Reading still works. */
    public static function readOnly(): bool
    {
        return (self::current()['status'] ?? '') === 'suspended';
    }
}
