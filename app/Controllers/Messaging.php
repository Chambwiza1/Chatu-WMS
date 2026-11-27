<?php

namespace App\Controllers;

use App\Libraries\Messaging\Messages;
use App\Libraries\Messaging\Outbox;
use App\Libraries\Messaging\Senders;

/** core 1.4 (E5): Administration > Messages: the organisation's own senders, and everything it sent. */
class Messaging extends BaseController
{
    public function index()
    {
        if ($r = $this->need('admin.settings')) {
            return $r;
        }
        $f = ['status' => (string) $this->request->getGet('status'), 'channel' => (string) $this->request->getGet('channel'), 'q' => (string) $this->request->getGet('q')];
        return $this->render('admin/messages', ['sum' => Senders::summary(), 'rows' => Outbox::list($f), 'counts' => Outbox::counts(), 'waiting' => Outbox::waiting(), 'f' => $f,
            'webhook' => site_url('hooks/whatsapp')], 'Messages');
    }

    public function saveEmail()
    {
        return $this->act(function () {
            Senders::saveEmail((array) $this->request->getPost());
            return $this->ok(Senders::email() ? 'E-mail settings saved. Send yourself a test to check them.' : 'Saved. Without a sender address, e-mail waits in the outbox.', 'admin/messages');
        }, site_url('admin/messages'));
    }

    public function saveWhatsApp()
    {
        return $this->act(function () {
            Senders::saveWhatsApp((array) $this->request->getPost());
            return $this->ok(Senders::whatsapp() ? 'WhatsApp saved. Send yourself a test to check it.' : 'WhatsApp saved: off.', 'admin/messages');
        }, site_url('admin/messages'));
    }

    public function test()
    {
        return $this->act(function () {
            $r = Messages::test((string) $this->in('channel', 'email'), (string) $this->in('to', ''));
            if (in_array($r['status'], ['sent', 'delivered', 'read'], true)) {
                return $this->ok('Test sent to ' . $r['recipient'] . '.', 'admin/messages');
            }
            throw new \App\Libraries\Core\BizException('The test was not sent: ' . ($r['error'] ?: Outbox::LABELS[$r['status']]) . ($r['status'] === 'queued' ? ' It will be tried again.' : ''));
        }, site_url('admin/messages'));
    }

    public function notify()
    {
        return $this->act(function () {
            \App\Libraries\Core\Access::require('admin.settings');
            $on  = $this->in('notify_customers') === '1' ? '1' : '0';
            $old = (string) \App\Libraries\Core\Settings::tenant('notify_customers', '1');
            if ($old !== $on) {
                \App\Libraries\Core\Settings::setTenant('notify_customers', $on);
                \App\Libraries\Core\Audit::log('settings.change', 'settings', 'notify_customers', ['notify_customers' => $old], ['notify_customers' => $on]);
            }
            return $this->ok($on === '1' ? 'Customers are told when their goods leave and arrive.' : 'Customers are no longer told.', 'admin/messages');
        }, site_url('admin/messages'));
    }

    public function retry(int $id)
    {
        return $this->act(function () use ($id) {
            Outbox::retry($id);
            return $this->ok('It will be sent again within a few minutes.', 'admin/messages');
        }, site_url('admin/messages'));
    }

    public function cancel(int $id)
    {
        return $this->act(function () use ($id) {
            Outbox::cancel($id);
            return $this->ok('Cancelled.', 'admin/messages');
        }, site_url('admin/messages'));
    }
}
