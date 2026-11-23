<?php

use App\Libraries\Core\Access;
use App\Libraries\Core\Settings;

if (! function_exists('money')) {
    /** Format an amount with the currency symbol. */
    function money($v, bool $symbol = true): string
    {
        $f = (float) ($v ?? 0);
        return ($f < 0 ? '-' : '') . ($symbol ? Settings::get('currency_symbol') : '') . number_format(abs($f), 2);
    }

    /** A quantity with as many decimals as the item uses (or as needed). */
    function qty($v, ?int $decimals = null): string
    {
        $f = (float) ($v ?? 0);
        if ($decimals === null) {
            $decimals = abs($f - round($f)) < 0.0005 ? 0 : 3;
        }
        $s = number_format($f, $decimals);
        return $decimals > 0 ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    function can(string $perm, ?int $warehouseId = null): bool
    {
        return $warehouseId ? Access::canAt($warehouseId, $perm) : Access::can($perm);
    }

    /** One-time token for a form, so a double submit is done once. */
    function op_token(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    function token_field(): string
    {
        return '<input type="hidden" name="op_token" value="' . op_token() . '">';
    }

    /** UTC timestamp to local time for display. */
    function local_time(?string $utc, string $fmt = 'j M Y H:i'): string
    {
        if (! $utc) {
            return '';
        }
        $d = new DateTime(substr($utc, 0, 19), new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(config('App')->appTimezone));
        return $d->format($fmt);
    }

    function nice_date(?string $d): string
    {
        return $d ? date('j M Y', strtotime($d)) : '';
    }

    function state_label(string $s): string
    {
        return [
            'draft' => 'Draft', 'open' => 'Open', 'partly_received' => 'Partly received', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
            'allocated' => 'Allocated', 'in_transit' => 'In transit', 'confirmed' => 'Confirmed', 'in_progress' => 'Being prepared', 'dispatched' => 'Dispatched',
            'delivered' => 'Delivered', 'picked' => 'Picked', 'packed' => 'Packed', 'failed' => 'Delivery failed', 'posted' => 'Posted', 'voided' => 'Voided',
            'pending' => 'Waiting for approval', 'rejected' => 'Rejected', 'submitted' => 'Submitted', 'available' => 'Available', 'quarantine' => 'Quarantine',
            'damaged' => 'Damaged', 'hold' => 'On hold', 'on_hold' => 'On hold', 'recalled' => 'Recalled', 'active' => 'Active', 'blocked' => 'Blocked', 'inactive' => 'Inactive',
            'planned' => 'Planned', 'on_road' => 'On the road', 'completed' => 'Completed', 'on_trip' => 'On a trip', 'in_service' => 'In the workshop', 'retired' => 'Retired',
            'in_stock' => 'In stock', 'returned_supplier' => 'Returned to supplier', 'scrapped' => 'Written off', 'consumed' => 'Used in production', 'lost' => 'Lost',
            'done' => 'Done',
        ][$s] ?? ucfirst(str_replace('_', ' ', $s));
    }

    function status_tone(string $s): string
    {
        return match ($s) {
            'received', 'delivered', 'posted', 'completed', 'available', 'active', 'in_stock', 'done' => 'good',
            'cancelled', 'rejected', 'failed', 'voided', 'recalled', 'damaged', 'lost', 'scrapped', 'blocked', 'retired' => 'bad',
            'pending', 'submitted', 'in_transit', 'partly_received', 'quarantine', 'hold', 'on_hold', 'on_road', 'on_trip', 'in_service', 'allocated', 'picked', 'packed', 'in_progress' => 'warn',
            default => 'quiet',
        };
    }

    function badge(string $s): string
    {
        return '<span class="badge ' . status_tone($s) . '">' . esc(state_label($s)) . '</span>';
    }

    /** Item code plate (the one decorative element, kept from Chatu Assets). */
    function sku_plate(string $sku, ?string $href = null): string
    {
        $inner = '<span class="tag-plate">' . esc($sku) . '</span>';
        return $href ? '<a class="tag-link" href="' . esc($href, 'attr') . '">' . $inner . '</a>' : $inner;
    }

    function wh_name(int $id): string
    {
        $w = \App\Libraries\Core\Db::row('SELECT code, name FROM {warehouses} WHERE id = ?', [$id]);
        return $w ? $w['code'] . ' ' . $w['name'] : '';
    }

    /** " (10 carton)" after a quantity entered in a pack. */
    function pack_hint($qty, ?string $unit, $factor): string
    {
        if (! $unit || (float) $factor <= 1) {
            return '';
        }
        $n = (float) $qty / (float) $factor;
        return ' <span class="muted small">(' . (abs($n - round($n)) < 0.0005 ? number_format($n) : number_format($n, 2)) . ' ' . esc($unit) . ')</span>';
    }

    /** Days until a date, as a short phrase; negative = past. */
    function days_left(?string $d): string
    {
        if (! $d) {
            return '';
        }
        $n = (int) floor((strtotime($d) - strtotime(date('Y-m-d'))) / 86400);
        return $n < 0 ? abs($n) . ' days ago' : ($n === 0 ? 'today' : 'in ' . $n . ' days');
    }
}
