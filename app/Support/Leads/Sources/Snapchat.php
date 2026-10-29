<?php

namespace App\Support\Leads\Sources;

/** Snapchat Lead Generation forms (Snap Ads webhook). */
class Snapchat extends SignedWebhook
{
    public static function key(): string
    {
        return 'snapchat';
    }

    protected static function label(): string
    {
        return 'Snapchat';
    }

    protected static function defaultHeader(): string
    {
        return 'X-Snap-Signature';
    }
}
