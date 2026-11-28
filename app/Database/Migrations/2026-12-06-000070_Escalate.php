<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeEscalate;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.8 (0.8.0): escalation of overdue work. */
class Escalate extends Migration
{
    public function up()
    {
        UpgradeEscalate::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
