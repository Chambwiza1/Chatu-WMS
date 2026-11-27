<?php

namespace App\Libraries\Messaging;

use Throwable;

/**
 * The two ways a message leaves the server (core 1.4, from ChatuPass; e-mail takes the organisation's own sender). Each returns
 * ['ok' => bool, 'id' => provider id or null, 'permanent' => bool (do not retry), 'error' => text or null].
 *
 * Tests (and anyone checking an installation without sending) replace the real calls with Transport::$fake, a function
 * (channel, to, message) => result. With the installation setting wa_transport = log, WhatsApp messages are written to the
 * log and reported as sent, so an installation can be tried out before Meta approves its templates.
 */
final class Transport
{
    /** @var null|callable(string, string, array): array */
    public static $fake = null;

    /** Meta error codes that will not go away by trying again. */
    private const PERMANENT = [
        100,    // invalid parameter (bad number format, bad template parameter)
        131008, // required parameter missing
        131009, // parameter value not valid
        131021, // sender and recipient are the same number
        131026, // message undeliverable: not on WhatsApp, or an old app
        131045, // phone number not registered
        131047, // more than 24 hours: needs a template (a set-up mistake)
        131051, // message type not supported
        132000, // number of template parameters does not match
        132001, // template does not exist in this language
        132005, // template text too long after filling in
        132007, // template format policy
        132012, // template parameter format
        132015, // template paused
        132016, // template disabled
        190,    // access token expired or not valid
        10,     // permission not granted
        200,    // permission
    ];

    /**
     * Send a WhatsApp template message. $m: template, lang, params (strings for {{1}}, {{2}}, ...).
     * $wa: the sender from Senders::whatsapp().
     */
    public static function whatsapp(array $wa, string $to, array $m): array
    {
        if (self::$fake) {
            return (self::$fake)('whatsapp', $to, $m + ['sender' => $wa['sender']]);
        }
        if (($wa['transport'] ?? 'live') === 'log') {
            log_message('info', 'WhatsApp (log only) to ' . $to . ': ' . $m['template'] . ' ' . json_encode($m['params']));
            return ['ok' => true, 'id' => 'log.' . bin2hex(random_bytes(8)), 'permanent' => false, 'error' => null];
        }
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => ltrim($to, '+'),
            'type'              => 'template',
            'template'          => [
                'name'       => $m['template'],
                'language'   => ['code' => $m['lang']],
                'components' => [['type' => 'body', 'parameters' => array_map(static fn ($p) => ['type' => 'text', 'text' => self::param((string) $p)], $m['params'])]],
            ],
        ];
        try {
            $res = service('curlrequest')->post('https://graph.facebook.com/' . rawurlencode($wa['api']) . '/' . rawurlencode($wa['phone_number_id']) . '/messages', [
                'headers'     => ['Authorization' => 'Bearer ' . $wa['token'], 'Content-Type' => 'application/json'],
                'body'        => json_encode($body),
                'timeout'     => 15,
                'http_errors' => false,
            ]);
            $code = $res->getStatusCode();
            $json = json_decode((string) $res->getBody(), true) ?: [];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'permanent' => false, 'error' => 'Could not reach WhatsApp: ' . mb_substr($e->getMessage(), 0, 150)];
        }
        if ($code >= 200 && $code < 300 && ! empty($json['messages'][0]['id'])) {
            return ['ok' => true, 'id' => (string) $json['messages'][0]['id'], 'permanent' => false, 'error' => null];
        }
        $err  = $json['error'] ?? [];
        $ecode = (int) ($err['code'] ?? 0);
        $text  = trim(($ecode ? $ecode . ': ' : '') . ($err['error_data']['details'] ?? $err['message'] ?? ('HTTP ' . $code)));
        $permanent = in_array($ecode, self::PERMANENT, true) || ($code >= 400 && $code < 500 && ! in_array($code, [408, 429], true) && ! in_array($ecode, [4, 80007, 130429, 131048, 131056, 133016], true));
        return ['ok' => false, 'id' => null, 'permanent' => $permanent, 'error' => mb_substr($text, 0, 250)];
    }

    /**
     * Send an e-mail through a sender from Senders::email(). $m: subject, text, reply_to (optional; else the sender's).
     */
    public static function email(array $sender, string $to, array $m): array
    {
        if (self::$fake) {
            return (self::$fake)('email', $to, $m + ['from' => $sender['from'], 'sender' => $sender['sender']]);
        }
        try {
            $email = service('email', null, false);
            $email->initialize($sender['host'] !== '' ? [
                'protocol' => 'smtp', 'SMTPHost' => $sender['host'], 'SMTPPort' => (int) $sender['port'],
                'SMTPUser' => $sender['user'], 'SMTPPass' => $sender['pass'], 'SMTPCrypto' => $sender['crypto'], 'mailType' => 'text', 'SMTPTimeout' => 15,
            ] : ['protocol' => 'mail', 'mailType' => 'text']);
            $email->clear();
            $email->setFrom($sender['from'], $sender['from_name']);
            $reply = (string) ($m['reply_to'] ?? '') !== '' ? (string) $m['reply_to'] : (string) $sender['reply_to'];
            if ($reply !== '') {
                $email->setReplyTo($reply);
            }
            $email->setTo($to);
            $email->setSubject($m['subject']);
            $email->setMessage($m['text']);
            if ($email->send(false)) {
                return ['ok' => true, 'id' => null, 'permanent' => false, 'error' => null];
            }
            $why = trim(html_entity_decode(strip_tags((string) $email->printDebugger(['headers'])), ENT_QUOTES));
            $why = trim(preg_split('/\s*(Date|From|Return-Path):/', $why)[0]);   // the reason, not the headers
            return ['ok' => false, 'id' => null, 'permanent' => false, 'error' => mb_substr($why !== '' ? $why : 'The mail server refused the message.', 0, 250)];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'permanent' => false, 'error' => mb_substr('Mail failed: ' . $e->getMessage(), 0, 250)];
        }
    }

    /** WhatsApp template parameters: no new lines or tabs, no more than four spaces in a row, at most 1,000 characters. */
    public static function param(string $p): string
    {
        $p = preg_replace('/[\r\n\t]+/', ' ', $p);
        $p = preg_replace('/ {4,}/', '   ', $p);
        return mb_substr(trim($p), 0, 1000) ?: '-';
    }
}
