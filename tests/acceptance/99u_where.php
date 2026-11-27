<?php

use App\Commands\TestKit;
use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;

/** ChatuERP core 1.2 (B4): who works where, and giving a warehouse its team. */
return [
    'B4-1 who works where: each person at their warehouses, every-warehouse people apart; a warehouse is given its team with the rules of a person\'s own page, and each change is audited' => static function (TestKit $t) {
        $t->as('sam');
        $lsk = (int) Db::value("SELECT id FROM {warehouses} WHERE tenant_id = {tid} AND code = 'LSK'");
        $ktw = (int) Db::value("SELECT id FROM {warehouses} WHERE tenant_id = {tid} AND code = 'KTW'");
        $steve = $t->uid('steve');
        $kim   = $t->uid('kim');
        $w = Users::whoWorksWhere();
        $byId = array_column($w['people'], null, 'id');
        $t->eq([[$lsk], [$ktw]], [$byId[$steve]['at'], $byId[$kim]['at']], 'each at their own warehouse');
        $t->true((bool) $byId[$t->uid('sam')]['every'], 'the administrator works at every warehouse');
        $team = Users::team($ktw);
        $t->true(in_array($kim, array_map('intval', array_column($team['here'], 'id')), true), 'Kim is on the Kitwe team');
        $t->true(in_array($steve, array_map('intval', array_column($team['others'], 'id')), true), 'Steve can be added to it');

        $t->true(! Access::canAt($ktw, 'stock.view', $steve), 'before: Steve cannot see Kitwe');
        $before = (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'user.access' AND object_id = ?", [$steve]);
        $d = Users::setTeam($ktw, [$steve], []);
        $t->eq([$steve], $d['added']);
        $t->true(Access::canAt($ktw, 'stock.view', $steve), 'after: Steve works at Kitwe too');
        $t->true(Access::canAt($lsk, 'stock.view', $steve), 'and still at Lusaka');
        $t->eq([], Users::setTeam($ktw, [$steve], [])['added'], 'adding twice changes nothing');
        $d = Users::setTeam($lsk, [], [$steve]);
        $t->eq([[$steve], []], [$d['removed'], $d['left_without']]);
        $t->true(! Access::canAt($lsk, 'stock.view', $steve), 'taken off Lusaka');
        $t->eq($before + 2, (int) Db::value("SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND action = 'user.access' AND object_id = ?", [$steve]), 'each change in the audit log');
        $d = Users::setTeam($ktw, [], [$steve]);
        $t->eq([$steve], $d['left_without'], 'told when someone is left at no warehouse');
        Users::setTeam($lsk, [$steve], []);

        $t->throws(static fn () => Users::setTeam($lsk, [$t->uid('sam')], []), 'your own access', DeniedException::class);
        $t->throws(static fn () => Users::setTeam($lsk, [], [$t->uid('dora')]), 'every warehouse', BizException::class);
        $t->throws(static fn () => Users::setTeam(999999, [$steve], []), 'not found');

        $t->as('steve');
        $t->throws(static fn () => Users::setTeam($lsk, [$kim], []), 'user.update', DeniedException::class);
        $t->throws(static fn () => Users::whoWorksWhere(), 'user.view', DeniedException::class);
    },
];
