<?php

namespace App\Libraries\Labels;

use App\Libraries\Core\BizException;

/**
 * Code 128 barcodes drawn as SVG (sharp at any print size, no image library needed).
 * Uses code set B (all printable ASCII), switching to code set C for runs of digits to keep labels short.
 */
final class Code128
{
    /** Bar and space widths of the 107 symbols (0-102 data, 103-105 start A/B/C, 106 stop). */
    private const P = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** Symbol values for $text, including start, checksum and stop. */
    public static function encode(string $text): array
    {
        if ($text === '' || strlen($text) > 80 || preg_match('/[^\x20-\x7E]/', $text)) {
            throw new BizException('A barcode holds 1 to 80 plain characters (letters, digits, - . / and so on).');
        }
        $codes = [];
        $set   = null;
        $i     = 0;
        $n     = strlen($text);
        while ($i < $n) {
            // a run of 4+ digits (or 2+ at the very start/end) is cheaper in set C
            preg_match('/^\d+/', substr($text, $i), $m);
            $run = isset($m[0]) ? strlen($m[0]) : 0;
            $useC = $run >= 4 || ($run >= 2 && $i + $run === $n && $set === 'C');
            if ($useC) {
                $even = $run - ($run % 2);
                if ($set !== 'C') {
                    $codes[] = $set === null ? 105 : 99;
                    $set = 'C';
                }
                for ($k = 0; $k < $even; $k += 2) {
                    $codes[] = (int) substr($text, $i + $k, 2);
                }
                $i += $even;
                continue;
            }
            if ($set !== 'B') {
                $codes[] = $set === null ? 104 : 100;
                $set = 'B';
            }
            $codes[] = ord($text[$i]) - 32;
            $i++;
        }
        $sum = $codes[0];
        for ($k = 1, $c = count($codes); $k < $c; $k++) {
            $sum += $codes[$k] * $k;
        }
        $codes[] = $sum % 103;
        $codes[] = 106;
        return $codes;
    }

    /** SVG of the barcode; $module = width of the narrowest bar in SVG units, quiet zone included. */
    public static function svg(string $text, int $height = 40, float $module = 1.0): string
    {
        $x     = 10 * $module;
        $rects = '';
        foreach (self::encode($text) as $code) {
            $bar = true;
            foreach (str_split(self::P[$code]) as $w) {
                $w = (int) $w * $module;
                if ($bar) {
                    $rects .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($w, 2) . '" height="' . $height . '"/>';
                }
                $x  += $w;
                $bar = ! $bar;
            }
        }
        $width = $x + 10 * $module;
        return '<svg xmlns="http://www.w3.org/2000/svg" class="barcode" viewBox="0 0 ' . round($width, 2) . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="Barcode ' . htmlspecialchars($text, ENT_QUOTES) . '">'
            . '<rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
    }
}
