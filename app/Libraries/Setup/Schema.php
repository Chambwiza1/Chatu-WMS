<?php

namespace App\Libraries\Setup;

/** Reads the schema files. app/Database/sql/schema.sql can also be imported by hand (replace {P} with the prefix). */
final class Schema
{
    /** Every schema file in order, for a fresh database built without the migration runner. */
    public const FILES = ['schema.sql'];

    public static function statements(string $prefix, string $file = 'schema.sql'): array
    {
        $sql = file_get_contents(APPPATH . 'Database/sql/' . $file);
        $sql = str_replace('{P}', $prefix, $sql);
        $sql = preg_replace('/^--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    public static function all(string $prefix): array
    {
        $out = [];
        foreach (self::FILES as $f) {
            $out = array_merge($out, self::statements($prefix, $f));
        }
        return $out;
    }

    /** Table names in the schema, in creation order. */
    public static function tables(): array
    {
        preg_match_all('/CREATE TABLE IF NOT EXISTS \{P\}([a-z_]+)/', file_get_contents(APPPATH . 'Database/sql/schema.sql'), $m);
        return array_merge($m[1], ['item_units', 'pick_waves']); // tables added by later releases (Setup\Upgrade)
    }
}
