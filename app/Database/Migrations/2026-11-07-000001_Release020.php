<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\Upgrade;
use CodeIgniter\Database\Migration;

/** 0.2.0: pack sizes, foreign-currency purchasing, wave picking, vehicle link to Chatu Assets. */
class Release020 extends Migration
{
    public function up()
    {
        Upgrade::v020($this->db);
    }

    public function down()
    {
        // additive only; nothing to undo safely
    }
}
