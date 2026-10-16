<?php

namespace App\Support;

/** Small helpers for UAE-style phone numbers. */
class Phone
{
    /** Digits only, with a UAE country code added to local numbers (05x... => 9715x...). */
    public static function digits(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '971'.substr($digits, 1);
        }

        return $digits ?: null;
    }

    /** Last 9 digits: matches +971 50…, 050…, 0097150… and spaced versions. */
    public static function key(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -9) : null;
    }

    /** Add "any of these columns holds the same number" to a query (spaces, +971, 0 ignored). */
    public static function whereMatches($query, array $columns, ?string $phone)
    {
        $key = self::key($phone);
        if (! $key) {
            return $query;
        }

        return $query->where(function ($q) use ($columns, $key) {
            foreach ($columns as $column) {
                $q->orWhereRaw("RIGHT(REGEXP_REPLACE(COALESCE({$column}, ''), '[^0-9]', ''), 9) = ?", [$key]);
            }
        });
    }

    public static function whatsappUrl(?string $phone, ?string $message = null): ?string
    {
        $digits = self::digits($phone);

        if (! $digits) {
            return null;
        }

        return 'https://wa.me/'.$digits.($message ? '?text='.rawurlencode($message) : '');
    }

    public static function telUrl(?string $phone): ?string
    {
        $digits = self::digits($phone);

        return $digits ? 'tel:+'.$digits : null;
    }
}
