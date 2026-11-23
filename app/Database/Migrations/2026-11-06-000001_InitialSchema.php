<?php

namespace App\Database\Migrations;

use App\Libraries\Setup\Schema;
use CodeIgniter\Database\Migration;
use RuntimeException;

/** Creates every table from app/Database/sql/schema.sql (also importable by hand). */
class InitialSchema extends Migration
{
    public function up()
    {
        foreach (Schema::statements($this->db->getPrefix()) as $sql) {
            if ($this->db->query($sql) === false) {
                $err = $this->db->error();
                throw new RuntimeException('Schema statement failed: ' . ($err['message'] ?? '') . "\n" . substr($sql, 0, 200));
            }
        }
    }

    public function down()
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(Schema::tables()) as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($this->db->getPrefix() . $t));
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
