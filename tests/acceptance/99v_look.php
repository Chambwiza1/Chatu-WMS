<?php

use App\Commands\TestKit;
use App\Libraries\Admin\Theme;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Settings;
use App\Libraries\Reports\Dashboard;

/** ChatuERP core 1.3: light or dark on the account (C1), the organisation's colours (C2), pop-up alerts (E1). */
$labels = static fn (array $alerts): array => array_map(static fn ($a) => preg_replace('/^\d+ · /', '', $a['title']), $alerts);

return [
    'C13-1 light or dark is kept on the person\'s account: follow the device by default; only auto, light or dark; each change audited' => static function (TestKit $t) {
        $t->as('steve');
        $me = (int) Auth::id();
        $t->eq('auto', Auth::theme($me), 'follow the device by default');
        Auth::setTheme($me, 'dark');
        $t->eq('dark', Auth::theme($me));
        $t->eq('auto', Auth::theme($t->uid('kim')), 'one person\'s choice is theirs alone');
        $t->throws(static fn () => Auth::setTheme($me, 'purple'), 'Follow the device', BizException::class);
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'user.theme' AND object_id = ?", [$me]));
        Auth::setTheme($me, 'dark');
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'user.theme' AND object_id = ?", [$me]), 'no change, no line');
    },

    'C13-2 the organisation\'s colours: a ready palette or its own two, refused when white text or the highlight would not read; Chatu green is the stylesheet\'s own; only with Settings' => static function (TestKit $t) {
        $t->as('sam');
        $t->eq('green', Theme::current()[0], 'Chatu green by default');
        $t->true(! str_contains(Theme::css(), '--patina:'), 'green adds nothing to the stylesheet');
        $tag = Theme::tag();
        Theme::save('teal');
        $t->eq(['teal', '#0f766e', '#ffd166'], Theme::current());
        $css = Theme::css();
        $t->true(str_contains($css, '--patina: #0f766e') && str_contains($css, '--lime: #ffd166') && str_contains($css, '--forest:'), 'the brand roles of Warehouse\'s stylesheet');
        $t->true(str_contains($css, ':root[data-theme="dark"]'), 'with their dark shades');
        $t->true($tag !== Theme::tag(), 'the stylesheet address changes with the colours');
        $t->throws(static fn () => Theme::save('custom', '#f5f5a0', '#7cf25e'), 'too light', BizException::class);
        $t->throws(static fn () => Theme::save('custom', '#1d3557', '#203040'), 'does not stand out', BizException::class);
        $t->throws(static fn () => Theme::save('custom', 'green', '#ffffff'), 'give a colour', BizException::class);
        $t->eq('teal', Theme::current()[0], 'a refused colour changes nothing');
        Theme::save('custom', '#7a3fa8', '#f2c94c');
        $t->eq(['custom', '#7a3fa8', '#f2c94c'], Theme::current());
        $t->true(Theme::contrast('#ffffff', Theme::current()[1]) >= 4.5, 'white text reads on the main colour');
        $t->eq(1, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'settings.theme' AND after_json LIKE '%7a3fa8%'"), 'audited');
        $t->throws(static fn () => Theme::save('nope'), 'Choose one', BizException::class);
        $t->as('steve');
        $t->throws(static fn () => Theme::save('ocean'), 'admin.settings', DeniedException::class);
    },

    'C13-3 alerts: what waits for each person pops up, from their own duties and warehouses only; put aside, it comes back when the number changes; on unless the organisation switches them off' => static function (TestKit $t) use ($labels) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $req = static function () use ($t) {
            $t->as('steve');
            \App\Libraries\Docs\Adjustments::request(['warehouse_id' => $t->wh('LSK'), 'reason_code' => 'DAMAGE', 'notes' => 'Torn bag', 'lines' => [['stock_id' => $t->row('LSK', 'RICE5', 'STOCK'), 'qty' => -1]]]);
        };
        $req();
        $t->as('wendy');
        $w = Dashboard::alerts();
        $t->true(in_array('Adjustments to approve', $labels($w), true), 'the Lusaka manager is asked to approve: ' . implode(' / ', $labels($w)));
        foreach ($w as $a) {
            $t->true(in_array($a['tone'], ['warn', 'bad'], true) && preg_match('/^a-[0-9a-f]{10}-\d+$/', $a['key']) === 1, 'only what needs doing, keyed by what and how many');
        }
        $adj = array_values(array_filter($w, static fn ($a) => str_ends_with($a['title'], 'Adjustments to approve')))[0];
        $t->true(! in_array('Adjustments to approve', $labels(Dashboard::alerts([$adj['key']])), true), 'put aside');
        // another adjustment waiting: the number changes and it comes back
        $req();
        $t->as('wendy');
        $back = array_values(array_filter(Dashboard::alerts([$adj['key']]), static fn ($a) => str_ends_with($a['title'], 'Adjustments to approve')));
        $t->true($back && $back[0]['key'] !== $adj['key'], 'back when the number changes');
        $t->as('steve');
        $t->true(! in_array('Adjustments to approve', $labels(Dashboard::alerts()), true), 'not for someone who does not approve');
        $t->as('kim');
        $t->true(! in_array('Adjustments to approve', $labels(Dashboard::alerts()), true), 'nor for Kitwe, when the adjustment is at Lusaka');
        $t->eq('1', Settings::get('live_alerts'), 'on by default');
    },
];
