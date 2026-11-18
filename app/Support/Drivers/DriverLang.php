<?php

namespace App\Support\Drivers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\App;

/** Language of the driver screens (4 Nov, 15; H-31): English, Urdu, Arabic. Only for drivers. */
class DriverLang
{
    public const NAMES = ['en' => 'English', 'ur' => 'اردو', 'ar' => 'العربية', 'hi' => 'हिन्दी'];

    public const RTL = ['ur', 'ar'];

    public static function available(): array
    {
        $on = array_values(array_intersect((array) Setting::read('driver_languages', ['en', 'ur', 'ar']), array_keys(self::NAMES)));

        return array_intersect_key(self::NAMES, array_flip(array_unique(['en', ...$on])));
    }

    public static function for(?User $user): string
    {
        $l = (string) ($user?->locale ?? 'en');

        return $user && $user->isDriverOnly() && array_key_exists($l, self::available()) ? $l : 'en';
    }

    public static function apply(?User $user): void
    {
        $l = self::for($user);
        if ($l !== App::getLocale()) {
            App::setLocale($l);
        }
    }

    public static function dir(): string
    {
        return in_array(App::getLocale(), self::RTL, true) ? 'rtl' : 'ltr';
    }
}
