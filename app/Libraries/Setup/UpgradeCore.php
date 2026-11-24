<?php

namespace App\Libraries\Setup;

use App\Libraries\Core\Rights;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Chatu Warehouse 0.3.0: the ChatuERP core 1.1 tenant model (as ChatuPOS 2.0 and Chatu Assets 2.x).
 *
 * - Organisations (tenants), their own settings (tenant_settings) and members (tenant_users), one login per person.
 * - Every warehouse table gets tenant_id, a unique key on (tenant_id, id), and its links to other warehouse tables
 *   become (tenant_id, column) -> parent(tenant_id, id), so a row can only point at a row of its own organisation.
 *   Codes and document numbers are unique per organisation.
 * - Roles are templates (tenant_id NULL) that each organisation copies (beta12); a person holds roles and direct
 *   permissions at all warehouses or at a list (member_warehouses).
 * - The audit log has one chain per organisation and one for the platform (audit_heads).
 *
 * One program for every table, so a fresh installation (empty tables) and an update from 0.2.1 (one company's data,
 * which becomes organisation 1 of the single-company edition) end in the same schema. Safe to run twice.
 */
final class UpgradeCore
{
    private static BaseConnection $db;
    private static string $p;

    public static function v030(BaseConnection $db): void
    {
        self::$db = $db;
        self::$p  = $p = $db->getPrefix();
        $hadData  = (int) self::val("SELECT COUNT(*) FROM {$p}users") > 0 && ! self::hasTable('tenants');

        // 1. organisations and memberships
        self::q("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'active',
            country CHAR(2) NOT NULL DEFAULT 'ZM',
            timezone VARCHAR(40) NOT NULL DEFAULT 'Africa/Lusaka',
            locale VARCHAR(10) NOT NULL DEFAULT 'en',
            trial_ends_at DATETIME NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::q("CREATE TABLE IF NOT EXISTS {$p}tenant_settings (
            tenant_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            value TEXT NULL,
            PRIMARY KEY (tenant_id, name),
            CONSTRAINT fk_ts_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::q("CREATE TABLE IF NOT EXISTS {$p}tenant_users (
            tenant_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            status ENUM('active','off') NOT NULL DEFAULT 'active',
            is_account_owner TINYINT(1) NOT NULL DEFAULT 0,
            all_warehouses TINYINT(1) NOT NULL DEFAULT 0,
            department VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (tenant_id, user_id),
            KEY idx_tu_user (user_id),
            CONSTRAINT fk_tu_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
            CONSTRAINT fk_tu_user FOREIGN KEY (user_id) REFERENCES {$p}users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::q("CREATE TABLE IF NOT EXISTS {$p}audit_heads (
            tenant_key INT UNSIGNED NOT NULL PRIMARY KEY,
            head CHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $tid = null;
        if ($hadData) {
            // the company of 0.2.1 becomes organisation 1, in the single-company edition
            $name = trim((string) self::val("SELECT value FROM {$p}settings WHERE name = 'company_name'")) ?: 'My company';
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-')) ?: 'company';
            $slug = substr(strlen($slug) < 2 ? 'company' : $slug, 0, 40);
            $at   = self::val("SELECT value FROM {$p}settings WHERE name = 'installed_at'") ?: gmdate('Y-m-d H:i:s');
            $db->query("INSERT INTO {$p}tenants (slug, name, status, created_at) VALUES (?, ?, 'active', ?)", [$slug, mb_substr($name, 0, 150), $at]);
            $tid = (int) $db->insertID();
            $db->query("INSERT INTO {$p}settings (name, value) VALUES ('edition', 'single') ON DUPLICATE KEY UPDATE value = VALUES(value)");
            foreach (array_keys(Settings::TENANT_DEFAULTS) as $k) {
                $db->query("INSERT IGNORE INTO {$p}tenant_settings (tenant_id, name, value) SELECT ?, name, value FROM {$p}settings WHERE name = ?", [$tid, $k]);
                $db->query("DELETE FROM {$p}settings WHERE name = ?", [$k]);
            }
            $db->query("INSERT IGNORE INTO {$p}tenant_users (tenant_id, user_id, status, is_account_owner, all_warehouses, created_at)
                        SELECT ?, id, 'active', 0, 0, created_at FROM {$p}users", [$tid]);
        }

        // 2. every warehouse table belongs to an organisation
        $business = Tenancy::business();
        $fks      = self::businessFks($business);
        foreach ($fks as $fk) {
            self::q("ALTER TABLE {$p}{$fk['table']} DROP FOREIGN KEY {$fk['name']}");
            if (self::hasIdx($fk['table'], $fk['name'])) {
                self::q("ALTER TABLE {$p}{$fk['table']} DROP INDEX {$fk['name']}");
            }
        }
        foreach ($business as $t) {
            if (! self::hasCol($t, 'tenant_id')) {
                $after = self::hasCol($t, 'id') ? 'AFTER id' : 'FIRST';
                self::q("ALTER TABLE {$p}{$t} ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT " . ($tid ?? 1) . " {$after}");
                self::q("ALTER TABLE {$p}{$t} ALTER COLUMN tenant_id DROP DEFAULT");
            }
            if (self::hasCol($t, 'id') && ! self::hasIdx($t, 'uq_' . $t . '_tid')) {
                self::q("ALTER TABLE {$p}{$t} ADD UNIQUE KEY uq_{$t}_tid (tenant_id, id)");
            }
            // codes and document numbers: unique per organisation
            foreach (self::uniques($t) as $name => $cols) {
                if ($cols[0] === 'tenant_id' || in_array('tenant_id', $cols, true)) {
                    continue;
                }
                $list = 'tenant_id, ' . implode(', ', $cols);
                self::q($name === 'PRIMARY' ? "ALTER TABLE {$p}{$t} DROP PRIMARY KEY, ADD PRIMARY KEY ({$list})" : "ALTER TABLE {$p}{$t} DROP INDEX `{$name}`, ADD UNIQUE KEY `{$name}` ({$list})");
            }
            if (! self::hasFk($t, 'fk_' . $t . '_tenant')) {
                self::q("ALTER TABLE {$p}{$t} ADD CONSTRAINT fk_{$t}_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)");
            }
        }
        foreach ($fks as $fk) {
            self::q("ALTER TABLE {$p}{$fk['table']} ADD CONSTRAINT {$fk['name']} FOREIGN KEY (tenant_id, {$fk['col']}) REFERENCES {$p}{$fk['ref']}(tenant_id, id)"
                . ($fk['delete'] === 'CASCADE' ? ' ON DELETE CASCADE' : ''));
        }

        // 3. roles are templates each organisation copies; people hold them in an organisation
        if (! self::hasCol('roles', 'tenant_id')) {
            self::q("ALTER TABLE {$p}roles ADD COLUMN tenant_id INT UNSIGNED NULL AFTER id, ADD COLUMN template VARCHAR(40) NULL,
                     ADD COLUMN level ENUM('tenant','platform') NOT NULL DEFAULT 'tenant' AFTER description,
                     ADD COLUMN tenant_key INT UNSIGNED AS (COALESCE(tenant_id, 0)) STORED");
            if (self::hasIdx('roles', 'code')) {
                self::q("ALTER TABLE {$p}roles DROP INDEX code");
            }
            self::q("ALTER TABLE {$p}roles ADD UNIQUE KEY uq_role_code (tenant_key, code), ADD KEY ix_role_tenant (tenant_id),
                     ADD CONSTRAINT fk_role_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)");
        }
        self::q("CREATE TABLE IF NOT EXISTS {$p}member_warehouses (
            tenant_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (tenant_id, user_id, warehouse_id), KEY ix_mw_wh (tenant_id, warehouse_id),
            CONSTRAINT fk_mw_member FOREIGN KEY (tenant_id, user_id) REFERENCES {$p}tenant_users(tenant_id, user_id),
            CONSTRAINT fk_mw_warehouse FOREIGN KEY (tenant_id, warehouse_id) REFERENCES {$p}warehouses(tenant_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::q("CREATE TABLE IF NOT EXISTS {$p}member_permissions (
            tenant_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, permission_code VARCHAR(60) NOT NULL,
            PRIMARY KEY (tenant_id, user_id, permission_code),
            CONSTRAINT fk_mp_member FOREIGN KEY (tenant_id, user_id) REFERENCES {$p}tenant_users(tenant_id, user_id),
            CONSTRAINT fk_mp_perm FOREIGN KEY (permission_code) REFERENCES {$p}permissions(code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (! self::hasCol('user_roles', 'tenant_id')) {
            self::q("ALTER TABLE {$p}user_roles ADD COLUMN tenant_id INT UNSIGNED NULL AFTER id,
                     MODIFY scope_type ENUM('system','warehouse','platform','tenant') NOT NULL DEFAULT 'tenant',
                     ADD COLUMN tenant_key INT UNSIGNED AS (COALESCE(tenant_id, 0)) STORED");
            if ($tid) {
                // warehouses from the old scopes: a role for the whole company gives every warehouse, a role at a warehouse gives that one
                $db->query("UPDATE {$p}user_roles SET tenant_id = ?", [$tid]);
                $db->query("UPDATE {$p}tenant_users tu SET all_warehouses = 1 WHERE tu.tenant_id = ? AND EXISTS (SELECT 1 FROM {$p}user_roles ur WHERE ur.user_id = tu.user_id AND ur.scope_type = 'system')", [$tid]);
                $db->query("INSERT IGNORE INTO {$p}member_warehouses (tenant_id, user_id, warehouse_id) SELECT ?, user_id, scope_id FROM {$p}user_roles WHERE scope_type = 'warehouse' AND scope_id IS NOT NULL", [$tid]);
            }
            self::q("DELETE FROM {$p}user_roles WHERE tenant_id IS NULL");
            self::q("UPDATE {$p}user_roles SET scope_type = 'tenant', scope_id = NULL");
            // one holding per role and person (several warehouses became the warehouse list)
            self::q("DELETE ur FROM {$p}user_roles ur JOIN {$p}user_roles keep ON keep.user_id = ur.user_id AND keep.role_id = ur.role_id AND keep.tenant_id = ur.tenant_id AND keep.id < ur.id");
            self::q("ALTER TABLE {$p}user_roles ADD UNIQUE KEY uq_user_role_t (user_id, role_id, tenant_key, scope_type, scope_key)");
            self::q("ALTER TABLE {$p}user_roles DROP INDEX uq_user_role");
            self::q("ALTER TABLE {$p}user_roles RENAME INDEX uq_user_role_t TO uq_user_role, MODIFY scope_type ENUM('platform','tenant','warehouse') NOT NULL DEFAULT 'tenant',
                     ADD KEY idx_ur_tenant (tenant_id, user_id),
                     ADD CONSTRAINT fk_ur_member FOREIGN KEY (tenant_id, user_id) REFERENCES {$p}tenant_users(tenant_id, user_id),
                     ADD CONSTRAINT chk_ur_scope CHECK ((scope_type = 'platform' AND tenant_id IS NULL AND scope_id IS NULL)
                              OR (scope_type = 'tenant' AND tenant_id IS NOT NULL AND scope_id IS NULL)
                              OR (scope_type = 'warehouse' AND tenant_id IS NOT NULL AND scope_id IS NOT NULL))");
        }

        // 4. messages and the audit log per organisation
        if (! self::hasCol('notifications', 'tenant_id')) {
            self::q("ALTER TABLE {$p}notifications ADD COLUMN tenant_id INT UNSIGNED NULL AFTER id, ADD KEY idx_notif_tenant (tenant_id, user_id, read_at),
                     ADD CONSTRAINT fk_notif_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)");
            if ($tid) {
                $db->query("UPDATE {$p}notifications SET tenant_id = ?", [$tid]);
            }
        }
        if (! self::hasCol('audit_events', 'tenant_id')) {
            self::q("ALTER TABLE {$p}audit_events ADD COLUMN tenant_id INT UNSIGNED NULL AFTER id, ADD KEY idx_audit_tenant (tenant_id, id)");
            if ($tid) {
                self::resealAudit($tid);
            }
        }
        self::q("DELETE FROM {$p}settings WHERE name = 'audit_head'");

        // 5. sign-in by e-mail needs e-mail addresses to be unique (when they already are)
        if (! self::hasIdx('users', 'uq_user_email') && ! self::val("SELECT 1 FROM {$p}users WHERE email IS NOT NULL AND email <> '' GROUP BY email HAVING COUNT(*) > 1 LIMIT 1")) {
            self::q("UPDATE {$p}users SET email = NULL WHERE email = ''");
            self::q("ALTER TABLE {$p}users ADD UNIQUE KEY uq_user_email (email)");
        }

        // 6. the permission codes of 0.3.0, the templates, and each organisation's own copies
        \App\Libraries\Core\Db::reset($db);
        Seed::base();
        foreach ($db->query("SELECT id FROM {$p}roles WHERE tenant_id IS NULL AND level = 'tenant'")->getResultArray() as $r) {
            self::recode((int) $r['id']);
        }
        if ($tid) {
            foreach ($db->query("SELECT * FROM {$p}roles WHERE tenant_id IS NULL AND level = 'tenant'")->getResultArray() as $tpl) {
                $db->query("INSERT INTO {$p}roles (tenant_id, code, name, description, level, rank_level, template) VALUES (?, ?, ?, ?, 'tenant', ?, ?)",
                    [$tid, $tpl['code'], $tpl['name'], $tpl['description'], $tpl['rank_level'], $tpl['code']]);
                $cid = (int) $db->insertID();
                self::q("INSERT IGNORE INTO {$p}role_permissions (role_id, permission_code) SELECT {$cid}, permission_code FROM {$p}role_permissions WHERE role_id = " . (int) $tpl['id']);
                $db->query("UPDATE {$p}user_roles SET role_id = ? WHERE tenant_id = ? AND role_id = ?", [$cid, $tid, $tpl['id']]);
            }
        }
        foreach (array_keys(Rights::RENAMED) as $old) {
            $db->query("DELETE FROM {$p}role_permissions WHERE permission_code = ?", [$old]);
            $db->query("DELETE FROM {$p}permissions WHERE code = ?", [$old]);
        }
        \App\Libraries\Core\Db::reset($db);
        Settings::flush();
    }

    /** A role's codes in 0.3.0 words (Rights::upgrade), keeping what was there. */
    private static function recode(int $roleId): void
    {
        $p     = self::$p;
        $codes = array_column(self::$db->query("SELECT permission_code FROM {$p}role_permissions WHERE role_id = ?", [$roleId])->getResultArray(), 'permission_code');
        foreach (Rights::upgrade($codes) as $c) {
            self::$db->query("INSERT IGNORE INTO {$p}role_permissions (role_id, permission_code) VALUES (?, ?)", [$roleId, $c]);
        }
    }

    /**
     * The 0.2.1 chain becomes organisation 1's chain. It is checked first with the old rule; when intact, every event
     * is sealed again with the organisation in the hash and an event records the old head, so the history stays
     * provable. A chain that was already broken is left as it is, so the check keeps showing where it broke.
     */
    private static function resealAudit(int $tid): void
    {
        $db   = self::$db;
        $p    = self::$p;
        $old  = (string) self::val("SELECT value FROM {$p}settings WHERE name = 'audit_head'");
        $prev = str_repeat('0', 64);
        $ok   = true;
        $last = 0;
        while ($ok) {
            $batch = $db->query("SELECT * FROM {$p}audit_events WHERE id > ? ORDER BY id LIMIT 1000", [$last])->getResultArray();
            if (! $batch) {
                break;
            }
            foreach ($batch as $r) {
                if (strlen($r['at']) === 19) {
                    $r['at'] .= '.000';
                }
                if ($r['prev_hash'] !== $prev || self::legacyHash($r) !== $r['hash']) {
                    $ok = false;
                    break;
                }
                $prev = $r['hash'];
                $last = (int) $r['id'];
            }
        }
        if ($ok && $last > 0 && $old !== '' && $old !== $prev) {
            $ok = false;
        }
        $db->query("UPDATE {$p}audit_events SET tenant_id = ?", [$tid]);
        if (! $ok) {
            $head = (string) self::val("SELECT hash FROM {$p}audit_events ORDER BY id DESC LIMIT 1");
            $db->query("INSERT INTO {$p}audit_heads (tenant_key, head) VALUES (?, ?) ON DUPLICATE KEY UPDATE head = VALUES(head)", [$tid, $head ?: str_repeat('0', 64)]);
            return;
        }
        $prev = str_repeat('0', 64);
        $last = 0;
        while (true) {
            $batch = $db->query("SELECT * FROM {$p}audit_events WHERE id > ? ORDER BY id LIMIT 1000", [$last])->getResultArray();
            if (! $batch) {
                break;
            }
            foreach ($batch as $r) {
                if (strlen($r['at']) === 19) {
                    $r['at'] .= '.000';
                }
                $r['prev_hash'] = $prev;
                $h = \App\Libraries\Core\Audit::hashOf($r);
                $db->query("UPDATE {$p}audit_events SET prev_hash = ?, hash = ? WHERE id = ?", [$prev, $h, $r['id']]);
                $prev = $h;
                $last = (int) $r['id'];
            }
        }
        $mt  = microtime(true);
        $row = ['tenant_id' => $tid, 'at' => gmdate('Y-m-d H:i:s', (int) $mt) . '.' . str_pad((string) ((int) ($mt * 1000) % 1000), 3, '0', STR_PAD_LEFT),
            'effective_date' => null, 'user_id' => null, 'username' => 'system', 'on_behalf_of' => null, 'ip' => 'cli', 'user_agent' => 'update', 'app_version' => \App\Libraries\Core\Audit::APP_VERSION,
            'device_id' => null, 'action' => 'audit.resealed', 'object_type' => 'system', 'object_id' => null, 'request_id' => null, 'before_json' => json_encode(['head' => $old, 'events' => $last]),
            'after_json' => null, 'outcome' => 'success', 'detail' => 'The 0.2.1 audit chain was intact and is now this organisation\'s chain, sealed with the organisation in every hash.', 'prev_hash' => $prev];
        $row['hash'] = \App\Libraries\Core\Audit::hashOf($row);
        $db->table('audit_events')->insert($row);
        $db->query("INSERT INTO {$p}audit_heads (tenant_key, head) VALUES (?, ?) ON DUPLICATE KEY UPDATE head = VALUES(head)", [$tid, $row['hash']]);
    }

    /** The hash rule of 0.2.1 (no organisation). */
    private static function legacyHash(array $row): string
    {
        $parts = [];
        foreach (['at', 'effective_date', 'user_id', 'username', 'on_behalf_of', 'ip', 'user_agent', 'app_version', 'device_id', 'action', 'object_type', 'object_id', 'request_id', 'before_json', 'after_json', 'outcome', 'detail', 'prev_hash'] as $f) {
            $v       = $row[$f] ?? null;
            $parts[] = $v === null ? "\0" : (string) $v;
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    /** Single-column foreign keys from one warehouse table to another. */
    private static function businessFks(array $business): array
    {
        $p   = self::$p;
        $out = [];
        $rows = self::$db->query("SELECT k.TABLE_NAME t, k.CONSTRAINT_NAME n, GROUP_CONCAT(k.COLUMN_NAME) c, k.REFERENCED_TABLE_NAME r, rc.DELETE_RULE d
                                    FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                                      ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND rc.TABLE_NAME = k.TABLE_NAME
                                   WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME")->getResultArray();
        foreach ($rows as $r) {
            $t   = substr($r['t'], strlen($p));
            $ref = substr($r['r'], strlen($p));
            if (! str_starts_with($r['t'], $p) || ! in_array($t, $business, true) || ! in_array($ref, $business, true) || str_contains($r['c'], ',')) {
                continue;
            }
            $out[] = ['table' => $t, 'name' => $r['n'], 'col' => $r['c'], 'ref' => $ref, 'delete' => $r['d']];
        }
        return $out;
    }

    /** Unique keys of a table (PRIMARY only when it is not the id): name => columns. */
    private static function uniques(string $t): array
    {
        $p   = self::$p;
        $out = [];
        foreach (self::$db->query("SELECT INDEX_NAME n, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM information_schema.STATISTICS
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 GROUP BY INDEX_NAME", [$p . $t])->getResultArray() as $r) {
            $cols = explode(',', $r['c']);
            if ($r['n'] === 'PRIMARY' && $cols === ['id']) {
                continue;
            }
            $out[$r['n']] = $cols;
        }
        return $out;
    }

    private static function hasTable(string $t): bool
    {
        return (bool) self::val("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::$p . $t . "'");
    }

    private static function hasCol(string $t, string $c): bool
    {
        return (bool) self::val("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::$p . $t . "' AND COLUMN_NAME = '{$c}'");
    }

    private static function hasIdx(string $t, string $k): bool
    {
        return (bool) self::val("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::$p . $t . "' AND INDEX_NAME = '{$k}' LIMIT 1");
    }

    private static function hasFk(string $t, string $k): bool
    {
        return (bool) self::val("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::$p . $t . "' AND CONSTRAINT_NAME = '{$k}' LIMIT 1");
    }

    private static function val(string $sql)
    {
        $r = self::$db->query($sql)->getRowArray();
        return $r ? reset($r) : null;
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new RuntimeException('Database update failed: ' . (self::$db->error()['message'] ?? '') . ' (' . substr(preg_replace('/\s+/', ' ', $sql), 0, 160) . ')');
        }
    }
}
