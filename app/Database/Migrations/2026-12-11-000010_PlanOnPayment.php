<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradePlanRequests;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.5.6: the plan changes when the invoice is paid; a credit for unused annual time. */
class PlanOnPayment extends Migration
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
