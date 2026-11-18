<?php

namespace App\Support\Push;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Setting;

/**
 * The server's Web Push keys (VAPID, 4 Nov 16.3). Made once (automatically on first use, or from
 * Settings › Apps), stored encrypted with the other integration keys. "Regenerate keys" makes every
 * device allow notifications again.
 */
class Vapid
{
    public const PROVIDER = 'webpush';

    /** @return array{private: string, public: string} public = 65 raw bytes */
    public static function keys(bool $create = true): ?array
    {
        $i = Integration::for(self::PROVIDER);
        $private = $i->secret('vapid_private');
        $public = $i->secret('vapid_public');
        if ($private && $public) {
            return ['private' => $private, 'public' => WebPush::unb64($public)];
        }

        return $create ? self::generate() : null;
    }

    public static function publicKey(): ?string
    {
        $k = self::keys();

        return $k ? WebPush::b64($k['public']) : null;
    }

    public static function generate(): array
    {
        [$pem, $public] = WebPush::newKeyPair();
        $i = Integration::for(self::PROVIDER);
        $i->fill(['enabled' => true, 'mode' => 'live', 'status' => 'connected', 'config' => []]);
        $i->secrets = ['vapid_private' => $pem, 'vapid_public' => WebPush::b64($public)];
        $i->save();
        rescue(fn () => AuditLog::event('integration', 'Web push keys created'), report: false);

        return ['private' => $pem, 'public' => $public];
    }

    /** Who the push services can contact about this server. */
    public static function subject(): string
    {
        $email = Setting::read('company_email') ?: Setting::read('website_email');

        return $email ? 'mailto:'.$email : (string) config('app.url');
    }
}
