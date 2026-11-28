<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradePlanRequests;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.5.5: an organisation chooses its plan and asks for its invoice. */
class PlanRequests extends Migration
{
    public function up()
    {
        UpgradePlanRequests::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
