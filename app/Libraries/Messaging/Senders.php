<?php

namespace App\Libraries\Messaging;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * Who sends an organisation's messages (core 1.4, E5): **the organisation itself.**
 *
 * - E-mail: the organisation's own mail server and address (Administration > Messages). Until it is set, its
 *   business e-mail waits in the outbox and its administrators get an alert. In the single-company edition the
 *   installation's mail settings count as the organisation's own, so nothing changes there.
 * - WhatsApp: the organisation's own WhatsApp Business number (phone number id and a permanent access token from
 *   Meta), or none. There is no platform number (decided 23 Nov).
 * - The installation's own mailer sends only the platform's mail and account mail (password reset), as agreed.
 *
 * Secrets (mail password, WhatsApp token and app secret) are kept encrypted with the installation key and never shown.
 */
final class Senders
{
    public const CRYPTO = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', '' => 'None (not recommended)'];

    public static function enc(string $plain): string
    {
        return $plain === '' ? '' : base64_encode(service('encrypter')->encrypt($plain));
    }

    public static function dec(?string $enc): string
    {
        if (! $enc) {
            return '';
        }
        try {
            return (string) service('encrypter')->decrypt(base64_decode($enc));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The current organisation's e-mail sender, or null while it has none:
     * ['sender' => own|installation, 'from', 'from_name', 'reply_to', 'host' ('' = the server's own mail), 'port', 'user', 'pass', 'crypto'].
     */
    public static function email(): ?array
    {
        $from = (string) Settings::tenant('mail_own_from');
        if ($from !== '') {
            return ['sender' => 'own', 'from' => $from, 'from_name' => (string) Settings::tenant('mail_own_name') ?: self::orgName(), 'reply_to' => (string) Settings::tenant('mail_own_reply'),
                'host' => (string) Settings::tenant('mail_own_host'), 'port' => (int) (Settings::tenant('mail_own_port') ?: 587), 'user' => (string) Settings::tenant('mail_own_user'),
                'pass' => self::dec(Settings::tenant('mail_own_pass_enc')), 'crypto' => (string) Settings::tenant('mail_own_crypto', 'tls')];
        }
        if (Tenancy::single() && (string) Settings::get('mail_from') !== '') {
            return ['sender' => 'installation', 'from' => (string) Settings::get('mail_from'), 'from_name' => self::orgName(), 'reply_to' => '',
                'host' => (string) Settings::get('smtp_host'), 'port' => (int) (Settings::get('smtp_port') ?: 587), 'user' => (string) Settings::get('smtp_user'),
                'pass' => (string) Settings::get('smtp_pass'), 'crypto' => (string) Settings::get('smtp_crypto', 'tls')];
        }
        return null;
    }

    /** The organisation's WhatsApp number, or null when it has none that can send. */
    public static function whatsapp(): ?array
    {
        if ((string) Settings::tenant('wa_mode', 'off') !== 'own') {
            return null;
        }
        $id        = (string) Settings::tenant('wa_phone_number_id');
        $tok       = self::dec(Settings::tenant('wa_token_enc'));
        $transport = (string) Settings::get('wa_transport', 'live');
        if ($id === '' || ($tok === '' && $transport === 'live')) {
            return null;
        }
        return ['sender' => 'own', 'phone_number_id' => $id, 'token' => $tok, 'api' => (string) Settings::get('wa_api_version', 'v21.0'),
            'display' => (string) Settings::tenant('wa_display'), 'template' => (string) Settings::tenant('wa_template_notice') ?: 'chatu_notice',
            'lang' => (string) Settings::tenant('wa_lang') ?: 'en', 'transport' => $transport];
    }

    public static function orgName(): string
    {
        return (string) (Settings::tenant('company_name') ?: (Tenancy::name() ?: 'Chatu Warehouse'));
    }

    /** What the Messages page shows about the two senders. */
    public static function summary(): array
    {
        $e = self::email();
        $w = self::whatsapp();
        return ['email' => $e, 'email_ready' => (bool) $e, 'whatsapp' => $w, 'whatsapp_ready' => (bool) $w, 'wa_mode' => (string) Settings::tenant('wa_mode', 'off'),
            'log_only' => ($w['transport'] ?? '') === 'log', 'single' => Tenancy::single()];
    }

    /** The organisation's own mail server. An empty address takes it off (business e-mail then waits). */
    public static function saveEmail(array $d): void
    {
        Access::require('admin.settings');
        $from = trim((string) ($d['mail_own_from'] ?? ''));
        if ($from !== '' && ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new BizException('The sender address does not look right.');
        }
        $reply = trim((string) ($d['mail_own_reply'] ?? ''));
        if ($reply !== '' && ! filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            throw new BizException('The reply-to address does not look right.');
        }
        $host = trim((string) ($d['mail_own_host'] ?? ''));
        if ($host !== '' && ! preg_match('/^[a-z0-9.-]{3,190}$/i', $host)) {
            throw new BizException('The mail server is a name such as mail.example.com.');
        }
        $port = trim((string) ($d['mail_own_port'] ?? ''));
        if ($port !== '' && (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new BizException('The port is a number such as 587 or 465.');
        }
        $crypto = (string) ($d['mail_own_crypto'] ?? 'tls');
        if (! array_key_exists($crypto, self::CRYPTO)) {
            throw new BizException('Choose how the connection is secured.');
        }
        if ($from !== '' && $host === '' && ! Tenancy::single()) {
            throw new BizException('Give your mail server: on the online service, e-mail goes out through your own server.');
        }
        self::put([
            'mail_own_from' => $from, 'mail_own_name' => mb_substr(trim((string) ($d['mail_own_name'] ?? '')), 0, 80), 'mail_own_reply' => $reply,
            'mail_own_host' => $host, 'mail_own_port' => $port, 'mail_own_user' => mb_substr(trim((string) ($d['mail_own_user'] ?? '')), 0, 190), 'mail_own_crypto' => $crypto,
        ], ['mail_own_pass_enc' => (string) ($d['mail_own_pass'] ?? '')], 'messaging.email');
    }

    /** The organisation's own WhatsApp number: mode own or off, phone number id, token and app secret (empty = keep). */
    public static function saveWhatsApp(array $d): void
    {
        Access::require('admin.settings');
        $mode = (string) ($d['wa_mode'] ?? 'off');
        if (! in_array($mode, ['own', 'off'], true)) {
            throw new BizException('Choose your own WhatsApp number, or none.');
        }
        $id = trim((string) ($d['wa_phone_number_id'] ?? ''));
        if ($id !== '' && ! ctype_digit($id)) {
            throw new BizException('The phone number id is the long number Meta shows for the phone number (digits only), not the phone number itself.');
        }
        if ($mode === 'own' && $id === '') {
            throw new BizException('Give the phone number id of your WhatsApp Business number.');
        }
        $tpl = trim((string) ($d['wa_template_notice'] ?? ''));
        if ($tpl !== '' && ! preg_match('/^[a-z0-9_]{1,80}$/', $tpl)) {
            throw new BizException('A WhatsApp template name has small letters, digits and _ only, as in WhatsApp Manager.');
        }
        $lang = trim((string) ($d['wa_lang'] ?? ''));
        if ($lang !== '' && ! preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang)) {
            throw new BizException('Give the template language as WhatsApp Manager shows it, for example en or en_GB.');
        }
        if ($mode === 'own' && $id !== '' && Db::unscoped(static fn () => Db::value("SELECT 1 FROM {tenant_settings} WHERE name = 'wa_phone_number_id' AND value = ? AND tenant_id <> ? LIMIT 1", [$id, Tenancy::require()]))) {
            throw new BizException('That WhatsApp number is already used by another organisation.');
        }
        self::put(['wa_mode' => $mode, 'wa_phone_number_id' => $id, 'wa_display' => mb_substr(trim((string) ($d['wa_display'] ?? '')), 0, 40), 'wa_template_notice' => $tpl, 'wa_lang' => $lang],
            ['wa_token_enc' => (string) ($d['wa_token'] ?? ''), 'wa_app_secret_enc' => (string) ($d['wa_app_secret'] ?? '')], 'messaging.whatsapp');
    }

    /** Write plain and secret settings, audit what changed (secrets as ***). Inside one transaction. */
    private static function put(array $plain, array $secrets, string $action): void
    {
        Db::atomic(static function () use ($plain, $secrets, $action) {
            $before = $after = [];
            foreach ($plain as $k => $v) {
                $old = (string) Settings::tenant($k);
                if ($old !== $v) {
                    Settings::setTenant($k, $v);
                    $before[$k] = $old;
                    $after[$k]  = $v;
                }
            }
            foreach ($secrets as $k => $v) {
                $v = trim($v);
                if ($v !== '') {
                    $old = (string) Settings::tenant($k);
                    Settings::setTenant($k, self::enc($v));
                    $before[$k] = $old === '' ? '' : '***';
                    $after[$k]  = '***';
                }
            }
            if ($after) {
                Audit::log($action, 'tenant', Tenancy::require(), $before, $after);
            }
        });
    }

    /** The app secret that signs delivery reports for a phone number id (only an organisation's own number). */
    public static function appSecretFor(?string $phoneNumberId): string
    {
        if (! $phoneNumberId) {
            return '';
        }
        $own = Db::unscoped(static fn () => Db::value(
            "SELECT s2.value FROM {tenant_settings} s1 JOIN {tenant_settings} s2 ON s2.tenant_id = s1.tenant_id AND s2.name = 'wa_app_secret_enc'
              WHERE s1.name = 'wa_phone_number_id' AND s1.value = ? LIMIT 1", [$phoneNumberId]));
        return $own ? self::dec((string) $own) : '';
    }
}
