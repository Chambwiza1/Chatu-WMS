<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/**
 * ChatuERP core 1.4 (E5): the outbox. Every e-mail and WhatsApp message an organisation sends, one row per person per
 * channel, born with its organisation column. Additive and safe to run twice.
 */
final class UpgradeOutbox
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}message_outbox (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            channel ENUM('email','whatsapp') NOT NULL,
            purpose VARCHAR(20) NOT NULL COMMENT 'staff: a copy of a message to one of its people; customer: a notice to a customer; test',
            object_type VARCHAR(30) NULL,
            object_id INT UNSIGNED NULL,
            recipient VARCHAR(190) NOT NULL,
            sender VARCHAR(20) NOT NULL COMMENT 'own: the organisation''s own server or number; installation: the single-company installation''s mail server',
            template VARCHAR(80) NULL,
            body_enc MEDIUMTEXT NULL COMMENT 'encrypted; wiped once sent or cancelled, and a week after failing',
            status ENUM('queued','sending','sent','delivered','read','failed','cancelled') NOT NULL DEFAULT 'queued',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claim CHAR(16) NULL,
            claimed_at DATETIME NULL,
            provider_id VARCHAR(120) NULL,
            error VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            read_at DATETIME NULL,
            failed_at DATETIME NULL,
            UNIQUE KEY uq_mo_tenant_id (tenant_id, id),
            KEY idx_mo_due (tenant_id, status, next_attempt_at),
            KEY idx_mo_object (tenant_id, object_type, object_id),
            KEY idx_mo_provider (provider_id),
            KEY idx_mo_claim (tenant_id, claim)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
