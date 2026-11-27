<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/** ChatuERP core 1.5.4: sign-up by the customer. Additive and safe to run twice. */
final class UpgradeSignup
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}signups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mode ENUM('self','request') NOT NULL COMMENT 'self: the customer creates the organisation; request: the platform owner does',
            status ENUM('pending','requested','done','dismissed') NOT NULL COMMENT 'pending: waiting for the e-mailed link (self)',
            org_name VARCHAR(150) NOT NULL,
            contact_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(30) NULL,
            username VARCHAR(60) NULL,
            password_hash VARCHAR(255) NULL COMMENT 'self only; cleared once the organisation exists',
            answers TEXT NULL COMMENT 'JSON: the product''s own questions',
            token_hash CHAR(64) NULL COMMENT 'SHA-256 of the link; the link itself is never kept',
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            tenant_id INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            decided_by INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            UNIQUE KEY uq_signup_token (token_hash),
            KEY ix_signup_status (status, created_at),
            KEY ix_signup_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
