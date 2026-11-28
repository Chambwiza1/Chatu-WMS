<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Docs\Adjustments;
use App\Libraries\Workflow\Escalate;

/** ChatuERP core 1.8 (E4): late work is chased, then escalated to managers, on the organisation's working days. */
return [
    'E4-1 working days: the organisation\'s own week and public holidays' => static function (TestKit $t) {
        $t->as('sam');
        $t->eq(5, Escalate::workingDays('2026-11-27', '2026-12-04'), 'Friday to Friday: five working days');
        Settings::setTenant('working_days', '1,2,3,4,5,6');
        $t->eq(6, Escalate::workingDays('2026-11-27', '2026-12-04'), 'with Saturdays');
        Settings::setTenant('holidays', '2026-12-01, 2026-12-25');
        $t->eq(5, Escalate::workingDays('2026-11-27', '2026-12-04'), 'less a public holiday');
        $t->eq(0, Escalate::workingDays('2026-12-04', '2026-12-04'), 'the same day is none');
        Settings::setTenant('working_days', '1,2,3,4,5');
        Settings::setTenant('holidays', '');
    },

    'E4-2 an adjustment waiting: reminded to the approvers after the set days (never the one who raised it), then escalated to managers; each level once' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $t->as('steve');
        $id = Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'DAMAGE', 'notes' => 'Torn', 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => -1]]]);
        $age = static function (int $days) use ($id) {
            Db::exec('UPDATE {adjustments} SET created_at = ? WHERE tenant_id = {tid} AND id = ?', [date('Y-m-d 08:00:00', strtotime("-$days days")), $id]);
        };
        $t->as('sam');
        $age(0);
        $t->eq(0, Escalate::run(), 'not late yet');
        $age(4);
        $o = array_values(array_filter(Escalate::overdue(), static fn ($x) => $x['kind'] === 'adjustment' && $x['id'] === $id))[0] ?? null;
        $t->true($o !== null && $o['age'] >= 2, 'late after two working days: ' . json_encode($o));
        $before = (int) Db::value("SELECT COUNT(*) FROM {notifications} WHERE tenant_id = {tid} AND type = 'reminder'");
        $n = Escalate::run();
        $t->true($n >= 1, 'reminded');
        $who = array_map('intval', array_column(Db::rows("SELECT user_id FROM {notifications} WHERE tenant_id = {tid} AND type IN ('reminder','escalation') AND link = ?", ['/adjustments/' . $id]), 'user_id'));
        $t->true(in_array($t->uid('wendy'), $who, true), 'the Lusaka manager is reminded');
        $t->true(! in_array($t->uid('steve'), $who, true), 'never the one who raised it');
        $t->true(! in_array($t->uid('kalo'), $who, true), 'nor the Kitwe manager');
        $t->eq(0, Escalate::run(), 'each level once');
        $age(10);
        Escalate::run();
        $esc = array_map('intval', array_column(Db::rows("SELECT user_id FROM {notifications} WHERE tenant_id = {tid} AND type = 'escalation' AND link = ?", ['/adjustments/' . $id]), 'user_id'));
        $t->true($esc !== [], 'escalated to managers');
        foreach ($esc as $u) {
            $t->true((bool) Db::value("SELECT 1 FROM {tenant_users} WHERE tenant_id = {tid} AND user_id = ? AND (all_warehouses = 1 OR is_account_owner = 1)", [$u]), 'managers: people at every warehouse');
        }
        $t->eq(2, (int) Db::value("SELECT COUNT(*) FROM {escalations} WHERE tenant_id = {tid} AND kind = 'adjustment' AND object_id = ?", [$id]));
        $t->as('wendy');
        Adjustments::approve($id);
        $t->eq([], array_filter(Escalate::overdue(), static fn ($x) => $x['kind'] === 'adjustment' && $x['id'] === $id), 'dealt with: no longer late');
    },
];
