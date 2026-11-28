<?php

use App\Commands\TestKit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Find;
use App\Libraries\Master\Items;

/** ChatuERP core 1.7: one search rule for every list (D1), bulk edit on screen (D4). Pickers (D2) are checked in the browser. */
$find = static function (string $q): array {
    [$w, $b] = Find::where($q, ['i.sku', 'i.name', 'i.description'], ['i.barcode']);
    return array_column(Db::rows("SELECT i.sku FROM {items} i WHERE i.tenant_id = {tid} AND $w ORDER BY i.sku", $b), 'sku');
};

return [
    'D1 search: words in any order, part of a code, every word needed, % and _ are letters, an exact barcode' => static function (TestKit $t) use ($find) {
        $t->as('bella');
        Db::exec("UPDATE {items} SET barcode = '6009876543210' WHERE tenant_id = {tid} AND sku = 'RICE5'");
        $t->eq(['RICE5'], $find('bag rice'), 'words in any order');
        $t->eq(['RICE5'], $find('ICE5'), 'part of a code');
        $t->eq([], $find('rice sugar'), 'every word is needed');
        $t->eq(['RICE5'], $find('6009876543210'), 'an exact barcode');
        $t->eq([], $find('%'), 'a % is a letter, not "anything"');
        $t->eq([], $find('_'), 'nor is _');
        $t->eq(['1=1', []], Find::where('   ', ['x']), 'an empty search is no condition');
        $t->eq(8, count(Find::words('a b c d e f g h i j')), 'at most eight words');
    },

    'D4 bulk edit: prices up by a percentage, reorder levels, category; each item checked and audited; all or nothing' => static function (TestKit $t) {
        $t->as('bella');
        $ids = [$t->item('RICE5'), $t->item('SUGAR')];
        $before = array_map(static fn ($id) => (float) Items::get($id)['sell_price'], $ids);
        $audit = (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'item.update'");
        $n = Items::bulkUpdate($ids, ['price_mode' => 'up_pct', 'price_value' => '10', 'reorder_level' => '25']);
        $t->eq(2, $n);
        foreach ($ids as $k => $id) {
            Items::flush();
            $i = Items::get($id);
            $t->eq(round($before[$k] * 1.1, 2), (float) $i['sell_price'], $i['sku'] . ' up 10%');
            $t->eq(25.0, (float) $i['reorder_level']);
        }
        $t->eq($audit + 2, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'item.update'"), 'each item in the audit log');
        $t->eq(0, Items::bulkUpdate($ids, ['reorder_level' => '25']), 'nothing to change, nothing changed');
        // one item would go below nothing: none changes
        Items::flush();
        $cheap = min(array_map(static fn ($id) => (float) Items::get($id)['sell_price'], $ids));
        $t->throws(static fn () => Items::bulkUpdate($ids, ['price_mode' => 'down_amt', 'price_value' => (string) ($cheap + 1), 'reorder_level' => '30']), 'less than nothing', BizException::class);
        Items::flush();
        $t->eq(25.0, (float) Items::get($ids[0])['reorder_level'], 'all or nothing');
        $t->throws(static fn () => Items::bulkUpdate($ids, []), 'Choose what to change', BizException::class);
        $t->throws(static fn () => Items::bulkUpdate([], ['reorder_level' => '1']), 'Tick the items', BizException::class);
        $t->throws(static fn () => Items::bulkUpdate($ids, ['price_mode' => 'down_pct', 'price_value' => '100']), 'less than 100%', BizException::class);
        $t->as('steve');
        $t->throws(static fn () => Items::bulkUpdate($ids, ['reorder_level' => '1']), 'item.update', DeniedException::class);
    },
];
