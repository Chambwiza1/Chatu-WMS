<?php

namespace App\Libraries\Core;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Database helpers: one connection, nested-safe transactions, one-time operation tokens,
 * table prefixing for raw SQL. Every business write runs inside Db::atomic().
 *
 * core 1.1 (as ChatuPOS): the tenant guard. A query naming an organisation's table must name tenant_id, and {tid} in
 * SQL stands for the current organisation's id (an integer; none chosen = refused). Inserts into an organisation's
 * table take the current organisation unless they name one.
 */
final class Db
{
    private static ?BaseConnection $conn = null;
    private static int $depth = 0;

    public static function conn(): BaseConnection
    {
        if (self::$conn === null) {
            self::$conn = Database::connect();
        }
        return self::$conn;
    }

    /** Reset the cached connection (used by tests and the installer). */
    public static function reset(?BaseConnection $c = null): void
    {
        self::$conn  = $c;
        self::$depth = 0;
    }

    /** Prefixed table name for raw SQL. */
    public static function t(string $table): string
    {
        return self::conn()->prefixTable($table);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** Raw query returning all rows. Use {table} placeholders for prefixed table names. */
    /**
     * Run a query and throw DbError on failure. CodeIgniter returns false (without throwing)
     * for a failed query inside a transaction, so every query goes through here.
     */
    public static function q(string $sql, array $binds = [])
    {
        $db = self::conn();
        self::guard($sql);
        try {
            $res = $db->query(self::expand($sql), $binds);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            throw new DbError($e->getMessage(), (int) $e->getCode(), $e);
        } catch (\mysqli_sql_exception $e) {
            throw new DbError($e->getMessage(), (int) $e->getCode(), $e);
        }
        if ($res === false) {
            $err = $db->error();
            throw new DbError('Database error ' . ($err['code'] ?? '') . ': ' . ($err['message'] ?? 'unknown'), (int) ($err['code'] ?? 0));
        }
        return $res;
    }

    public static function rows(string $sql, array $binds = []): array
    {
        return self::q($sql, $binds)->getResultArray();
    }

    public static function row(string $sql, array $binds = []): ?array
    {
        $r = self::q($sql, $binds)->getRowArray();
        return $r ?: null;
    }

    public static function value(string $sql, array $binds = [])
    {
        $r = self::q($sql, $binds)->getRowArray();
        return $r ? reset($r) : null;
    }

    public static function exec(string $sql, array $binds = []): int
    {
        self::q($sql, $binds);
        return self::conn()->affectedRows();
    }

    public static function insert(string $table, array $data): int
    {
        $db   = self::conn();
        if (in_array($table, Tenancy::TABLES, true) && ! array_key_exists('tenant_id', $data)) {
            if (self::$unscoped > 0) {
                throw new DbError('Tenant guard: insert into ' . $table . ' without tenant_id.');
            }
            $data = ['tenant_id' => Tenancy::require()] + $data;
        }
        $cols = implode(', ', array_map(static fn ($c) => $db->escapeIdentifiers($c), array_keys($data)));
        $ph   = implode(', ', array_fill(0, count($data), '?'));
        self::q('INSERT INTO ' . $db->escapeIdentifiers(self::t($table)) . " ($cols) VALUES ($ph)", array_values($data));
        return (int) $db->insertID();
    }

    /** Replace {name} with the prefixed table name and {tid} with the current organisation's id. */
    public static function expand(string $sql): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static fn ($m) => $m[1] === 'tid' ? (string) Tenancy::require() : self::t($m[1]), $sql);
    }

    /** The current organisation's id for binds (refused when none is chosen). */
    public static function tid(): int
    {
        return Tenancy::require();
    }

    /**
     * Tenant guard. A query that names an organisation's table ({items}, {stock}, ...) must also name tenant_id, so a
     * lookup by id alone can never reach another organisation's row. It runs on every query, in production too:
     * failing closed is better than showing one organisation's data to another. Platform code that really works
     * across organisations (the console, sign-in, the integrity check) wraps itself in unscoped().
     */
    private static function guard(string $sql): void
    {
        if (self::$unscoped > 0 || ! preg_match_all('/\{([a-z_]+)\}/', $sql, $m)) {
            return;
        }
        $hit = array_intersect($m[1], Tenancy::TABLES);
        if ($hit && stripos($sql, 'tenant_id') === false) {
            throw new DbError('Tenant guard: query on ' . implode(', ', array_unique($hit)) . ' without tenant_id.');
        }
    }

    private static int $unscoped = 0;

    /** Run $fn with the tenant guard off. Only for platform code that is meant to see every organisation. */
    public static function unscoped(callable $fn)
    {
        self::$unscoped++;
        try {
            return $fn();
        } finally {
            self::$unscoped--;
        }
    }

    /**
     * Run $fn in one transaction. Nested calls join the outer transaction.
     * Any exception rolls back everything and is rethrown.
     */
    public static function atomic(callable $fn)
    {
        $db = self::conn();
        if (self::$depth > 0) {
            self::$depth++;
            try {
                return $fn();
            } finally {
                self::$depth--;
            }
        }
        for ($attempt = 1; ; $attempt++) {
            $db->transBegin();
            self::$depth = 1;
            try {
                $result = $fn();
                if (! $db->transCommit()) {
                    throw new DbError('Could not commit the transaction.');
                }
                self::$depth = 0;
                return $result;
            } catch (Throwable $e) {
                $db->transRollback();
                self::$depth = 0;
                // deadlock (1213) or lock wait timeout (1205): retry the whole unit of work
                if ($e instanceof DbError && in_array($e->getCode(), [1213, 1205], true) && $attempt < 3) {
                    usleep(random_int(20000, 120000));
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /**
     * One-time operation token: the first call runs $fn and stores its result;
     * a repeated token returns the stored result without running again.
     * Must be called inside atomic() so the token and the work commit together.
     */
    public static function once(?string $token, ?int $userId, callable $fn)
    {
        if ($token === null || $token === '') {
            return $fn();
        }
        if (! preg_match('/^[A-Za-z0-9-]{8,36}$/', $token)) {
            throw new BizException('Invalid operation token.');
        }
        $existing = self::row('SELECT result FROM {op_tokens} WHERE token = ? FOR UPDATE', [$token]);
        if ($existing !== null) {
            return json_decode((string) $existing['result'], true);
        }
        // Reserve the token first so a concurrent duplicate waits on the primary key.
        self::exec('INSERT INTO {op_tokens} (token, user_id, result, created_at) VALUES (?, ?, NULL, ?)', [$token, $userId, self::now()]);
        $result = $fn();
        self::exec('UPDATE {op_tokens} SET result = ? WHERE token = ?', [json_encode($result), $token]);
        return $result;
    }
}
