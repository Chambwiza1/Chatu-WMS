<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeReset;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.5.1 (B12): links for a forgotten password. */
class PasswordResets extends Migration
{
    public function up()
    {
        UpgradeReset::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
