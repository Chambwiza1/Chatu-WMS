<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/** ChatuERP core 1.8 (E4): which overdue item was escalated to which level, so each level is sent once. */
final class UpgradeEscalate
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}escalations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            kind VARCHAR(30) NOT NULL,
            object_id INT UNSIGNED NOT NULL,
            level TINYINT UNSIGNED NOT NULL COMMENT '1: a reminder to those who act; 2: to their managers',
            told SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            age_days SMALLINT UNSIGNED NOT NULL,
            at DATETIME NOT NULL,
            UNIQUE KEY uq_esc (tenant_id, kind, object_id, level),
            UNIQUE KEY uq_esc_tenant_id (tenant_id, id),
            KEY idx_esc_at (tenant_id, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
