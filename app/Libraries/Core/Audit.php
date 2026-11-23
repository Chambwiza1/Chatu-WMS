<?php

namespace App\Libraries\Core;

use Throwable;

/**
 * Append-only, hash-chained audit log (OWASP: when, where, who, what, outcome).
 * The chain head lives in settings.audit_head and is locked for every write,
 * so concurrent writers cannot fork the chain.
 */
final class Audit
{
    public const APP_VERSION = '0.2.1';

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
            $head = Db::row("SELECT value FROM {settings} WHERE name = 'audit_head' FOR UPDATE");
            if ($head === null) {
                Db::exec("INSERT INTO {settings} (name, value) VALUES ('audit_head', ?)", [str_repeat('0', 64)]);
                $prev = str_repeat('0', 64);
            } else {
                $prev = (string) $head['value'];
            }
            $mt  = microtime(true);
            $at  = gmdate('Y-m-d H:i:s', (int) $mt) . '.' . str_pad((string) ((int) ($mt * 1000) % 1000), 3, '0', STR_PAD_LEFT);
            $row = [
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
            Db::exec("UPDATE {settings} SET value = ? WHERE name = 'audit_head'", [$row['hash']]);
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
        $fields = ['at', 'effective_date', 'user_id', 'username', 'on_behalf_of', 'ip', 'user_agent', 'app_version', 'device_id',
            'action', 'object_type', 'object_id', 'request_id', 'before_json', 'after_json', 'outcome', 'detail', 'prev_hash'];
        $parts = [];
        foreach ($fields as $f) {
            $v       = $row[$f] ?? null;
            $parts[] = $v === null ? "\0" : (string) $v;
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Walk the chain. Returns null when intact, otherwise the id of the first bad event.
     */
    public static function verify(): ?int
    {
        $prev   = str_repeat('0', 64);
        $lastId = 0;
        while (true) {
            $batch = Db::rows('SELECT * FROM {audit_events} WHERE id > ? ORDER BY id LIMIT 1000', [$lastId]);
            if (! $batch) {
                break;
            }
            foreach ($batch as $r) {
                $at = $r['at'];
                if (strlen($at) === 19) {
                    $at .= '.000';
                }
                $r['at'] = $at;
                if ($r['prev_hash'] !== $prev || self::hashOf($r) !== $r['hash']) {
                    return (int) $r['id'];
                }
                $prev   = $r['hash'];
                $lastId = (int) $r['id'];
            }
        }
        $head = Db::value("SELECT value FROM {settings} WHERE name = 'audit_head'");
        if ($lastId > 0 && $head !== $prev) {
            return $lastId;
        }
        return null;
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
