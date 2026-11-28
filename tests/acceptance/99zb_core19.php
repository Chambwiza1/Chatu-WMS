<?php

use App\Commands\TestKit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Filters;
use App\Libraries\Core\Tenancy;
use App\Libraries\Core\Xlsx;
use App\Libraries\Setup\Demo;

/** ChatuERP core 1.9 (D6): Excel exports beside every CSV export, and saved filters. */
return [
    'D6-1 an Excel export opens as a workbook: header, numbers as numbers, text as text, formulas never run' => static function (TestKit $t) {
        $bin = Xlsx::build('on_hand', ['Item', 'Qty', 'Value', 'Note'], [['RICE5', '12', 1250.5, '=HYPERLINK("http://x")'], ['SALT & co <1kg>', -3, '0012', '']]);
        $f = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($f, $bin);
        $z = new ZipArchive();
        $t->true($z->open($f) === true, 'a zip');
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels'] as $p) {
            $t->true($z->locateName($p) !== false, 'has ' . $p);
        }
        $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        @unlink($f);
        $x = simplexml_load_string($sheet);
        $t->true($x !== false, 'the sheet is well-formed XML');
        $t->true(str_contains($sheet, '<c r="B2"><v>12</v></c>') && str_contains($sheet, '<c r="C2"><v>1250.5</v></c>') && str_contains($sheet, '<c r="B3"><v>-3</v></c>'), 'numbers are numbers');
        $t->true(str_contains($sheet, '<c r="C3" t="inlineStr"><is><t xml:space="preserve">0012</t>'), 'a code with a leading zero stays text');
        $t->true(! str_contains($sheet, '<f>') && str_contains($sheet, '=HYPERLINK(&quot;http://x&quot;)'), 'formula-looking text is text');
        $t->true(str_contains($sheet, 'SALT &amp; co &lt;1kg&gt;'), 'escaped');
        $t->true(str_contains($sheet, 'ySplit="1"') && str_contains($sheet, '<autoFilter ref="A1:D3"/>'), 'header row stays in view, with a filter');
        $t->eq('AA', Xlsx::col(27), 'column letters');
    },

    'D6-2 saved filters are each person\'s own; the query keeps filters, never exports or paging' => static function (TestKit $t) {
        $t->as('steve');
        $id = Filters::save('/stock', 'Rice in Lusaka', 'q=RICE&csv=1&as=xlsx&page=3&warehouse_id=' . $t->wh('LSK'));
        $f = Db::row('SELECT * FROM {saved_filters} WHERE id = ? AND tenant_id = {tid}', [$id]);
        $t->eq('stock', $f['page'], 'page key');
        $t->eq('q=RICE&warehouse_id=' . $t->wh('LSK'), $f['query'], 'exports and paging dropped');
        $again = Filters::save('stock', 'Rice in Lusaka', 'q=RICE5');
        $t->eq($id, $again, 'same name: replaced');
        $t->eq(1, count(Filters::forPage('stock')), 'one');
        $t->throws(static fn () => Filters::save('stock', 'Empty', 'csv=1'), 'Set some filters');
        $t->throws(static fn () => Filters::save('stock', 'Shared', 'q=X', 1), 'Only an administrator', DeniedException::class);
        $t->as('kim');
        $t->eq([], Filters::forPage('stock'), 'someone else does not see it');
        $t->throws(static fn () => Filters::delete($id), 'Only the person', DeniedException::class);
        $t->as('steve');
        Filters::delete($id);
        $t->eq([], Filters::forPage('stock'), 'removed by its owner');
    },

    'D6-3 shared with a role: its holders see it, others and other organisations do not' => static function (TestKit $t) {
        $t->as('sam');
        $role = (int) Db::value("SELECT id FROM {roles} WHERE tenant_id = {tid} AND code = 'storekeeper'");
        $id = Filters::save('reports/on_hand', 'Quarantine', 'status=quarantine', $role);
        $t->as('steve');
        $seen = Filters::forPage('reports/on_hand');
        $t->eq([$id], array_map('intval', array_column($seen, 'id')), 'a storekeeper sees it');
        $t->eq('Storekeeper', $seen[0]['role_name'], 'with the role it is shared with');
        $t->as('bella');
        $t->eq([], Filters::forPage('reports/on_hand'), 'a buyer does not');
        $f = Demo::second($t->c);
        Tenancy::set($f['tenant']);
        Auth::actAs($f['users']['mia']);
        $t->eq([], Filters::forPage('reports/on_hand'), 'a storekeeper of the other organisation does not');
        $t->throws(static fn () => Filters::save('reports/on_hand', 'X', 'q=1', $role), 'Only an administrator', DeniedException::class);
        Auth::actAs($f['users']['fay']);
        $t->throws(static fn () => Filters::save('reports/on_hand', 'X', 'q=1', $role), 'this organisation', null);
        $t->throws(static fn () => Filters::delete($id), 'not there', null);
        Tenancy::set($t->c['tenant']);
        $t->as('sam');
        Filters::delete($id);
        $t->eq(0, (int) Db::value('SELECT COUNT(*) FROM {saved_filters} WHERE tenant_id = {tid}'), 'removed by an administrator');
    },
];
