<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Changes after the first schema, written so they can run more than once and on MariaDB or MySQL 8:
 * every new column is added only when it is missing. Used by the migrations and by the automated tests.
 */
final class Upgrade
{
    /** 0.2.0: pack sizes, foreign-currency purchasing, wave picking, vehicle link to Chatu Assets. */
    public static function v020(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        self::q($db, "CREATE TABLE IF NOT EXISTS {$p}item_units (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id INT UNSIGNED NOT NULL,
            name VARCHAR(12) NOT NULL,
            factor DECIMAL(18,4) NOT NULL,
            barcode VARCHAR(60) NULL,
            buy_default TINYINT(1) NOT NULL DEFAULT 0,
            sell_default TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_item_unit (item_id, name),
            KEY idx_unit_barcode (barcode),
            CONSTRAINT fk_unit_item FOREIGN KEY (item_id) REFERENCES {$p}items(id) ON DELETE CASCADE,
            CONSTRAINT chk_unit_factor CHECK (factor > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::q($db, "CREATE TABLE IF NOT EXISTS {$p}pick_waves (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            number VARCHAR(20) NOT NULL UNIQUE,
            warehouse_id INT UNSIGNED NOT NULL,
            status ENUM('open','picked','cancelled') NOT NULL DEFAULT 'open',
            note VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            picked_by INT UNSIGNED NULL,
            picked_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (['po_lines', 'so_lines', 'transfer_lines', 'receipt_lines'] as $t) {
            self::col($db, $t, 'pack_unit', 'VARCHAR(12) NULL');
            self::col($db, $t, 'pack_factor', 'DECIMAL(18,4) NULL');
        }
        self::col($db, 'purchase_orders', 'currency', "CHAR(3) NOT NULL DEFAULT ''");
        self::col($db, 'purchase_orders', 'fx_rate', 'DECIMAL(18,6) NOT NULL DEFAULT 1');
        self::col($db, 'purchase_orders', 'landed_pct', 'DECIMAL(9,3) NOT NULL DEFAULT 0');
        self::col($db, 'receipts', 'currency', "CHAR(3) NOT NULL DEFAULT ''");
        self::col($db, 'receipts', 'fx_rate', 'DECIMAL(18,6) NOT NULL DEFAULT 1');
        self::col($db, 'receipts', 'landed_pct', 'DECIMAL(9,3) NOT NULL DEFAULT 0');
        self::col($db, 'receipt_lines', 'unit_cost_fc', 'DECIMAL(18,4) NULL');
        self::col($db, 'shipments', 'wave_id', 'INT UNSIGNED NULL');
        self::col($db, 'vehicles', 'asset_code', 'VARCHAR(40) NULL');
        self::col($db, 'vehicles', 'vin', 'VARCHAR(40) NULL');
        // a price per pack divided into base units needs more than two decimals
        self::q($db, 'ALTER TABLE ' . $p . 'so_lines MODIFY unit_price DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    private static function col(BaseConnection $db, string $table, string $col, string $def): void
    {
        if (! $db->fieldExists($col, $table)) {
            self::q($db, 'ALTER TABLE ' . $db->getPrefix() . $table . ' ADD COLUMN ' . $col . ' ' . $def);
        }
    }

    private static function q(BaseConnection $db, string $sql): void
    {
        if ($db->query($sql) === false) {
            throw new RuntimeException('Database update failed: ' . ($db->error()['message'] ?? '') . ' (' . substr($sql, 0, 120) . ')');
        }
    }

    /** Migrations not yet run (for the update button under Administration). */
    public static function pending(): array
    {
        $runner = service('migrations');
        $runner->setNamespace('App');
        $done = [];
        foreach (db_connect()->table('migrations')->select('version')->get()->getResultArray() as $r) {
            $done[$r['version']] = true;
        }
        $out = [];
        foreach ($runner->findMigrations() as $m) {
            if (! isset($done[$m->version])) {
                $out[] = $m->name;
            }
        }
        return $out;
    }
}
