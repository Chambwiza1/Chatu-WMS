<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/**
 * core 1.5 (ChatuERP): plans with limits and prices, platform invoices, online payments through Lenco and DPO,
 * and the platform owner's module rules. The same tables in every product on the tenant_id core.
 * Additive and safe to run twice.
 */
final class UpgradeCore15
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}platform_plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(80) NOT NULL,
            monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'ZMW',
            max_locations INT UNSIGNED NULL,
            max_users INT UNSIGNED NULL,
            modules TEXT NULL,
            trial_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_plan_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}platform_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            number VARCHAR(30) NOT NULL,
            plan_id INT UNSIGNED NULL,
            months TINYINT UNSIGNED NOT NULL DEFAULT 1,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'ZMW',
            status ENUM('issued','paid','void') NOT NULL DEFAULT 'issued',
            issued_at DATETIME NOT NULL,
            issued_by INT UNSIGNED NULL,
            due_on DATE NOT NULL,
            paid_at DATETIME NULL,
            paid_ref VARCHAR(100) NULL,
            paid_via VARCHAR(20) NULL,
            note VARCHAR(255) NULL,
            UNIQUE KEY uq_pinv_number (number),
            KEY ix_pinv_tenant (tenant_id, status),
            CONSTRAINT fk_pinv_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // One row per attempt to pay online. tenant_id is the organisation that pays (platform invoices) or that is
        // paid (its own sales and tickets, with its own merchant keys). Our reference is unique, and so is the
        // gateway's: a callback or webhook that arrives twice settles once.
        $db->query("CREATE TABLE IF NOT EXISTS {$p}gateway_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            purpose VARCHAR(20) NOT NULL,
            object_id INT UNSIGNED NOT NULL,
            gateway VARCHAR(10) NOT NULL,
            reference VARCHAR(60) NOT NULL,
            gateway_ref VARCHAR(100) NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'ZMW',
            phone VARCHAR(20) NULL,
            operator VARCHAR(10) NULL,
            status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
            pay_url VARCHAR(500) NULL,
            detail VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            settled_at DATETIME NULL,
            UNIQUE KEY uq_gp_ref (reference),
            UNIQUE KEY uq_gp_gateway_ref (gateway, gateway_ref),
            KEY ix_gp_object (purpose, object_id),
            KEY ix_gp_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::col($db, 'tenants', 'plan_id', 'INT UNSIGNED NULL');
        self::col($db, 'tenants', 'paid_until', 'DATE NULL');
        self::col($db, 'tenants', 'billing_email', 'VARCHAR(150) NULL');
        self::col($db, 'tenants', 'status_reason', 'VARCHAR(255) NULL');
        // what this product needs on top (for example, keeping each organisation's modules as they were)
        if (method_exists(\App\Libraries\Platform\Product::class, 'upgrade')) {
            \App\Libraries\Platform\Product::upgrade($db);
        }
    }

    private static function col(BaseConnection $db, string $table, string $col, string $def): void
    {
        $p = $db->getPrefix();
        if (! $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}{$table}' AND COLUMN_NAME = '{$col}' LIMIT 1")->getRow()) {
            $db->query("ALTER TABLE {$p}{$table} ADD COLUMN {$col} {$def}");
        }
    }
}
