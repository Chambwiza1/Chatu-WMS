<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/** ChatuERP core 1.9 (D6): saved filters on lists and reports, per person or shared with a role. */
final class UpgradeFilters
{
    public static function run(BaseConnection $db): void
    {
        $p = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS {$p}saved_filters (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL COMMENT 'who saved it',
            page VARCHAR(120) NOT NULL COMMENT 'the list or report, as its address',
            name VARCHAR(80) NOT NULL,
            query VARCHAR(1000) NOT NULL COMMENT 'the query string, without exports and paging',
            role_id INT UNSIGNED NULL COMMENT 'shared with the holders of this role; empty: only its owner',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_sf_tenant_id (tenant_id, id),
            UNIQUE KEY uq_sf_name (tenant_id, user_id, page, name),
            KEY idx_sf_page (tenant_id, page)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
