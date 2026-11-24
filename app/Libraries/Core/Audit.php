<?php

namespace App\Libraries\Core;

use Throwable;

/**
 * Append-only, hash-chained audit log (OWASP: when, where, who, what, outcome).
 * Each tenant has its own chain, and the platform has one more (tenant_key 0). The chain head lives in
 * audit_heads and is locked for every write, so concurrent writers cannot fork a chain, and one busy
 * organisation never waits on another's lock.
 */
final class Audit
{
    public const APP_VERSION = '0.3.0';

    public static function log(
        string $action,
        ?string $objectType = null,
        $objectId = null,
        ?array $before = null,
        ?array $after = null,
        string $outcome = 'success',
        ?string $detail = null,
        ?int $requestId = null,
        ?string $effectiveDate = null,
        ?int $onBehalfOf = null
    ): void {
        $write = static function () use ($action, $objectType, $objectId, $before, $after, $outcome, $detail, $requestId, $effectiveDate, $onBehalfOf) {
            $user = Auth::user();
            $ctx  = self::context();
            $tid  = Tenancy::id();
            $key  = $tid ?? 0;
            Db::exec('INSERT IGNORE INTO {audit_heads} (tenant_key, head) VALUES (?, ?)', [$key, str_repeat('0', 64)]);
            $prev = (string) Db::value('SELECT head FROM {audit_heads} WHERE tenant_key = ? FOR UPDATE', [$key]);
            $mt  = microtime(true);
            $at  = gmdate('Y-m-d H:i:s', (int) $mt) . '.' . str_pad((string) ((int) ($mt * 1000) % 1000), 3, '0', STR_PAD_LEFT);
            $row = [
                'tenant_id'      => $tid,
                'at'             => $at,
                'effective_date' => $effectiveDate,
                'user_id'        => $user['id'] ?? null,
                'username'       => $user['username'] ?? ($ctx['actor'] ?? null),
                'on_behalf_of'   => $onBehalfOf,
                'ip'             => $ctx['ip'],
                'user_agent'     => $ctx['ua'],
                'app_version'    => self::APP_VERSION,
                'device_id'      => $ctx['device'],
                'action'         => $action,
                'object_type'    => $objectType,
                'object_id'      => $objectId === null ? null : (string) $objectId,
                'request_id'     => $requestId,
                'before_json'    => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                'after_json'     => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                'outcome'        => $outcome,
                'detail'         => $detail === null ? null : mb_substr($detail, 0, 500),
                'prev_hash'      => $prev,
            ];
            $row['hash'] = self::hashOf($row);
            Db::insert('audit_events', $row);
            Db::exec('UPDATE {audit_heads} SET head = ? WHERE tenant_key = ?', [$row['hash'], $key]);
        };
        Db::atomic($write);
    }

    /** Log a refusal. Called after any rollback so the entry survives. */
    public static function denied(string $action, string $detail, ?string $objectType = null, $objectId = null): void
    {
        try {
            self::log($action, $objectType, $objectId, null, null, 'denied', $detail);
        } catch (Throwable $e) {
            log_message('error', 'Audit denied write failed: ' . $e->getMessage());
        }
    }

    public static function hashOf(array $row): string
    {
        $fields = ['tenant_id', 'at', 'effective_date', 'user_id', 'username', 'on_behalf_of', 'ip', 'user_agent', 'app_version', 'device_id',
            'action', 'object_type', 'object_id', 'request_id', 'before_json', 'after_json', 'outcome', 'detail', 'prev_hash'];
        $parts = [];
        foreach ($fields as $f) {
            $v       = $row[$f] ?? null;
            $parts[] = $v === null ? "\0" : (string) $v;
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Walk one chain: a tenant's (its id) or the platform's (null). Returns null when intact,
     * otherwise the id of the first bad event.
     */
    public static function verify(?int $tenantId): ?int
    {
        return Db::unscoped(static function () use ($tenantId) {
            $prev   = str_repeat('0', 64);
            $lastId = 0;
            $cond   = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ' . (int) $tenantId;
            while (true) {
                $batch = Db::rows("SELECT * FROM {audit_events} WHERE $cond AND id > ? ORDER BY id LIMIT 1000", [$lastId]);
                if (! $batch) {
                    break;
                }
                foreach ($batch as $r) {
                    if (strlen($r['at']) === 19) {
                        $r['at'] .= '.000';
                    }
                    if ($r['prev_hash'] !== $prev || self::hashOf($r) !== $r['hash']) {
                        return (int) $r['id'];
                    }
                    $prev   = $r['hash'];
                    $lastId = (int) $r['id'];
                }
            }
            $head = Db::value('SELECT head FROM {audit_heads} WHERE tenant_key = ?', [$tenantId ?? 0]);
            if ($head !== null && $head !== $prev) {
                return $lastId ?: -1; // events were removed from the end of the chain
            }
            return null;
        });
    }

    /** Every chain: tenant key (0 = platform) => first bad event id, only for broken chains. */
    public static function verifyAll(): array
    {
        $bad  = [];
        $keys = Db::unscoped(static fn () => array_map('intval', array_column(Db::rows('SELECT tenant_key FROM {audit_heads} ORDER BY tenant_key'), 'tenant_key')));
        foreach ($keys as $k) {
            $b = self::verify($k === 0 ? null : $k);
            if ($b !== null) {
                $bad[$k] = $b;
            }
        }
        return $bad;
    }

    private static function context(): array
    {
        $ip = $ua = $device = null;
        if (! is_cli()) {
            $req    = service('request');
            $ip     = $req->getIPAddress();
            $ua     = mb_substr((string) $req->getUserAgent(), 0, 255);
            $device = $req->getHeaderLine('X-Device-Id') ?: ($_COOKIE['device_id'] ?? null);
            if ($device !== null) {
                $device = preg_replace('/[^A-Za-z0-9-]/', '', $device) ?: null;
                $device = $device ? substr($device, 0, 64) : null;
            }
        }
        return ['ip' => $ip ?? 'cli', 'ua' => $ua ?? 'cli', 'device' => $device, 'actor' => is_cli() ? 'system' : null];
    }
}
