<?php

namespace App\Libraries\Admin;

use App\Libraries\Core\Access;
use App\Libraries\Core\Audit;
use App\Libraries\Core\BizException;
use App\Libraries\Core\Db;
use App\Libraries\Core\Settings;
use App\Libraries\Core\Tenancy;

/**
 * Each organisation's colours (core 1.3, C2; the same rules as ChatuPOS beta9). This class writes the brand roles of
 * Warehouse's stylesheet (--patina buttons and links, its ink and wash, --forest and --forest-2 of the top bar, --lime
 * the highlight) as /brand.css, for light and dark. A ready palette, or the organisation's own main and highlight
 * colour, from which the tints are worked out. Colours that would make button text or highlights hard to read are
 * refused. Status colours (good, warning, bad) are not brand colours and stay the same everywhere.
 */
final class Theme
{
    /** name, primary, accent (the rest is worked out the same way as for the organisation's own colours) */
    public const PRESETS = [
        'green'      => ['Chatu green', '#1d7a34', '#7cf25e'],
        'teal'       => ['Teal', '#0f766e', '#ffd166'],
        'terracotta' => ['Terracotta', '#b4482a', '#ffb547'],
        'plum'       => ['Plum', '#7a3fa8', '#f2c94c'],
        'ocean'      => ['Ocean', '#1f5fa8', '#7fe3f0'],
        'charcoal'   => ['Charcoal', '#3f4a52', '#f5a524'],
    ];

    private const DARK_SURFACE = '#17211b';

    /** POS's brand roles, and the names Warehouse's stylesheet gives them. */
    private const MAP = ['primary' => 'patina', 'primary-ink' => 'patina-ink', 'primary-wash' => 'patina-wash', 'brand-dark' => 'forest', 'brand-mid' => 'forest-2', 'accent' => 'lime'];

    /** The organisation's choice: [key, primary, accent]. Outside an organisation: Chatu green. */
    public static function current(): array
    {
        if (Tenancy::id() === null) {
            return ['green', self::PRESETS['green'][1], self::PRESETS['green'][2]];
        }
        $key = (string) Settings::tenant('brand_theme', 'green');
        if ($key === 'custom') {
            return ['custom', (string) Settings::tenant('brand_primary', self::PRESETS['green'][1]), (string) Settings::tenant('brand_accent', self::PRESETS['green'][2])];
        }
        $p = self::PRESETS[$key] ?? self::PRESETS['green'];
        return [isset(self::PRESETS[$key]) ? $key : 'green', $p[1], $p[2]];
    }

    /** A short tag that changes with the colours, for the stylesheet's address (so browsers fetch it again). */
    public static function tag(): string
    {
        return substr(md5(implode('|', self::current())), 0, 10);
    }

    /** Save a ready palette, or the organisation's own colours (both #rrggbb). Refused when they do not read well. */
    public static function save(string $key, string $primary = '', string $accent = ''): void
    {
        Access::require('admin.settings');
        if (Tenancy::id() === null) {
            throw new BizException('Choose an organisation first.');
        }
        if ($key !== 'custom' && ! isset(self::PRESETS[$key])) {
            throw new BizException('Choose one of the colour themes, or your own colours.');
        }
        if ($key === 'custom') {
            $primary = self::hex($primary, 'Main colour');
            $accent  = self::hex($accent, 'Highlight colour');
            self::tokens($primary, $accent); // refuses what does not read
        }
        Db::atomic(static function () use ($key, $primary, $accent) {
            $before = self::current();
            Settings::setTenant('brand_theme', $key);
            if ($key === 'custom') {
                Settings::setTenant('brand_primary', $primary);
                Settings::setTenant('brand_accent', $accent);
            }
            Audit::log('settings.theme', 'settings', null, ['theme' => $before], ['theme' => self::current()]);
        });
    }

