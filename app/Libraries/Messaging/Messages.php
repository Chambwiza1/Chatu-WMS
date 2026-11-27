<?php

namespace App\Libraries\Messaging;

use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * What Warehouse sends through the outbox (core 1.4, E5).
 *
 * - **Copies to the organisation's people:** each message they get in Warehouse (approvals waiting, transfers
 *   arriving, stock to reorder) is copied to their e-mail. These wait in the outbox until the organisation's e-mail
 *   is set up, then go out.
 * - **Notices to customers:** when their goods leave the warehouse, and when they are delivered (setting "Tell
 *   customers", on by default). By e-mail when the customer has an address, and by WhatsApp (template chatu_notice,
 *   the same as ChatuPass) when the customer has a phone number. A notice is only queued on a channel the organisation
 *   has set up: a "your goods left" message sent days later would be wrong.
 *
 * Platform and account mail (billing, password reset) do not come here: the installation's own mailer sends them.
 */
final class Messages
{
    /** Queue the e-mail copies of the organisation's new messages (the last three days). Returns how many. */
    public static function staffCopies(int $limit = 200): int
    {
        $tid  = Tenancy::require();
        $rows = Db::rows("SELECT n.*, u.email, u.name FROM {notifications} n JOIN {users} u ON u.id = n.user_id
                           WHERE n.tenant_id = ? AND n.type NOT IN ('billing','support') AND n.emailed_at IS NULL AND u.email IS NOT NULL AND u.email <> '' AND n.created_at > ? ORDER BY n.id LIMIT " . (int) $limit,
            [$tid, gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
        $base = rtrim((string) config('App')->baseURL, '/');
        $org  = Senders::orgName();
        foreach ($rows as $n) {
            Outbox::queue(['channel' => 'email', 'purpose' => 'staff', 'object_type' => 'notification', 'object_id' => (int) $n['id'], 'recipient' => $n['email'], 'sender' => 'own',
                'body' => ['subject' => $org . ': ' . $n['title'],
                    'text' => "Hello {$n['name']},\n\n{$n['title']}\n" . ($n['body'] ? "\n{$n['body']}\n" : '') . ($n['link'] ? "\nOpen: {$base}{$n['link']}\n" : '') . "\nSign in to act on it. This e-mail cannot be used to approve anything.\n"]]);
            Db::exec('UPDATE {notifications} SET emailed_at = ? WHERE tenant_id = ? AND id = ?', [Db::now(), $tid, $n['id']]);
        }
        return count($rows);
    }

    /** A notice to the customer of a shipment: $event dispatched or delivered. Returns how many messages were queued. */
    public static function shipment(int $shipmentId, string $event): int
    {
        if (Settings::tenant('notify_customers', '1') !== '1') {
            return 0;
        }
        $s = Db::row("SELECT s.*, so.number so_number, so.customer_ref, c.name customer, c.email, c.phone, w.name wh_name, w.town
                        FROM {shipments} s JOIN {sales_orders} so ON so.tenant_id = s.tenant_id AND so.id = s.so_id
                        JOIN {customers} c ON c.tenant_id = so.tenant_id AND c.id = so.customer_id
                        JOIN {warehouses} w ON w.tenant_id = s.tenant_id AND w.id = s.warehouse_id
                       WHERE s.tenant_id = {tid} AND s.id = ?", [$shipmentId]);
        if (! $s) {
            return 0;
        }
        $org  = Senders::orgName();
        $ref  = trim($s['so_number'] . ($s['customer_ref'] ? ' (your order ' . $s['customer_ref'] . ')' : ''));
        $text = $event === 'delivered'
            ? 'Order ' . $ref . ' was delivered' . ($s['pod_name'] ? ' and received by ' . $s['pod_name'] : '') . '. Delivery note ' . $s['number'] . '.'
            : 'Order ' . $ref . ' has left our ' . ($s['town'] ?: $s['wh_name']) . ' warehouse' . ((int) $s['packages'] ? ': ' . (int) $s['packages'] . ' package' . ((int) $s['packages'] === 1 ? '' : 's') : '') . '. Delivery note ' . $s['number'] . '.';
        $n = 0;
        if (filter_var((string) $s['email'], FILTER_VALIDATE_EMAIL) && Senders::email()) {
            Outbox::queue(['channel' => 'email', 'purpose' => 'customer', 'object_type' => 'shipment', 'object_id' => $shipmentId, 'recipient' => $s['email'], 'sender' => 'own',
                'body' => ['subject' => $org . ': ' . ($event === 'delivered' ? 'delivered, ' : 'on its way, ') . $s['so_number'],
                    'text' => "Dear {$s['customer']},\n\n{$text}\n\n{$org}\n"]]);
            $n++;
        }
        $phone = self::phone((string) $s['phone']);
        if ($phone && ($wa = Senders::whatsapp())) {
            Outbox::queue(['channel' => 'whatsapp', 'purpose' => 'customer', 'object_type' => 'shipment', 'object_id' => $shipmentId, 'recipient' => $phone, 'sender' => 'own',
                'template' => $wa['template'], 'body' => ['params' => [$org, $text]]]);
            $n++;
        }
        return $n;
    }

    /** A phone number for WhatsApp, in international digits (a Zambian 0977 123456 becomes 260977123456), or null. */
    public static function phone(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (str_starts_with(trim($raw), '00')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 10 && $d[0] === '0') {
            $d = '260' . substr($d, 1);
        }
        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
    }

    /** A test message to the person setting it up, on one channel, queued and sent at once. Returns the outbox row. */
    public static function test(string $channel, string $to): array
    {
        \App\Libraries\Core\Access::require('admin.settings');
        $org = Senders::orgName();
        if ($channel === 'whatsapp') {
            $wa = Senders::whatsapp();
            $to = self::phone($to) ?? '';
            if (! $wa || $to === '') {
                throw new \App\Libraries\Core\BizException($wa ? 'Give a phone number with its country code, for example 0977 123456 or +260 977 123456.' : 'Set up your WhatsApp number first.');
            }
            $id = Outbox::queue(['channel' => 'whatsapp', 'purpose' => 'test', 'recipient' => $to, 'sender' => 'own', 'template' => $wa['template'], 'body' => ['params' => [$org, 'This is a test message from Chatu Warehouse.']]]);
        } else {
            if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \App\Libraries\Core\BizException('Give an e-mail address to send the test to.');
            }
            if (! Senders::email()) {
                throw new \App\Libraries\Core\BizException('Set up your e-mail first.');
            }
            $id = Outbox::queue(['channel' => 'email', 'purpose' => 'test', 'recipient' => $to, 'sender' => 'own', 'body' => ['subject' => $org . ': test message', 'text' => "This is a test message from Chatu Warehouse, sent through {$org}'s own e-mail settings.\n"]]);
        }
        Outbox::process(10, 15.0);
        return Outbox::get($id);
    }
}
