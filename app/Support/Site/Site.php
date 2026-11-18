<?php

namespace App\Support\Site;

use App\Models\Category;
use App\Models\Setting;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/**
 * Small helpers for the public website: links in the current language, the
 * language switch, contact details from Business settings and which sections
 * are switched on in the CRM.
 */
class Site
{
    public static function locale(): string
    {
        return App::getLocale() === 'ar' ? 'ar' : 'en';
    }

    public static function rtl(): bool
    {
        return self::locale() === 'ar';
    }

    /** A website page in the given (or current) language. */
    public static function route(string $name, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::locale();

        return route(($locale === 'ar' ? 'site.ar.' : 'site.').$name, $params);
    }

    /** The current page in the other language (or the given one). */
    public static function switchUrl(?string $to = null): string
    {
        $to ??= self::locale() === 'ar' ? 'en' : 'ar';
        $current = Route::current();
        $name = (string) ($current?->getName() ?? 'site.home');
        $base = preg_replace('/^site\.(ar\.)?/', '', $name) ?: 'home';
        if (! Route::has('site.'.$base)) {
            $base = 'home';
        }
        $url = self::route($base, $current?->parameters() ?? [], $to);
        $query = request()->getQueryString();

        return $query ? $url.'?'.$query : $url;
    }

    /** Is this page (by route name, without the language part) the current one? */
    public static function is(string ...$names): bool
    {
        $name = preg_replace('/^site\.(ar\.)?/', '', (string) Route::currentRouteName());

        return in_array($name, $names, true);
    }

    /** Is this request for the website (its domain, or /site locally)? */
    public static function isSiteRequest(\Illuminate\Http\Request $request): bool
    {
        $domain = config('website.domain');
        if ($domain) {
            return in_array($request->getHost(), [$domain, 'www.'.$domain], true);
        }
        $path = trim($request->path(), '/');

        return $path === config('website.path', 'site') || str_starts_with($path, config('website.path', 'site').'/');
    }

    /** Language of a website request from its address (used when no route matched). */
    public static function localeFromPath(\Illuminate\Http\Request $request): string
    {
        $path = trim($request->path(), '/');
        if (! config('website.domain')) {
            $path = ltrim(substr($path, strlen((string) config('website.path', 'site'))), '/');
        }

        return $path === 'ar' || str_starts_with($path, 'ar/') ? 'ar' : 'en';
    }

    /* ---------- Contact details (Business settings › Website contact & social) ---------- */

    public static function phone(): ?string
    {
        return Setting::read('website_phone') ?: Setting::read('company_phone');
    }

    public static function tel(): ?string
    {
        $p = self::phone();

        return $p ? 'tel:'.preg_replace('/[^0-9+]/', '', $p) : null;
    }

    public static function email(): ?string
    {
        return Setting::read('website_email') ?: Setting::read('company_email');
    }

    public static function address(): ?string
    {
        return Setting::read('website_address') ?: Setting::read('company_address');
    }

    public static function mapUrl(): ?string
    {
        return Setting::read('website_map_url') ?: null;
    }

