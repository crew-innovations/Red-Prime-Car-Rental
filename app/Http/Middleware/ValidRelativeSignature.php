<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 20 Nov (Bilal, item 10: "403 Invalid signature" when opening documents). Laravel's own check rebuilds
 * the signed text from the server's raw QUERY_STRING; on the live server (LiteSpeed, two rewrites) that
 * string does not come back exactly as signed, so every document link was refused, fresh or not.
 *
 * Our links (private files, login invites, unsubscribe) sign only the path and the expiry time, so this
 * checks exactly that: HMAC over "/path?expires=…" (or "/path" without expiry) with the app key, ignoring
 * any extra parameter a server or proxy adds. Laravel's normal check is tried first. An expired link says
 * so plainly. Who may open the file is still checked after this (login + App\Support\PrivateFiles).
 */
class ValidRelativeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidSignature(false)) {
            return $next($request);
        }

        $signature = (string) $request->query('signature', '');
        $expires = $request->query('expires');
        if ($expires !== null && ctype_digit((string) $expires) && (int) $expires < now()->getTimestamp()) {
            abort(403, 'This link has expired. Go back to the page and open it again.');
        }

        $suffix = $expires !== null ? '?expires='.$expires : '';
        $paths = array_unique(array_filter([
            '/'.ltrim($request->path(), '/'),
            '/'.ltrim(rawurldecode($request->path()), '/'),
            (string) parse_url((string) $request->getRequestUri(), PHP_URL_PATH),
        ]));
        $keys = array_filter([config('app.key'), ...array_filter((array) config('app.previous_keys', []))]);
        foreach ($paths as $path) {
            foreach ($keys as $key) {
                if ($signature !== '' && hash_equals(hash_hmac('sha256', $path.$suffix, $key), $signature)) {
                    return $next($request);
                }
            }
        }

        Log::warning('Signed link refused', [
            'route' => $request->route()?->getName(),
            'path' => $request->path(),
            'request_uri' => mb_substr((string) $request->getRequestUri(), 0, 300),
            'query_keys' => array_keys($request->query()),
            'raw_query_keys' => collect(explode('&', (string) $request->server('QUERY_STRING')))->map(fn ($p) => strtok($p, '='))->values()->all(),
            'base_url' => $request->getBaseUrl(),
        ]);
        abort(403, 'Invalid signature.');
    }
}
