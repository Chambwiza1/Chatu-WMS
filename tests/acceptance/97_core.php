<?php

use App\Commands\TestKit;
use App\Libraries\Admin\Roles;
use App\Libraries\Admin\Users;
use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DbError;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\NotFoundException;
use App\Libraries\Core\Rights;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Master\Items;
use App\Libraries\Master\Warehouses;
use App\Libraries\Platform\Billing;
use App\Libraries\Platform\Modules;
use App\Libraries\Platform\Pay;
use App\Libraries\Platform\Tenants;
use App\Libraries\Setup\Demo;
use App\Libraries\Setup\Seed;

/**
 * 0.3.0: Chatu Warehouse on the ChatuERP core. 1.1: organisations kept apart (tenant_id, composite keys, the query
 * guard), the access model of ChatuPOS beta12 with warehouses, the platform console. 1.5: plans with limits,
 * invoices, trials, module rules, online payment through Lenco and DPO.
 */

/** The platform owner of the test installation. */
function core_pat(): int
{
    $id = (int) Db::value("SELECT id FROM {users} WHERE username = 'pat'");
    if (! $id) {
        $id = Seed::login('pat', 'Pat Platform', Demo::PASSWORD, 'pat@chatu.example', 'Platform owner');
        Seed::platformRole($id);
    }
    Tenancy::set(null);
    Auth::actAs($id);
    return $id;
}

/** A fake gateway: records the calls and answers from a queue. */
function core_gateway(array &$calls, array $answers): void
{
    Pay::$http = static function (string $m, string $url, array $h, ?string $body) use (&$calls, &$answers) {
        $calls[] = [$m, $url, $body];
        return array_shift($answers) ?? [500, ''];
    };
}

