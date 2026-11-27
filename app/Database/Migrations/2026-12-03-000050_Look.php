<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\Upgrade;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.3 (0.4.0): light or dark on the person's account. */
class Look extends Migration
{
    public function up()
    {
        Upgrade::v040($this->db);
    }

    public function down()
    {
        // additive only
    }
}
