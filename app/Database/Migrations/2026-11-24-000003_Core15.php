<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeCore15;
use CodeIgniter\Database\Migration;

/** core 1.5 (Chatu Warehouse 0.3.0, as ChatuPOS 2.0.0-beta27): plans, platform invoices, online payments (Lenco, DPO), module rules. */
class Core15 extends Migration
{
    public function up()
    {
        UpgradeCore15::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