    /** wa.me link, optionally with a ready message. */
    public static function whatsapp(?string $text = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) (Setting::read('website_whatsapp') ?: self::phone()));
        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.$digits.($text ? '?text='.rawurlencode($text) : '');
    }

    /** @return array<string, string> network => url (only the ones filled in) */
    public static function socials(): array
    {
        return array_filter([
            'instagram' => Setting::read('website_instagram'),
            'facebook' => Setting::read('website_facebook'),
            'tiktok' => Setting::read('website_tiktok'),
            'snapchat' => Setting::read('website_snapchat'),
        ]);
    }

    public static function company(): string
    {
        return (string) (Setting::read('company_name') ?: 'RED PRIME RENTALS');
    }

    /* ---------- Sections ---------- */

    protected static ?array $sections = null;

    /** Which parts of the website have something to show (switched on in the CRM and at least one published item). */
    public static function sections(): array
    {
        return self::$sections ??= [
            'cars' => Category::isEnabled(Category::CARS) && \App\Models\Car::query()->publiclyVisible()->exists(),
            'yachts' => Category::isEnabled(Category::YACHTS) && \App\Models\Yacht::query()->publiclyVisible()->exists(),
            'trips' => Category::isEnabled(Category::YACHT_TRIPS) && \App\Models\Trip::query()->publiclyVisible()->where('status', \App\Enums\TripStatus::Active)->exists(),
            'packages' => \App\Models\Package::query()->publiclyVisible()->active()->exists(),
            'limousine' => \App\Models\TransferService::query()->where('is_active', true)->where('is_published', true)->exists()
                && \App\Models\VehicleCategory::query()->where('is_active', true)->where('is_published', true)->exists(),
        ];
    }

    public static function has(string $section): bool
    {
        return (bool) (self::sections()[$section] ?? false);
    }

    /** Forget per-request caches (tests). */
    public static function flush(): void
    {
        self::$sections = null;
    }

    /* ---------- Images and services (29 Oct, Bilal: logo + photos from the Instagram account) ---------- */

    /** A picture shipped with the website (public/images/site). */
    public static function img(string $name): string
    {
        return asset('images/site/'.$name);
    }

    /** Pictures for a car type, used until the CRM has photos of a car of that type. */
    public const TYPE_IMAGES = [
        'suv' => 'fleet-g63.webp', '4x4' => 'fleet-defender.webp', 'jeep' => 'fleet-g63.webp', 'crossover' => 'fleet-rr-sport.webp',
        'luxury' => 'fleet-s580.webp', 'sedan' => 'fleet-s580.webp', 'saloon' => 'fleet-s580.webp', 'executive' => 'fleet-735i.webp',
        'supercar' => 'photo-lambo-desert.jpg', 'super' => 'photo-lambo-desert.jpg', 'exotic' => 'photo-lambo-night.jpg',
        'sport' => 'fleet-m4.webp', 'coupe' => 'fleet-m4.webp', 'convertible' => 'fleet-m4.webp', 'cabrio' => 'fleet-m4.webp',
        'van' => 'fleet-escalade.webp', 'mpv' => 'fleet-escalade.webp', 'limousine' => 'fleet-escalade.webp',
        'pickup' => 'fleet-yukon.webp', 'truck' => 'fleet-yukon.webp', 'economy' => 'fleet-kia.webp', 'compact' => 'fleet-mazda.webp',
    ];

    /** The picture for a car type: a CRM photo of a car of that type first, then the website's own picture. */
    public static function typeImage(?string $body, $cars = null): string
    {
        $crm = collect($cars ?? [])->first(fn ($c) => ($c['body'] ?? null) === $body && ! empty($c['images']['cover']['large']));
        if ($crm) {
            return $crm['images']['cover']['large'];
        }
        $key = mb_strtolower((string) $body);
        foreach (self::TYPE_IMAGES as $word => $file) {
            if (str_contains($key, $word)) {
                return self::img($file);
            }
        }

        return self::img('fleet-garage.webp');
    }

    /**
     * Service pages. Texts are in lang/{en,ar}/site.php under services.items.{slug}.
     * `cars` picks which published cars are shown on the page; `lead` is the service
     * the enquiry form sends to the CRM.
     */
    public const SERVICES = [
        'chauffeur-service' => ['image' => 'fleet-s580.webp', 'icon' => 'steering', 'cars' => 'with_driver', 'lead' => 'car', 'needs' => 'cars'],
        'self-drive-car-rental' => ['image' => 'fleet-rr-sport.webp', 'icon' => 'car', 'cars' => 'self_drive', 'lead' => 'car', 'needs' => 'cars'],
        'monthly-car-rental' => ['image' => 'fleet-patrol.webp', 'icon' => 'calendar', 'cars' => 'monthly', 'lead' => 'car', 'needs' => 'cars'],
        'airport-transfers' => ['image' => 'fleet-escalade.webp', 'icon' => 'plane', 'cars' => 'with_driver', 'lead' => 'transfer', 'needs' => null],
        'wedding-and-events' => ['image' => 'car-g63-white.jpg', 'icon' => 'heart', 'cars' => 'featured', 'lead' => 'car', 'needs' => 'cars'],
        'corporate-car-rental' => ['image' => 'fleet-735i.webp', 'icon' => 'shield', 'cars' => 'all', 'lead' => 'car', 'needs' => 'cars'],
        'car-delivery' => ['image' => 'photo-gmc-marina.jpg', 'icon' => 'pin', 'cars' => 'delivery', 'lead' => 'car', 'needs' => 'cars'],
        'yacht-charter' => ['image' => 'yacht-01.webp', 'icon' => 'yacht', 'cars' => null, 'lead' => 'yacht', 'needs' => 'yachts'],
    ];

    /** Services whose part of the fleet is on the website. */
    public static function services(): array
    {
        return array_filter(self::SERVICES, fn ($s, $slug) => match (true) {
            $s['needs'] === 'cars' => self::has('cars'),
            $s['needs'] === 'yachts' => self::has('yachts'),
            $slug === 'airport-transfers' => self::has('limousine') || self::has('cars'),
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** The Instagram pictures shown on the gallery page (public/images/site/insta-01…12.jpg). */
    public static function instagram(): array
    {
        return array_map(fn ($n) => self::img(sprintf('insta-%02d.jpg', $n)), range(1, 12));
    }

    /** Photos of the real fleet from redprime.ae (30 Oct, Bilal), used on the gallery page. */
    public const FLEET_PHOTOS = ['fleet-garage.webp', 'fleet-s580.webp', 'fleet-g63.webp', 'fleet-escalade.webp', 'fleet-brabus.webp', 'fleet-rr-sport.webp',
        'fleet-defender.webp', 'fleet-m4.webp', 'fleet-yukon.webp', 'fleet-735i.webp', 'fleet-patrol.webp', 'fleet-e200.webp', 'fleet-mazda.webp', 'fleet-kia.webp'];

    /** The yacht photo Bilal sent (30 Oct). Used where the website talks about yachts in general, never as the photo of one CRM yacht. */
    public static function yachtImage(bool $small = false): string
    {
        return self::img($small ? 'yacht-01-s.webp' : 'yacht-01.webp');
    }

    /**
     * Car brands with a logo (public/images/brands), in the order redprime.ae shows them (30 Oct, Bilal).
     * These always have a brand page; CRM cars are matched to them by `match` (brand field, lower case).
     * A CRM brand that is not listed here still gets a page with a name tile instead of a logo.
     */
    public const BRANDS = [
        'mercedes-benz' => ['name' => 'Mercedes-Benz', 'logo' => 'mercedes-benz.webp', 'photo' => 'fleet-s580.webp', 'match' => ['mercedes', 'mercedes-benz', 'mercedes benz', 'benz', 'mercedes-amg', 'amg', 'brabus', 'mercedes-maybach', 'maybach']],
        'cadillac' => ['name' => 'Cadillac', 'logo' => 'cadillac.webp', 'photo' => 'fleet-escalade.webp', 'match' => ['cadillac']],
        'gmc' => ['name' => 'GMC', 'logo' => 'gmc.webp', 'photo' => 'fleet-yukon.webp', 'match' => ['gmc']],
        'range-rover' => ['name' => 'Range Rover', 'logo' => 'range-rover.webp', 'photo' => 'fleet-rr-sport.webp', 'match' => ['range rover', 'range-rover', 'rangerover']],
        'land-rover' => ['name' => 'Land Rover', 'logo' => 'land-rover.svg', 'photo' => 'fleet-defender.webp', 'match' => ['land rover', 'land-rover', 'landrover']],
        'bmw' => ['name' => 'BMW', 'logo' => 'bmw.webp', 'photo' => 'fleet-735i.webp', 'match' => ['bmw', 'bmw m']],
        'nissan' => ['name' => 'Nissan', 'logo' => 'nissan.webp', 'photo' => 'fleet-patrol.webp', 'match' => ['nissan']],
        'mazda' => ['name' => 'Mazda', 'logo' => 'mazda.webp', 'photo' => 'fleet-mazda.webp', 'match' => ['mazda']],
        'kia' => ['name' => 'Kia', 'logo' => 'kia.webp', 'photo' => 'fleet-kia.webp', 'match' => ['kia']],
    ];

    /**
     * The brand page a car belongs to. A Land Rover whose name says "Range Rover" goes to Range Rover,
     * because that is how the showroom groups them.
     */
    public static function brandSlug(?string $brand, ?string $name = null): ?string
    {
        $b = mb_strtolower(trim((string) $brand));
        if ($b === '') {
            return null;
        }
        foreach (self::BRANDS as $slug => $info) {
            if (in_array($b, $info['match'], true)) {
                return $slug === 'land-rover' && str_contains(mb_strtolower((string) $name), 'range rover') ? 'range-rover' : $slug;
            }
        }

        return \Illuminate\Support\Str::slug($brand) ?: null;
    }

    public static function brandLogo(string $slug): ?string
    {
        return isset(self::BRANDS[$slug]) ? asset('images/brands/'.self::BRANDS[$slug]['logo']) : null;
    }

    /**
     * Delivery area pages (30 Oct, Bilal chose "Delivery areas"). Texts are in lang/{en,ar}/site.php
     * under areas.items.{slug}. No fee or delivery time is promised here: the price estimate shows
     * the delivery fee from the CRM price list.
     */
    public const AREAS = [
        'dubai-marina' => ['image' => 'fleet-g63.webp', 'near' => ['jbr', 'jlt', 'palm-jumeirah']],
        'jbr' => ['image' => 'fleet-rr-sport.webp', 'near' => ['dubai-marina', 'jlt', 'palm-jumeirah']],
        'downtown-dubai' => ['image' => 'fleet-735i.webp', 'near' => ['business-bay', 'difc', 'jumeirah']],
        'business-bay' => ['image' => 'fleet-e200.webp', 'near' => ['downtown-dubai', 'difc', 'jumeirah']],
        'palm-jumeirah' => ['image' => 'fleet-s580.webp', 'near' => ['dubai-marina', 'jbr', 'al-barsha']],
        'difc' => ['image' => 'fleet-escalade.webp', 'near' => ['downtown-dubai', 'business-bay', 'jumeirah']],
        'jumeirah' => ['image' => 'fleet-defender.webp', 'near' => ['downtown-dubai', 'al-barsha', 'business-bay']],
        'al-barsha' => ['image' => 'fleet-yukon.webp', 'near' => ['dubai-hills', 'jumeirah', 'dubai-marina']],
        'jlt' => ['image' => 'fleet-m4.webp', 'near' => ['dubai-marina', 'jbr', 'dubai-hills']],
        'dubai-hills' => ['image' => 'fleet-patrol.webp', 'near' => ['al-barsha', 'jlt', 'downtown-dubai']],
        'deira' => ['image' => 'fleet-brabus.webp', 'near' => ['dubai-airport', 'downtown-dubai', 'business-bay']],
        'dubai-airport' => ['image' => 'fleet-escalade.webp', 'near' => ['deira', 'downtown-dubai', 'business-bay']],
    ];

    /**
     * Occasion tiles on the home page. Each opens the quick quote with the occasion filled in,
     * so the request reaches the CRM as a lead with that note.
     */
    public const OCCASIONS = [
        'wedding' => ['image' => 'car-g63-white.jpg', 'icon' => 'heart', 'service' => 'car'],
        'birthday' => ['image' => 'fleet-brabus.webp', 'icon' => 'sparkle', 'service' => 'car'],
        'airport' => ['image' => 'fleet-escalade.webp', 'icon' => 'plane', 'service' => 'transfer'],
        'business' => ['image' => 'fleet-735i.webp', 'icon' => 'shield', 'service' => 'car'],
        'photoshoot' => ['image' => 'photo-lambo-night.jpg', 'icon' => 'star', 'service' => 'car'],
        'yacht-party' => ['image' => 'yacht-01-s.webp', 'icon' => 'yacht', 'service' => 'yacht'],
    ];

    /** Photos staff can pick for an offer (Website offers & reviews page). */
    public const OFFER_IMAGES = [
        'fleet-garage.webp' => 'Fleet in the garage', 'fleet-s580.webp' => 'Mercedes S580', 'fleet-g63.webp' => 'Mercedes G63',
        'fleet-brabus.webp' => 'G63 Brabus', 'fleet-escalade.webp' => 'Cadillac Escalade', 'fleet-yukon.webp' => 'GMC Yukon',
        'fleet-rr-sport.webp' => 'Range Rover Sport', 'fleet-defender.webp' => 'Defender', 'fleet-735i.webp' => 'BMW 735i',
        'fleet-m4.webp' => 'BMW M4', 'fleet-patrol.webp' => 'Nissan Patrol', 'fleet-e200.webp' => 'Mercedes E200',
        'fleet-mazda.webp' => 'Mazda CX-30', 'fleet-kia.webp' => 'Kia Sportage', 'yacht-01.webp' => 'Yacht',
    ];

    /**
     * Offers entered by staff (CRM › Settings › Website offers & reviews). Nothing is invented:
     * with no offer saved, the offers page shows the longer-rental rates from the CRM prices only.
     */
    public static function offers(): array
    {
        $today = now()->toDateString();

        return collect((array) Setting::read('website_offers', []))
            ->filter(fn ($o) => is_array($o) && filled($o['title'] ?? null) && ($o['is_active'] ?? true)
                && (empty($o['valid_until']) || (string) $o['valid_until'] >= $today))
            ->values()->all();
    }

    /** Real customer reviews entered by staff. Empty = the reviews section only links to Google. */
    public static function reviews(): array
    {
        return collect((array) Setting::read('website_reviews', []))
            ->filter(fn ($r) => is_array($r) && filled($r['name'] ?? null) && filled($r['text'] ?? null))
            ->map(fn ($r) => $r + ['rating' => 5, 'source' => null])
            ->values()->all();
    }

    /* ---------- Formatting ---------- */

    public static function money(?float $amount, bool $decimals = false): string
    {
        if ($amount === null) {
            return '';
        }
        $n = number_format($amount, $decimals && floor($amount) != $amount ? 2 : 0);

        return self::rtl() ? $n.' '.__('site.aed') : __('site.aed').' '.$n;
    }

    /**
     * Rich text written in the CRM, made safe for the public page: only simple tags,
     * no attributes except a plain http(s)/mailto link on <a>.
     */
    public static function safeHtml(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $html = strip_tags($html, '<p><br><ul><ol><li><strong><b><em><i><h2><h3><h4><a><blockquote>');
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="rp-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        $root = $xpath->query('//div[@id="rp-root"]')->item(0);
        foreach ($xpath->query('//div[@id="rp-root"]//*[@*]') as $el) {
            /** @var \DOMElement $el */
            $href = $el->nodeName === 'a' ? (string) $el->getAttribute('href') : '';
            foreach (iterator_to_array($el->attributes) as $attr) {
                $el->removeAttribute($attr->nodeName);
            }
            if ($href !== '' && preg_match('#^(https?:|mailto:)#i', $href)) {
                $el->setAttribute('href', $href);
                $el->setAttribute('rel', 'nofollow noopener');
                $el->setAttribute('target', '_blank');
            }
        }
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /** Website address of a page for canonical tags and the sitemap. */
    public static function absolute(string $url): string
    {
        $base = (string) config('website.url');
        if ($base === '' || ! config('website.domain')) {
            return $url;
        }

        return $base.(parse_url($url, PHP_URL_PATH) ?: '/');
    }
}
