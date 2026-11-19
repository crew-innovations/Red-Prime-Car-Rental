<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Support\Site\Catalog;
use App\Support\Site\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The public website pages (M3). Everything shown comes from the CRM through
 * App\Support\Site\Catalog (same public rules and fields as the website API).
 * Prices, availability and requests are worked out by the API on the server.
 */
class SiteController extends Controller
{
    public function __construct()
    {
        Site::flush();
    }

    protected function page(string $view, array $data, array $seo = []): Response
    {
        $seo += ['title' => null, 'description' => __('site.seo.default_description'), 'image' => null, 'jsonld' => [], 'noindex' => false];

        $response = response()->view('site.'.$view, $data + ['seo' => $seo])
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 19 Nov (Bilal: new cars showed on the type pages but not on "All cars"): pages were kept by the
        // browser for 5 minutes, so a page seen before showed the old fleet. Now the browser asks every time
        // and gets a short "not changed" answer when nothing changed, so CRM changes show straight away.
        $response->setEtag(md5((string) $response->getContent()));
        $response->headers->set('Cache-Control', 'public, no-cache');
        $response->isNotModified(request());

        return $response;
    }

    public function home()
    {
        $cars = Site::has('cars') ? Catalog::cars() : collect();
        $yachts = Site::has('yachts') ? Catalog::yachts() : collect();

        return $this->page('home', [
            'cars' => $cars,
            'featured' => $cars->sortByDesc(fn ($c) => ! empty($c['featured']))->take(8)->values(),
            'yachts' => $yachts->take(3),
            'trips' => Site::has('trips') ? Catalog::trips()->take(3) : collect(),
            'packages' => Site::has('packages') ? Catalog::packages()->take(3) : collect(),
            'brands' => $cars->pluck('brand')->filter()->unique()->values(),
            'brandList' => Site::has('cars') ? Catalog::brands($cars) : [],
            'offers' => Catalog::offers(),
            'reviews' => Site::reviews(),
            'posts' => BlogPost::query()->published()->with('media')->orderByDesc('published_at')->take(3)->get(),
            'bodies' => $cars->pluck('body')->filter()->countBy()->sortDesc(),
            'hero' => $cars->first(fn ($c) => ! empty($c['images']['cover']['large'])),
            'headerSolid' => false,
        ], [
            'title' => __('site.seo.home_title'),
            'jsonld' => [self::organisation()],
        ]);
    }

    public function cars(Request $request)
    {
        abort_unless(Site::has('cars'), 404);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'brand' => ['nullable', 'array'], 'brand.*' => ['string', 'max:80'],
            'body' => ['nullable', 'array'], 'body.*' => ['string', 'max:80'],
            'mode' => ['nullable', 'in:self_drive,with_driver'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:20'],
            'max' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:featured,price_asc,price_desc,power,newest'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'available' => ['nullable', 'boolean'],
        ]);
        [$f['start'], $f['end']] = Catalog::range($f['from'] ?? null, $f['to'] ?? null);

