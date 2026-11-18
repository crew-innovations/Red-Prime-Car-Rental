<?php

namespace App\Support\Site;

use App\Enums\TripStatus;
use App\Http\Resources\Api\FleetResource;
use App\Http\Resources\Api\PackageResource;
use App\Http\Resources\Api\YachtTripResource;
use App\Models\Car;
use App\Models\Package;
use App\Models\Trip;
use App\Models\Yacht;
use App\Support\Availability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the website shows, read straight from the CRM with the same public rules
 * and the same public fields as the website API (published, active, section on).
 * Nothing private ever reaches a page: everything goes through the API resources.
 */
class Catalog
{
    protected static function arr($resource, bool $detail = false): array
    {
        if ($detail) {
            $resource->additional(['detail' => true]);
        }

        return json_decode(json_encode($resource->toArray(request())), true);
    }

    /** @return Collection<int, array> */
    public static function cars(array $f = []): Collection
    {
        $cars = Car::query()->publiclyVisible()->with('media')->orderBy('sort_order')->orderBy('name')->get();

        $items = $cars->map(function (Car $car) use ($f) {
            $a = self::arr(new FleetResource($car));
            $a['_from'] = self::carFrom($a, $f['mode'] ?? null);
            $a['_available'] = null;
            if (! empty($f['start']) && ! empty($f['end'])) {
                $a['_available'] = ! Availability::restriction('car', $car->id)
                    && ! Availability::assetConflict('car', $car->id, $f['start'], $f['end'], null, false);
            }

            return $a;
        });

        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));

        $items = $items
            ->when($q !== '', fn ($c) => $c->filter(fn ($a) => str_contains(mb_strtolower(($a['name'] ?? '').' '.($a['brand'] ?? '').' '.($a['model'] ?? '').' '.($a['body'] ?? '')), $q)))
            ->when(! empty($f['brand']), fn ($c) => $c->filter(fn ($a) => in_array($a['brand'] ?? null, (array) $f['brand'], true)))
            ->when(! empty($f['body']), fn ($c) => $c->filter(fn ($a) => in_array($a['body'] ?? null, (array) $f['body'], true)))
            ->when(($f['mode'] ?? null) === 'self_drive', fn ($c) => $c->filter(fn ($a) => ! empty($a['self_drive'])))
            ->when(($f['mode'] ?? null) === 'with_driver', fn ($c) => $c->filter(fn ($a) => ! empty($a['with_driver'])))
            ->when(! empty($f['seats']), fn ($c) => $c->filter(fn ($a) => (int) ($a['seats'] ?? 0) >= (int) $f['seats']))
            ->when(! empty($f['max']), fn ($c) => $c->filter(fn ($a) => $a['_from'] !== null && $a['_from'] <= (float) $f['max']))
            ->when(! empty($f['available']) && ! empty($f['start']), fn ($c) => $c->filter(fn ($a) => $a['_available'] !== false));

        $items = match ($f['sort'] ?? null) {
            'price_asc' => $items->sortBy(fn ($a) => $a['_from'] ?? PHP_INT_MAX),
            'price_desc' => $items->sortByDesc(fn ($a) => $a['_from'] ?? -1),
            'power' => $items->sortByDesc(fn ($a) => (int) ($a['horsepower'] ?? 0)),
            'newest' => $items->sortByDesc(fn ($a) => (int) ($a['year'] ?? 0)),
            default => $items->sortByDesc(fn ($a) => ! empty($a['featured']))->values(),
        };

        return $items->values();
    }

    /** "From" price per day for a car (chauffeur price when that mode is chosen). */
    public static function carFrom(array $a, ?string $mode = null): ?float
    {
        $p = $a['prices'] ?? [];
        if ($mode === 'with_driver') {
            return isset($p['chauffeur_daily']) ? (float) $p['chauffeur_daily'] : (isset($p['daily']) && ! empty($a['with_driver']) ? (float) $p['daily'] : null);
        }

        return isset($p['daily']) ? (float) $p['daily'] : (isset($p['chauffeur_daily']) ? (float) $p['chauffeur_daily'] : null);
    }

    public static function car(string $slug): ?array
    {
        $car = Car::query()->publiclyVisible()->where('slug', $slug)->with('media')->first();

        return $car ? self::arr(new FleetResource($car), true) : null;
    }

    /** Filter options built from what is actually published. */
    public static function carOptions(): array
    {
        $all = self::cars();

        return [
            'brands' => $all->pluck('brand')->filter()->unique()->sort()->values()->all(),
            'bodies' => $all->pluck('body')->filter()->unique()->sort()->values()->all(),
            'seats' => $all->pluck('seats')->filter()->unique()->sort()->values()->all(),
            'max_price' => (float) ($all->pluck('_from')->filter()->max() ?? 0),
            'min_price' => (float) ($all->pluck('_from')->filter()->min() ?? 0),
        ];
    }

    /** @return Collection<int, array> */
    public static function yachts(array $f = []): Collection
    {
        $items = Yacht::query()->publiclyVisible()->with('media')->orderBy('sort_order')->orderBy('name')->get()
            ->map(function (Yacht $y) {
                $a = self::arr(new FleetResource($y));
                $p = $a['prices'] ?? [];
                $a['_from'] = isset($p['hourly']) ? (float) $p['hourly'] : (! empty($p) ? (float) min($p) : null);

                return $a;
            });

        $items = $items
            ->when(! empty($f['guests']), fn ($c) => $c->filter(fn ($a) => (int) ($a['guests'] ?? 0) >= (int) $f['guests']))
            ->when(! empty($f['marina']), fn ($c) => $c->filter(fn ($a) => ($a['marina'] ?? null) === $f['marina']));

        $items = match ($f['sort'] ?? null) {
            'price_asc' => $items->sortBy(fn ($a) => $a['_from'] ?? PHP_INT_MAX),
            'price_desc' => $items->sortByDesc(fn ($a) => $a['_from'] ?? -1),
            'size' => $items->sortByDesc(fn ($a) => (float) ($a['length_ft'] ?? 0)),
            default => $items,
        };

        return $items->values();
    }

    public static function yacht(string $slug): ?array
    {
        $y = Yacht::query()->publiclyVisible()->where('slug', $slug)->with('media')->first();

        return $y ? self::arr(new FleetResource($y), true) : null;
    }

    /** @return Collection<int, array> */
    public static function trips(): Collection
    {
        return Trip::query()->publiclyVisible()->where('status', TripStatus::Active)->with(['media', 'upcomingDates'])->orderBy('sort_order')->get()
            ->map(fn (Trip $t) => self::arr(new YachtTripResource($t)));
    }

    public static function trip(string $slug): ?array
    {
        $t = Trip::query()->publiclyVisible()->where('status', TripStatus::Active)->where('slug', $slug)->with(['media', 'upcomingDates'])->first();

        return $t ? self::arr(new YachtTripResource($t), true) : null;
    }

    /** @return Collection<int, array> */
    public static function packages(): Collection
    {
        return Package::query()->publiclyVisible()->active()->with(['media', 'category', 'upcomingDepartures'])->orderBy('sort_order')->get()
            ->map(fn (Package $p) => self::arr(new PackageResource($p)));
    }

    public static function package(string $slug): ?array
    {
        $p = Package::query()->publiclyVisible()->active()->where('slug', $slug)->with(['media', 'category', 'upcomingDepartures'])->first();

        return $p ? self::arr(new PackageResource($p), true) : null;
    }

    public static function transferServices(): array
    {
        return json_decode((new \App\Http\Controllers\Api\V1\CatalogController)->transferServices()->getContent(), true)['data'] ?? [];
    }

    public static function vehicleCategories(): array
    {
        return json_decode((new \App\Http\Controllers\Api\V1\CatalogController)->vehicleCategories()->getContent(), true)['data'] ?? [];
    }

    /** Brands with published cars: slug => [name, count]. */
    public static function brands(?Collection $cars = null): array
    {
        $counts = collect($cars ?? self::cars())
            ->map(fn ($c) => ['slug' => Site::brandSlug($c['brand'] ?? null, $c['name'] ?? null), 'brand' => $c['brand'] ?? null])
            ->filter(fn ($c) => $c['slug']);
        $out = [];
        // The showroom brands first (logos, always shown), then any other brand the CRM has.
        foreach (Site::BRANDS as $slug => $info) {
            $out[$slug] = ['name' => $info['name'], 'slug' => $slug, 'count' => $counts->where('slug', $slug)->count(),
                'logo' => Site::brandLogo($slug), 'photo' => Site::img($info['photo'])];
        }
        foreach ($counts->groupBy('slug')->sortKeys() as $slug => $g) {
            $out[$slug] ??= ['name' => $g->first()['brand'], 'slug' => $slug, 'count' => $g->count(), 'logo' => null, 'photo' => null];
        }

        return $out;
    }

    /** Published cars of one brand page. */
    public static function carsOfBrand(string $slug, ?Collection $cars = null): Collection
    {
        return collect($cars ?? self::cars())->filter(fn ($c) => Site::brandSlug($c['brand'] ?? null, $c['name'] ?? null) === $slug)->values();
    }

    /**
     * Longer-rental rates straight from the CRM prices (weekly price / 7, monthly price / 30
     * against the daily price). Only cars where the longer rate is really cheaper are listed.
     */
    public static function longRentalDeals(?Collection $cars = null): Collection
    {
        return collect($cars ?? self::cars())->map(function ($c) {
            $p = $c['prices'] ?? [];
            $daily = isset($p['daily']) ? (float) $p['daily'] : null;
            if (! $daily) {
                return null;
            }
            $week = isset($p['weekly']) ? round((float) $p['weekly'] / 7, 2) : null;
            $month = isset($p['monthly']) ? round((float) $p['monthly'] / 30, 2) : null;
            $week = $week && $week < $daily ? $week : null;
            $month = $month && $month < $daily ? $month : null;
            if (! $week && ! $month) {
                return null;
            }
            $best = min(array_filter([$week, $month]));

            return $c + ['_deal' => ['daily' => $daily, 'week_day' => $week, 'month_day' => $month,
                'save' => (int) floor((1 - $best / $daily) * 100)]];
        })->filter()->sortByDesc(fn ($c) => $c['_deal']['save'])->values();
    }

    /** Offers from the CRM with their picture and link worked out. */
    public static function offers(): array
    {
        $cars = Site::has('cars') ? self::cars()->keyBy('slug') : collect();

        return collect(Site::offers())->map(function ($o) use ($cars) {
            $car = filled($o['car'] ?? null) ? $cars->get($o['car']) : null;
            $o['image_url'] = $car['images']['cover']['large'] ?? (filled($o['image'] ?? null) ? Site::img($o['image']) : Site::img('fleet-garage.webp'));
            $o['link'] = filled($o['url'] ?? null) ? $o['url'] : ($car ? Site::route('car', ['slug' => $car['slug']]) : null);
            $o['car_name'] = $car['name'] ?? null;

            return $o;
        })->all();
    }

    /** Car types with published cars: slug => [name, count]. */
    public static function types(?Collection $cars = null): array
    {
        return collect($cars ?? self::cars())->filter(fn ($c) => filled($c['body'] ?? null))
            ->groupBy('body')->map(fn ($g, $name) => ['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'count' => $g->count()])
            ->sortByDesc('count')->keyBy('slug')->all();
    }

    /** Published cars for a service page (Site::SERVICES[...]['cars']). */
    public static function carsFor(?string $rule, int $limit = 8): Collection
    {
        if (! $rule) {
            return collect();
        }
        $cars = self::cars();

        return (match ($rule) {
            'with_driver' => $cars->filter(fn ($c) => ! empty($c['with_driver'])),
            'self_drive' => $cars->filter(fn ($c) => ! empty($c['self_drive'])),
            'monthly' => $cars->filter(fn ($c) => ! empty($c['prices']['monthly']))->whenEmpty(fn () => $cars),
            'delivery' => $cars->filter(fn ($c) => ! empty(Car::query()->where('slug', $c['slug'])->value('delivery_available')))->whenEmpty(fn () => $cars),
            'featured' => $cars->sortByDesc(fn ($c) => ! empty($c['featured'])),
            default => $cars,
        })->take($limit)->values();
    }

    /** Date range from the cars filter (pickup / return), or nulls. */
    public static function range(?string $from, ?string $to): array
    {
        try {
            $s = $from ? Carbon::parse($from) : null;
            $e = $to ? Carbon::parse($to) : null;
        } catch (\Throwable) {
            return [null, null];
        }

        return $s && $e && $e->greaterThan($s) ? [$s, $e] : [null, null];
    }
}
