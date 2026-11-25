<?php

namespace App\Libraries\Platform;

use App\Libraries\Core\Audit;
use App\Libraries\Core\Auth;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use Throwable;

/**
 * core 1.5b (ChatuERP): online payments through Lenco and DPO. One library, two uses:
 *
 *   (a) platform billing: an organisation pays Chatu's invoice; Chatu's merchant keys, in the platform settings.
 *   (b) an organisation's own sales (POS) and ticket sales (VisitorPass events): the organisation's own merchant
 *       keys, in its settings, so the money goes straight to the organisation.
 *
 * Every attempt is a row in gateway_payments with our unique reference. A payment settles exactly once, whichever
 * arrives first: the customer coming back (DPO), the gateway's webhook (Lenco, signature checked), or the check
 * that asks the gateway (Check now, and every few minutes from the scheduled tick for anything still pending).
 *
 *   Lenco  mobile money collection: POST {base}/collections/mobile-money; the customer approves on the phone;
 *          GET {base}/collections/status/{reference}; webhook X-Lenco-Signature = HMAC-SHA512(body, SHA256(token)).
 *   DPO    hosted payment page (card and mobile money): createToken, the customer pays on DPO's page and comes back
 *          to our return address; verifyToken says whether it was paid (000).
 *
 * Secrets are kept encrypted with the installation key and never shown again.
 */
final class Pay
{
    public const GATEWAYS = ['lenco' => 'Mobile money (Lenco)', 'dpo' => 'Card or mobile money (DPO)'];
    public const OPERATORS = ['airtel' => 'Airtel Money', 'mtn' => 'MTN MoMo', 'zamtel' => 'Zamtel Kwacha'];

    /** Settings names, the same for the platform (installation) and for an organisation (tenant settings). */
    public const KEYS = [
        'pay_lenco_token_enc'  => 'Lenco API token',
        'pay_lenco_base'       => 'Lenco API address',
        'pay_dpo_token_enc'    => 'DPO company token',
        'pay_dpo_service'      => 'DPO service type',
        'pay_dpo_base'         => 'DPO API address',
        'pay_dpo_page'         => 'DPO payment page',
    ];
    public const DEFAULTS = [
        'pay_lenco_base' => 'https://api.lenco.co/access/v2',
        'pay_dpo_base'   => 'https://secure.3gdirectpay.com/API/v6/',
        'pay_dpo_page'   => 'https://secure.3gdirectpay.com/payv2.php?ID=',
    ];

    /** Handlers for purposes other than platform invoices: purpose => callable(array $payment): void. */
    public static array $handlers = [];

    /** Where the customer lands after paying on DPO's page, per purpose: a path, or callable(array $payment): string. */
    public static array $returns = [];

    /** HTTP for tests: callable(string $method, string $url, array $headers, ?string $body): array [int status, string body]. */
    public static $http = null;

    // ---- keys -------------------------------------------------------------------------------------------------

    /** The keys in force: the platform's ($tenantId null) or an organisation's own. */
    public static function config(?int $tenantId): array
    {
        $out = [];
        foreach (self::KEYS as $k => $_) {
            $v = $tenantId === null ? Settings::get($k)
                : Db::value('SELECT value FROM {tenant_settings} WHERE tenant_id = ? AND name = ?', [$tenantId, $k]);
            $v = (string) $v;
            if (str_ends_with($k, '_enc')) {
                $v = self::dec($v);
            }
            $out[$k] = $v !== '' ? $v : (self::DEFAULTS[$k] ?? '');
        }
        return $out;
    }

    /** Gateways that have keys, for the platform (null) or an organisation. */
    public static function available(?int $tenantId): array
    {
        $c = self::config($tenantId);
        return array_filter(['lenco' => $c['pay_lenco_token_enc'] !== '', 'dpo' => $c['pay_dpo_token_enc'] !== '' && $c['pay_dpo_service'] !== '']);
    }

