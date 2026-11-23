<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\Upgrade;
use CodeIgniter\Database\Migration;

/** Chatu Warehouse 0.2.1 (core 1.1): one sign-in per account. */
class Release021 extends Migration
{
    public function up()
    {
        Upgrade::v021($this->db);
    }

    public function down()
    {
        // additive only; nothing to undo safely
    }
}
