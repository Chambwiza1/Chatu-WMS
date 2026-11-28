<?php

use App\Commands\TestKit;
use App\Libraries\Core\Activity;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Search;
use App\Libraries\Core\Tenancy;
use App\Libraries\Master\Items;
use App\Libraries\Reports\Overview;
use App\Libraries\Setup\Demo;

/** ChatuERP core 1.6: search everything (C7), "+" (C8), the activity of a record (C10), the dashboard (C12). */
$groups = static fn (array $res): array => array_column($res, 'rows', 'group');
$titles = static fn (array $res, string $g): array => array_column(array_column($res, 'rows', 'group')[$g] ?? [], 'title');

return [
    'C16-1 search: each group holds only what the person may open, by duty and by warehouse; documents by number' => static function (TestKit $t) use ($groups, $titles) {
        $t->receive('KTW', [['RICE5', 4, 80]], 'kim');
        $grn = (string) Db::value("SELECT number FROM {receipts} WHERE tenant_id = {tid} AND warehouse_id = ? ORDER BY id DESC LIMIT 1", [$t->wh('KTW')]);
        $t->as('kim');
        $t->true(in_array($grn, $titles(Search::run($grn), 'Documents'), true), 'Kim finds the Kitwe delivery by its number');
        $t->true(in_array('KTW Kitwe depot', $titles(Search::run('kitwe'), 'Warehouses'), true), 'and her warehouse');
        $t->as('steve');
        $r = Search::run('rice');
        $t->true(str_starts_with($titles($r, 'Items')[0] ?? '', 'RICE5'), 'items by name');
        $t->true(! in_array($grn, $titles(Search::run($grn), 'Documents'), true), 'Steve (Lusaka) does not find the Kitwe delivery');
        $t->true(! isset($groups(Search::run('kitwe'))['Warehouses']), 'nor the Kitwe warehouse');
        $t->true(! isset($groups(Search::run('shop'))['Customers']), 'no customers without Customers, View');
        $t->as('sally');
        $t->true(isset($groups(Search::run('shop'))['Customers']), 'the sales clerk finds customers');
        $t->eq([], Search::run('r'), 'two letters at least');
        $t->eq([], Search::run('%'), 'a % is a letter, not "anything"');
    },

    'C16-2 "+": what the person may add, by their duties and the organisation\'s modules' => static function (TestKit $t) {
        $t->as('sally');
        $labels = array_column(Search::quickAdd(), 1);
        $t->true(in_array('Sales order', $labels, true) && ! in_array('Purchase order', $labels, true), 'the sales clerk: ' . implode(', ', $labels));
        $t->as('bella');
        $labels = array_column(Search::quickAdd(), 1);
        $t->true(in_array('Purchase order', $labels, true) && ! in_array('Sales order', $labels, true), 'the buyer: ' . implode(', ', $labels));
        $t->as('tom');
        $t->true(in_array('Trip', array_column(Search::quickAdd(), 1), true), 'transport plans trips');
        $t->as('sam');
        \App\Libraries\Platform\Modules::setOwn('fleet', false);
        $t->as('tom');
        $t->true(! in_array('Trip', array_column(Search::quickAdd(), 1), true), 'not when Fleet is switched off');
        $t->as('sam');
        \App\Libraries\Platform\Modules::setOwn('fleet', true);
    },

    'C16-3 a record\'s activity, from the audit log: who did what and what changed; only for people who see that kind of record' => static function (TestKit $t) {
        $t->as('bella');
        $id = $t->item('RICE5');
        $old = Items::get($id);
        Items::save(['name' => 'Rice 5 kg bag (white)'] + $old, $id);
        $a = Activity::of('item', $id);
        $t->true(count($a) >= 1 && $a[0]['what'] === 'Changed' && $a[0]['who'] !== '', 'newest first: ' . json_encode($a[0]));
        $t->true((bool) array_filter($a[0]['changes'], static fn ($c) => str_contains($c, 'Rice 5 kg bag (white)')), 'what changed: ' . json_encode($a[0]['changes']));
        $t->as('tom');
        $t->eq([], Activity::of('purchase_order', 1), 'transport does not read purchase orders\' activity');
        $t->eq([], Activity::of('tenant', 1), 'only kinds of record that have an activity tab');
    },

    'C16-4 the dashboard: the person\'s own warehouses; money only with See costs' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $t->receive('KTW', [['RICE5', 5, 80]], 'kim');
        $t->as('wendy');
        $d = Overview::build(30);
        $t->eq(1, $d['received'], 'Lusaka only: one delivery');
        $t->eq(30, count($d['per']));
        $t->true($d['value'] !== null && abs($d['value'] - 800.0) < 0.01, 'stock value for a manager who sees costs: ' . json_encode($d['value']));
        $t->as('steve');
        $d = Overview::build(7);
        $t->true($d['value'] === null && $d['losses'] === null && $d['byWh'] === [], 'no money for a storekeeper');
        $t->eq(7, count($d['per']));
    },

    'ORG-4 an item\'s barcode, a batch\'s supplier number and a serial check never reach another organisation (fixed in 0.6.0)' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        Tenancy::set($f['tenant']);
        Auth::actAs($f['users']['fay']);
        Db::exec("UPDATE {items} SET barcode = '6001234500017' WHERE tenant_id = {tid} AND id = ?", [$f['item']]);
        Tenancy::set($t->c['tenant']);
        $t->as('bella');
        [$hit] = Items::byCode('6001234500017');
        $t->true($hit === null || (int) $hit['tenant_id'] === (int) $t->c['tenant'], 'their barcode does not find their item from here');
        $t->eq([], array_filter(Search::run('6001234500017'), static fn ($g) => $g['group'] === 'Items'), 'nor does search');
        $t->eq([], \App\Libraries\Reports\Reconcile::serials(), 'the serial check sees only this organisation');
    },
];
