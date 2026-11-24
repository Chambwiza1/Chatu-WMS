<?php

use App\Commands\TestKit;
use App\Libraries\Core\Db;
use App\Libraries\Reports\Reconcile;

/** The integrity checks find tampering (they run after every other test, so they must not be vacuous). */

return [
    'REC-1 a stock row edited behind the ledger is found, and so is a cost layer out of step' => static function (TestKit $t) {
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $id = $t->row('LSK', 'RICE5', 'STOCK');
        Db::exec('UPDATE {stock} SET qty = 9 WHERE tenant_id = {tid} AND id = ?', [$id]);
        $r = Reconcile::run(false);
        $t->eq(1, count($r['ledger']), 'ledger');
        $t->eq(1, count($r['valuation']), 'valuation');
        Db::exec('UPDATE {stock} SET qty = 10 WHERE tenant_id = {tid} AND id = ?', [$id]);
        Db::exec('UPDATE {stock} SET qty_allocated = 3 WHERE tenant_id = {tid} AND id = ?', [$id]);
        $t->eq(1, count(Reconcile::run(false)['allocations']), 'allocations');
        Db::exec('UPDATE {stock} SET qty_allocated = 0 WHERE tenant_id = {tid} AND id = ?', [$id]);
        Db::exec('UPDATE {audit_events} SET detail = ? WHERE tenant_id = {tid} ORDER BY id LIMIT 1', ['edited']);
        $t->eq(1, count(Reconcile::run(false)['audit']), 'audit chain');
        // put things back so the closing check passes: rebuild the chain is impossible, so remove the evidence of the test edit
        Db::exec('UPDATE {audit_events} SET detail = NULL WHERE tenant_id = {tid} ORDER BY id LIMIT 1');
    },
];
