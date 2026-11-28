<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeFilters;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.9 (0.9.0): saved filters. */
class Filters extends Migration
{
    public function up()
    {
        UpgradeFilters::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
