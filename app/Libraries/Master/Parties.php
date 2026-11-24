<?php

namespace App\Libraries\Master;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;

/** Suppliers and customers. */
final class Parties
{
    public const FIELDS = [
        'suppliers' => ['code', 'name', 'contact', 'phone', 'email', 'address', 'tax_no', 'lead_days', 'notes', 'active'],
        'customers' => ['code', 'name', 'contact', 'phone', 'email', 'address', 'town', 'tax_no', 'min_shelf_days', 'notes', 'active'],
    ];

    public static function save(string $table, array $d, ?int $id = null): int
    {
        if (! isset(self::FIELDS[$table])) {
            throw new BizException('Unknown list.');
        }
        Access::requireSave($table === 'suppliers' ? 'supplier' : 'customer', $id === null, $id !== null && isset($d['active']) && ! $d['active']);
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9._-]{1,30}$/', $code)) {
            throw new BizException('Code: letters, numbers, dot and dash; up to 30 characters.');
        }
        if (trim((string) ($d['name'] ?? '')) === '') {
            throw new BizException('Give a name.');
        }
        if (($d['email'] ?? '') !== '' && ! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            throw new BizException('That e-mail address is not valid.');
        }
        $row = [];
        foreach (self::FIELDS[$table] as $f) {
            $v = $d[$f] ?? null;
            if (in_array($f, ['lead_days', 'min_shelf_days'], true)) {
                $v = max(0, (int) $v);
            } elseif ($f === 'active') {
                $v = $v === null ? 1 : (int) (bool) $v;
            } else {
                $v = trim((string) $v) === '' ? null : mb_substr(trim((string) $v), 0, $f === 'address' || $f === 'notes' ? 500 : 190);
            }
            $row[$f] = $v;
        }
        $row['code'] = $code;
        return Db::atomic(static function () use ($table, $row, $id, $code) {
            if (Db::value("SELECT id FROM {{$table}} WHERE tenant_id = {tid} AND code = ? AND id <> ?", [$code, $id ?? 0])) {
                throw new BizException('Code ' . $code . ' is already used.');
            }
            if ($id) {
                $sets = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($row)));
                Db::exec("UPDATE {{$table}} SET $sets WHERE tenant_id = {tid} AND id = ?", array_merge(array_values($row), [$id]));
                Audit::log(rtrim($table, 's') . '.update', rtrim($table, 's'), $id, null, $row);
            } else {
                $id = Db::insert($table, $row + ['created_at' => Db::now()]);
                Audit::log(rtrim($table, 's') . '.create', rtrim($table, 's'), $id, null, $row);
            }
            return $id;
        });
    }

    public static function get(string $table, int $id): array
    {
        $r = Db::row("SELECT * FROM {{$table}} WHERE tenant_id = {tid} AND id = ?", [$id]);
        if (! $r) {
            throw new \App\Libraries\Core\NotFoundException(($table === 'suppliers' ? 'Supplier' : 'Customer') . ' not found.');
        }
        return $r;
    }

    public static function options(string $table): array
    {
        return Db::rows("SELECT id, code, name FROM {{$table}} WHERE tenant_id = {tid} AND active = 1 ORDER BY name");
    }
}
