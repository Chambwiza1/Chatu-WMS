<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeSignup;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.5.4: sign-up by the customer. */
class Signups extends Migration
{
    public function up()
    {
        UpgradeSignup::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
