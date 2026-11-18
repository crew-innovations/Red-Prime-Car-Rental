<?php

namespace App\Support\Maps;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Maps for limousine and transfer quotes (brief 28 Oct-2, 4.3).
 *  - route(): road distance and time from the Routes API (computeRoutes). Never
 *    a straight line: when Google can't answer, staff type the distance and the
 *    booking is marked "manual".
 *  - search(): address suggestions from Places API (New) autocomplete, called
 *    from the server so the server key never reaches the browser.
 *  - embedUrl(): the route map, with the restricted browser key only.
 * Results are stored on the quotation / booking as the record of how its price
 * was worked out; nothing else is cached.
 */
class GoogleMaps
{
    public const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';

    public const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** "25.2048,55.2708" (a pin on the map) → [lat, lng], or null when it is an address. */
    public static function ll(?string $text): ?array
    {
        if (! preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', (string) $text, $m)) {
            return null;
        }
        $lat = (float) $m[1];
        $lng = (float) $m[2];

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }

    /** 11 Nov (limousine): where an address is, to move the map picker there. Null when unknown. @return array{lat: float, lng: float}|null */
    public static function locate(string $text): ?array
    {
        $i = self::integration();
        $text = mb_substr(trim($text), 0, 200);
        if (! $i->isReady() || mb_strlen($text) < 3) {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember('gmaps:loc:'.sha1(mb_strtolower($text)), now()->addDay(), function () use ($i, $text) {
            try {
                $r = Http::timeout(6)->get(self::GEOCODE_URL, ['address' => $text, 'region' => (string) $i->config('region', 'ae'), 'key' => $i->secret('server_key')]);
            } catch (\Throwable) {
                return null;
            }
            $at = $r->successful() ? $r->json('results.0.geometry.location') : null;

            return isset($at['lat'], $at['lng']) ? ['lat' => (float) $at['lat'], 'lng' => (float) $at['lng']] : null;
        });
    }

    /** A route point for Google: the exact pin when given, else the address. */
    protected static function waypoint(string $place): array
    {
        $ll = self::ll($place);

        return $ll ? ['location' => ['latLng' => ['latitude' => $ll[0], 'longitude' => $ll[1]]]] : ['address' => $place];
    }

    /** 11 Nov (limousine): the address of a pin (map or "my location"). Null when Google is off or finds nothing. */
    public static function reverse(float $lat, float $lng): ?string
    {
        $i = self::integration();
        if (! $i->isReady() || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }
        $key = 'gmaps:rev:'.round($lat, 4).','.round($lng, 4);

        return \Illuminate\Support\Facades\Cache::remember($key, now()->addDay(), function () use ($i, $lat, $lng) {
            try {
                $r = Http::timeout(6)->get(self::GEOCODE_URL, ['latlng' => $lat.','.$lng, 'key' => $i->secret('server_key'), 'language' => 'en']);
            } catch (\Throwable) {
                return null;
            }
            $results = $r->successful() ? (array) $r->json('results', []) : [];
            // A plus code ("6789+XQ Dubai") says little to a driver: prefer the first real address.
            $best = collect($results)->first(fn ($x) => ! in_array('plus_code', (array) ($x['types'] ?? []), true)) ?? ($results[0] ?? null);

            return filled($best['formatted_address'] ?? null) ? mb_substr((string) $best['formatted_address'], 0, 255) : null;
        });
    }

    public static function integration(): Integration
    {
        return Integration::for('google_maps');
    }

    public static function ready(): bool
    {
        return self::integration()->isReady();
    }

    /**
     * @param  list<string>  $stops
     * @return array{km: float, minutes: int, legs: list<array{km: float, minutes: int}>, source: string}
     */
    public static function route(string $from, string $to, array $stops = [], ?Carbon $departAt = null, ?Integration $i = null): array
    {
        // 6 Nov (limousine): the same route within 30 minutes is not asked twice (website prices every class, Google bills per call).
        if (! $i && trim($from) !== '' && trim($to) !== '') {
            $key = 'gmaps:route:'.sha1(json_encode([mb_strtolower(trim($from)), mb_strtolower(trim($to)), array_values($stops), $departAt?->format('Y-m-d H')]));

            return \Illuminate\Support\Facades\Cache::remember($key, now()->addMinutes(30), fn () => self::routeLive($from, $to, $stops, $departAt));
        }

        return self::routeLive($from, $to, $stops, $departAt, $i);
    }

    protected static function routeLive(string $from, string $to, array $stops = [], ?Carbon $departAt = null, ?Integration $i = null): array
    {
        $i ??= self::integration();
        if (! $i->isReady()) {
            throw new RuntimeException('Google Maps is not set up (Settings › Integrations). Type the distance by hand.');
        }
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '') {
            throw new RuntimeException('Enter the pickup and the destination address first.');
        }

        $body = [
            'origin' => self::waypoint($from),
            'destination' => self::waypoint($to),
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
            'computeAlternativeRoutes' => false,
            'units' => 'METRIC',
            'languageCode' => 'en',
            'regionCode' => (string) $i->config('region', 'ae'),
        ];
        $stops = array_values(array_filter(array_map('trim', $stops)));
        if ($stops) {
            $body['intermediates'] = array_map(fn ($s) => self::waypoint($s), $stops);
        }
        if ($departAt && $departAt->isFuture()) {
            $body['departureTime'] = $departAt->copy()->utc()->format('Y-m-d\TH:i:s\Z'); // traffic estimate for that time
        }

        $send = fn () => Http::timeout(12)->withHeaders([
            'X-Goog-Api-Key' => $i->secret('server_key'),
            'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.legs.distanceMeters,routes.legs.duration',
        ])->post(self::ROUTES_URL, $body);
        try {
            try {
                $response = $send();
            } catch (\Illuminate\Http\Client\ConnectionException) {
                usleep(400000);
                $response = $send(); // one retry after a network hiccup
            }
        } catch (\Throwable $e) {
            IntegrationEvent::log('google_maps', 'out', 'failed', 'route', null, [], 'Could not reach Google: '.$e->getMessage());
            throw new RuntimeException('Google Maps did not answer. Try again, or type the distance by hand.');
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('error.message') ?? $response->status());
            IntegrationEvent::log('google_maps', 'out', 'failed', 'route', null, ['status' => $response->status()], $message);
            if (in_array($response->status(), [401, 403], true)) {
                $i->forceFill(['status' => 'error', 'last_error' => mb_substr($message, 0, 500)])->save();
            }
            throw new RuntimeException($response->status() === 400 ? 'Google could not find one of these addresses. Check them, or type the distance by hand.' : 'Google Maps error: '.$message);
        }

