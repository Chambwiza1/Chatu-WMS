<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeSignIn;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.2: signing in as someone, and support access. */
class SignInAs extends Migration
{
    public function up()
    {
        UpgradeSignIn::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
