<?php

namespace App\Controllers\Hooks;

use App\Controllers\BaseController;
use App\Libraries\Core\Settings;
use App\Libraries\Messaging\Outbox;
use App\Libraries\Messaging\Senders;

/**
 * Delivery reports from WhatsApp (Meta webhooks): /hooks/whatsapp (core 1.4; the same as ChatuPass).
 *
 * GET:  Meta checks the address once, with hub.verify_token (must equal the platform setting wa_verify_token) and a
 *       challenge to send back.
 * POST: status updates (sent, delivered, read, failed) for messages we sent. The body must carry a valid
 *       X-Hub-Signature-256 (HMAC-SHA256 of the raw body with the organisation's own Meta app secret for that
 *       number). Unsigned or wrongly signed reports are refused, so nobody can mark a message as
 *       read or failed. Incoming messages from customers are not stored.
 */
class WhatsApp extends BaseController
{
    public function verify()
    {
        $mode  = (string) $this->request->getGet('hub_mode');
        $token = (string) $this->request->getGet('hub_verify_token');
        $want  = (string) Settings::get('wa_verify_token');
        if ($mode === 'subscribe' && $want !== '' && hash_equals($want, $token)) {
            return $this->response->setContentType('text/plain')->setBody((string) $this->request->getGet('hub_challenge'));
        }
        return $this->response->setStatusCode(403)->setBody('Forbidden');
    }

    public function receive()
    {
        $raw  = (string) $this->request->getBody();
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return $this->response->setStatusCode(400)->setBody('Bad request');
        }
        if (! self::signed($raw, $json, (string) $this->request->getHeaderLine('X-Hub-Signature-256'))) {
            log_message('warning', 'WhatsApp webhook: signature missing or wrong');
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }
        $n = self::apply($json);
        return $this->response->setJSON(['ok' => true, 'statuses' => $n]);
    }

    /** Is the body signed with the app secret of the number it reports on (or the platform's)? */
    public static function signed(string $raw, array $json, string $signature): bool
    {
        $phoneId = (string) ($json['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '');
        $secret  = Senders::appSecretFor($phoneId !== '' ? $phoneId : null);
        return $secret !== '' && $signature !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature);
    }

    /** Apply the status updates of a webhook body. Returns how many matched one of our messages. */
    public static function apply(array $json): int
    {
        $n = 0;
        foreach ((array) ($json['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $s) {
                    $id = (string) ($s['id'] ?? '');
                    $st = (string) ($s['status'] ?? '');
                    if ($id === '' || ! in_array($st, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $err = null;
                    if ($st === 'failed' && ! empty($s['errors'][0])) {
                        $e   = $s['errors'][0];
                        $err = trim(($e['code'] ?? '') . ': ' . ($e['error_data']['details'] ?? $e['message'] ?? $e['title'] ?? ''));
                    }
                    if (Outbox::report($id, $st, (int) ($s['timestamp'] ?? 0), $err)) {
                        $n++;
                    }
                }
            }
        }
        return $n;
    }
}
