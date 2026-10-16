<?php

namespace App\Support;

use App\Models\Setting;

/** VAT is only applied when it is switched on in Settings; no rate is assumed. */
class Tax
{
    public static function enabled(): bool
    {
        return (bool) Setting::read('vat_enabled') && (float) Setting::read('vat_rate') > 0;
    }

    public static function defaultRate(): ?float
    {
        return self::enabled() ? (float) Setting::read('vat_rate') : null;
    }

    public static function defaultInclusive(): bool
    {
        return (bool) Setting::read('vat_inclusive');
    }

    /** @return array{0: float, 1: float} [tax, total] for a net amount. */
    public static function apply(float $amount, ?float $rate, bool $inclusive): array
    {
        $amount = max(0, round($amount, 2));
        if (! $rate || $rate <= 0) {
            return [0.0, $amount];
        }

        if ($inclusive) {
            $tax = round($amount - $amount / (1 + $rate / 100), 2);

            return [$tax, $amount];
        }

        $tax = round($amount * $rate / 100, 2);

        return [$tax, round($amount + $tax, 2)];
    }
}