    /** Save keys (platform: $tenantId null). Empty secrets keep the current ones. Returns [before, after] for the audit log. */
    public static function saveKeys(?int $tenantId, array $in): array
    {
        $before = $after = [];
        foreach (self::KEYS as $k => $label) {
            if (! array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            $secret = str_ends_with($k, '_enc');
            if ($secret && $v === '') {
                continue;
            }
            if (! $secret && $v !== '' && in_array($k, ['pay_lenco_base', 'pay_dpo_base', 'pay_dpo_page'], true) && ! preg_match('#^https://\S+$#', $v)) {
                throw new BizException($label . ': an https:// address.');
            }
            $store = $secret ? ($v === '-' ? '' : self::enc($v)) : $v;
            $old = $tenantId === null ? (string) Settings::get($k) : (string) Db::value('SELECT value FROM {tenant_settings} WHERE tenant_id = ? AND name = ?', [$tenantId, $k]);
            if ($secret ? ($v === '-' ? $old !== '' : true) : $old !== $store) {
                $before[$k] = $secret ? ($old !== '' ? '(set)' : '') : $old;
                $after[$k]  = $secret ? ($store !== '' ? '(set)' : '') : $store;
                if ($tenantId === null) {
                    Settings::set($k, $store);
                } else {
                    Db::exec('INSERT INTO {tenant_settings} (tenant_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$tenantId, $k, $store]);
                }
            }
        }
        return [$before, $after];
    }

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
        } catch (Throwable) {
            return '';
        }
    }

    // ---- starting a payment -----------------------------------------------------------------------------------

