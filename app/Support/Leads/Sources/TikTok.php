<?php

namespace App\Support\Leads\Sources;

/** TikTok Instant Form leads (TikTok Leads Center / Business API webhook). */
class TikTok extends SignedWebhook
{
    public static function key(): string
    {
        return 'tiktok';
    }

    protected static function label(): string
    {
        return 'TikTok';
    }

    protected static function defaultHeader(): string
    {
        return 'X-TikTok-Signature';
    }
}
