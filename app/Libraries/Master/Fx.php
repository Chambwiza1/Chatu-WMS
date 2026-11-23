<?php

namespace App\Libraries\Master;

use App\Libraries\Core\BizException;
use App\Libraries\Core\Settings;

/**
 * Purchase costs in another currency. A purchase order (or a receipt without one) may be priced in, say, USD
 * at a rate of home currency per unit of that currency, plus a landed-cost percentage for freight, duty and
 * clearing. Stock is always valued in the home currency: unit cost = foreign cost × rate × (1 + landed% / 100).
 */
final class Fx
{
    public static function home(): string
    {
        return strtoupper((string) Settings::get('currency_code')) ?: 'ZMW';
    }

    /** Validate [currency, rate, landed%]; '' currency = home currency (rate 1). */
    public static function clean($currency, $rate, $landed): array
    {
        $cur = strtoupper(trim((string) $currency));
        if ($cur === self::home()) {
            $cur = '';
        }
        if ($cur !== '' && ! preg_match('/^[A-Z]{3}$/', $cur)) {
            throw new BizException('Currency: three letters, such as USD or ZAR.');
        }
        $r = $cur === '' ? 1.0 : (float) str_replace(',', '', (string) $rate);
        if ($cur !== '' && ($r <= 0 || $r > 1000000)) {
            throw new BizException('Exchange rate: how many ' . self::home() . ' one ' . $cur . ' buys, more than 0.');
        }
        $lp = ($landed === null || $landed === '') ? 0.0 : (float) str_replace(',', '', (string) $landed);
        if ($lp < 0 || $lp > 500) {
            throw new BizException('Landed cost: a percentage from 0 to 500.');
        }
        return [$cur, round($r, 6), round($lp, 3)];
    }

    /** Home-currency unit cost. */
    public static function toHome(float $cost, float $rate, float $landed): float
    {
        return round($cost * $rate * (1 + $landed / 100), 4);
    }

    /** "USD 12.50" or the home money format. */
    public static function money($v, string $currency): string
    {
        return $currency === '' ? money($v) : $currency . ' ' . number_format((float) $v, 2);
    }
}
