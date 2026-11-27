<?php

use App\Commands\TestKit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\DeniedException;
use App\Libraries\Core\Settings;
use App\Libraries\Docs\Sales;
use App\Libraries\Docs\Shipments;
use App\Libraries\Messaging\Messages;
use App\Libraries\Messaging\Outbox;
use App\Libraries\Messaging\Senders;
use App\Libraries\Messaging\Transport;
use App\Libraries\Reports\Dashboard;
use App\Libraries\Workflow\Notify;

/** ChatuERP core 1.4 (E5): the outbox, with the organisation's own e-mail and WhatsApp senders. */
$sent  = [];
$fake  = static function (array &$sent, array $plan = []): void {
    Transport::$fake = static function (string $ch, string $to, array $m) use (&$sent, &$plan) {
        $sent[] = [$ch, $to, $m];
        $next   = array_shift($plan) ?? 'ok';
        return $next === 'ok' ? ['ok' => true, 'id' => $ch === 'whatsapp' ? 'wamid.' . count($sent) : null, 'permanent' => false, 'error' => null]
            : ['ok' => false, 'id' => null, 'permanent' => $next === 'permanent', 'error' => $next === 'permanent' ? '131026: not on WhatsApp' : 'timed out'];
    };
};
$ownMail = ['mail_own_from' => 'stores@demo.example', 'mail_own_name' => 'Demo Stores', 'mail_own_host' => 'mail.demo.example', 'mail_own_port' => '587', 'mail_own_user' => 'stores', 'mail_own_pass' => 'S3cret-pass', 'mail_own_crypto' => 'tls'];