        $route = $response->json('routes.0');
        if (! $route || ! isset($route['distanceMeters'])) {
            IntegrationEvent::log('google_maps', 'out', 'failed', 'route', null, [], 'No route found');
            throw new RuntimeException('No road route found between these addresses. Check them, or type the distance by hand.');
        }

        $meters = (int) $route['distanceMeters'];
        if ($meters <= 0) {
            IntegrationEvent::log('google_maps', 'out', 'failed', 'route', null, [], 'Route with no distance');
            throw new RuntimeException('Google returned a route without a distance. Check the addresses, or type the distance by hand.');
        }
        IntegrationEvent::log('google_maps', 'out', 'ok', 'route', null, ['meters' => $meters, 'stops' => count($stops)]);

        return [
            'km' => self::km($meters),
            'meters' => $meters,
            'minutes' => self::minutes($route['duration'] ?? '0s'),
            'legs' => collect($route['legs'] ?? [])->map(fn ($l) => ['km' => self::km((int) ($l['distanceMeters'] ?? 0)), 'meters' => (int) ($l['distanceMeters'] ?? 0), 'minutes' => self::minutes($l['duration'] ?? '0s')])->all(),
            'source' => 'google',
        ];
    }

    /**
     * Metres → billed km (Bilal, 29 Oct: to 0.1 km). Business settings › "Road distance rounding":
     *  0.1   to the nearest 0.1 km (28.64 → 28.6)
     *  up_1  up to the next whole km (28.1 → 29)
     *  0.01  two decimals (28.64)
     */
    public static function km(int $meters): float
    {
        $km = $meters / 1000;

        return match ((string) \App\Models\Setting::read('transfer_km_rounding', '0.1')) {
            'up_1' => (float) ceil(round($km, 3)),
            '0.01' => round($km, 2),
            default => round($km, 1),
        };
    }

    /** Proof that a distance came from Google for these addresses (checked on every save). */
    public static function stamp(string $from, string $to, array $stops, float $km, ?float $returnKm = null, array $extra = []): array
    {
        return $extra + ['km' => $km, 'return_km' => $returnKm, 'at' => now()->toDateTimeString(), 'sig' => self::signature($from, $to, $stops, $km, $returnKm)];
    }

    public static function signature(?string $from, ?string $to, array $stops, ?float $km, ?float $returnKm): string
    {
        $norm = fn ($v) => mb_strtolower(trim((string) $v));
        $payload = implode('|', [$norm($from), $norm($to), implode('>', array_map($norm, $stops)), number_format((float) $km, 2, '.', ''), $returnKm === null ? '' : number_format($returnKm, 2, '.', '')]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public static function stampValid(?array $route, ?string $from, ?string $to, array $stops, ?float $km, ?float $returnKm): bool
    {
        $sig = (string) data_get($route, 'sig');

        return $sig !== '' && $km !== null && hash_equals(self::signature($from, $to, $stops, $km, $returnKm), $sig);
    }

    /** "1234s" → 21 minutes. */
    public static function minutes(string $duration): int
    {
        return (int) ceil(((float) rtrim($duration, 's')) / 60);
    }

    /** @return array<string, string> address => address (for a search field) */
    public static function search(string $text): array
    {
        $i = self::integration();
        $text = trim($text);
        if (! $i->isReady() || mb_strlen($text) < 3) {
            return [];
        }
        try {
            $response = Http::timeout(6)->withHeaders(['X-Goog-Api-Key' => $i->secret('server_key')])
                ->post(self::AUTOCOMPLETE_URL, ['input' => mb_substr($text, 0, 200), 'includedRegionCodes' => [(string) $i->config('region', 'ae')], 'languageCode' => 'en']);
        } catch (\Throwable) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('suggestions', []))
            ->map(fn ($s) => $s['placePrediction']['text']['text'] ?? null)->filter()->unique()->take(8)
            ->mapWithKeys(fn ($a) => [$a => $a])->all();
    }

    /** The restricted browser key for maps inside the CRM (live drivers map), or null. */
    public static function browserKey(): ?string
    {
        $i = self::integration();

        return $i->enabled ? ($i->secret('browser_key') ?: null) : null;
    }

    /** Map preview inside the CRM (restricted browser key), or null. */
    public static function embedUrl(?string $from, ?string $to, array $stops = []): ?string
    {
        $i = self::integration();
        $key = $i->enabled ? $i->secret('browser_key') : null;
        if (! $key || blank($from) || blank($to)) {
            return null;
        }
        $q = ['key' => $key, 'origin' => $from, 'destination' => $to, 'mode' => 'driving'];
        if ($stops = array_values(array_filter($stops))) {
            $q['waypoints'] = implode('|', $stops);
        }

        return 'https://www.google.com/maps/embed/v1/directions?'.http_build_query($q);
    }

    /** "Open in Google Maps" link: needs no key, also used on the driver's phone. */
    public static function directionsLink(?string $from, ?string $to, array $stops = []): ?string
    {
        if (blank($to) && blank($from)) {
            return null;
        }
        $q = array_filter(['api' => 1, 'origin' => $from, 'destination' => $to ?: $from, 'travelmode' => 'driving']);
        if ($stops = array_values(array_filter($stops))) {
            $q['waypoints'] = implode('|', $stops);
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($q);
    }

    /** Connection test for Settings › Integrations: a real route between two Dubai landmarks. */
    public static function test(Integration $i): array
    {
        if (! $i->hasSecret('server_key')) {
            return [false, 'Save the server key first.', null];
        }
        $was = $i->enabled;
        $i->enabled = true; // test before switching it on
        try {
            $r = self::route('Dubai Mall, Dubai', 'Dubai International Airport Terminal 3', [], null, $i);

            return [true, null, 'Routes API working ('.$r['km'].' km test route)'.($i->hasSecret('browser_key') ? '' : ' · no browser key: map preview off')];
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), null];
        } finally {
            $i->enabled = $was;
        }
    }
}