    /**
     * Start paying. $own: the organisation's own keys (its sales); otherwise the platform's (Chatu's invoices).
     * $opt: phone and operator (Lenco), return_url (DPO), description.
     * Returns the payment row; for DPO its pay_url is where to send the customer.
     */
    public static function start(string $purpose, int $objectId, int $tenantId, float $amount, string $gateway, bool $own, array $opt = []): array
    {
        if (! isset(self::GATEWAYS[$gateway])) {
            throw new BizException('Choose how to pay.');
        }
        if ($amount <= 0) {
            throw new BizException('Nothing to pay.');
        }
        $keyOwner = $own ? $tenantId : null;
        if (! isset(self::available($keyOwner)[$gateway])) {
            throw new BizException(self::GATEWAYS[$gateway] . ' is not set up' . ($own ? ' for your organisation (Settings, Online payments).' : ' yet. Pay by bank or mobile money and send the reference to Chatu Innovations.'));
        }
        $phone = $operator = null;
        if ($gateway === 'lenco') {
            $phone    = preg_replace('/\D+/', '', (string) ($opt['phone'] ?? ''));
            $operator = (string) ($opt['operator'] ?? '');
            if (str_starts_with($phone, '260')) {
                $phone = '0' . substr($phone, 3);
            }
            if (! preg_match('/^0\d{9}$/', $phone)) {
                throw new BizException('Give the mobile money number, for example 0971234567.');
            }
            if (! isset(self::OPERATORS[$operator])) {
                throw new BizException('Choose the network: Airtel, MTN or Zamtel.');
            }
        }
        $ref = strtoupper($purpose === 'platform_invoice' ? 'CPI' : substr($purpose, 0, 3)) . '-' . $objectId . '-' . strtoupper(bin2hex(random_bytes(5)));
        $id  = Db::insert('gateway_payments', ['tenant_id' => $tenantId, 'purpose' => $purpose, 'object_id' => $objectId, 'gateway' => $gateway, 'reference' => $ref,
            'amount' => round($amount, 2), 'currency' => Billing::currency(), 'phone' => $phone, 'operator' => $operator, 'status' => 'pending',
            'created_by' => Auth::id(), 'created_at' => Db::now(), 'detail' => $own ? 'own' : 'platform']);
        $cfg = self::config($keyOwner);
        try {
            if ($gateway === 'lenco') {
                [$code, $body] = self::http('POST', rtrim($cfg['pay_lenco_base'], '/') . '/collections/mobile-money', self::lencoHeaders($cfg),
                    json_encode(['amount' => round($amount, 2), 'reference' => $ref, 'phone' => $phone, 'operator' => $operator, 'country' => 'zm', 'bearer' => 'merchant']));
                $j = json_decode($body, true);
                if ($code >= 300 || empty($j['status']) || ! is_array($j['data'] ?? null)) {
                    throw new BizException('Lenco did not accept the request' . (isset($j['message']) ? ': ' . $j['message'] : ' (' . $code . ')') . '.');
                }
                Db::exec('UPDATE {gateway_payments} SET gateway_ref = ?, updated_at = ? WHERE id = ?', [(string) ($j['data']['lencoReference'] ?? $j['data']['id'] ?? '') ?: null, Db::now(), $id]);
                $st = (string) ($j['data']['status'] ?? 'pending');
                if (in_array($st, ['successful', 'failed'], true)) {
                    self::settle($id, $st === 'successful' ? 'paid' : 'failed', (string) ($j['data']['reasonForFailure'] ?? ''));
                }
            } else {
                $xml = '<?xml version="1.0" encoding="utf-8"?><API3G><CompanyToken>' . self::x($cfg['pay_dpo_token_enc']) . '</CompanyToken><Request>createToken</Request>'
                    . '<Transaction><PaymentAmount>' . number_format($amount, 2, '.', '') . '</PaymentAmount><PaymentCurrency>' . self::x(Billing::currency()) . '</PaymentCurrency>'
                    . '<CompanyRef>' . self::x($ref) . '</CompanyRef><RedirectURL>' . self::x((string) ($opt['return_url'] ?? '')) . '</RedirectURL>'
                    . '<BackURL>' . self::x((string) ($opt['back_url'] ?? $opt['return_url'] ?? '')) . '</BackURL><CompanyRefUnique>1</CompanyRefUnique><PTL>60</PTL></Transaction>'
                    . '<Services><Service><ServiceType>' . self::x($cfg['pay_dpo_service']) . '</ServiceType><ServiceDescription>' . self::x(mb_substr((string) ($opt['description'] ?? $ref), 0, 100)) . '</ServiceDescription>'
                    . '<ServiceDate>' . date('Y/m/d H:i') . '</ServiceDate></Service></Services></API3G>';
                [$code, $body] = self::http('POST', $cfg['pay_dpo_base'], ['Content-Type: application/xml'], $xml);
                $r = self::xml($body);
                if ($code >= 300 || ($r['Result'] ?? '') !== '000' || empty($r['TransToken'])) {
                    throw new BizException('DPO did not accept the request' . (isset($r['ResultExplanation']) ? ': ' . $r['ResultExplanation'] : ' (' . $code . ')') . '.');
                }
                Db::exec('UPDATE {gateway_payments} SET gateway_ref = ?, pay_url = ?, updated_at = ? WHERE id = ?', [$r['TransToken'], $cfg['pay_dpo_page'] . rawurlencode($r['TransToken']), Db::now(), $id]);
            }
        } catch (BizException $e) {
            Db::exec("UPDATE {gateway_payments} SET status = 'failed', detail = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [mb_substr($e->getMessage(), 0, 255), Db::now(), $id]);
            throw $e;
        } catch (Throwable $e) {
            Db::exec("UPDATE {gateway_payments} SET status = 'failed', detail = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [mb_substr('Could not reach the gateway: ' . $e->getMessage(), 0, 255), Db::now(), $id]);
            throw new BizException('Could not reach ' . self::GATEWAYS[$gateway] . '. Try again in a minute.');
        }
        Tenancy::run($own ? $tenantId : null, static fn () => Audit::log('pay.start', 'gateway_payment', $id, null, ['ref' => $ref, 'gateway' => $gateway, 'amount' => round($amount, 2), 'purpose' => $purpose, 'object' => $objectId]));
        return self::get($id);
    }

    public static function get(int $id): array
    {
        $p = Db::row('SELECT * FROM {gateway_payments} WHERE id = ?', [$id]);
        if (! $p) {
            throw new BizException('Payment not found.');
        }
        return $p;
    }

    /** Our reference as a till's mobile money transaction ID (letters and digits only). */
    public static function tenderRef(array $p): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $p['reference']));
    }

    public static function byReference(string $ref): ?array
    {
        return Db::row('SELECT * FROM {gateway_payments} WHERE reference = ?', [$ref]);
    }

    /**
     * A waiting card payment by our reference, for the step page that passes the customer on to DPO. Null when the
     * reference is not ours, is not a card payment, is no longer waiting, or has no page to go to.
     */
    public static function cardPage(string $ref): ?array
    {
        $p = preg_match('/^[A-Za-z0-9._-]{6,60}$/', $ref) ? self::byReference($ref) : null;
        $ok = $p && $p['gateway'] === 'dpo' && $p['status'] === 'pending' && preg_match('~^https?://~i', (string) ($p['pay_url'] ?? ''));

        return $ok ? $p : null;
    }

    // ---- finding out whether it was paid ----------------------------------------------------------------------

    /**
     * A Lenco webhook. $keyOwner is the organisation whose own account sent it, or null for the platform's account
     * (from the address it was sent to). Returns what happened, for the log; refuses a wrong signature.
     */
    public static function lencoWebhook(string $raw, string $signature, ?int $keyOwner): string
    {
        $token = self::config($keyOwner)['pay_lenco_token_enc'];
        if ($token === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $raw, hash('sha256', $token)), strtolower(trim($signature)))) {
            Tenancy::run(null, static fn () => Audit::log('pay.webhook', 'gateway_payment', null, null, ['gateway' => 'lenco'], 'denied', 'Signature did not match'));
            throw new BizException('Signature did not match.');
        }
        $j     = json_decode($raw, true) ?: [];
        $event = (string) ($j['event'] ?? '');
        $ref   = (string) ($j['data']['reference'] ?? '');
        if (! str_starts_with($event, 'collection.') || $ref === '') {
            return 'ignored ' . $event;
        }
        $p = self::byReference($ref);
        if (! $p || $p['gateway'] !== 'lenco' || ($keyOwner !== null && (int) $p['tenant_id'] !== $keyOwner) || ($keyOwner === null && $p['detail'] === 'own')) {
            return 'unknown reference';
        }
        $status = match ($event) {
            'collection.successful', 'collection.settled' => 'paid',
            'collection.failed' => 'failed',
            default => null,
        };
        if ($status === null) {
            return 'ignored ' . $event;
        }
        if ($status === 'paid' && abs((float) ($j['data']['amount'] ?? $p['amount']) - (float) $p['amount']) > 0.005) {
            Db::exec('UPDATE {gateway_payments} SET detail = ?, updated_at = ? WHERE id = ?', ['Amount paid differs: ' . ($j['data']['amount'] ?? '?'), Db::now(), $p['id']]);
            return 'amount differs';
        }
        return self::settle((int) $p['id'], $status, (string) ($j['data']['reasonForFailure'] ?? ''), (string) ($j['data']['lencoReference'] ?? '')) ? 'settled ' . $status : 'already settled';
    }

    /** Ask the gateway now (Check now, the customer coming back, the scheduled check). Returns the row afterwards. */
    public static function check(int $id): array
    {
        $p = self::get($id);
        if ($p['status'] !== 'pending') {
            return $p;
        }
        $cfg = self::config($p['detail'] === 'own' ? (int) $p['tenant_id'] : null);
        try {
            if ($p['gateway'] === 'lenco') {
                [$code, $body] = self::http('GET', rtrim($cfg['pay_lenco_base'], '/') . '/collections/status/' . rawurlencode($p['reference']), self::lencoHeaders($cfg), null);
                $j  = json_decode($body, true);
                $st = (string) ($j['data']['status'] ?? '');
                if ($code < 300 && in_array($st, ['successful', 'failed'], true)) {
                    self::settle($id, $st === 'successful' ? 'paid' : 'failed', (string) ($j['data']['reasonForFailure'] ?? ''), (string) ($j['data']['lencoReference'] ?? ''));
                }
            } elseif ($p['gateway_ref']) {
                $xml = '<?xml version="1.0" encoding="utf-8"?><API3G><CompanyToken>' . self::x($cfg['pay_dpo_token_enc']) . '</CompanyToken><Request>verifyToken</Request><TransactionToken>' . self::x($p['gateway_ref']) . '</TransactionToken></API3G>';
                [$code, $body] = self::http('POST', $cfg['pay_dpo_base'], ['Content-Type: application/xml'], $xml);
                $r = self::xml($body);
                $res = (string) ($r['Result'] ?? '');
                if ($code < 300 && $res === '000') {
                    if (isset($r['TransactionAmount']) && abs((float) $r['TransactionAmount'] - (float) $p['amount']) > 0.005) {
                        Db::exec('UPDATE {gateway_payments} SET detail = ?, updated_at = ? WHERE id = ?', ['Amount paid differs: ' . $r['TransactionAmount'], Db::now(), $id]);
                    } else {
                        self::settle($id, 'paid', '', (string) ($r['TransactionApproval'] ?? ''));
                    }
                } elseif (in_array($res, ['901', '904'], true)) {
                    self::settle($id, $res === '904' ? 'cancelled' : 'failed', (string) ($r['ResultExplanation'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'Payment check failed for ' . $p['reference'] . ': ' . $e->getMessage());
        }
        return self::get($id);
    }

    /** Pending payments older than a few minutes are checked (scheduled tick); old ones give up. Returns counts. */
    public static function reconcile(int $limit = 20): array
    {
        $out = ['checked' => 0, 'settled' => 0, 'expired' => 0];
        $rows = Db::rows("SELECT id, created_at FROM {gateway_payments} WHERE status = 'pending' AND created_at < ? ORDER BY id LIMIT " . max(1, $limit), [gmdate('Y-m-d H:i:s', time() - 120)]);
        foreach ($rows as $r) {
            $out['checked']++;
            $after = self::check((int) $r['id']);
            if ($after['status'] !== 'pending') {
                $out['settled']++;
            } elseif ($r['created_at'] < gmdate('Y-m-d H:i:s', time() - 2 * 86400)) {
                self::settle((int) $r['id'], 'cancelled', 'No answer from the gateway in two days');
                $out['expired']++;
            }
        }
        return $out;
    }

    /**
     * Settle once. Only a pending payment changes; the second callback, webhook or check finds it settled and does
     * nothing. A paid platform invoice payment marks the invoice paid (Billing::pay). Returns whether it changed now.
     */
    public static function settle(int $id, string $status, string $detail = '', string $gatewayRef = ''): bool
    {
        return Db::atomic(static function () use ($id, $status, $detail, $gatewayRef) {
            $p = Db::row('SELECT * FROM {gateway_payments} WHERE id = ? FOR UPDATE', [$id]);
            if (! $p || $p['status'] !== 'pending') {
                return false;
            }
            Db::exec('UPDATE {gateway_payments} SET status = ?, settled_at = ?, updated_at = ?, gateway_ref = COALESCE(gateway_ref, ?), detail = ? WHERE id = ?',
                [$status, Db::now(), Db::now(), $gatewayRef !== '' ? $gatewayRef : null, mb_substr($p['detail'] . ($detail !== '' ? ': ' . $detail : ''), 0, 255), $id]);
            $p['status'] = $status;
            if ($status === 'paid') {
                if ($p['purpose'] === 'platform_invoice') {
                    $inv = Db::row('SELECT status FROM {platform_invoices} WHERE id = ?', [$p['object_id']]);
                    if ($inv && $inv['status'] === 'issued') {
                        Billing::pay((int) $p['object_id'], strtoupper($p['gateway']) . ' ' . $p['reference'], $p['gateway'], true);
                    } else {
                        // paid twice, or after the invoice was cancelled: the platform owner refunds or credits it
                        Db::exec('UPDATE {gateway_payments} SET detail = ? WHERE id = ?', ['Paid, but the invoice was already ' . ($inv['status'] ?? 'gone') . ': refund or credit it', $id]);
                        foreach (Billing::platformOwners() as $uid) {
                            Tenancy::run(null, static fn () => \App\Libraries\Workflow\Notify::user($uid, 'billing', 'Online payment ' . $p['reference'] . ' needs a refund or credit', 'The invoice was already ' . ($inv['status'] ?? 'gone') . '.', '/platform/payments'));
                        }
                    }
                } elseif ($h = self::handler($p['purpose'])) {
                    $h($p);
                }
            }
            Tenancy::run($p['detail'] === 'own' ? (int) $p['tenant_id'] : null, static fn () => Audit::log('pay.settle', 'gateway_payment', $id, ['status' => 'pending'], ['status' => $status, 'ref' => $p['reference']], 'success', $detail !== '' ? $detail : null));
            return true;
        });
    }

    // ---- plumbing ---------------------------------------------------------------------------------------------

    /** Who is told when a payment of this purpose is paid: set at run time, or by the product (Product::payHandlers). */
    public static function handler(string $purpose): ?callable
    {
        if (isset(self::$handlers[$purpose])) {
            return self::$handlers[$purpose];
        }
        $h = method_exists(Product::class, 'payHandlers') ? (Product::payHandlers()[$purpose] ?? null) : null;
        return is_callable($h) ? $h : null;
    }

    /** Where the payer lands after paying on the gateway's page, for this payment. */
    public static function returnPath(array $p): string
    {
        $to = self::$returns[$p['purpose']] ?? (method_exists(Product::class, 'payReturns') ? (Product::payReturns()[$p['purpose']] ?? null) : null);
        if ($p['purpose'] === 'platform_invoice') {
            return 'billing/invoices/' . $p['object_id'];
        }
        return is_callable($to) ? (string) $to($p) : (string) ($to ?? '/');
    }

    private static function lencoHeaders(array $cfg): array
    {
        return ['Authorization: Bearer ' . $cfg['pay_lenco_token_enc'], 'Content-Type: application/json', 'Accept: application/json'];
    }

    private static function http(string $method, string $url, array $headers, ?string $body): array
    {
        if (self::$http !== null) {
            return (self::$http)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new \RuntimeException($err ?: 'no answer');
        }
        return [$code, (string) $out];
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** The top-level elements of a DPO answer as name => text. */
    private static function xml(string $body): array
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        $out = [];
        if ($x !== false) {
            foreach ($x->children() as $k => $v) {
                $out[(string) $k] = trim((string) $v);
            }
        }
        return $out;
    }
}
