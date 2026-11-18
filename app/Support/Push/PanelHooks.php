<?php

namespace App\Support\Push;

use Illuminate\Support\HtmlString;

/** Manifest + app script for the signed-in panels (4 Nov, 16). Nothing on the login page itself. */
class PanelHooks
{
    public static function head(): HtmlString
    {
        $app = Apps::get(Apps::for(auth()->user()));

        return new HtmlString(
            '<link rel="manifest" href="'.e(url('/manifest/'.$app['key'].'.webmanifest')).'">'
            .'<meta name="theme-color" content="'.e($app['theme']).'">'
            .'<meta name="apple-mobile-web-app-capable" content="yes">'
            .'<meta name="apple-mobile-web-app-title" content="'.e($app['short_name']).'">'
            .'<link rel="apple-touch-icon" href="'.e(Apps::iconUrl($app['key'], 'apple-touch-180.png')).'">'
        );
    }

    public static function body(): HtmlString
    {
        $user = auth()->user();
        if (! $user) {
            return new HtmlString('');
        }
        $app = Apps::get(Apps::for($user));
        if ($app['key'] === 'driver') {
            \App\Support\Drivers\DriverLang::apply($user); // 4 Nov (15): the driver's language
        }
        $cfg = [
            'app' => $app['key'],
            'scope' => $app['scope'],
            'version' => $app['version'],
            'vapid' => rescue(fn () => Vapid::publicKey(), null, false),
            'deviceUrl' => url('/device'),
            'alwaysAsk' => $app['key'] === 'driver',
            'texts' => [
                'allow' => $app['key'] === 'driver' ? __('driver.allow_push') : ($app['key'] === 'customer' ? 'Allow notifications for updates on your booking.' : 'Allow notifications for new leads, payments and approvals.'),
                'allowButton' => $app['key'] === 'driver' ? __('driver.allow_button') : 'Allow notifications',
                'iosHint' => $app['key'] === 'driver' ? __('driver.ios_hint') : 'Tip: tap Share › "Add to Home Screen" to install the app and get notifications.',
            ],
        ];

        return new HtmlString('<script>window.RP_PWA='.json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).';</script>'
            .'<script src="'.e(asset('js/pwa.js')).'?v='.@filemtime(public_path('js/pwa.js')).'" defer></script>');
    }
}
