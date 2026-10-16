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
