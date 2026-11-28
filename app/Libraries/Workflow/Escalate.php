<?php

namespace App\Libraries\Workflow;

use App\Libraries\Core\Access;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * Late and overdue work is chased, then escalated to managers (core 1.8, E4), on the organisation's own working days.
 *
 * After "escalate_days" working days (default 2), the people who should act are reminded (level 1). After twice that,
 * the organisation's managers are told (level 2): those who hold the same duty at every warehouse, or the account
 * owner if nobody does. Each level is sent once per item. Nobody is asked to approve their own document.
 */
final class Escalate
{
    /**
     * What can be late. Each: label, the duty that acts on it, and a query giving id, number, warehouse_id, since
     * (the date it started waiting or was due), created_by (or null) and the link.
     */
    public static function kinds(): array
    {
        return [
            'adjustment' => ['Adjustment waiting for approval', 'stock.approve',
                "SELECT id, number, warehouse_id, created_at since, created_by, CONCAT('/adjustments/', id) link FROM {adjustments} WHERE tenant_id = {tid} AND status = 'pending'"],
            'count' => ['Count waiting for approval', 'stock.approve',
                "SELECT id, number, warehouse_id, submitted_at since, submitted_by created_by, CONCAT('/counts/', id) link FROM {counts} WHERE tenant_id = {tid} AND status = 'submitted'"],
            'transfer' => ['Transfer on the road, not received', 'transfer.receive',
                "SELECT id, number, to_warehouse_id warehouse_id, dispatched_at since, NULL created_by, CONCAT('/transfers/', id) link FROM {transfers} WHERE tenant_id = {tid} AND status = 'in_transit'"],
            'sales_order' => ['Sales order past the date asked', 'dispatch.manage',
                "SELECT id, number, warehouse_id, required_date since, NULL created_by, CONCAT('/orders/', id) link FROM {sales_orders} WHERE tenant_id = {tid} AND status IN ('confirmed','in_progress') AND required_date IS NOT NULL AND required_date < CURDATE()"],
            'purchase_order' => ['Purchase order past its expected date', 'purchase.manage',
                "SELECT id, number, warehouse_id, expected_date since, NULL created_by, CONCAT('/purchase-orders/', id) link FROM {purchase_orders} WHERE tenant_id = {tid} AND status IN ('open','partly_received') AND expected_date IS NOT NULL AND expected_date < CURDATE()"],
            'shipment' => ['Shipment dispatched, delivery not recorded', 'dispatch.manage',
                "SELECT id, number, warehouse_id, dispatched_at since, NULL created_by, CONCAT('/shipments/', id) link FROM {shipments} WHERE tenant_id = {tid} AND status = 'dispatched'"],
        ];
    }

    /** Working days between two dates (after $from, up to and including $to), on the organisation's days and holidays. */
    public static function workingDays(string $from, string $to): int
    {
        $days = array_map('intval', array_filter(explode(',', (string) Settings::tenant('working_days', '1,2,3,4,5'))));
        $days = $days ?: [1, 2, 3, 4, 5];
        preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) Settings::tenant('holidays', ''), $m);
        $hol = array_flip($m[0]);
        $d   = new \DateTimeImmutable(substr($from, 0, 10));
        $end = new \DateTimeImmutable(substr($to, 0, 10));
        $n   = 0;
        for ($i = 0; $d < $end && $i < 4000; $i++) {
            $d = $d->modify('+1 day');
            if (in_array((int) $d->format('N'), $days, true) && ! isset($hol[$d->format('Y-m-d')])) {
                $n++;
            }
        }
        return $n;
    }

    /** Everything late now, oldest first: kind, label, number, warehouse, age in working days, level due, level sent. */
    public static function overdue(?array $warehouseIds = null): array
    {
        $limit = max(1, (int) Settings::tenant('escalate_days', '2'));
        $today = date('Y-m-d');
        $sent  = [];
        foreach (Db::rows('SELECT kind, object_id, MAX(level) level FROM {escalations} WHERE tenant_id = {tid} GROUP BY kind, object_id') as $r) {
            $sent[$r['kind'] . ':' . $r['object_id']] = (int) $r['level'];
        }
        $codes = array_column(Db::rows('SELECT id, code FROM {warehouses} WHERE tenant_id = {tid}'), 'code', 'id');
        $out   = [];
        foreach (self::kinds() as $kind => [$label, $perm, $sql]) {
            foreach (Db::rows($sql . ' ORDER BY id LIMIT 500') as $r) {
                if (! $r['since'] || ($warehouseIds !== null && ! in_array((int) $r['warehouse_id'], $warehouseIds, true))) {
                    continue;
                }
                $age = self::workingDays($r['since'], $today);
                if ($age < $limit) {
                    continue;
                }
                $out[] = ['kind' => $kind, 'label' => $label, 'perm' => $perm, 'id' => (int) $r['id'], 'number' => $r['number'], 'warehouse_id' => (int) $r['warehouse_id'],
                    'warehouse' => $codes[$r['warehouse_id']] ?? '', 'since' => substr((string) $r['since'], 0, 10), 'age' => $age, 'due' => $age >= 2 * $limit ? 2 : 1,
                    'sent' => $sent[$kind . ':' . $r['id']] ?? 0, 'created_by' => $r['created_by'] ? (int) $r['created_by'] : null, 'link' => $r['link']];
            }
        }
        usort($out, static fn ($a, $b) => $b['age'] <=> $a['age']);
        return $out;
    }

    /** The managers of a duty: those who hold it at every warehouse; else the account owner. */
    public static function managers(string $perm, int $warehouseId): array
    {
        $tid  = Tenancy::require();
        $wide = array_map('intval', array_column(Db::rows("SELECT user_id FROM {tenant_users} WHERE tenant_id = ? AND status = 'active' AND (all_warehouses = 1 OR is_account_owner = 1)", [$tid]), 'user_id'));
        $m    = array_values(array_intersect(Access::usersWith($perm, $warehouseId, false), $wide));
        return $m ?: array_map('intval', array_column(Db::rows("SELECT user_id FROM {tenant_users} WHERE tenant_id = ? AND status = 'active' AND is_account_owner = 1", [$tid]), 'user_id'));
    }

    /** The daily run, per organisation: send each level that is due and not yet sent. Returns how many people were told. */
    public static function run(): int
    {
        $told = 0;
        foreach (self::overdue() as $o) {
            for ($level = $o['sent'] + 1; $level <= $o['due']; $level++) {
                $who = $level === 1 ? Access::usersWith($o['perm'], $o['warehouse_id'], false) : self::managers($o['perm'], $o['warehouse_id']);
                $who = array_values(array_filter(array_unique($who), static fn ($u) => $u !== $o['created_by'] || ! in_array($o['kind'], ['adjustment', 'count'], true)));
                $title = ($level === 2 ? 'Overdue, ' : 'Waiting, ') . $o['age'] . ' working days: ' . $o['label'] . ' ' . $o['number'] . ' (' . $o['warehouse'] . ')';
                $body  = $level === 2 ? 'Escalated to you as a manager: it has been waiting since ' . $o['since'] . '.' : 'Waiting since ' . $o['since'] . '. Please deal with it.';
                foreach ($who as $uid) {
                    Notify::user($uid, $level === 2 ? 'escalation' : 'reminder', $title, $body, $o['link']);
                }
                Db::insert('escalations', ['kind' => $o['kind'], 'object_id' => $o['id'], 'level' => $level, 'told' => count($who), 'age_days' => $o['age'], 'at' => Db::now()]);
                $told += count($who);
            }
        }
        return $told;
    }
}
