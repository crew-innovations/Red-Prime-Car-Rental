<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Website language from the address: English at the root, Arabic under /ar. */
class SiteLocale
{
    public function handle(Request $request, Closure $next, string $locale = 'en'): Response
    {
        $locale = in_array($locale, (array) config('website.locales', ['en', 'ar']), true) ? $locale : 'en';
        App::setLocale($locale);
        \Illuminate\Support\Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
