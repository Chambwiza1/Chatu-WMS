<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/** ChatuERP core 1.5.1 (B12): links for a forgotten password. Additive and safe to run twice. */
final class UpgradeReset
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL COMMENT 'NULL: a request that matched no account (kept to limit requests per address)',
            token_hash CHAR(64) NULL COMMENT 'SHA-256 of the link; the link itself is never kept',
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            UNIQUE KEY uq_pwreset_token (token_hash),
            KEY ix_pwreset_user (user_id, created_at),
            KEY ix_pwreset_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
