<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\UpgradeOutbox;
use CodeIgniter\Database\Migration;

/** ChatuERP core 1.4 (0.5.0): the outbox, with the organisation's own e-mail and WhatsApp senders. */
class Outbox extends Migration
{
    public function up()
    {
        UpgradeOutbox::run($this->db);
    }

    public function down()
    {
        // additive only
    }
}
