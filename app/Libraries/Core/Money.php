<?php

namespace App\Libraries\Core;

/**
 * Money is handled as integer minor units (ngwee, cents) to avoid floating point errors.
 * The database stores DECIMAL(18,2); these helpers convert both ways.
 */
final class Money
{
    /** "1234.5" or "1,234.50" -> 123450 */
    public static function toCents($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $s = str_replace([',', ' '], '', trim((string) $value));
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
            if (is_numeric($s)) {
                return (int) round(((float) $s) * 100);
            }
            throw new BizException('Invalid amount: ' . $value);
        }
        $neg = str_starts_with($s, '-');
        $s   = ltrim($s, '-');
        [$whole, $frac] = array_pad(explode('.', $s, 2), 2, '0');
        $frac  = str_pad($frac, 2, '0');
        $cents = ((int) $whole) * 100 + (int) $frac;
        return $neg ? -$cents : $cents;
    }

    /** 123450 -> "1234.50" (for the database) */
    public static function toDecimal(int $cents): string
    {
        $neg = $cents < 0;
        $c   = abs($cents);
        return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 123450 -> "1,234.50" (for display) */
    public static function format(int $cents): string
    {
        $neg = $cents < 0;
        $c   = abs($cents);
        return ($neg ? '-' : '') . number_format(intdiv($c, 100)) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Integer division rounded half away from zero. */
    public static function divRound(int $num, int $den): int
    {
        if ($den === 0) {
            throw new BizException('Division by zero.');
        }
        if ($den < 0) {
            $num = -$num;
            $den = -$den;
        }
        $q = intdiv($num, $den);
        $r = $num - $q * $den;
        if (2 * abs($r) >= $den) {
            $q += $num >= 0 ? 1 : -1;
        }
        return $q;
    }
}
