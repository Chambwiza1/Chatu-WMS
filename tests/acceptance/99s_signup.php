<?php

use App\Commands\TestKit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\Db;
use App\Libraries\Core\PasswordReset;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use App\Libraries\Platform\Signup;
use App\Libraries\Setup\Demo;
use App\Libraries\Setup\Seed;

/** ChatuERP core 1.5.4: the sign-up page sums up what the customer needs and sends a request. */
if (! function_exists('core_pat')) {
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
}
$form = ['org_name' => 'Kafue Stores', 'contact_name' => 'Chanda Mwale', 'email' => 'chanda@kafue.example', 'phone' => '+260 977 000 900', 'agree' => '1', 'q' => ['warehouses' => '2', 'people' => '9', 'fleet' => 'yes']];
$link = static fn (string $tok): string => 'https://x.test/signup/confirm/' . $tok;

return [
    'SGR-1 a request: the form is checked, the answers are kept, the customer is thanked and the platform owner told; no organisation or sign-in is created' => static function (TestKit $t) use ($form, $link) {
        Settings::set('edition', 'saas');
        core_pat();
        Db::exec("UPDATE {users} SET email = 'pat@chatu.example' WHERE username = 'pat'");
        Auth::actAs(null);
        Tenancy::set(null);
        PasswordReset::$outbox = [];
        $t->eq(['request', true], [Signup::mode(), Signup::open()], 'open without e-mail set up: a request needs none');
        $orgs  = (int) Db::value('SELECT COUNT(*) FROM {tenants}');
        $users = (int) Db::value('SELECT COUNT(*) FROM {users}');
        foreach ([['org_name', '', 'name of your organisation'], ['email', 'nobody', 'e-mail address'], ['phone', '', 'phone number'], ['agree', '', 'Tick the box'], ['q', [], 'please answer'],
            ['q', ['people' => 'lots'] + $form['q'], 'whole number']] as [$k, $v, $msg]) {
            $t->throws(static fn () => Signup::submit([$k => $v] + $form, '10.9.1.1', $link), $msg);
        }
        $id = Signup::submit($form, '10.9.1.1', $link);
        PasswordReset::flush();
        $row = Db::row('SELECT * FROM {signups} WHERE id = ?', [$id]);
        $t->eq(['requested', 'request', null, null, null], [$row['status'], $row['mode'], $row['token_hash'], $row['password_hash'], $row['username']]);
        $t->eq([$orgs, $users], [(int) Db::value('SELECT COUNT(*) FROM {tenants}'), (int) Db::value('SELECT COUNT(*) FROM {users}')], 'nothing is created');
        $to = array_column(PasswordReset::$outbox, 'to');
        sort($to);
        $t->eq(['chanda@kafue.example', 'pat@chatu.example'], $to, 'the customer is thanked and the platform owner told');
        $owner = array_values(array_filter(PasswordReset::$outbox, static fn ($m) => $m['to'] === 'pat@chatu.example'))[0]['body'];
        $t->true(str_contains($owner, 'Kafue Stores') && str_contains($owner, 'Do you run your own delivery vehicles?: Yes'), 'the e-mail sums up what they need');
        $t->eq(1, Signup::waiting());
    },

    'SGR-2 the platform owner answers a request: done, or dismissed with a note; once; the page can be closed; only the platform owner sees the list' => static function (TestKit $t) use ($form, $link) {
        Settings::set('edition', 'saas');
        Auth::actAs(null);
        Tenancy::set(null);
        PasswordReset::$outbox = [];
        $a = Signup::submit($form, '10.9.1.2', $link);
        $b = Signup::submit(['org_name' => 'Second Traders'] + $form, '10.9.1.2', $link);
        $t->throws(static fn () => Signup::list(), 'platform owner only');
        core_pat();
        $rows = Signup::list('requested');
        $t->eq(2, count($rows));
        $t->true(str_contains($rows[0]['summary'], 'Do you run your own delivery vehicles?: Yes'), 'the list shows the answers in words');
        $t->throws(static fn () => Signup::decide($b, 'dismissed', ''), 'Say why');
        Signup::decide($b, 'dismissed', 'Duplicate of the first');
        Signup::decide($a, 'done', 'Created as Kafue Stores');
        $t->throws(static fn () => Signup::decide($a, 'dismissed', 'x'), 'already answered');
        $t->eq(0, Signup::waiting());
        $t->eq(['done', 'dismissed'], [Db::value('SELECT status FROM {signups} WHERE id = ?', [$a]), Db::value('SELECT status FROM {signups} WHERE id = ?', [$b])]);
        Settings::set('signup_open', '0');
        Auth::actAs(null);
        $t->throws(static fn () => Signup::submit($form, '10.9.1.3', $link), 'not open');
        core_pat();
        Settings::set('signup_open', '1');
        Settings::set('edition', 'single');
        $t->eq(false, Signup::open(), 'never on a single-company installation');
        Settings::set('edition', 'saas');
    },
];
