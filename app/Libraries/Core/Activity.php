<?php

namespace App\Libraries\Core;

/**
 * The activity tab of a record (core 1.6, C10; Chatu Construction's model): who did what to it and when, read from
 * the organisation's audit log. Nothing extra is stored. Each record's own page decides whether the person may see
 * the record; this only reads its lines, and only for people who may see records of that kind at all.
 */
final class Activity
{
    private const VERBS = [
        'create' => 'created', 'save' => 'saved', 'update' => 'changed', 'issue' => 'issued', 'post' => 'posted', 'confirm' => 'confirmed',
        'request' => 'raised', 'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled', 'void' => 'voided', 'start' => 'started',
        'enter' => 'counts entered', 'submit' => 'submitted', 'reopen' => 'reopened', 'allocate' => 'stock reserved', 'unallocate' => 'reservation released',
        'dispatch' => 'dispatched', 'receive' => 'received', 'pick' => 'picked', 'pack' => 'packed', 'deliver' => 'delivered', 'plan' => 'planned',
        'depart' => 'left the gate', 'complete' => 'closed', 'import_assets' => 'imported from Chatu Assets', 'link_assets' => 'linked to Chatu Assets',
        'status' => 'status changed', 'close' => 'closed', 'hold' => 'held', 'recall' => 'recalled', 'release' => 'released', 'upload' => 'proof of delivery added',
    ];

    /** Kinds of record with an activity tab, and the permission that lets someone see that kind of record at all. */
    public const KINDS = [
        'item' => ['item.view', 'stock.view'], 'purchase_order' => ['purchase.manage', 'receive.post'], 'receipt' => ['receive.post', 'stock.view'],
        'sales_order' => ['sales.manage', 'dispatch.manage'], 'shipment' => ['dispatch.manage', 'sales.manage'], 'transfer' => ['transfer.manage', 'transfer.receive', 'stock.view'],
        'adjustment' => ['adjust.request', 'stock.approve'], 'count' => ['count.manage', 'count.enter', 'stock.approve'], 'production_run' => ['production.manage'],
        'trip' => ['fleet.manage', 'dispatch.manage'], 'vehicle' => ['fleet.manage'], 'driver' => ['fleet.manage'], 'customer' => ['customer.view'], 'supplier' => ['supplier.view'],
        'wave' => ['dispatch.manage'], 'recipe' => ['production.manage'], 'warehouse' => ['warehouse.setup'],
    ];

    /** Lines about one record, newest first: at, who, what, and the changed fields when the log kept them. */
    public static function of(string $type, int $id, int $limit = 50): array
    {
        if (! isset(self::KINDS[$type]) || ! Access::canAny(self::KINDS[$type]) || ! Tenancy::id()) {
            return [];
        }
        $rows = Db::rows("SELECT a.id, a.at, a.username, a.action, a.before_json, a.after_json, u.name
                            FROM {audit_events} a LEFT JOIN {users} u ON u.id = a.user_id
                           WHERE a.tenant_id = {tid} AND a.object_type = ? AND a.object_id = ? AND a.outcome = 'success'
                           ORDER BY a.id DESC LIMIT " . max(1, min(200, $limit)), [$type, (string) $id]);
        return array_map(static fn ($r) => ['at' => $r['at'], 'who' => $r['name'] ?: ($r['username'] ?: 'The system'), 'what' => self::words($r['action']),
            'changes' => self::changes($r['before_json'], $r['after_json'])], $rows);
    }

    public static function words(string $action): string
    {
        $verb = explode('.', $action, 2)[1] ?? $action;
        return ucfirst(self::VERBS[$verb] ?? str_replace('_', ' ', $verb));
    }

    /** The fields an update changed, as "name: old → new" (at most six; secrets never shown). */
    private static function changes(?string $before, ?string $after): array
    {
        $b = json_decode((string) $before, true);
        $a = json_decode((string) $after, true);
        if (! is_array($b) || ! is_array($a)) {
            return [];
        }
        $out = [];
        foreach ($a as $k => $v) {
            if (! array_key_exists($k, $b) || $b[$k] === $v || is_array($v) || is_array($b[$k]) || preg_match('/pass|secret|token|hash/i', (string) $k)) {
                continue;
            }
            $out[] = str_replace('_', ' ', (string) $k) . ': ' . (($b[$k] ?? '') === '' ? '(empty)' : mb_strimwidth((string) $b[$k], 0, 40, '…')) . ' → ' . (($v ?? '') === '' ? '(empty)' : mb_strimwidth((string) $v, 0, 40, '…'));
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }
}
