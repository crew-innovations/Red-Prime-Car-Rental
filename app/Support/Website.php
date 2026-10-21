<?php

namespace App\Support;

use App\Models\Setting;

/** The public website's connection to the CRM (API access). */
class Website
{
    /** Browser origins allowed to call the API: .env list plus Business settings, normalised. */
    public static function allowedOrigins(): array
    {
        $fromEnv = (array) config('cors.allowed_origins', []);
        $fromSettings = (array) Setting::read('website_origins', []);

        return array_values(array_unique(array_filter(array_map(
            fn ($o) => rtrim(trim((string) $o), '/'),
            [...$fromEnv, ...$fromSettings],
        ))));
    }
}
