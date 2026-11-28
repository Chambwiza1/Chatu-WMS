<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/**
 * ChatuERP core 1.5.5: an organisation's own requests for a plan and its invoice, and "Plan and billing" for
 * accountants. Additive and safe to run twice.
 */
final class UpgradePlanRequests
{
    /** Role templates that get "Plan and billing" (billing.manage); the organisations' copies of them too. */
    public const ROLES = ['accountant'];

    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}platform_plan_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            plan_id INT UNSIGNED NOT NULL,
            months TINYINT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            requested_by INT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            reason VARCHAR(255) NULL,
            invoice_id INT UNSIGNED NULL,
            KEY ix_planreq_tenant (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("INSERT INTO {$p}permissions (code, description) VALUES ('billing.manage', 'Plan and billing: see Billing, pay invoices, and ask for a plan and its invoice (the account owner approves)')
                    ON DUPLICATE KEY UPDATE description = VALUES(description)");
        foreach (self::ROLES as $code) {
            $db->query("INSERT IGNORE INTO {$p}role_permissions (role_id, permission_code)
                        SELECT r.id, 'billing.manage' FROM {$p}roles r WHERE r.code = ? OR r.template = ?", [$code, $code]);
        }
    }
}
