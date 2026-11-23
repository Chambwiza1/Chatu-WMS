<?php

namespace App\Libraries\Master;

use App\Libraries\Core\Db;

/** Document numbers, per prefix and year: PO-2026-00001. Gap-free within a committed transaction. */
final class Numbers
{
    public const PREFIXES = [
        'po' => 'PO', 'receipt' => 'GRN', 'supplier_return' => 'RTS', 'move' => 'MV', 'transfer' => 'TO', 'sales_order' => 'SO',
        'shipment' => 'SH', 'customer_return' => 'CR', 'production' => 'PR', 'adjustment' => 'ADJ', 'count' => 'CNT', 'trip' => 'TRP', 'wave' => 'WV',
    ];

    public static function next(string $doc): string
    {
        $prefix = self::PREFIXES[$doc] ?? strtoupper($doc);
        $year   = (int) date('Y');
        Db::exec('INSERT IGNORE INTO {doc_counters} (prefix, year, next_no) VALUES (?, ?, 1)', [$prefix, $year]);
        $n = (int) Db::value('SELECT next_no FROM {doc_counters} WHERE prefix = ? AND year = ? FOR UPDATE', [$prefix, $year]);
        Db::exec('UPDATE {doc_counters} SET next_no = next_no + 1 WHERE prefix = ? AND year = ?', [$prefix, $year]);
        return sprintf('%s-%d-%05d', $prefix, $year, $n);
    }
}