        return $this->page('cars', [
            'cars' => Catalog::cars($f),
            'options' => Catalog::carOptions(),
            'f' => $f,
        ], [
            'title' => __('site.seo.cars_title'),
            'description' => __('site.seo.cars_description'),
            'noindex' => count(array_filter($request->except('sort'))) > 0, // filtered lists: not indexed
        ]);
    }

    public function car(string $slug)
    {
        $car = Catalog::car($slug) ?? abort(404);
        $related = Catalog::cars()->reject(fn ($c) => $c['slug'] === $car['slug'])
            ->sortBy(fn ($c) => ($c['body'] ?? null) === ($car['body'] ?? null) ? 0 : 1)->take(4)->values();

        return $this->page('car', ['car' => $car, 'related' => $related], [
            'title' => $car['seo']['title'] ?? $car['name'],
            'description' => $car['seo']['description'] ?? ($car['summary'] ?: __('site.seo.car_description', ['name' => $car['name']])),
            'image' => $car['images']['cover']['large'] ?? null,
            'jsonld' => [self::productLd($car, Catalog::carFrom($car), Site::route('car', ['slug' => $car['slug']]))],
        ]);
    }

    public function yachts(Request $request)
    {
        abort_unless(Site::has('yachts'), 404);
        $f = $request->validate([
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
            'marina' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:featured,price_asc,price_desc,size'],
        ]);
        $all = Catalog::yachts();

        return $this->page('yachts', [
            'yachts' => Catalog::yachts($f),
            'marinas' => $all->pluck('marina')->filter()->unique()->values(),
            'maxGuests' => (int) $all->max('guests'),
            'trips' => Site::has('trips') ? Catalog::trips()->take(3) : collect(),
            'f' => $f,
        ], [
            'title' => __('site.seo.yachts_title'),
            'description' => __('site.seo.yachts_description'),
            'noindex' => count(array_filter($request->except('sort'))) > 0,
        ]);
    }

    public function yacht(string $slug)
    {
        $yacht = Catalog::yacht($slug) ?? abort(404);
        $others = Catalog::yachts()->reject(fn ($y) => $y['slug'] === $yacht['slug'])->take(3)->values();

        return $this->page('yacht', ['yacht' => $yacht, 'others' => $others], [
            'title' => $yacht['seo']['title'] ?? $yacht['name'],
            'description' => $yacht['seo']['description'] ?? ($yacht['summary'] ?: __('site.seo.yacht_description', ['name' => $yacht['name']])),
            'image' => $yacht['images']['cover']['large'] ?? null,
            'jsonld' => [self::productLd($yacht, $yacht['prices']['hourly'] ?? null, Site::route('yacht', ['slug' => $yacht['slug']]))],
        ]);
    }

    public function trips()
    {
        abort_unless(Site::has('trips'), 404);

        return $this->page('trips', ['trips' => Catalog::trips()], [
            'title' => __('site.seo.trips_title'), 'description' => __('site.seo.trips_description'),
        ]);
    }

    public function trip(string $slug)
    {
        $trip = Catalog::trip($slug) ?? abort(404);

        return $this->page('trip', ['trip' => $trip, 'kind' => 'trip'], [
            'title' => $trip['seo']['title'] ?? $trip['name'],
            'description' => $trip['seo']['description'] ?? $trip['summary'],
            'image' => $trip['images']['cover']['large'] ?? null,
            'jsonld' => [self::productLd($trip, $trip['price']['amount'] ?? null, Site::route('trip', ['slug' => $trip['slug']]))],
        ]);
    }

    public function packages()
    {
        abort_unless(Site::has('packages'), 404);

        return $this->page('packages', ['packages' => Catalog::packages()], [
            'title' => __('site.seo.packages_title'), 'description' => __('site.seo.packages_description'),
        ]);
    }

    public function package(string $slug)
    {
        $package = Catalog::package($slug) ?? abort(404);

        return $this->page('trip', ['package' => $package, 'kind' => 'package'], [
            'title' => $package['seo']['title'] ?? $package['name'],
            'description' => $package['seo']['description'] ?? $package['summary'],
            'image' => $package['images']['cover']['large'] ?? null,
            'jsonld' => [self::productLd($package, $package['price']['from_amount'] ?? null, Site::route('package', ['slug' => $package['slug']]))],
        ]);
    }

    public function limousine()
    {
        $services = Catalog::transferServices();
        $categories = Catalog::vehicleCategories();
        abort_if(! $services || ! $categories, 404);

        return $this->page('limousine', ['services' => $services, 'categories' => $categories], [
            'title' => __('site.seo.limo_title'), 'description' => __('site.seo.limo_description'),
        ]);
    }

    /* ---------- Services, brands, car types, how it works, gallery (29 Oct-2) ---------- */

    public function services()
    {
        return $this->page('services', ['services' => Site::services()], [
            'title' => __('site.services.title'), 'description' => __('site.services.lead'),
        ]);
    }

    public function service(string $slug)
    {
        $all = Site::services();
        $svc = $all[$slug] ?? abort(404);
        $t = __('site.services.items.'.$slug);

        return $this->page('service', [
            'slug' => $slug, 'svc' => $svc, 't' => $t,
            'cars' => Catalog::carsFor($svc['cars']),
            'yachts' => $slug === 'yacht-charter' ? Catalog::yachts()->take(6) : collect(),
            'others' => array_diff_key($all, [$slug => true]),
        ], [
            'title' => $t['seo'] ?? $t['title'], 'description' => $t['lead'],
            'image' => Site::img($svc['image']),
            'jsonld' => [['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $t['title'], 'description' => $t['lead'],
                'provider' => ['@type' => 'AutoRental', 'name' => Site::company()], 'areaServed' => 'Dubai']],
        ]);
    }

    public function brands()
    {
        abort_unless(Site::has('cars'), 404);
        $cars = Catalog::cars();

        return $this->page('brands', ['brands' => Catalog::brands($cars), 'cars' => $cars], [
            'title' => __('site.brands.title'), 'description' => __('site.brands.lead'),
        ]);
    }

    public function brand(string $slug)
    {
        abort_unless(Site::has('cars'), 404);
        $cars = Catalog::cars();
        $all = Catalog::brands($cars);
        $brand = $all[$slug] ?? abort(404);
        $list = Catalog::carsOfBrand($slug, $cars);

        return $this->page('collection', [
            'kind' => 'brand', 'name' => $brand['name'], 'logo' => $brand['logo'],
            'cars' => $list,
            'image' => $list->first()['images']['cover']['large'] ?? $brand['photo'] ?? Site::typeImage($list->first()['body'] ?? null),
            'links' => collect($all)->except($slug)->map(fn ($b) => ['name' => $b['name'], 'url' => Site::route('brand', ['slug' => $b['slug']]), 'count' => $b['count'], 'logo' => $b['logo']]),
        ], [
            'title' => __('site.collection.brand_title', ['name' => $brand['name']]),
            'description' => __('site.collection.brand_description', ['name' => $brand['name']]),
        ]);
    }

    public function type(string $slug)
    {
        abort_unless(Site::has('cars'), 404);
        $cars = Catalog::cars();
        $type = Catalog::types($cars)[$slug] ?? abort(404);

        return $this->page('collection', [
            'kind' => 'type', 'name' => $type['name'],
            'cars' => $cars->filter(fn ($c) => ($c['body'] ?? null) === $type['name'])->values(),
            'image' => Site::typeImage($type['name'], $cars),
            'links' => collect(Catalog::types($cars))->except($slug)->map(fn ($b) => ['name' => $b['name'], 'url' => Site::route('type', ['slug' => $b['slug']]), 'count' => $b['count']]),
        ], [
            'title' => __('site.collection.type_title', ['name' => $type['name']]),
            'description' => __('site.collection.type_description', ['name' => $type['name']]),
        ]);
    }

    /* ---------- Offers, blog, delivery areas (30 Oct) ---------- */

    public function offers()
    {
        $cars = Site::has('cars') ? Catalog::cars() : collect();

        return $this->page('offers', [
            'offers' => Catalog::offers(),
            'deals' => Catalog::longRentalDeals($cars)->take(12),
        ], ['title' => __('site.offers.title'), 'description' => __('site.offers.lead'), 'image' => Site::img('fleet-garage.webp')]);
    }

    public function blog()
    {
        $posts = BlogPost::query()->published()->with('media')->orderByDesc('published_at')->get();

        return $this->page('blog', ['posts' => $posts], ['title' => __('site.blog.title'), 'description' => __('site.blog.lead')]);
    }

    public function post(string $slug)
    {
        $post = BlogPost::query()->published()->with('media')->where('slug', $slug)->first() ?? abort(404);
        $image = self::postImage($post, 'large');

        return $this->page('post', [
            'post' => $post,
            'image' => $image,
            'more' => BlogPost::query()->published()->with('media')->whereKeyNot($post->id)->orderByDesc('published_at')->take(3)->get(),
        ], [
            'title' => $post->local('title'),
            'description' => $post->local('excerpt') ?: \Illuminate\Support\Str::limit(strip_tags((string) $post->local('body')), 155),
            'image' => $image,
            'jsonld' => [[
                '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post->local('title'),
                'datePublished' => optional($post->published_at)->toIso8601String(), 'dateModified' => $post->updated_at?->toIso8601String(),
                'image' => $image, 'author' => ['@type' => 'Organization', 'name' => Site::company()],
                'publisher' => ['@type' => 'Organization', 'name' => Site::company(), 'logo' => ['@type' => 'ImageObject', 'url' => asset('images/brand/icon-512.png')]],
                'mainEntityOfPage' => Site::absolute(Site::route('post', ['slug' => $post->slug])),
            ]],
        ]);
    }

    /** Cover of a blog post: the uploaded photo, else the chosen website photo. */
    public static function postImage(BlogPost $post, string $size = 'thumb'): string
    {
        $media = $post->getFirstMedia('cover');
        if ($media) {
            return \App\Http\Resources\Api\Media::image($media)[$size] ?? $media->getUrl();
        }

        return Site::img($post->cover_image ?: 'fleet-garage.webp');
    }

    public function areas()
    {
        return $this->page('areas', ['areas' => Site::AREAS], ['title' => __('site.areas.title'), 'description' => __('site.areas.lead')]);
    }

    public function area(string $slug)
    {
        $area = Site::AREAS[$slug] ?? abort(404);
        $name = __('site.areas.items.'.$slug.'.name');

        return $this->page('area', [
            'slug' => $slug, 'area' => $area, 'name' => $name,
            'cars' => Site::has('cars') ? Catalog::carsFor('delivery', 8) : collect(),
        ], [
            'title' => __('site.areas.page_title', ['area' => $name]),
            'description' => __('site.areas.page_description', ['area' => $name]),
            'image' => Site::img($area['image']),
            'jsonld' => [['@context' => 'https://schema.org', '@type' => 'Service', 'name' => __('site.areas.page_title', ['area' => $name]),
                'provider' => ['@type' => 'AutoRental', 'name' => Site::company()], 'areaServed' => ['@type' => 'Place', 'name' => $name.', Dubai']]],
        ]);
    }

    public function how()
    {
        return $this->page('how', [], ['title' => __('site.how.title'), 'description' => __('site.how.lead')]);
    }

    public function gallery()
    {
        return $this->page('gallery', ['photos' => array_merge(array_map(fn ($f) => Site::img($f), Site::FLEET_PHOTOS), [Site::yachtImage()], Site::instagram())], ['title' => __('site.gallery.title'), 'description' => __('site.gallery.lead')]);
    }

    public function about()
    {
        return $this->page('about', [], ['title' => __('site.seo.about_title'), 'description' => __('site.seo.about_description')]);
    }

    public function contact()
    {
        return $this->page('contact', [], ['title' => __('site.seo.contact_title'), 'description' => __('site.seo.contact_description'), 'jsonld' => [self::organisation()]]);
    }

    public function faq()
    {
        $faqs = __('site.faq.items');

        return $this->page('faq', ['faqs' => $faqs], [
            'title' => __('site.seo.faq_title'),
            'jsonld' => [[
                '@context' => 'https://schema.org', '@type' => 'FAQPage',
                'mainEntity' => collect($faqs)->filter(fn ($f) => ! str_contains($f['a'], '['))->map(fn ($f) => [
                    '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
                ])->values()->all(),
            ]],
        ]);
    }

    public function terms()
    {
        return $this->page('legal', ['kind' => 'terms'], ['title' => __('site.legal.terms_title'), 'noindex' => true]);
    }

    public function privacy()
    {
        return $this->page('legal', ['kind' => 'privacy'], ['title' => __('site.legal.privacy_title'), 'noindex' => true]);
    }

    /* ---------- SEO files ---------- */

    public function sitemap()
    {
        $urls = [];
        $add = function (string $name, array $params = [], string $freq = 'weekly', string $priority = '0.7') use (&$urls) {
            $urls[] = [
                'loc' => Site::absolute(Site::route($name, $params, 'en')),
                'ar' => Site::absolute(Site::route($name, $params, 'ar')),
                'freq' => $freq, 'priority' => $priority,
            ];
        };

        $add('home', [], 'daily', '1.0');
        if (Site::has('cars')) {
            $add('cars', [], 'daily', '0.9');
            Catalog::cars()->each(fn ($c) => $add('car', ['slug' => $c['slug']], 'weekly', '0.8'));
        }
        if (Site::has('yachts')) {
            $add('yachts', [], 'daily', '0.9');
            Catalog::yachts()->each(fn ($y) => $add('yacht', ['slug' => $y['slug']], 'weekly', '0.8'));
        }
        if (Site::has('trips')) {
            $add('trips', [], 'daily', '0.8');
            Catalog::trips()->each(fn ($t) => $add('trip', ['slug' => $t['slug']]));
        }
        if (Site::has('packages')) {
            $add('packages', [], 'weekly', '0.8');
            Catalog::packages()->each(fn ($p) => $add('package', ['slug' => $p['slug']]));
        }
        if (Site::has('limousine')) {
            $add('limousine', [], 'weekly', '0.8');
        }
        $add('services', [], 'weekly', '0.8');
        foreach (array_keys(Site::services()) as $slug) {
            $add('service', ['slug' => $slug], 'weekly', '0.8');
        }
        if (Site::has('cars')) {
            $add('brands', [], 'weekly', '0.7');
            foreach (Catalog::brands() as $b) {
                $add('brand', ['slug' => $b['slug']], 'weekly', '0.7');
            }
            foreach (Catalog::types() as $t) {
                $add('type', ['slug' => $t['slug']], 'weekly', '0.8');
            }
        }
        $add('offers', [], 'weekly', '0.7');
        $add('areas', [], 'monthly', '0.7');
        foreach (array_keys(Site::AREAS) as $slug) {
            $add('area', ['slug' => $slug], 'monthly', '0.7');
        }
        $add('blog', [], 'weekly', '0.6');
        BlogPost::query()->published()->get(['slug'])->each(fn ($p) => $add('post', ['slug' => $p->slug], 'monthly', '0.6'));
        foreach (['about', 'contact', 'faq', 'how', 'gallery'] as $p) {
            $add($p, [], 'monthly', '0.5');
        }

        return response()->view('site.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots()
    {
        $sitemap = Site::absolute(route('site.sitemap'));

        // CRM, customer area and private links share this app: never indexed from the website domain.
        $private = collect(['/crm', '/account', '/livewire', '/upload/', '/quote/', '/pay/', '/f/', '/invite', '/api/'])->map(fn ($p) => 'Disallow: '.$p)->implode("\n");

        return response("User-agent: *\nAllow: /\n{$private}\n\nSitemap: {$sitemap}\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /* ---------- Structured data ---------- */

    public static function organisation(): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'AutoRental',
            'name' => Site::company(),
            'url' => Site::absolute(Site::route('home', [], 'en')),
            'telephone' => Site::phone(),
            'email' => Site::email(),
            'address' => Site::address() ? ['@type' => 'PostalAddress', 'streetAddress' => Site::address(), 'addressLocality' => 'Dubai', 'addressCountry' => 'AE']
                : ['@type' => 'PostalAddress', 'addressLocality' => 'Dubai', 'addressCountry' => 'AE'],
            'areaServed' => 'Dubai',
            'hasMap' => Site::mapUrl(),
            'sameAs' => array_values(Site::socials()),
        ]);
    }

    protected static function productLd(array $item, ?float $price, string $url): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $item['name'],
            'description' => Str::limit((string) ($item['summary'] ?? ''), 300),
            'image' => $item['images']['cover']['large'] ?? null,
            'brand' => isset($item['brand']) ? ['@type' => 'Brand', 'name' => $item['brand']] : null,
            'offers' => $price ? ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'AED', 'availability' => 'https://schema.org/InStock', 'url' => Site::absolute($url)] : null,
        ]);
    }
}
