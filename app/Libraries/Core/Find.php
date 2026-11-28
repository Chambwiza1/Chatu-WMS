<?php

namespace App\Libraries\Core;

/**
 * One search rule for every list (core 1.7, D1; the rule of ChatuPOS beta14 and Chatu Assets 2.1):
 *
 * - words in any order: "bag rice" finds "Rice 5 kg bag";
 * - part of a code: "CE5" finds "RICE5";
 * - every word must be found, each in any of the list's columns;
 * - % and _ are letters, not wildcards;
 * - an exact barcode or code also matches as a whole (a scanned "6001234500017" finds its item).
 */
final class Find
{
    /** Words of a search, at most 8, each at most 60 characters. */
    public static function words(string $q): array
    {
        $w = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_map(static fn ($x) => mb_substr($x, 0, 60), $w), 0, 8);
    }

    public static function like(string $word): string
    {
        return '%' . addcslashes($word, '%_\\') . '%';
    }

    /**
     * The condition for $q over $cols (each word in one of them), or over $exact (the whole search equal to one).
     * Returns [sql in brackets, binds]; ['1=1', []] for an empty search.
     */
    public static function where(string $q, array $cols, array $exact = []): array
    {
        $words = self::words($q);
        if (! $words) {
            return ['1=1', []];
        }
        $and = [];
        $b   = [];
        foreach ($words as $w) {
            $and[] = '(' . implode(' OR ', array_map(static fn ($c) => "$c LIKE ?", $cols)) . ')';
            foreach ($cols as $_) {
                $b[] = self::like($w);
            }
        }
        $sql = '(' . implode(' AND ', $and);
        foreach ($exact as $c) {
            $sql .= " OR $c = ?";
            $b[] = trim($q);
        }
        return [$sql . ')', $b];
    }
}