    /** The /brand.css text for the organisation's colours. */
    public static function css(): string
    {
        [$key, $p, $a] = self::current();
        if ($key === 'green') {
            return "/* Chatu green: the stylesheet's own colours */\n";
        }
        try {
            $t = self::tokens($p, $a);
        } catch (BizException $e) {
            $t = self::tokens(self::PRESETS['green'][1], self::PRESETS['green'][2]);
        }
        $out = ":root {\n";
        foreach ($t['light'] as $k => $v) {
            if (isset(self::MAP[$k])) {
                $out .= '  --' . self::MAP[$k] . ": {$v};\n";
            }
        }
        $out .= "}\n:root[data-theme=\"dark\"] {\n";
        foreach ($t['dark'] as $k => $v) {
            if (isset(self::MAP[$k])) {
                $out .= '  --' . self::MAP[$k] . ": {$v};\n";
            }
        }
        return $out . "}\n";
    }

    /**
     * All brand roles from a primary and an accent colour, for light and dark mode. White text must read on the
     * primary (4.5:1) and on the dark brand colour; the accent must stand out on the dark brand colour (3:1).
     */
    public static function tokens(string $primary, string $accent): array
    {
        $white = '#ffffff';
        if (self::contrast($white, $primary) < 4.5) {
            throw new BizException('The main colour ' . $primary . ' is too light: white text on buttons would be hard to read. Choose a darker shade.');
        }
        $dark   = self::mix($primary, '#000000', 0.62);
        $mid    = self::mix($primary, '#000000', 0.40);
        $strong = self::mix($primary, '#000000', 0.22);
        if (self::contrast($accent, $dark) < 3.0) {
            throw new BizException('The highlight colour ' . $accent . ' does not stand out on the dark top bar. Choose a brighter one.');
        }
        $wash = self::mix($primary, '#ffffff', 0.88);
        $ink  = self::mix($primary, '#000000', 0.30);
        for ($i = 0; self::contrast($ink, $wash) < 4.5 && $i < 10; $i++) {
            $ink = self::mix($ink, '#000000', 0.15);
        }
        $dWash = self::mix($primary, self::DARK_SURFACE, 0.78);
        $dInk  = self::mix($primary, '#ffffff', 0.55);
        for ($i = 0; self::contrast($dInk, self::DARK_SURFACE) < 4.5 && $i < 10; $i++) {
            $dInk = self::mix($dInk, '#ffffff', 0.2);
        }
        $accentInk = self::contrast('#0b1510', $accent) >= self::contrast($white, $accent) ? '#0b1510' : $white;
        $header    = self::mix($primary, '#ffffff', 0.80);
        $base = ['primary' => $primary, 'primary-strong' => $strong, 'brand-dark' => $dark, 'brand-mid' => $mid, 'accent' => $accent, 'accent-ink' => $accentInk, 'header-ink' => $header];
        return [
            'light' => $base + ['primary-ink' => $ink, 'primary-wash' => $wash],
            'dark'  => ['primary-ink' => $dInk, 'primary-wash' => $dWash, 'brand-dark' => self::mix($primary, '#000000', 0.72)],
        ];
    }

    private static function hex(string $v, string $what): string
    {
        $v = strtolower(trim($v));
        if (preg_match('/^#?([0-9a-f]{3})$/', $v, $m)) {
            $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (! preg_match('/^#?[0-9a-f]{6}$/', $v)) {
            throw new BizException($what . ': give a colour such as #1d7a34.');
        }
        return '#' . ltrim($v, '#');
    }

    /** $a mixed toward $b by $t (0: $a, 1: $b). */
    public static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return sprintf('#%02x%02x%02x', (int) round($r1 + ($r2 - $r1) * $t), (int) round($g1 + ($g2 - $g1) * $t), (int) round($b1 + ($b2 - $b1) * $t));
    }

    /** WCAG contrast ratio of two colours. */
    public static function contrast(string $a, string $b): float
    {
        $l1 = self::lum($a);
        $l2 = self::lum($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    private static function lum(string $hex): float
    {
        $c = array_map(static function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
}
