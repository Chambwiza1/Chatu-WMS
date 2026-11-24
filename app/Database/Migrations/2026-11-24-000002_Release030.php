<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeCore;
use CodeIgniter\Database\Migration;

/**
 * Chatu Warehouse 0.3.0 (ChatuERP core 1.1): organisations. Every warehouse table gets tenant_id with links inside
 * the organisation; the company of 0.2.1 becomes organisation 1 of the single-company edition.
 */
class Release030 extends Migration
{
    public function up()
    {
        UpgradeCore::v030($this->db);
    }

    public function down()
    {
        // not reversible: restore the backup taken before the update
    }
}
