<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Support\Push\Apps;
use Illuminate\Http\Response;

/** Installable apps (4 Nov, 16): manifests, the service worker and the install pages. */
class AppController extends Controller
{
    public function manifest(string $app)
    {
        abort_unless(in_array($app, Apps::KEYS, true), 404);

        return response()->json(Apps::manifest($app), 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'no-cache']);
    }

    /** One service worker; each app registers it for its own scope. */
    public function worker(): Response
    {
        $version = collect(Apps::KEYS)->map(fn ($a) => Apps::get($a)['version'])->implode('-');

        return response(view('pwa.sw', ['version' => $version])->render(), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function offline()
    {
        return response()->view('pwa.offline', [], 200, ['Cache-Control' => 'no-cache']);
    }

    public function install(string $app)
    {
        abort_unless(in_array($app, Apps::KEYS, true), 404);

        return response()->view('pwa.install', ['app' => Apps::get($app), 'qr' => \App\Support\Documents\Pdf::qr(Apps::installUrl($app))], 200,
            ['X-Robots-Tag' => 'noindex']);
    }
}