return [
    'E5-1 business e-mail waits for the organisation\'s own sender, untouched, with an alert; then goes out from its own address; the installation\'s mailer keeps only platform and account mail' => static function (TestKit $t) use (&$sent, $fake, $ownMail) {
        $sent = [];
        $fake($sent);
        Settings::set('edition', 'saas');
        Settings::set('mail_from', 'platform@chatu.example');
        Db::exec("UPDATE {users} SET email = 'wendy@demo.example' WHERE username = 'wendy'");
        Notify::user($t->uid('wendy'), 'approval', 'Adjustment ADJ-1 waits for approval', 'Value 246.00', '/adjustments/1');
        $t->eq(null, Senders::email(), 'online service: no sender until the organisation sets its own (not the platform\'s)');
        $t->eq(1, Messages::staffCopies());
        $t->eq(0, Messages::staffCopies(), 'copied once');
        Notify::user($t->uid('wendy'), 'billing', 'New invoice INV-1', null, '/billing');
        $t->eq(0, Messages::staffCopies(), 'plan and invoice messages stay with the installation\'s mailer');
        $r = Outbox::process();
        $t->eq([0, 1], [$r['sent'], $r['waiting']]);
        $row = Db::row("SELECT * FROM {message_outbox} WHERE tenant_id = {tid} AND purpose = 'staff'");
        $t->eq(['queued', 0], [$row['status'], (int) $row['attempts']], 'waiting does not use up tries');
        $t->true(str_contains((string) $row['error'], 'Waiting for your e-mail'), 'says why');
        $t->true(! str_contains((string) $row['body_enc'], 'ADJ-1'), 'the text is kept encrypted');
        $t->as('sam');
        $t->true(in_array('Messages waiting for your sender', array_map(static fn ($a) => preg_replace('/^\d+ · /', '', $a['title']), Dashboard::alerts()), true), 'the administrator is alerted');
        $t->as('steve');
        $t->throws(static fn () => Senders::saveEmail(['mail_own_from' => 'x@y.example']), 'admin.settings', DeniedException::class);
        $t->as('sam');
        $t->throws(static fn () => Senders::saveEmail(['mail_own_from' => 'stores@demo.example']), 'your own server', BizException::class);
        $t->throws(static fn () => Senders::saveEmail(['mail_own_from' => 'not an address']), 'does not look right', BizException::class);
        Senders::saveEmail($ownMail);
        $stored = (string) Db::value("SELECT value FROM {tenant_settings} WHERE tenant_id = {tid} AND name = 'mail_own_pass_enc'");
        $t->true($stored !== '' && ! str_contains($stored, 'S3cret'), 'the password is kept encrypted');
        $audit = (string) Db::value("SELECT after_json FROM {audit_events} WHERE tenant_id = {tid} AND action = 'messaging.email' ORDER BY id DESC LIMIT 1");
        $t->true(str_contains($audit, '***') && ! str_contains($audit, 'S3cret'), 'and never written to the audit log');
        $t->eq(['own', 'S3cret-pass'], [Senders::email()['sender'], Senders::email()['pass']]);
        $r = Outbox::process();
        $t->eq(1, $r['sent']);
        $t->eq(['email', 'wendy@demo.example', 'stores@demo.example'], [$sent[0][0], $sent[0][1], $sent[0][2]['from']], 'from the organisation\'s own address');
        $row = Outbox::get((int) $row['id']);
        $t->eq(['sent', null], [$row['status'], $row['body_enc']], 'sent, and its text wiped');
        $t->true(! in_array('Messages waiting for your sender', array_map(static fn ($a) => preg_replace('/^\d+ · /', '', $a['title']), Dashboard::alerts()), true), 'the alert goes');
        // the single-company edition: the installation's mail settings count as the organisation's own
        Settings::set('edition', 'single');
        Settings::setTenant('mail_own_from', '');
        $t->eq(['installation', 'platform@chatu.example'], [Senders::email()['sender'], Senders::email()['from']]);
        Transport::$fake = null;
    },

    'E5-2 sending: temporary failures are tried again later, permanent ones fail at once; two runs never send one message twice; failed ones can be tried again, waiting ones cancelled' => static function (TestKit $t) use (&$sent, $fake, $ownMail) {
        $sent = [];
        Settings::set('edition', 'saas');
        $t->as('sam');
        Senders::saveEmail($ownMail);
        $fake($sent, ['temp', 'permanent']);
        $a = Outbox::queue(['channel' => 'email', 'purpose' => 'test', 'recipient' => 'a@x.example', 'sender' => 'own', 'body' => ['subject' => 'A', 'text' => 'a']]);
        $b = Outbox::queue(['channel' => 'email', 'purpose' => 'test', 'recipient' => 'b@x.example', 'sender' => 'own', 'body' => ['subject' => 'B', 'text' => 'b']]);
        Outbox::process();
        $ra = Outbox::get($a);
        $t->eq(['queued', 1], [$ra['status'], (int) $ra['attempts']], 'a temporary failure waits');
        $t->true($ra['next_attempt_at'] > gmdate('Y-m-d H:i:s'), 'until later');
        $t->eq('failed', Outbox::get($b)['status'], 'a permanent one fails at once');
        $t->eq(0, Outbox::process()['sent'], 'not due yet: nothing sent');
        Db::exec('UPDATE {message_outbox} SET next_attempt_at = ? WHERE tenant_id = {tid} AND id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $a]);
        Db::exec("UPDATE {message_outbox} SET status = 'sending', claim = 'someoneelse0000' WHERE tenant_id = {tid} AND id = ?", [$a]);
        $t->eq(0, Outbox::process()['sent'], 'claimed by another run: left alone');
        Db::exec("UPDATE {message_outbox} SET status = 'queued', claim = NULL WHERE tenant_id = {tid} AND id = ?", [$a]);
        $t->eq(1, Outbox::process()['sent']);
        $t->eq(3, count($sent), 'three sendings in all, none twice');
        Outbox::retry($b);
        $t->eq('queued', Outbox::get($b)['status']);
        Outbox::cancel($b);
        $t->eq(['cancelled', null], [Outbox::get($b)['status'], Outbox::get($b)['body_enc']]);
        $t->throws(static fn () => Outbox::retry($a), 'not delivered', BizException::class);
        $t->as('steve');
        $t->throws(static fn () => Outbox::cancel($a), 'admin.settings', DeniedException::class);
        Transport::$fake = null;
    },

    'E5-3 customers are told when their goods leave and arrive, by e-mail and by the organisation\'s own WhatsApp number; delivery reports only move a message forward and must be signed' => static function (TestKit $t) use (&$sent, $fake, $ownMail) {
        $sent = [];
        $fake($sent);
        Settings::set('edition', 'saas');
        $cust = (int) $t->c['customer']['SHOPRITE'];
        Db::exec("UPDATE {customers} SET email = 'orders@shoprite.example', phone = '0977 123456' WHERE tenant_id = {tid} AND id = ?", [$cust]);
        $t->receive('LSK', [['RICE5', 10, 80]]);
        $go = static function () use ($t, $cust): int {
            $t->as('sally');
            $so = Sales::saveDraft(['customer_id' => $cust, 'warehouse_id' => $t->wh('LSK'), 'lines' => [['item_id' => $t->item('RICE5'), 'qty' => 2]]]);
            Sales::confirm($so);
            $t->as('steve');
            $sh = Shipments::create($so);
            Shipments::pick($sh);
            Shipments::pack($sh, 1);
            Shipments::dispatch($sh);
            return $sh;
        };
        $sh = $go();
        $t->eq(0, (int) Db::value("SELECT COUNT(*) FROM {message_outbox} WHERE tenant_id = {tid} AND purpose = 'customer'"), 'no sender yet: nothing queued that would go out days late');
        $t->as('sam');
        Senders::saveEmail($ownMail);
        $t->throws(static fn () => Senders::saveWhatsApp(['wa_mode' => 'own', 'wa_phone_number_id' => '+260 97']), 'digits only', BizException::class);
        Senders::saveWhatsApp(['wa_mode' => 'own', 'wa_phone_number_id' => '109876543210', 'wa_token' => 'EAAG-token', 'wa_app_secret' => 'app-secret-1', 'wa_display' => '+260 97 1234567']);
        $sh = $go();
        $rows = Db::rows("SELECT channel, recipient, template FROM {message_outbox} WHERE tenant_id = {tid} AND purpose = 'customer' AND object_id = ? ORDER BY channel", [$sh]);
        $t->eq([['email', 'orders@shoprite.example', null], ['whatsapp', '260977123456', 'chatu_notice']], array_map('array_values', $rows), 'by e-mail and WhatsApp, the Zambian number made international');
        Outbox::process();
        $wa = array_values(array_filter($sent, static fn ($s) => $s[0] === 'whatsapp'))[0];
        $t->true(str_contains($wa[2]['params'][1], 'has left our') && str_contains($wa[2]['params'][1], 'Delivery note'), 'what happened: ' . $wa[2]['params'][1]);
        $t->eq('own', $wa[2]['sender'], 'from the organisation\'s own number');
        $t->as('steve');
        Shipments::deliver($sh, 'J Banda');
        $t->eq(4, (int) Db::value("SELECT COUNT(*) FROM {message_outbox} WHERE tenant_id = {tid} AND purpose = 'customer' AND object_id = ?", [$sh]), 'and again on delivery');
        // delivery reports
        $pid = (string) Db::value("SELECT provider_id FROM {message_outbox} WHERE tenant_id = {tid} AND channel = 'whatsapp' AND status = 'sent' LIMIT 1");
        $body = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '109876543210'], 'statuses' => [['id' => $pid, 'status' => 'read', 'timestamp' => time()]]]]]]]];
        $raw = json_encode($body);
        $t->true(! \App\Controllers\Hooks\WhatsApp::signed($raw, $body, 'sha256=' . hash_hmac('sha256', $raw, 'wrong')), 'a wrong signature is refused');
        $t->true(\App\Controllers\Hooks\WhatsApp::signed($raw, $body, 'sha256=' . hash_hmac('sha256', $raw, 'app-secret-1')), 'the organisation\'s own app secret');
        $t->eq(1, \App\Controllers\Hooks\WhatsApp::apply($body));
        $t->eq('read', Db::value("SELECT status FROM {message_outbox} WHERE tenant_id = {tid} AND provider_id = ?", [$pid]));
        Outbox::report($pid, 'delivered', time());
        $t->eq('read', Db::value("SELECT status FROM {message_outbox} WHERE tenant_id = {tid} AND provider_id = ?", [$pid]), 'never backwards');
        Settings::setTenant('notify_customers', '0');
        $t->eq(0, Messages::shipment($sh, 'delivered'), 'the organisation can stop telling customers');
        $t->eq(null, Messages::phone('12345'));
        Transport::$fake = null;
    },
];