return [
    'ORG-1 two organisations: the same item code in both; another organisation\'s record is "not found"; lists show only your own' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        $t->as('bella');
        $own = $t->item('RICE5');
        $t->eq('Rice 5 kg bag', Items::get($own)['name']);
        $t->throws(static fn () => Items::get($f['item']), 'not found');
        $t->eq($own, (int) Items::bySku('RICE5')['id'], 'the code finds our own item');
        Tenancy::set($f['tenant']);
        Auth::actAs($f['users']['fay']);
        $t->eq($f['item'], (int) Items::bySku('RICE5')['id'], 'and theirs in their organisation');
        $t->throws(static fn () => Items::get($own), 'not found');
        $t->eq(1, count(Warehouses::all()), 'Demo Foods sees its one warehouse');
        $t->throws(static fn () => Warehouses::get($t->wh('LSK')), 'not found');
        // a person in both: memberships, and only what each organisation gives
        $mia = $f['users']['mia'];
        $t->eq(['Demo Distributors Ltd', 'Demo Foods'], array_column(Tenancy::memberships($mia), 'name'));
        Auth::actAs($mia);
        $t->true(Access::can('inventory.move'), 'storekeeper at Demo Foods');
        Tenancy::set($t->c['tenant']);
        $t->true(! Access::can('inventory.move') && Access::can('audit.view'), 'auditor at Demo Distributors');
    },

    'ORG-2 the database keeps them apart: a row cannot point at another organisation\'s row; codes are unique per organisation only' => static function (TestKit $t) {
        $f   = Demo::second($t->c);
        $lsk = $t->wh('LSK');
        // a bin of Demo Foods in Demo Distributors' warehouse: refused by the composite key
        $e = $t->throws(static fn () => Db::exec('INSERT INTO {bins} (tenant_id, warehouse_id, code, type) VALUES (?, ?, ?, ?)', [$f['tenant'], $lsk, 'X-1', 'storage']), 'foreign key');
        $t->true($e instanceof DbError, 'a database error');
        Tenancy::set($f['tenant']);
        Auth::actAs($f['users']['fay']);
        $kt = Warehouses::create(['code' => 'KTW', 'name' => 'Kitwe store']); // KTW is also Demo Distributors' code
        $t->true($kt > 0, 'the same warehouse code in another organisation');
        $t->throws(static fn () => Warehouses::create(['code' => 'KTW', 'name' => 'Again']), 'already');
    },

    'ORG-3 the tenant guard: a query on an organisation\'s table without tenant_id is refused, and so is one with no organisation chosen' => static function (TestKit $t) {
        $t->throws(static fn () => Db::rows('SELECT * FROM {items}'), 'Tenant guard', DbError::class);
        $t->throws(static fn () => Db::exec('UPDATE {stock} SET qty = qty WHERE id = 1'), 'Tenant guard', DbError::class);
        $t->true(count(Db::unscoped(static fn () => Db::rows('SELECT id FROM {items}'))) > 0, 'platform code may look across, on purpose');
        Tenancy::set(null);
        $t->throws(static fn () => Db::rows('SELECT * FROM {items} WHERE tenant_id = {tid}'), 'Choose an organisation', DeniedException::class);
        $t->throws(static fn () => Db::insert('items', ['sku' => 'X']), 'Choose an organisation', DeniedException::class);
        Tenancy::set($t->c['tenant']);
        // every organisation table has the key and the guard knows every table
        foreach (Tenancy::business() as $tb) {
            $t->true((bool) Db::value("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'tenant_id'", [Db::t($tb)]), $tb . ' has tenant_id');
        }
    },

    'ACC-1 roles are the organisation\'s own copies; a person holds roles and direct permissions; refusals name the module and the box' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        $t->as('sam');
        $roles = array_column(Roles::list(), null, 'code');
        $t->true(isset($roles['storekeeper']) && $roles['storekeeper']['template'] === 'storekeeper', 'copied from the template');
        $other = (int) Db::unscoped(static fn () => Db::value("SELECT id FROM {roles} WHERE tenant_id = ? AND code = 'storekeeper'", [$f['tenant']]));
        $t->throws(static fn () => Roles::get($other), 'not found');
        // change the storekeeper role here: Demo Foods' copy keeps its permissions
        $perms = Roles::perms((int) $roles['storekeeper']['id']);
        Roles::save(['name' => 'Storekeeper', 'description' => 'x', 'perms' => array_merge($perms, ['lot.hold'])], (int) $roles['storekeeper']['id']);
        $t->true(in_array('lot.hold', Roles::perms((int) $roles['storekeeper']['id']), true), 'changed here');
        $t->true(! (bool) Db::unscoped(static fn () => Db::value("SELECT 1 FROM {role_permissions} WHERE role_id = ? AND permission_code = 'lot.hold'", [$other])), 'not there');
        // a refusal names the module and the box
        $t->as('sally');
        $e = $t->throws(static fn () => Items::save(['sku' => 'NEW1', 'name' => 'New']), 'Items, Create');
        $t->true($e instanceof DeniedException && str_contains($e->getMessage(), '(item.create)'), 'and the code');
        // a direct permission on top of the roles
        $t->as('sam');
        $a = Users::access($t->uid('sally'));
        Users::setAccess($t->uid('sally'), $a['roles'], $a['all_warehouses'], $a['warehouses'], ['item.create']);
        $t->as('sally');
        $t->true(Items::save(['sku' => 'NEW1', 'name' => 'New']) > 0, 'given directly');
        $t->throws(static fn () => Items::save(['sku' => 'NEW1', 'name' => 'Renamed'], (int) Items::bySku('NEW1')['id']), 'Items, Edit');
        // Select: from any of Select, View, Create, Edit, Delete
        $t->true(Access::canSelect('item'), 'sally may pick items');
        $t->true(Access::canSelect('customer'), 'and customers');
        $t->as('sam');
        $t->true(! Access::canSelect('supplier'), 'the system administrator may not pick suppliers');
        $t->eq('Suppliers and purchasing, Select', Rights::label('supplier.select'));
    },

    'ACC-2 nobody changes their own access or a role they hold; the account owner cannot be switched off; the last person who can give access keeps it' => static function (TestKit $t) {
        $t->as('sam');
        $a = Users::access($t->uid('sam'));
        $t->throws(static fn () => Users::setAccess($t->uid('sam'), $a['roles'], true, [], []), 'your own access', DeniedException::class);
        $sysadmin = (int) Db::value("SELECT id FROM {roles} WHERE tenant_id = {tid} AND code = 'system_admin'");
        $t->throws(static fn () => Roles::save(['name' => 'System administrator', 'perms' => ['admin.settings']], $sysadmin), 'hold');
        $t->throws(static fn () => Users::setActive($t->uid('sam'), false), 'your own account');
        // sam is the only one who may change access; making dora able to as well, then switching sam off by dora works
        $d = Users::access($t->uid('dora'));
        Users::setAccess($t->uid('dora'), $d['roles'], true, [], ['user.view', 'user.update', 'user.delete']);
        $t->as('dora');
        Users::setActive($t->uid('sam'), false);
        $t->true(! Access::can('user.update', $t->uid('sam')), 'sam is off');
        // dora cannot take her own away, and nobody else could: the organisation is never locked out
        $t->throws(static fn () => Users::setAccess($t->uid('dora'), $d['roles'], true, [], []), 'your own access', DeniedException::class);
        $t->as('dora');
        Users::setActive($t->uid('sam'), true);
        // an account owner (Demo Foods' fay) cannot be switched off
        $f = Demo::second($t->c);
        Tenancy::set($f['tenant']);
        Auth::actAs($f['users']['fay']);
        $t->true(Access::can('stock.approve') && Access::tenantWide('warehouse.setup'), 'the owner may do everything everywhere');
        $mia = $f['users']['mia'];
        $m = Users::access($mia);
        Users::setAccess($mia, $m['roles'], false, $m['warehouses'], ['user.view', 'user.delete']);
        Auth::actAs($mia);
        $t->throws(static fn () => Users::setActive($f['users']['fay'], false), 'account owner');
    },

    'ACC-3 warehouses: what a person may do, they may do only at their warehouses; every warehouse includes new ones' => static function (TestKit $t) {
        $t->as('sam');
        $new = Warehouses::create(['code' => 'NDL', 'name' => 'Ndola depot']);
        $t->true(Access::canAt($new, 'stock.view', $t->uid('dora')), 'dora (every warehouse) has the new one at once');
        $t->true(! Access::canAt($new, 'stock.view', $t->uid('steve')), 'steve (Lusaka only) has not');
        $s = Users::access($t->uid('steve'));
        $t->eq([false, [$t->wh('LSK')]], [$s['all_warehouses'], $s['warehouses']]);
        Users::setAccess($t->uid('steve'), $s['roles'], false, [$t->wh('LSK'), $new], []);
        $t->true(Access::canAt($new, 'inventory.move', $t->uid('steve')), 'given Ndola');
        $t->throws(static fn () => Users::setAccess($t->uid('steve'), $s['roles'], false, [999999], []), 'not found');
        $t->as('steve');
        $t->throws(static fn () => Access::requireAt($t->wh('KTW'), 'inventory.move'), 'not one of yours');
    },

    'PLT-1 the platform owner adds organisations and sees their counts, never their stock; only the platform owner may' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $t->as('sam');
        $t->throws(static fn () => Tenants::create(['name' => 'X', 'owner' => ['username' => 'xx', 'name' => 'X', 'password' => 'Long-enough-2026']]), 'platform owner', DeniedException::class);
        core_pat();
        $r = Tenants::create(['name' => 'Copperbelt Cold Stores', 'status' => 'trial', 'location_code' => 'CHG', 'location_name' => 'Chingola cold store',
            'owner' => ['username' => 'carol', 'name' => 'Carol Owner', 'email' => 'carol@cold.example', 'password' => 'Long-enough-2026']]);
        $row = array_values(array_filter(Tenants::list(), static fn ($x) => (int) $x['id'] === $r['tenant']))[0];
        $t->eq(['Copperbelt Cold Stores', 'trial', 1, 1, 'Carol Owner'], [$row['name'], $row['status'], (int) $row['users'], (int) $row['locations'], $row['owner_name']]);
        $t->true(! Access::can('stock.view') && Tenancy::id() === null, 'the platform owner holds no organisation permission');
        Tenancy::run($r['tenant'], static function () use ($t) {
            $t->true((int) Db::value('SELECT COUNT(*) FROM {reasons} WHERE tenant_id = {tid}') >= 5, 'its own reason codes');
            $t->true((bool) Db::value("SELECT 1 FROM {roles} WHERE tenant_id = {tid} AND code = 'storekeeper'"), 'its own roles');
        });
        // carol signs in with her e-mail and works in her organisation only
        Auth::actAs(null);
        $t->true(Auth::attempt('carol@cold.example', 'Long-enough-2026')['ok'], 'sign in with the e-mail address');
        $t->eq([$r['tenant']], array_map('intval', array_column(Tenancy::memberships($r['owner']), 'id')));
        Tenancy::set($t->c['tenant']);
    },

    'AUD-1 one audit chain per organisation and one for the platform; each verifies on its own' => static function (TestKit $t) {
        $f = Demo::second($t->c);
        $t->as('sam');
        Audit::log('test.note', 'system', null, null, ['n' => 1]);
        $t->eq(null, Audit::verify($t->c['tenant']));
        $t->eq(null, Audit::verify($f['tenant']));
        $t->eq(null, Audit::verify(null));
        $t->eq(0, (int) Db::value('SELECT COUNT(*) FROM {audit_events} WHERE tenant_id = {tid} AND tenant_id <> ?', [$t->c['tenant']]), 'the log page reads its own chain');
        $t->eq([], Audit::verifyAll());
        // a changed record breaks only its own chain
        $id = (int) Db::value('SELECT MAX(id) FROM {audit_events} WHERE tenant_id = {tid}');
        Db::exec("UPDATE {audit_events} SET detail = 'changed' WHERE tenant_id = {tid} AND id = ?", [$id]);
        $t->eq([$t->c['tenant'] => $id], Audit::verifyAll());
        Db::exec('UPDATE {audit_events} SET detail = NULL WHERE tenant_id = {tid} AND id = ?', [$id]);
        $t->eq([], Audit::verifyAll());
    },

    'C15-1 plans: limits on warehouses and people are checked when choosing a plan and when adding; only the platform owner manages plans' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        $t->as('sam');
        $t->throws(static fn () => Billing::savePlan(['code' => 'X', 'name' => 'X']), 'platform owner', DeniedException::class);
        core_pat();
        $tiny = Billing::savePlan(['code' => 'TINY', 'name' => 'Tiny', 'monthly_price' => '100', 'max_locations' => '1', 'max_users' => '1']);
        $t->throws(static fn () => Billing::assignPlan($tid, $tiny), 'Choose a bigger plan');
        $used = \App\Libraries\Platform\Product::usage($tid);
        $t->eq(2, $used['locations'], 'two warehouses');
        $fit = Billing::savePlan(['code' => 'FIT', 'name' => 'Just fits', 'monthly_price' => '450.50', 'max_locations' => (string) $used['locations'], 'max_users' => (string) $used['users']]);
        Billing::assignPlan($tid, $fit);
        $t->as('sam');
        $t->throws(static fn () => Warehouses::create(['code' => 'NEW', 'name' => 'New depot']), 'Your plan allows');
        $t->throws(static fn () => Users::create(['username' => 'newbie', 'name' => 'New', 'password' => 'Long-enough-2026']), 'Your plan allows');
        core_pat();
        Billing::savePlan(['code' => 'FIT', 'name' => 'Just fits', 'monthly_price' => '450.50', 'max_locations' => '', 'max_users' => '', 'active' => '1'], $fit);
        $t->as('sam');
        $t->true(Warehouses::create(['code' => 'NEW', 'name' => 'New depot']) > 0, 'no limit now');
        // the single-company edition has no limits
        Settings::set('edition', 'single');
        core_pat();
        $t->as('sam');
        Users::create(['username' => 'newbie2', 'name' => 'New', 'password' => 'Long-enough-2026']);
    },

    'C15-2 invoices and the daily check: overdue, then suspended (read-only, nothing can be saved); paying makes it active again' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        core_pat();
        Billing::assignPlan($tid, Billing::savePlan(['code' => 'STD', 'name' => 'Standard', 'monthly_price' => '300']));
        $id = Billing::issue($tid, 1, null, null, '2026-01-01');
        $t->eq(300.0, (float) Billing::invoice($id)['amount']);
        Billing::daily('2026-01-16 08:00:00');
        $t->eq('past_due', Db::value('SELECT status FROM {tenants} WHERE id = ?', [$tid]));
        Billing::daily('2026-01-23 08:00:00');
        $t->eq('suspended', Db::value('SELECT status FROM {tenants} WHERE id = ?', [$tid]));
        Tenancy::set($tid);
        $t->true(Tenancy::readOnly(), 'suspended is read-only');
        Tenancy::set(null);
        Billing::pay($id, 'ZANACO 12345');
        $t->eq('active', Db::value('SELECT status FROM {tenants} WHERE id = ?', [$tid]), 'paid: active again');
        Tenancy::set($tid);
    },

    'C15-3 modules: Production and Fleet follow the platform owner\'s rule; the organisation switches what it may decide; in-use modules stay on' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        $t->true(Modules::on('production', $tid) && Modules::on('fleet', $tid), 'on by default');
        core_pat();
        Modules::setRules($tid, ['production' => 'off'], 'Not on their plan');
        Tenancy::set($tid);
        $t->as('paul');
        $t->eq('production', Modules::offFor('production/runs/new'), 'its pages are refused');
        $t->eq(null, Modules::offFor('stock'), 'other pages are not');
        $t->throws(static fn () => \App\Libraries\Docs\Production::saveRecipe(['code' => 'R1', 'name' => 'R', 'output_item_id' => $t->item('BREAD'), 'output_qty' => 1, 'lines' => [['item_id' => $t->item('FLOUR'), 'qty' => 1]]]), 'switched off');
        $t->true(! isset(Rights::modules()['production']), 'its permissions leave the grid');
        $t->as('sam');
        $t->throws(static fn () => Modules::setOwn('production', true), 'not available');
        // fleet: a trip on the road keeps the module on
        $t->as('tom');
        $veh = \App\Libraries\Fleet\Fleet::saveVehicle(['reg_no' => 'BAZ 1', 'body' => 'truck', 'capacity_kg' => 3000, 'insurance_expiry' => date('Y-m-d', strtotime('+90 days')), 'fitness_expiry' => date('Y-m-d', strtotime('+90 days')), 'road_tax_expiry' => date('Y-m-d', strtotime('+90 days'))]);
        $drv = \App\Libraries\Fleet\Fleet::saveDriver(['name' => 'D', 'licence_no' => 'L1', 'licence_expiry' => date('Y-m-d', strtotime('+90 days'))]);
        \App\Libraries\Fleet\Fleet::planTrip(['warehouse_id' => $t->wh('LSK'), 'vehicle_id' => $veh, 'driver_id' => $drv]);
        $t->as('sam');
        $t->throws(static fn () => Modules::setOwn('fleet', false), 'in use');
        core_pat();
        $t->throws(static fn () => Modules::setRules($tid, ['fleet' => 'off']), 'in use');
    },

    'C15-4 Lenco: the phone is asked; the signed webhook marks the invoice paid once; a wrong signature is refused' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        core_pat();
        Pay::saveKeys(null, ['pay_lenco_token_enc' => 'test-token-123', 'pay_lenco_base' => 'https://sandbox.lenco.co/access/v2']);
        $inv = Billing::issue($tid, 1, 300.0);
        Tenancy::set($tid);
        $t->as('sam');
        $calls = [];
        core_gateway($calls, [[200, json_encode(['status' => true, 'data' => ['id' => 'x1', 'lencoReference' => 'L-777', 'status' => 'pending', 'amount' => '300.00']])]]);
        $p = Pay::start('platform_invoice', $inv, $tid, 300.0, 'lenco', false, ['phone' => '+260 97 7123456', 'operator' => 'airtel']);
        $t->eq(['pending', '0977123456', 'L-777'], [$p['status'], $p['phone'], $p['gateway_ref']]);
        $body = json_encode(['event' => 'collection.successful', 'data' => ['reference' => $p['reference'], 'amount' => '300.00', 'lencoReference' => 'L-777', 'status' => 'successful']]);
        $sig  = hash_hmac('sha512', $body, hash('sha256', 'test-token-123'));
        $t->throws(static fn () => Pay::lencoWebhook($body, str_repeat('0', 128), null), 'Signature');
        $t->eq('settled paid', Pay::lencoWebhook($body, $sig, null));
        $t->eq('already settled', Pay::lencoWebhook($body, $sig, null), 'twice settles once');
        $t->eq('paid', Billing::invoice($inv)['status']);
        Pay::$http = null;
        Tenancy::set($tid);
    },

    'C15-5 DPO: a token and the payment page; not paid yet stays pending; the scheduled check settles it' => static function (TestKit $t) {
        Settings::set('edition', 'saas');
        $tid = $t->c['tenant'];
        core_pat();
        Pay::saveKeys(null, ['pay_dpo_token_enc' => 'COMPANY-TOKEN', 'pay_dpo_service' => '3854']);
        $inv = Billing::issue($tid, 2, 1000.0);
        $calls = [];
        core_gateway($calls, [
            [200, '<?xml version="1.0"?><API3G><Result>000</Result><ResultExplanation>Created</ResultExplanation><TransToken>TT-1</TransToken><TransRef>R1</TransRef></API3G>'],
            [200, '<?xml version="1.0"?><API3G><Result>900</Result><ResultExplanation>Not paid yet</ResultExplanation></API3G>'],
            [200, '<?xml version="1.0"?><API3G><Result>000</Result><ResultExplanation>Transaction Paid</ResultExplanation><TransactionAmount>1000.00</TransactionAmount><TransactionApproval>A1</TransactionApproval></API3G>'],
        ]);
        $p = Pay::start('platform_invoice', $inv, $tid, 1000.0, 'dpo', false, ['return_url' => 'https://example.test/pay/return']);
        $t->true(str_ends_with((string) $p['pay_url'], 'payv2.php?ID=TT-1'), 'sent to DPO\'s page');
        $t->eq('pending', Pay::check((int) $p['id'])['status'], 'not paid yet');
        Db::exec('UPDATE {gateway_payments} SET created_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 600), $p['id']]);
        $t->eq(1, Pay::reconcile()['settled'], 'the scheduled check found it paid');
        $t->eq('paid', Billing::invoice($inv)['status']);
        Pay::$http = null;
        Tenancy::set($tid);
    },

    'JOB-1 the scheduled job works for every organisation: its own check and reminders; the platform check covers every chain' => static function (TestKit $t) {
        Demo::second($t->c);
        Tenancy::set(null);
        Auth::actAs(null);
        $out = \App\Libraries\Jobs::run();
        $t->true(! isset($out['error']), 'ran: ' . json_encode($out));
        $t->eq(0, $out['reconcile'] ?? 0, 'nothing wrong in either organisation');
        $t->eq(0, $out['integrity'] ?? -1, 'the platform check found nothing');
        foreach (Db::unscoped(static fn () => Db::rows('SELECT id FROM {tenants}')) as $tn) {
            $t->eq(date('Y-m-d'), Tenancy::run((int) $tn['id'], static fn () => Settings::get('last_daily')), 'daily reminders for organisation ' . $tn['id']);
        }
        Tenancy::set($t->c['tenant']);
    },
];
