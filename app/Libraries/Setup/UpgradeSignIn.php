<?php

namespace App\Libraries\Setup;

use App\Libraries\Platform\Product;
use CodeIgniter\Database\BaseConnection;

/**
 * ChatuERP core 1.2: the record of signing in as someone, and its two permissions. The same file in every product on
 * the shared tenant model. Additive and safe to run twice.
 */
final class UpgradeSignIn
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}signin_as_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            kind ENUM('admin','support') NOT NULL COMMENT 'admin: the organisation''s administrator; support: the platform owner, while the organisation allows it',
            by_user_id INT UNSIGNED NOT NULL,
            by_name VARCHAR(150) NOT NULL,
            as_user_id INT UNSIGNED NOT NULL,
            reason VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            ended_how VARCHAR(20) NULL,
            actions INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_sial_tenant_id (tenant_id, id),
            KEY idx_sial_started (tenant_id, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}signin_as_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            log_id INT UNSIGNED NOT NULL,
            at DATETIME NOT NULL,
            method VARCHAR(8) NOT NULL,
            path VARCHAR(190) NOT NULL,
            KEY idx_siaa_log (tenant_id, log_id),
            CONSTRAINT fk_siaa_log FOREIGN KEY (tenant_id, log_id) REFERENCES {$p}signin_as_log (tenant_id, id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (method_exists(\App\Libraries\Core\Db::class, 'reset')) {
            \App\Libraries\Core\Db::reset($db);
        }
        Seed::base();   // the new permissions on the starting roles
        // organisations that keep their own copies of the roles: whoever holds the first permission gets the new ones
        $members = (bool) $db->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$p . 'member_permissions'])->getRow();
        foreach (Product::SIGNIN_AS['give'] as $held => $new) {
            foreach ($new as $c) {
                $db->query("INSERT IGNORE INTO {$p}role_permissions (role_id, permission_code)
                            SELECT DISTINCT rp.role_id, ? FROM {$p}role_permissions rp JOIN {$p}roles r ON r.id = rp.role_id WHERE r.tenant_id IS NOT NULL AND rp.permission_code = ?", [$c, $held]);
                if ($members) {
                    $db->query("INSERT IGNORE INTO {$p}member_permissions (tenant_id, user_id, permission_code)
                                SELECT DISTINCT tenant_id, user_id, ? FROM {$p}member_permissions WHERE permission_code = ?", [$c, $held]);
                }
            }
        }
    }
}
